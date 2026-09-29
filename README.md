# Estuda Fit Hub: extração do domínio de Cobrança

Resolução do desafio descrito em [DESAFIO.md](DESAFIO.md): extrair Cobrança do monólito para um serviço independente, sem parar check-in nem webhook.

A ideia em duas frases: **a leitura muda uma vez, para todas as unidades** (o monólito ganha um modelo de leitura financeiro próprio, `fin_faturas`, e check-in, tela do aluno, régua e relatórios passam a ler só dele); **a escrita muda unidade a unidade** (uma tabela de roteamento diz se a unidade é cobrada pelo legado ou pelo serviço, e trocar é um `UPDATE`).

| Documento | Conteúdo |
|---|---|
| [docs/ADR-001-extracao-cobranca.md](docs/ADR-001-extracao-cobranca.md) | Decisão: fronteira, contratos, dados, rollout, riscos |
| [docs/PLANO-MIGRACAO.md](docs/PLANO-MIGRACAO.md) | Plano de migração e corte, faturas históricas e cron mensal |
| [docs/ARQUITETURA.md](docs/ARQUITETURA.md) | Desenhos de antes, durante e depois da separação |

## Como rodar

Requisitos: Docker e Docker Compose v2.

```bash
docker compose up -d --build
```

Só isso: o container `setup` cria os schemas dos dois bancos e popula o seed (2 mil alunos, 5 unidades) na primeira subida. Todas as unidades começam no legado.

| Container | Papel | Porta |
|---|---|---|
| `app` | API do monólito | 8080 |
| `worker` | Inbox do webhook, relay da outbox, consumidor de eventos | |
| `cobranca` | API do serviço de Cobrança | 8081 |
| `cobranca-worker` | Registro de faturas no gateway, fora da transação | |
| `db` | MySQL 8.4 com os bancos `estuda_fit_hub` e `cobranca` | 3316 |

### Provar que funciona

```bash
make test                # monólito: 37 testes (legado, roteamento, consumidor, migração, rollback)
make test-cobranca       # serviço: 21 testes (gerar fatura, webhook duplicado e fora de ordem, cron, carga, re-migração)
make smoke               # fluxo legado ponta a ponta (unidade 1)
make migrar U=5          # corta a unidade 5: carga, troca de dono, delta, reconciliação
make smoke-servico U=5   # fluxo migrado: matrícula -> outbox -> serviço -> feed -> check-in -> webhook
make p95                 # derruba o serviço de Cobrança e mede o check-in
make reverter U=5        # rollback da unidade 5 para o legado
```

Sem `make`, os comandos equivalentes estão no [Makefile](Makefile) (`docker compose exec app php ...`).

Resultado de referência nesta máquina, com o serviço de Cobrança parado: `400 check-ins, concorrência 8: p50 39 ms, p95 99 ms, máx 236 ms, 0 erros`.

### Comandos de console

Monólito (`docker compose exec app php bin/console ...`):

| Comando | Papel |
|---|---|
| `cobranca:worker [--uma-vez]` | Inbox do webhook, outbox e feed (roda no container `worker`) |
| `cobranca:migrar-unidade N` | Corte de uma unidade |
| `cobranca:reconciliar N` | Legado × serviço por competência e status; sai com 1 se divergir |
| `cobranca:reverter-unidade N` | Rollback de uma unidade |
| `faturas:gerar-mensais`, `faturas:marcar-vencidas` | Crons legados; ignoram unidades migradas |
| `cobranca:regua` | Régua de acesso (bloqueio), lendo `fin_faturas` |

Serviço (`docker compose exec cobranca php bin/console ...`):

| Comando | Papel |
|---|---|
| `faturas:gerar-mensais [YYYY-MM] [--particao=i/n]` | Cron do dia 1º, particionável e reexecutável |
| `faturas:marcar-vencidas` | Cron diário; publica `FaturaVencida` |
| `gateway:registrar [--loop]` | Registra no gateway as faturas ainda sem referência |

### API do serviço de Cobrança

Rotas internas exigem `X-Servico-Token`; o webhook é público.

| Método | Rota | Descrição |
|---|---|---|
| POST | `/assinaturas` | `MatriculaCriada`: cria assinatura e primeira fatura (idempotente por `matricula_id`) |
| POST | `/assinaturas/{matricula}/faturas` | Gera a fatura de uma competência (idempotente) |
| POST | `/assinaturas/{matricula}/cancelamento` | `MatriculaCancelada`: cancela assinatura e faturas em aberto |
| POST | `/webhooks/pagamento` | Webhook do gateway: idempotente, tolera fora de ordem |
| GET | `/eventos?apos=&limite=` | Feed de eventos com cursor |
| GET | `/faturas?aluno_id=` | Faturas de um aluno |
| POST | `/migracao/lotes` | Carga do histórico (usada por `cobranca:migrar-unidade`) |
| GET | `/migracao/reconciliacao?unidade_id=` | Quantidade e soma por competência e status |
| GET | `/migracao/faturas?unidade_id=` | Faturas da unidade (usada no rollback) |
| POST | `/migracao/unidades/{id}/devolucao` | Rollback: serviço para de cobrar a unidade |

## O que mudou no monólito

| Arquivo | Mudança |
|---|---|
| `database/migrations/002_cobranca_transicao.sql` | `fin_faturas`, roteamento, outbox, inbox, triggers de sincronização (tudo aditivo) |
| `src/Cobranca/` | Integração: roteamento, outbox, inbox do webhook, consumidor, projeção, migração/rollback, cliente HTTP |
| `src/Controllers/CheckinController.php` | **Ponto B**: não consulta mais `faturas`; lê `fin_faturas` |
| `src/Controllers/PagamentoWebhookController.php` | Grava na inbox e responde 202 |
| `src/Services/MatriculaService.php` | Unidade migrada: outbox em vez de fatura, gateway e e-mail dentro da transação |
| `src/Controllers/AlunoController.php`, `RelatorioController.php`, `src/Console/CobrancaRegua.php` | Leem `fin_faturas` |
| `src/Console/FaturasGerarMensais.php`, `FaturasMarcarVencidas.php` | Ignoram unidades migradas |

### Testes existentes

Todos os testes originais continuam passando **sem alteração**, exceto `PagamentoWebhookTest`:

- O endpoint agora responde `202` e o efeito acontece quando o worker processa a inbox; o helper do teste faz os dois passos, e os cenários de negócio (pagar, reativar aluno, recusar) são os mesmos.
- `testWebhookDuplicadoRegistraPagamentoDuasVezesComportamentoAtual` documentava o defeito que o desafio pede para corrigir. Foi substituído por `testWebhookDuplicadoNaoRegistraPagamentoEmDobro`.
- `testReferenciaDesconhecidaRetorna404` virou `testReferenciaDesconhecidaNoLegadoEhEncaminhadaAoServico`: referência que o legado não conhece pode ser de uma fatura criada pelo serviço.

`CheckinTest` e `RelatorioInadimplenciaTest` passam intactos mesmo com os leitores trocados de fonte: as faturas criadas pelos testes chegam a `fin_faturas` pelos triggers. Isso é a prova de que o comportamento não mudou.

## Decisões tomadas por falta de tempo

- **Serviço em PHP 8.3 sem framework**, na mesma linha do monólito. A separação que importa é de processo, banco e deploy; a linguagem era livre, e manter a mesma reduz o custo para o time que já opera o monólito.
- **Mesma instância MySQL** com bancos e usuários separados. O `GRANT` é a fronteira; em produção seriam instâncias separadas.
- **Feed HTTP com cursor no lugar de broker.** Outbox dos dois lados; trocar o transporte depois não muda o domínio.
- **Workers como laço com `sleep`**, um por fila. Sem `SKIP LOCKED` na inbox e na outbox.
- **Token compartilhado** entre monólito e serviço; sem assinatura HMAC do gateway.
- **Triggers** para manter `fin_faturas` no legado, em vez de alterar cada ponto de escrita. Pegam qualquer caminho que escreva em `faturas` (inclusive SQL manual) e morrem junto com a tabela.

## O que faria a seguir

1. Outbox de notificações no consumidor (hoje "no máximo uma vez").
2. `SKIP LOCKED` na inbox e na outbox para escalar workers horizontalmente.
3. Carga do histórico com inserts multi-linha e paralelismo por faixa de id.
4. Assinatura HMAC do webhook e autenticação entre serviços (mTLS ou JWT de serviço).
5. Reconciliação diária automática serviço × `fin_faturas`, com alerta.
6. Contratos versionados dos eventos (JSON Schema) e testes de contrato entre os dois lados.
7. Regras de Finanças que motivaram a extração (multa, desconto por antecipação, parcelamento), agora com deploy próprio.

## Derrubar

```bash
docker compose down        # mantém os dados
docker compose down -v     # descarta os volumes
```

---

<details>
<summary>README original do monólito</summary>

Sistema fictício de gestão de uma rede de academias. PHP 8.3 sem framework, MySQL 8.4, Docker Compose. Sem `composer install`.

Endpoints do monólito: `GET /health`, `POST /alunos`, `GET /alunos/{id}`, `GET /alunos/{id}/faturas`, `POST /matriculas`, `POST /matriculas/{id}/cancelar`, `POST /checkins`, `POST /webhooks/pagamento`, `GET /relatorios/inadimplencia?unidade_id=`, `GET /relatorios/receita?competencia=YYYY-MM`. Exemplos em `http/requests.http`.

Latências simuladas no `docker-compose.yml`: `NOTIFICACAO_LATENCIA_MS` (padrão 150) e `GATEWAY_LATENCIA_MS` (padrão 2). Os testes zeram as duas.

</details>
