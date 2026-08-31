.PHONY: up down restart logs ps test test-race test-timeout test-fallback

# Запуск
up:
	docker compose up -d

down:
	docker compose down

restart:
	docker compose restart

logs:
	docker compose logs -f

ps:
	docker compose ps

# Тесты
test:
	./scripts/test-race.sh
	./scripts/test-timeout.sh
	./scripts/test-fallback.sh

test-race:
	./scripts/test-race.sh

test-timeout:
	./scripts/test-timeout.sh

test-fallback:
	MOCK_ERROR_RATE_A=100 MOCK_TIMEOUT_RATE_A=0 docker compose up -d provider-a
	./scripts/test-fallback.sh
	docker compose up -d provider-a

# Сценарии с настройками провайдеров
test-timeout-scenario:
	MOCK_ERROR_RATE_A=0 MOCK_TIMEOUT_RATE_A=100 docker compose up -d provider-a
	./scripts/test-timeout.sh
	docker compose up -d provider-a

test-fallback-scenario:
	MOCK_ERROR_RATE_A=100 MOCK_TIMEOUT_RATE_A=0 docker compose up -d provider-a
	./scripts/test-fallback.sh
	docker compose up -d provider-a