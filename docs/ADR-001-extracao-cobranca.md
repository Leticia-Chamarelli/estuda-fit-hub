# ADR-001: Extrair o domínio de Cobrança para um serviço independente

- **Status:** aceito, fatia vertical implementada neste repositório
- **Data:** 2026-09-28
- **Autora:** Letícia Chamarelli

## Contexto

Cobrança é o módulo com mais incidentes. O cron mensal (250 mil faturas) cai com qualquer deploy do monólito; o webhook do gateway, quando o gateway fica lento, prende workers e derruba o check-in; Finanças quer cadência própria. O desafio lista oito acoplamentos (A a H). As restrições que guiam tudo abaixo: check-in abaixo de 300 ms no p95 **mesmo com Cobrança fora do ar**, webhook sem indisponibilidade e tolerante a duplicata e fora de ordem, relatórios sempre de pé, e o serviço novo nunca tocando o banco do monólito.

## Decisão

A decisão tem duas metades, que andam em ritmos diferentes:

1. **Leitura primeiro, para todo mundo.** O monólito ganha um modelo de leitura financeiro próprio, `fin_faturas`, com uma linha por fatura. Check-in (B), tela do aluno (H), régua (E) e relatórios (F) passam a ler só dele, **para todas as unidades, no primeiro deploy**. Enquanto a fatura mora no legado, triggers em `faturas`/`pagamentos` mantêm a linha; quando mora no serviço, o consumidor de eventos mantém. Os leitores não sabem nem precisam saber quem é o dono.
2. **Escrita depois, unidade por unidade.** Uma tabela `cobranca_roteamento` diz quem escreve Cobrança de cada unidade. Legado: tudo como antes. Serviço: matrícula (A), cancelamento (G), crons (D) e webhook (C) passam para o serviço. Trocar de dono é um `UPDATE`, sem deploy.

### Fronteira

| Dado | Dono ao final | Por quê |
|---|---|---|
| `faturas`, `pagamentos` | **Cobrança** | É o núcleo do domínio. No monólito sobra só a projeção `fin_faturas`, somente leitura. |
| `planos.valor_mensal` | **Monólito** (catálogo) | Plano é produto, vendido por Matrícula. Cobrança recebe o valor no comando `MatriculaCriada` e guarda uma cópia na assinatura: reajuste de catálogo não muda contrato vigente. Preço por unidade ou reajuste contratual, quando vier, nasce em Cobrança. |
| `matriculas.dia_vencimento` | **Cobrança** (cópia na assinatura) | É um termo de pagamento. O monólito informa na criação; a partir daí, quem gera fatura lê a assinatura. A coluna no monólito fica como registro histórico da venda. |
| `alunos.situacao` | **Monólito** | Liberar ou bloquear pessoa é regra de acesso, não de cobrança. Hoje webhook e régua de Cobrança escrevem nela; passa a valer: Cobrança publica fatos (`FaturaVencida`, `FaturaPaga`) e o monólito decide. A régua (bloqueio > 10 dias) fica no monólito lendo `fin_faturas`; o desbloqueio acontece ao consumir `FaturaPaga`. |
| Notificações | **Monólito** | Cobrança não manda e-mail nem SMS. Isso tira chamada externa de dentro das transações (A, C). |

### Contratos

**Monólito → Cobrança: comandos pela outbox** (`cobranca_outbox`, gravada na mesma transação da matrícula; o worker entrega em ordem, "pelo menos uma vez"). O serviço é idempotente por `matricula_id`.

```
POST /assinaturas                          MatriculaCriada
{ matricula_id, aluno_id, unidade_id, plano_id, valor_mensal, dia_vencimento, inicio, fim, primeiro_vencimento }
-> 201 { assinatura, primeira_fatura, criada: true } | 200 se já existia

POST /assinaturas/{matricula_id}/cancelamento    MatriculaCancelada   { cancelada_em }
POST /assinaturas/{matricula_id}/faturas         gerar competência    { competencia: "YYYY-MM" }
```

**Cobrança → Monólito: feed de eventos** (`GET /eventos?apos={cursor}&limite=`), tabela `eventos` gravada na mesma transação da mudança. Cada evento carrega o **retrato completo da fatura e sua `versao`**:

```json
{ "id": 42, "tipo": "FaturaPaga", "criado_em": "2026-09-28 10:00:00.123",
  "fatura": { "codigo": "fat_…", "matricula_id": 1, "aluno_id": 7, "unidade_id": 5,
              "competencia": "2026-09-01", "valor": "119.90", "vencimento": "2026-09-10",
              "status": "paga", "pago_em": "…", "valor_pago": "119.90", "qtd_pagamentos": 1, "versao": 3 } }
```

Tipos: `FaturaGerada`, `FaturaVencida`, `FaturaPaga`, `FaturaCancelada`, `PagamentoRecusado`. O consumidor aplica numa transação com `cobranca_eventos_aplicados` (sem efeito repetido) e só troca a linha de `fin_faturas` se a versão recebida for maior (evento velho não regride estado). Com isso o monólito não precisa de ordem global nem de chamar o serviço de volta.

**Gateway → webhook.** Na transição o gateway continua apontando para o monólito, mas o endpoint vira uma **inbox**: um `INSERT` com chave única (`event_id` ou hash do payload) e `202`. Nada de transação longa, SMS ou rede na requisição do gateway, então gateway lento não disputa worker com a catraca. O worker roteia: fatura legada de unidade legada segue a regra antiga (agora idempotente); o resto vai para `POST /webhooks/pagamento` do serviço. Serviço fora: o item fica pendente e é reprocessado. No fim, o gateway aponta direto para o serviço, que tem o mesmo tratamento: `webhooks_recebidos.chave` único, `paga` como estado final, `UNIQUE(pagamentos.fatura_id)` e `SELECT … FOR UPDATE` na fatura.

### Dados

Serviço com banco e usuário próprios; o `GRANT` do usuário `cobranca` não inclui o banco do monólito. As 14 milhões de faturas terminam no banco de Cobrança. Chegam lá **empurradas pelo monólito** pela API de migração (`POST /migracao/lotes`), unidade por unidade: a carga é silenciosa (sem eventos, porque o monólito já tem o histórico em `fin_faturas`), idempotente por `legado_id` e preserva `gateway_ref` (webhooks de faturas antigas continuam achando a fatura). O código da fatura importada é `leg_{id}`, o mesmo da linha em `fin_faturas`, então os eventos seguintes atualizam a mesma linha. **Nenhuma exceção à regra de banco**: nem na transição o serviço lê ou escreve o banco do monólito.

### Rollout e rollback

Resumo aqui, detalhes em [PLANO-MIGRACAO.md](PLANO-MIGRACAO.md). Etapa 0 publica leitura por `fin_faturas` e inbox do webhook sem mudar dono de nada (rollback: deploy anterior; as tabelas novas são aditivas). Depois, por unidade, `cobranca:migrar-unidade N` faz carga, troca de dono, carga de delta e reconciliação. Rollback de uma unidade: `cobranca:reverter-unidade N` devolve a escrita ao legado e traz de volta para `faturas` o que o serviço criou ou alterou (`codigo_externo` mantém a mesma linha em `fin_faturas`). Só há ponto sem volta no fim: apagar `faturas` do monólito.

## Alternativas descartadas

- **Catraca consultando o serviço (com cache).** Viola "funcionar com Cobrança fora do ar"; cache só adia o problema para o primeiro miss.
- **Leitores trocando de fonte por unidade** (legado para umas, projeção para outras). Cada leitor carregaria o roteamento e duas consultas; relatório de receita cruzando unidades ficaria difícil. Uma projeção única que já nasce cobrindo o legado resolve B, E, F e H no mesmo passo.
- **Serviço lendo o banco do monólito durante a migração.** Proibido pelo enunciado e, na prática, nunca é removido.
- **Broker (Kafka/SQS) já na primeira fatia.** Outbox + feed com cursor dá as mesmas garantias neste volume (30 mil webhooks/dia, 250 mil eventos no dia 1º) sem infraestrutura nova no meio da extração. O relay troca de destino sem mudar domínio.
- **Big bang.** Sem critério objetivo de avanço nem rollback com dados.

## Consequências

- (+) Cobrança faz deploy sozinha; cron particionável e idempotente; webhook responde em milissegundos; catraca só depende do banco local.
- (−) Consistência eventual: fatura gerada ou paga aparece no monólito em segundos (worker a cada 2 s + atraso de segurança de 1 s no feed). A resposta de `POST /matriculas` em unidade migrada não traz mais a primeira fatura (`cobranca: "em_processamento"`).
- (−) Triggers durante a transição: custo por escrita em `faturas` e lógica fora do código PHP. São temporários e morrem com a tabela.

## Riscos e o que não foi resolvido

- **Notificações "no máximo uma vez":** saem depois do commit do consumidor; se o processo cair entre um e outro, a mensagem se perde. Próximo passo: outbox de notificações.
- **Feed por id auto-incremento:** uma transação lenta pode commitar um id menor depois do cursor passar. Mitigado com atraso de 1 s no feed; a solução definitiva é CDC (binlog) ou broker.
- **Janela do corte:** transação legada que começou antes do `UPDATE` de roteamento e termina depois da carga de delta. Mitigada com pausa antes do delta e com a reconciliação, que precisa zerar; não corte no dia 1º nem durante a régua.
- **Um worker por fila:** inbox e outbox não usam `SKIP LOCKED` ainda; dois workers em paralelo poderiam processar o mesmo item (o serviço é idempotente, o legado também, mas a notificação duplicaria).
- **Não implementado:** assinatura HMAC do webhook, autenticação forte entre serviços (hoje um token compartilhado), multa/parcelamento/desconto, pagamento parcial, estorno.
