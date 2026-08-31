#!/bin/bash
set -e

BASE_URL="http://localhost:8080"

echo "=== Тест: сверка ==="

# Создаём заказ
ORDER=$(curl -s -X POST "$BASE_URL/orders" \
  -H "Content-Type: application/json" \
  -d '{"sku":"KEY-CS2-PRIME","user_id":"recon_test"}')

ORDER_CODE=$(echo "$ORDER" | jq -r '.data.order.order_code')
ORDER_ID=$(echo "$ORDER" | jq -r '.data.order.id')

echo "Заказ: $ORDER_CODE"

# Оплачиваем
curl -s -X POST "$BASE_URL/webhook/payment" \
  -H "Content-Type: application/json" \
  -d "{\"event_id\":\"evt_recon_$(date +%s)\",\"order_id\":\"$ORDER_CODE\",\"status\":\"paid\",\"amount\":1290}" > /dev/null

# Делаем вид, что заказ завис
docker compose exec postgres psql -U app -d game_shop -c \
  "UPDATE orders SET status = 'paid', updated_at = NOW() - INTERVAL '10 minutes' WHERE id = '$ORDER_ID';" > /dev/null

# Проверяем сверку
echo "Сверка:"
curl -s "$BASE_URL/reconciliation" | jq .

# Проверяем, что заказ в списке
FOUND=$(curl -s "$BASE_URL/reconciliation" | jq --arg order_code "$ORDER_CODE" '.data.details.paid_not_delivered[] | select(.order_code == $order_code)' | wc -l)

if [ "$FOUND" -gt 0 ]; then
  echo "✅ ТЕСТ ПРОЙДЕН: зависший заказ найден"
else
  echo "❌ ТЕСТ ПРОВАЛЕН: заказ не найден в сверке"
fi