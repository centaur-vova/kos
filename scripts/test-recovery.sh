#!/bin/bash
# Тест: фоновая задача восстановления зависших заказов
# Ожидание: заказ со статусом paid и старым updated_at будет выдан

set -e

BASE_URL="http://localhost:8080"

echo "=== Тест: восстановление зависшего заказа ==="

# Создаём заказ
ORDER=$(curl -s -X POST "$BASE_URL/orders" \
  -H "Content-Type: application/json" \
  -d '{"sku":"KEY-CS2-PRIME","user_id":"recovery_test"}')

ORDER_CODE=$(echo "$ORDER" | jq -r '.order.order_code')
ORDER_ID=$(echo "$ORDER" | jq -r '.order.id')

echo "Заказ создан: $ORDER_CODE"

# Оплачиваем
curl -s -X POST "$BASE_URL/webhook/payment" \
  -H "Content-Type: application/json" \
  -d "{\"event_id\":\"evt_recovery_$(date +%s)\",\"order_id\":\"$ORDER_CODE\",\"status\":\"paid\",\"amount\":1290}" > /dev/null

echo "Оплата отправлена"

# Делаем заказ "зависшим"
docker compose exec postgres psql -U app -d game_shop -c \
  "UPDATE orders SET status = 'paid', updated_at = NOW() - INTERVAL '10 minutes', delivered_at = NULL WHERE id = '$ORDER_ID';" > /dev/null

echo "Заказ помечен как зависший"

# Проверяем сверку до восстановления
RECON=$(curl -s "$BASE_URL/reconciliation")
PAID_NOT_DELIVERED=$(echo "$RECON" | jq --arg order_code "$ORDER_CODE" '.details.paid_not_delivered[] | select(.order_code == $order_code)' | wc -l)

if [ "$PAID_NOT_DELIVERED" -eq 0 ]; then
  echo "❌ Заказ не найден в сверке"
  exit 1
fi

echo "Заказ найден в сверке"

# Ждём фоновую задачу (RECOVERY_INTERVAL_SEC + запас)
echo "Ждём восстановления..."
sleep 15

# Проверяем статус
STATUS=$(curl -s "$BASE_URL/orders/$ORDER_CODE" | jq -r '.order.status')

echo "Статус после восстановления: $STATUS"

if [ "$STATUS" = "delivered" ]; then
  echo "✅ ТЕСТ ПРОЙДЕН: заказ восстановлен и доставлен"
  exit 0
else
  echo "❌ ТЕСТ ПРОВАЛЕН: статус $STATUS"
  exit 1
fi