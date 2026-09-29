# Arquitetura

Três retratos: antes, durante a transição (o que roda neste repositório) e depois da separação.

## Antes: monólito com Cobrança embutida

```mermaid
flowchart LR
    catraca([Catraca]) -->|POST /checkins| mono
    gateway([Gateway de pagamento]) -->|webhook síncrono| mono
    cron([Agendador]) -->|dia 1º, diário| mono

    subgraph mono[Monólito PHP]
        direction TB
        checkin[Check-in]
        matricula[Matrículas]
        cobranca[Cobrança<br/>crons, webhook, régua]
        rel[Relatórios]
        notif[Notificações]
    end

    mono --> db[(MySQL único<br/>alunos, matriculas, planos,<br/>faturas, pagamentos, ...)]
    cobranca -->|dentro da transação| gw2([Gateway])
    notif -->|dentro da transação| prov([E-mail / SMS])
```

Problemas: webhook lento prende o worker da catraca; deploy de qualquer módulo derruba o cron mensal; check-in, relatórios, tela do aluno e régua leem `faturas` direto.

## Depois da separação (estado final)

```mermaid
flowchart LR
    catraca([Catraca]) -->|POST /checkins| checkin
    gateway([Gateway de pagamento]) -->|POST /webhooks/pagamento| wh

    subgraph MONO[Monólito]
        direction TB
        checkin[Check-in]
        matricula[Matrículas e Planos]
        regua[Régua de acesso<br/>bloqueia aluno]
        rel[Relatórios]
        notif[Notificações]
        relay[[Relay da outbox]]
        consumidor[[Consumidor de eventos]]
    end

    subgraph DBM["Banco do monólito"]
        direction TB
        cad[(alunos, matriculas,<br/>planos, unidades, checkins)]
        fin[(fin_faturas<br/>modelo de leitura)]
        outbox[(cobranca_outbox)]
    end

    subgraph COB[Serviço de Cobrança]
        direction TB
        api[API de assinaturas<br/>e faturas]
        wh[Webhook idempotente]
        cronm[Cron mensal<br/>particionado]
        gwreg[[Registro no gateway]]
        feed[Feed de eventos<br/>GET /eventos]
    end

    subgraph DBC["Banco de Cobrança"]
        direction TB
        cobt[(assinaturas, faturas,<br/>pagamentos, webhooks_recebidos)]
        ev[(eventos / outbox)]
    end

    matricula -->|mesma transação| outbox
    outbox --> relay
    relay -->|MatriculaCriada / Cancelada| api
    api --> cobt
    wh --> cobt
    cronm --> cobt
    cobt -.mesma transação.-> ev
    gwreg -->|fora da transação| gateway2([Gateway])
    ev --> feed
    feed -->|FaturaGerada, Paga, Vencida,<br/>Cancelada, PagamentoRecusado| consumidor
    consumidor --> fin
    consumidor --> notif
    consumidor -->|desbloqueia ao pagar| cad
    checkin -->|lê| fin
    rel -->|lê| fin
    regua -->|lê| fin
    regua -->|bloqueia| cad
```

Regras que o desenho garante:

- **Catraca** só fala com o monólito e só lê o banco local (`fin_faturas`). Cobrança fora do ar não muda a latência (medido: p95 de 99 ms com o serviço parado).
- **Cada serviço só acessa o próprio banco.** A comunicação é por HTTP: comandos do monólito para Cobrança (outbox), fatos de Cobrança para o monólito (feed).
- **Cobrança não decide acesso nem manda mensagem.** Publica fatos; bloqueio, desbloqueio e notificação são do monólito.
- **Gateway fala só com Cobrança.** O registro de cobrança e o webhook são dela.

## Durante a transição (o que este repositório roda)

A diferença para o estado final é que parte das unidades ainda está no legado. A leitura já é única; a escrita é roteada por unidade.

```mermaid
flowchart LR
    gateway([Gateway]) -->|POST /webhooks/pagamento| inbox

    subgraph MONO[Monólito]
        inbox[Inbox do webhook<br/>INSERT + 202]
        worker[[Worker<br/>a cada 2 s]]
        rota{cobranca_roteamento<br/>unidade migrada?}
        legado[Cobrança legada<br/>faturas, pagamentos, crons]
        leitores[Check-in, tela do aluno,<br/>régua, relatórios]
    end

    subgraph DBM["Banco do monólito"]
        faturas[(faturas / pagamentos<br/>legado)]
        fin[(fin_faturas)]
    end

    COB[Serviço de Cobrança<br/>+ banco próprio]

    inbox --> worker --> rota
    rota -->|não| legado --> faturas
    rota -->|sim, ou referência<br/>desconhecida no legado| COB
    faturas -.triggers.-> fin
    COB -.feed de eventos.-> worker
    worker -->|consumidor| fin
    leitores --> fin
```

| Acoplamento | Onde era | Como ficou |
|---|---|---|
| A: primeira fatura na transação da matrícula | `MatriculaService::criar` | Unidade migrada: matrícula + `MatriculaCriada` na outbox, na mesma transação; sem gateway nem e-mail dentro dela |
| B: catraca lê `faturas` | `CheckinController` | Lê `fin_faturas` via `SituacaoFinanceira`, para todas as unidades |
| C: webhook escreve em 3 tabelas + SMS síncrono | `PagamentoWebhookController` | Inbox + worker; idempotente; `alunos.situacao` só pelo monólito; serviço com o próprio webhook idempotente |
| D: cron mensal serial | `FaturasGerarMensais` | Legado ignora unidades migradas; no serviço, particionado e com gateway fora do laço |
| E: régua bloqueia lendo `faturas` | `CobrancaRegua` | Continua no monólito (é regra de acesso), lendo `fin_faturas` |
| F: relatórios com JOIN em `faturas` | `RelatorioController` | JOIN em `fin_faturas`, que cobre legado e serviço |
| G: cancelamento mexe em `faturas` | `MatriculaService::cancelar` | Unidade migrada: `MatriculaCancelada` na outbox |
| H: tela do aluno conta faturas | `AlunoController` | Lê `fin_faturas` |

## Containers (docker compose)

| Container | Papel | Porta |
|---|---|---|
| `app` | API do monólito | 8080 |
| `worker` | Inbox do webhook, relay da outbox, consumidor do feed | |
| `cobranca` | API do serviço de Cobrança | 8081 |
| `cobranca-worker` | Registro de faturas no gateway, fora da transação | |
| `setup` | Cria os schemas e popula o seed na primeira subida | |
| `db` | MySQL 8.4 com dois bancos e dois usuários (`estuda_fit_hub`, `cobranca`) | 3316 |
