.PHONY: \
	app-shell \
	db-shell \
	up \
	down \
	restart \
	logs \
	ps \
	test \
	test-unit \
	behat \
	test-reconciliation \
	test-recovery \
	test-catalog \
	reset-stock \
	empty-stock \
	clean-orders \
	clean \
	behat-focus

# Запуск
app-shell:
	docker compose exec app sh

db-shell:
	docker compose exec postgres psql -U app -d game_shop

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
test: reset-stock test-unit behat test-reconciliation test-recovery test-catalog

test-unit:
	docker compose exec app vendor/bin/phpunit

behat:
	docker compose exec app vendor/bin/behat

test-reconciliation:
	./scripts/test-reconciliation.sh

test-recovery:
	./scripts/test-recovery.sh

test-catalog:
	./scripts/test-catalog.sh

# Очистка системы
reset-stock:
	docker compose exec postgres psql -U app -d game_shop -c "UPDATE products SET stock = 1000, reserved = 0;"

# Установить сток в нули
empty-stock:
	docker compose exec postgres psql -U app -d game_shop -c "UPDATE products SET stock = 0, reserved = 0;"

clean-orders:
	docker compose exec postgres psql -U app -d game_shop -c "TRUNCATE deliveries, payments, refunds, order_items, order_events, supplier_queue, orders RESTART IDENTITY CASCADE; UPDATE products SET reserved = 0;"

clean: reset-stock clean-orders

behat-focus:
	docker compose exec app vendor/bin/behat --tags focus
