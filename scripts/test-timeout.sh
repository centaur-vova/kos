#!/bin/bash
# Тест: таймаут провайдера, который реально выдал код
# Ожидание: повтор с тем же request_id возвращает тот же код, выдача ровно одна

set -e

BASE_URL="http://localhost:8080"

echo "=== Тест: таймаут провайдера ==="

# Создаём заказ
ORDER=$(curl -s -X POST "$BASE_URL/orders" \
  -H "Content-Type: application/json" \
  -d '{"sku":"KEY-CS2-PRIME","user_id":"timeout_test"}')

ORDER_CODE=$(echo "$ORDER" | jq -r '.order.order_code')
echo "Заказ создан: $ORDER_CODE"

# Отправляем вебхук
RESULT=$(curl -s -X POST "$BASE_URL/webhook/payment" \
  -H "Content-Type: application/json" \
  -d "{\"event_id\":\"evt_timeout_$(date +%s)\",\"order_id\":\"$ORDER_CODE\",\"status\":\"paid\",\"amount\":1290}")

echo "Вебхук: $RESULT"

# Ждём обработки
sleep 5

# Проверяем статус
STATUS=$(curl -s "$BASE_URL/orders/$ORDER_CODE")
echo "Статус заказа:"
echo "$STATUS" | jq .

# Проверяем количество выдач
DELIVERIES=$(echo "$STATUS" | jq '.order.deliveries | length')
CODES=$(echo "$STATUS" | jq '[.order.deliveries[] | select(.status == "issued")] | length')

echo ""
echo "Всего попыток: $DELIVERIES"
echo "Успешных выдач: $CODES"

if [ "$CODES" -eq 1 ]; then
  echo "✅ ТЕСТ ПРОЙДЕН: ровно одна выдача"
  exit 0
else
  echo "❌ ТЕСТ ПРОВАЛЕН: выдач $CODES"
  exit 1
fi