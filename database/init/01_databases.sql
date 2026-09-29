-- Executado apenas na primeira subida do volume do MySQL.
CREATE DATABASE IF NOT EXISTS estuda_fit_hub_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON estuda_fit_hub.* TO 'estuda_fit_hub'@'%';
GRANT ALL PRIVILEGES ON estuda_fit_hub_test.* TO 'estuda_fit_hub'@'%';

-- Banco e usuário do serviço de Cobrança. Localmente dividem a instância do MySQL por economia;
-- em produção são instâncias separadas. O GRANT abaixo é a fronteira: o usuário `cobranca`
-- não enxerga o banco do monólito, e o usuário do monólito não enxerga o de Cobrança.
CREATE DATABASE IF NOT EXISTS cobranca CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE IF NOT EXISTS cobranca_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS 'cobranca'@'%' IDENTIFIED BY 'cobranca';
GRANT ALL PRIVILEGES ON cobranca.* TO 'cobranca'@'%';
GRANT ALL PRIVILEGES ON cobranca_test.* TO 'cobranca'@'%';

FLUSH PRIVILEGES;
