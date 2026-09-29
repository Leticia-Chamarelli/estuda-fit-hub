-- Transição da extração de Cobrança.
-- Tudo aqui é aditivo: nenhuma tabela legada é removida e a única coluna nova em `faturas` é opcional.
-- Aplicada por `db:migrate` logo depois de database/schema.sql (que recria o legado do zero).

DROP TABLE IF EXISTS fin_faturas;
DROP TABLE IF EXISTS cobranca_roteamento;
DROP TABLE IF EXISTS cobranca_outbox;
DROP TABLE IF EXISTS cobranca_consumo;
DROP TABLE IF EXISTS cobranca_eventos_aplicados;
DROP TABLE IF EXISTS webhook_inbox;

-- Modelo de leitura financeiro do monólito.
-- Uma linha por fatura, venha ela da tabela legada `faturas` (mantida pelos triggers abaixo)
-- ou do serviço de Cobrança (mantida pelo consumidor de eventos). Check-in, tela do aluno,
-- régua e relatórios leem só daqui, então não mudam quando uma unidade troca de dono.
CREATE TABLE fin_faturas (
    codigo          VARCHAR(40)   NOT NULL PRIMARY KEY,
    origem          ENUM('legado','servico') NOT NULL,
    matricula_id    INT UNSIGNED  NOT NULL,
    aluno_id        INT UNSIGNED  NOT NULL,
    competencia     DATE          NOT NULL,
    valor           DECIMAL(10,2) NOT NULL,
    vencimento      DATE          NOT NULL,
    status          ENUM('aberta','paga','vencida','cancelada') NOT NULL,
    pago_em         DATETIME      NULL,
    valor_pago      DECIMAL(10,2) NOT NULL DEFAULT 0,
    qtd_pagamentos  INT UNSIGNED  NOT NULL DEFAULT 0,
    versao          INT UNSIGNED  NOT NULL DEFAULT 0,
    atualizado_em   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_fin_aluno_status_venc (aluno_id, status, vencimento),
    KEY idx_fin_status_venc (status, vencimento),
    KEY idx_fin_competencia (competencia),
    KEY idx_fin_matricula (matricula_id)
) ENGINE=InnoDB;

-- Quem é dono da escrita de Cobrança em cada unidade. Sem linha = legado.
-- Trocar de dono é um UPDATE, sem deploy e sem restart.
CREATE TABLE cobranca_roteamento (
    unidade_id     INT UNSIGNED NOT NULL PRIMARY KEY,
    modo           ENUM('legado','servico') NOT NULL DEFAULT 'legado',
    alterado_em    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Outbox: comandos para o serviço, gravados na mesma transação que os originou.
CREATE TABLE cobranca_outbox (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tipo         VARCHAR(40)  NOT NULL,
    chave        VARCHAR(80)  NOT NULL,
    payload      JSON         NOT NULL,
    situacao     ENUM('pendente','enviado','rejeitado') NOT NULL DEFAULT 'pendente',
    criado_em    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enviado_em   DATETIME     NULL,
    tentativas   INT UNSIGNED NOT NULL DEFAULT 0,
    ultimo_erro  VARCHAR(255) NULL,
    KEY idx_outbox_situacao (situacao, id)
) ENGINE=InnoDB;

-- Posição do consumidor no feed de eventos do serviço e eventos já aplicados.
CREATE TABLE cobranca_consumo (
    nome     VARCHAR(40)     NOT NULL PRIMARY KEY,
    cursor_evento BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE cobranca_eventos_aplicados (
    evento_id   BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    tipo        VARCHAR(40)     NOT NULL,
    aplicado_em DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Inbox do webhook: o gateway recebe 202 depois de um INSERT, sem transação longa,
-- sem SMS e sem chamada de rede. Duplicatas morrem na chave única.
CREATE TABLE webhook_inbox (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chave         VARCHAR(80)  NOT NULL,
    referencia    VARCHAR(64)  NOT NULL,
    status_gateway VARCHAR(20) NOT NULL,
    payload       JSON         NOT NULL,
    recebido_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processado_em DATETIME     NULL,
    destino       ENUM('legado','servico','descartado') NULL,
    tentativas    INT UNSIGNED NOT NULL DEFAULT 0,
    ultimo_erro   VARCHAR(255) NULL,
    UNIQUE KEY uq_inbox_chave (chave),
    KEY idx_inbox_pendentes (processado_em, id)
) ENGINE=InnoDB;

-- Faturas trazidas de volta do serviço num rollback guardam o código original,
-- para continuarem apontando para a mesma linha de fin_faturas.
ALTER TABLE faturas ADD COLUMN codigo_externo VARCHAR(40) NULL, ADD UNIQUE KEY uq_faturas_codigo_externo (codigo_externo);

-- Triggers de sincronização legado -> modelo de leitura.
-- Existem só enquanto a tabela `faturas` existir; morrem com ela (etapa final do plano).
DELIMITER $$

CREATE TRIGGER trg_faturas_ai AFTER INSERT ON faturas FOR EACH ROW
    INSERT INTO fin_faturas (codigo, origem, matricula_id, aluno_id, competencia, valor, vencimento, status, pago_em)
    VALUES (COALESCE(NEW.codigo_externo, CONCAT('leg_', NEW.id)), 'legado', NEW.matricula_id, NEW.aluno_id,
            NEW.competencia, NEW.valor, NEW.vencimento, NEW.status, NEW.pago_em) AS n
    ON DUPLICATE KEY UPDATE origem = 'legado', status = n.status, pago_em = n.pago_em, valor = n.valor, vencimento = n.vencimento$$

CREATE TRIGGER trg_faturas_au AFTER UPDATE ON faturas FOR EACH ROW
    UPDATE fin_faturas
       SET status = NEW.status, pago_em = NEW.pago_em, valor = NEW.valor, vencimento = NEW.vencimento
     WHERE codigo = COALESCE(NEW.codigo_externo, CONCAT('leg_', NEW.id)) AND origem = 'legado'$$

CREATE TRIGGER trg_pagamentos_ai AFTER INSERT ON pagamentos FOR EACH ROW
    UPDATE fin_faturas f
      JOIN faturas l ON l.id = NEW.fatura_id
       SET f.valor_pago = f.valor_pago + NEW.valor, f.qtd_pagamentos = f.qtd_pagamentos + 1
     WHERE f.codigo = COALESCE(l.codigo_externo, CONCAT('leg_', l.id)) AND f.origem = 'legado'$$

DELIMITER ;

-- Carga inicial do modelo de leitura a partir do legado (em produção: em lotes por faixa de id).
INSERT INTO fin_faturas (codigo, origem, matricula_id, aluno_id, competencia, valor, vencimento, status, pago_em, valor_pago, qtd_pagamentos)
SELECT COALESCE(f.codigo_externo, CONCAT('leg_', f.id)), 'legado', f.matricula_id, f.aluno_id, f.competencia, f.valor,
       f.vencimento, f.status, f.pago_em, COALESCE(SUM(p.valor), 0), COUNT(p.id)
  FROM faturas f
  LEFT JOIN pagamentos p ON p.fatura_id = f.id
 GROUP BY f.id;
