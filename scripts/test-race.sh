#!/bin/bash
# Тест: 50 параллельных вебхуков на один заказ
# Ожидание: ровно одна выдача

set -e

BASE_URL="http://localhost:8080"

echo "=== Тест: 50 параллельных вебхуков ==="

# Создаём заказ
ORDER=$(curl -s -X POST "$BASE_URL/orders" \
  -H "Content-Type: application/json" \
  -d '{"sku":"KEY-CS2-PRIME","user_id":"race_test"}')

ORDER_CODE=$(echo "$ORDER" | jq -r '.order.order_code')
echo "Заказ создан: $ORDER_CODE"

# Отправляем 50 параллельных вебхуков
echo "Отправляем 50 вебхуков..."
for i in $(seq 1 50); do
  curl -s -X POST "$BASE_URL/webhook/payment" \
    -H "Content-Type: application/json" \
    -d "{\"event_id\":\"evt_race_${i}_$(date +%s)\",\"order_id\":\"$ORDER_CODE\",\"status\":\"paid\",\"amount\":1290}" &
done
wait

echo "Все вебхуки отправлены"

# Ждём до 30 секунд, пока заказ не доставлен
for i in $(seq 1 30); do
  STATUS=$(curl -s "$BASE_URL/orders/$ORDER_CODE" | jq -r '.order.status')
  if [ "$STATUS" = "delivered" ]; then
    echo "Заказ доставлен (через ${i} сек)"
    break
  fi
  sleep 1
done

# Проверяем статус
STATUS=$(curl -s "$BASE_URL/orders/$ORDER_CODE")
echo "Статус заказа:"
echo "$STATUS" | jq .

# Проверяем количество выдач
DELIVERIES=$(echo "$STATUS" | jq '[.order.deliveries[] | select(.status == "issued")] | length')
TOTAL_DELIVERIES=$(echo "$STATUS" | jq '.order.deliveries | length')

echo ""
echo "Успешных выдач: $DELIVERIES"
echo "Всего записей: $TOTAL_DELIVERIES"

if [ "$DELIVERIES" -eq 1 ]; then
  echo "✅ ТЕСТ ПРОЙДЕН: ровно одна выдача"
  exit 0
else
  echo "❌ ТЕСТ ПРОВАЛЕН: выдач $DELIVERIES"
  exit 1
fi