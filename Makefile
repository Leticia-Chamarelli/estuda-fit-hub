.PHONY: up down logs migrate seed test test-cobranca smoke smoke-servico p95 migrar reconciliar reverter faturas vencidas regua shell

up:
	docker compose up -d --build

down:
	docker compose down

logs:
	docker compose logs -f app worker cobranca cobranca-worker

migrate:
	docker compose exec app php bin/console db:migrate
	docker compose exec cobranca php bin/console db:migrate

seed:
	docker compose exec app php bin/console db:seed

test:
	docker compose exec app php tests/run.php

test-cobranca:
	docker compose exec cobranca php tests/run.php

smoke:
	docker compose exec app php scripts/smoke.php

smoke-servico:
	docker compose exec app php scripts/smoke_servico.php $(or $(U),5)

# Check-in com o serviço de Cobrança fora do ar
p95:
	docker compose stop cobranca cobranca-worker
	-docker compose exec app php scripts/checkin_p95.php 400 8
	docker compose start cobranca cobranca-worker

migrar:
	docker compose exec app php bin/console cobranca:migrar-unidade $(or $(U),5)

reconciliar:
	docker compose exec app php bin/console cobranca:reconciliar $(or $(U),5)

reverter:
	docker compose exec app php bin/console cobranca:reverter-unidade $(or $(U),5)

faturas:
	docker compose exec app php bin/console faturas:gerar-mensais
	docker compose exec cobranca php bin/console faturas:gerar-mensais

vencidas:
	docker compose exec app php bin/console faturas:marcar-vencidas
	docker compose exec cobranca php bin/console faturas:marcar-vencidas

regua:
	docker compose exec app php bin/console cobranca:regua --limite=50

shell:
	docker compose exec app sh
