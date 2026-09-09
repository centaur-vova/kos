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
test: reset-stock test-unit behat test-catalog

test-unit:
	docker compose exec -T app vendor/bin/phpunit

behat:
	docker compose exec -T app vendor/bin/behat

test-catalog:
	./scripts/test-catalog.sh

# Очистка системы
reset-stock:
	docker compose run --rm postgres psql -h postgres -U app -d game_shop -c "UPDATE products SET stock = 1000, reserved = 0;"

# Установить сток в нули
empty-stock:
	docker compose run --rm postgres psql -h postgres -U app -d game_shop -c "UPDATE products SET stock = 0, reserved = 0;"

clean-orders:
	docker compose run --rm postgres psql -h postgres -U app -d game_shop -c "TRUNCATE payments, refunds, order_items, order_events, orders RESTART IDENTITY CASCADE; UPDATE products SET reserved = 0;"

clean: reset-stock clean-orders

behat-focus:
	docker compose exec -T app vendor/bin/behat --tags focus