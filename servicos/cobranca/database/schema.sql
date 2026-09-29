-- Banco próprio do serviço de Cobrança. Nenhuma tabela aqui é lida pelo monólito,
-- e o usuário do serviço não tem GRANT no banco do monólito.

DROP TABLE IF EXISTS eventos;
DROP TABLE IF EXISTS webhooks_recebidos;
DROP TABLE IF EXISTS pagamentos;
DROP TABLE IF EXISTS faturas;
DROP TABLE IF EXISTS assinaturas;

-- A face financeira de uma matrícula. valor_mensal e dia_vencimento são cópias do momento
-- da contratação: o catálogo de planos continua no monólito.
CREATE TABLE assinaturas (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    matricula_id    INT UNSIGNED  NOT NULL,
    aluno_id        INT UNSIGNED  NOT NULL,
    unidade_id      INT UNSIGNED  NOT NULL,
    plano_id        INT UNSIGNED  NOT NULL,
    valor_mensal    DECIMAL(10,2) NOT NULL,
    dia_vencimento  TINYINT UNSIGNED NOT NULL,
    inicio          DATE          NOT NULL,
    fim             DATE          NULL,
    status          ENUM('ativa','suspensa','cancelada','devolvida') NOT NULL DEFAULT 'ativa',
    criado_em       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_assinaturas_matricula (matricula_id),
    KEY idx_assinaturas_unidade_status (unidade_id, status)
) ENGINE=InnoDB;

CREATE TABLE faturas (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo          VARCHAR(40)   NOT NULL,
    legado_id       INT UNSIGNED  NULL,
    assinatura_id   INT UNSIGNED  NOT NULL,
    matricula_id    INT UNSIGNED  NOT NULL,
    aluno_id        INT UNSIGNED  NOT NULL,
    unidade_id      INT UNSIGNED  NOT NULL,
    competencia     DATE          NOT NULL,
    valor           DECIMAL(10,2) NOT NULL,
    vencimento      DATE          NOT NULL,
    status          ENUM('aberta','paga','vencida','cancelada') NOT NULL DEFAULT 'aberta',
    pago_em         DATETIME      NULL,
    valor_pago      DECIMAL(10,2) NOT NULL DEFAULT 0,
    qtd_pagamentos  INT UNSIGNED  NOT NULL DEFAULT 0,
    metodo          VARCHAR(30)   NULL,
    gateway_ref     VARCHAR(64)   NULL,
    versao          INT UNSIGNED  NOT NULL DEFAULT 1,
    -- Versão no momento da última carga do legado ou devolução; ver Migracao::importarFatura.
    versao_importada INT UNSIGNED NULL,
    criado_em       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_faturas_codigo (codigo),
    UNIQUE KEY uq_faturas_competencia (assinatura_id, competencia),
    UNIQUE KEY uq_faturas_legado (legado_id),
    UNIQUE KEY uq_faturas_gateway_ref (gateway_ref),
    KEY idx_faturas_aluno (aluno_id),
    KEY idx_faturas_status_venc (status, vencimento),
    KEY idx_faturas_unidade (unidade_id, competencia)
) ENGINE=InnoDB;

CREATE TABLE pagamentos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fatura_id       INT UNSIGNED  NOT NULL,
    valor           DECIMAL(10,2) NOT NULL,
    metodo          VARCHAR(30)   NOT NULL,
    webhook_id      INT UNSIGNED  NULL,
    criado_em       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pagamentos_fatura (fatura_id),
    CONSTRAINT fk_pagamentos_fatura FOREIGN KEY (fatura_id) REFERENCES faturas (id)
) ENGINE=InnoDB;

-- Todo webhook recebido, uma vez. A chave única é o que torna o endpoint idempotente.
CREATE TABLE webhooks_recebidos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chave           VARCHAR(80)   NOT NULL,
    referencia      VARCHAR(64)   NOT NULL,
    status_gateway  VARCHAR(20)   NOT NULL,
    payload         JSON          NOT NULL,
    resultado       VARCHAR(40)   NOT NULL,
    recebido_em     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_webhooks_chave (chave),
    KEY idx_webhooks_referencia (referencia)
) ENGINE=InnoDB;

-- Outbox do serviço, exposta como feed (GET /eventos?apos=). O id é o cursor.
CREATE TABLE eventos (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tipo        VARCHAR(40)  NOT NULL,
    fatura_codigo VARCHAR(40) NOT NULL,
    aluno_id    INT UNSIGNED NOT NULL,
    payload     JSON         NOT NULL,
    criado_em   DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY idx_eventos_criado (criado_em)
) ENGINE=InnoDB;
