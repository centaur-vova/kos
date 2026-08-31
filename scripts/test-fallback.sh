#!/bin/bash
# Тест: провайдер A падает, fallback на B
# Ожидание: товар выдан через B, ровно один раз

set -e

BASE_URL="http://localhost:8080"

echo "=== Тест: fallback на B ==="

# Создаём заказ
ORDER=$(curl -s -X POST "$BASE_URL/orders" \
  -H "Content-Type: application/json" \
  -d '{"sku":"KEY-CS2-PRIME","user_id":"fallback_test"}')

ORDER_CODE=$(echo "$ORDER" | jq -r '.data.order.order_code')
echo "Заказ создан: $ORDER_CODE"

# Отправляем вебхук
RESULT=$(curl -s -X POST "$BASE_URL/webhook/payment" \
  -H "Content-Type: application/json" \
  -d "{\"event_id\":\"evt_fallback_$(date +%s)\",\"order_id\":\"$ORDER_CODE\",\"status\":\"paid\",\"amount\":1290}")

echo "Вебхук: $RESULT"

# Ждём до 30 секунд, пока заказ не доставлен
for i in $(seq 1 30); do
  STATUS=$(curl -s "$BASE_URL/orders/$ORDER_CODE" | jq -r '.data.order.status')
  if [ "$STATUS" = "delivered" ]; then
    echo "Заказ доставлен (через ${i} сек)"
    break
  fi
  sleep 1
done

# Проверяем статус
STATUS=$(curl -s "$BASE_URL/orders/$ORDER_CODE")
PROVIDER=$(echo "$STATUS" | jq -r '.data.order.provider')
DELIVERIES=$(echo "$STATUS" | jq '[.data.order.deliveries[] | select(.status == "issued")] | length')

echo "Провайдер: $PROVIDER"
echo "Успешных выдач: $DELIVERIES"

if [ "$PROVIDER" = "B" ] && [ "$DELIVERIES" -eq 1 ]; then
  echo "✅ ТЕСТ ПРОЙДЕН: fallback на B, одна выдача"
  exit 0
else
  echo "❌ ТЕСТ ПРОВАЛЕН: провайдер $PROVIDER, выдач $DELIVERIES"
  exit 1
fi