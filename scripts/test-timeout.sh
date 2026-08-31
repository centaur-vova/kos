#!/bin/bash
# Тест: таймаут провайдера, который реально выдал код
# Ожидание: повтор с тем же request_id возвращает тот же код, выдача ровно одна

set -e

BASE_URL="http://localhost:8080"

echo "=== Тест: таймаут провайдера ==="

# Загружаем настройки из .env
TIMEOUT_DURATION=$(grep MOCK_TIMEOUT_DURATION_SEC_A .env | cut -d '=' -f 2)
if [ -z "$TIMEOUT_DURATION" ]; then
  TIMEOUT_DURATION=7
fi

# Таймаут + первый ретрай + запас
SLEEP_TIME=$((TIMEOUT_DURATION + 10))

# Создаём заказ
ORDER=$(curl -s -X POST "$BASE_URL/orders" \
  -H "Content-Type: application/json" \
  -d '{"sku":"KEY-CS2-PRIME","user_id":"timeout_test"}')

ORDER_CODE=$(echo "$ORDER" | jq -r '.data.order.order_code')
echo "Заказ создан: $ORDER_CODE"

# Отправляем вебхук
RESULT=$(curl -s -X POST "$BASE_URL/webhook/payment" \
  -H "Content-Type: application/json" \
  -d "{\"event_id\":\"evt_timeout_$(date +%s)\",\"order_id\":\"$ORDER_CODE\",\"status\":\"paid\",\"amount\":1290}")

echo "Вебхук: $RESULT"

# Ждём обработки
echo "Ждём обработки (${SLEEP_TIME} сек)..."
sleep $SLEEP_TIME

# Проверяем статус
STATUS=$(curl -s "$BASE_URL/orders/$ORDER_CODE")
echo "Статус заказа:"
echo "$STATUS" | jq .

# Проверяем количество выдач
DELIVERIES=$(echo "$STATUS" | jq '.data.order.deliveries | length')
CODES=$(echo "$STATUS" | jq '[.data.order.deliveries[] | select(.status == "issued")] | length')

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