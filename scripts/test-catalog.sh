#!/bin/bash
set -e

BASE_URL="http://localhost:8080"

echo "=== Тест: каталог под нагрузкой ==="

# 1. Генерация 10 000 SKU
echo "Генерация 10 000 SKU..."
docker compose exec -T postgres psql -U app -d game_shop <<SQL
INSERT INTO products (sku, name, type, price_cents, currency, stock, reserved)
SELECT
    'GEN-' || i,
    'Generated Product ' || i,
    CASE WHEN i % 3 = 0 THEN 'key' WHEN i % 3 = 1 THEN 'topup' ELSE 'subscription' END,
    (i * 100)::BIGINT,
    'RUB',
    50,
    0
FROM generate_series(1, 10000) AS i
ON CONFLICT (sku) DO NOTHING;
SQL

# 2. Проверка количества
TOTAL=$(docker compose exec -T postgres psql -U app -d game_shop -t -c "SELECT COUNT(*) FROM products;")
echo "Всего SKU: $TOTAL"

# 3. Проверка плана запроса
echo ""
echo "=== План запроса ==="
docker compose exec -T postgres psql -U app -d game_shop -c "
EXPLAIN ANALYZE
SELECT sku, name, type, price_cents, currency, stock, reserved, (stock - reserved) as available
FROM products
WHERE stock > reserved
ORDER BY type, price_cents
LIMIT 100;
"

# 4. Время ответа API
echo ""
echo "=== Время ответа ==="
TIME=$(curl -s -o /dev/null -w "%{time_total}" "$BASE_URL/catalog")
echo "Одиночный запрос: ${TIME}s"

# 5. Нагрузка: 100 параллельных запросов
echo ""
echo "=== Нагрузка: 100 параллельных запросов ==="
START=$(date +%s%N)

for i in $(seq 1 100); do
  curl -s "$BASE_URL/catalog" > /dev/null &
done
wait

END=$(date +%s%N)
ELAPSED=$((($END - $START) / 1000000))
echo "Время на 100 запросов: ${ELAPSED}ms"
echo "Среднее на запрос: $((ELAPSED / 100))ms"

# 6. Очистка
echo ""
echo "Очистка сгенерированных SKU..."
docker compose exec -T postgres psql -U app -d game_shop -c "DELETE FROM products WHERE sku LIKE 'GEN-%';"

echo "✅ ТЕСТ ЗАВЕРШЕН"