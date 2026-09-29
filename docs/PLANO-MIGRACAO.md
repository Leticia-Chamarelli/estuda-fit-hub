# Plano de migração e corte

Estratégia: strangler por unidade. A leitura muda uma vez, para todos; a escrita muda unidade a unidade. Cada etapa tem critério objetivo para avançar e um rollback conhecido.

## Etapa 0: preparar sem trocar dono (1 deploy do monólito + serviço no ar sem tráfego)

1. Subir o serviço de Cobrança com banco próprio (`db:migrate`), health check e alertas.
2. Aplicar `database/migrations/002_cobranca_transicao.sql` no monólito. Em produção, fora do `db:migrate`:
   - criar as tabelas novas e os triggers (aditivo, sem lock longo);
   - carregar `fin_faturas` a partir de `faturas` **em lotes por faixa de id** (ex.: 50 mil por vez, com pausa), não com o `INSERT … SELECT` único usado localmente. Os triggers já estão ativos, então o que muda durante a carga não se perde (o upsert é idempotente);
   - conferir: `COUNT(*)` e `SUM(valor)` por `status` iguais em `faturas` e `fin_faturas`.
3. Deploy do código: leitores (check-in, tela do aluno, régua, relatórios) passam a ler `fin_faturas`; o webhook vira inbox + worker. `cobranca_roteamento` vazio = todas as unidades no legado.

**Critério para avançar (7 dias):** p95 do check-in igual ou melhor; `webhook_inbox` sem pendentes com mais de 1 minuto; relatórios batendo com a consulta antiga (rodar as duas em paralelo e comparar).
**Rollback:** deploy anterior. Tabelas e triggers novos podem ficar; não atrapalham o código antigo.

## Etapa 1: primeira unidade (a menor)

```bash
php bin/console cobranca:migrar-unidade 5
```

O comando faz, nesta ordem:

1. **Carga inicial:** lê assinaturas (matrículas) e faturas da unidade no banco do monólito e envia ao serviço em lotes (`POST /migracao/lotes`). Silenciosa e idempotente. O legado continua sendo o dono enquanto isso.
2. **Troca de dono:** `cobranca_roteamento.modo = 'servico'` para a unidade. A partir daqui: matrícula e cancelamento vão pela outbox; crons legados ignoram a unidade; webhooks de faturas da unidade são encaminhados ao serviço.
3. **Pausa curta e carga de delta:** reenvia tudo da unidade. Pega o que o legado alterou entre a carga inicial e a troca (pagamento, vencimento). Não sobrescreve fatura que o serviço já alterou (`versao > 1`).
4. **Reconciliação:** quantidade e soma por competência e status, legado × serviço. Código de saída 1 se divergir.

**Critério para avançar (7 dias):** reconciliação zerada (`cobranca:reconciliar 5`); nenhum item `rejeitado` em `cobranca_outbox`; outbox e inbox sem pendentes com mais de 1 minuto; atraso do consumidor (maior id do feed − cursor) abaixo de 1 minuto; p95 do check-in inalterado; nenhum `referencia_desconhecida` inesperado no serviço.

**Rollback:** `php bin/console cobranca:reverter-unidade 5`. Devolve a escrita ao legado, marca as assinaturas da unidade como `devolvida` no serviço (o cron dele para de cobrar) e traz de volta para `faturas` o que o serviço criou (inserida com `codigo_externo`) ou alterou (status, pagamento). O modelo de leitura continua com uma linha por fatura. Pode ser executado de novo para pegar retardatários. Eventos atrasados do serviço que chegam depois do rollback não retomam as linhas (a checagem é atômica no upsert do consumidor), e uma re-migração posterior sobrescreve no serviço o que o legado mudou durante o rollback (`versao_importada`). Validado localmente com três ciclos seguidos de migrar, operar (matrícula, webhook, cron) e reverter a mesma unidade: reconciliação zerada em todos e `faturas` × `fin_faturas` sem divergência.

## Etapa 2: ondas

| Onda | Unidades | Por quê |
|---|---|---|
| 1 | 1 pequena | Prova o caminho completo com risco mínimo |
| 2 | 4 de perfis diferentes | Uma grande, uma com muita inadimplência, uma multiunidade, uma de outra cidade |
| 3 | blocos de 10 | O restante, um bloco por semana |

Cada unidade repete a Etapa 1. **Janela de corte:** nunca entre o dia 28 e o dia 2 (cron mensal) nem durante a régua diária.

## Faturas históricas (14 milhões)

- **Onde terminam:** no banco do serviço de Cobrança. No monólito fica só `fin_faturas` (modelo de leitura), que continua existindo depois do fim.
- **Como chegam:** por unidade, empurradas pelo monólito pela API de migração (o serviço nunca lê o banco do monólito). ~350 mil faturas por unidade em lotes de 1.000 = ~350 requisições; em produção rodar de madrugada e com lotes maiores via inserts multi-linha (pendência abaixo).
- **Identidade:** fatura importada vira `leg_{id}` no serviço, igual à chave em `fin_faturas`; `gateway_ref` é preservado, então webhook de fatura antiga acha a fatura no serviço.
- **Faturas muito antigas** (pagas ou canceladas há mais de N anos): candidatas a ir para armazenamento frio em vez do banco quente do serviço; relatórios de histórico longo continuam em `fin_faturas`. Decisão de Finanças, fora desta fatia.

## Cron mensal

- **Durante a transição:** no dia 1º rodam os dois. O legado (`faturas:gerar-mensais` no monólito) ignora unidades migradas; o do serviço só conhece assinaturas migradas. Não há como os dois gerarem a mesma fatura: são conjuntos disjuntos de matrículas, e no serviço há `UNIQUE (assinatura_id, competencia)`.
- **No serviço:** `faturas:gerar-mensais 2026-10 --particao=i/n` roda em N processos em paralelo (ex.: 8), inserindo em lotes por transação, com o evento na mesma transação. O gateway é chamado depois, pelo worker `gateway:registrar`, então lentidão do gateway não atrasa a geração. Pode ser interrompido e reexecutado: o que já existe é pulado.
- **Deploy no dia 1º:** o cron agora vive num processo separado do monólito. Deploy do monólito não mata o cron de Cobrança; deploy do serviço no meio do cron é seguro porque o cron é reexecutável.
- **Desligar o legado:** quando a última unidade migrar, o agendador para de chamar os crons legados.

## Etapa 3: gateway direto no serviço

Com todas as unidades migradas, trocar no gateway a URL do webhook para o serviço. A inbox do monólito continua viva 30 dias encaminhando o que ainda chegar nela (reenvios antigos). Rollback: voltar a URL.

## Etapa 4: limpeza (ponto sem volta)

Depois de 30 dias estáveis na Etapa 3:

- remover triggers, `faturas`, `pagamentos` e `faturas.codigo_externo` do monólito (antes, um dump final para arquivo);
- remover `PagamentoLegado`, os caminhos legados de `MatriculaService`, os crons legados, a inbox e `cobranca_roteamento`;
- `fin_faturas` fica: é o modelo de leitura do monólito.

**Pontos sem volta:** apagar `faturas` (Etapa 4). Até lá, qualquer unidade volta com `cobranca:reverter-unidade`.

## Pendências conhecidas do plano

- Carga com inserts multi-linha e paralelismo por faixa de id.
- Painel com os critérios de avanço (hoje são consultas SQL manuais).
- Script de reconciliação contínua (diária) entre o serviço e `fin_faturas`, não só no corte.
