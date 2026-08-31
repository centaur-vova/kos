.PHONY: \
	up \
	down \
	restart \
	logs \
	ps \
	test \
	test-race \
	test-timeout \
	test-fallback \
	test-reconciliation \
	test-recovery \
	reset-stock \
	clean-orders \
	clean

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
test: test-race test-timeout test-fallback test-reconciliation

test-race:
	./scripts/test-race.sh

test-timeout:
	./scripts/test-timeout.sh

test-fallback:
	MOCK_ERROR_RATE_A=100 MOCK_TIMEOUT_RATE_A=0 docker compose up -d provider-a
	./scripts/test-fallback.sh
	docker compose up -d provider-a

test-reconciliation:
	./scripts/test-reconciliation.sh

test-recovery:
	./scripts/test-recovery.sh

# Сценарии с настройками провайдеров
test-timeout-scenario:
	MOCK_ERROR_RATE_A=0 MOCK_TIMEOUT_RATE_A=100 docker compose up -d provider-a
	./scripts/test-timeout.sh
	docker compose up -d provider-a

test-fallback-scenario:
	MOCK_ERROR_RATE_A=100 MOCK_TIMEOUT_RATE_A=0 docker compose up -d provider-a
	./scripts/test-fallback.sh
	docker compose up -d provider-a

# Очистка системы
reset-stock:
	docker compose exec postgres psql -U app -d game_shop -c "UPDATE products SET stock = 1000, reserved = 0;"

clean-orders:
	docker compose exec postgres psql -U app -d game_shop -c "DELETE FROM deliveries; DELETE FROM payments; DELETE FROM orders; UPDATE products SET reserved = 0;"

clean: reset-stock clean-orders