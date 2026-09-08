#!/bin/bash
set -e

BASE_URL="http://localhost:8080"

echo "=== Мульти-товарный заказ ==="

# 1. Создаём заказ
ORDER=$(curl -s -X POST "$BASE_URL/orders" \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": "test_user",
    "items": [
      {"sku":"KEY-CS2-PRIME"},
      {"sku":"STEAM-TOPUP-500"},
      {"sku":"GIFT-PSN-1000"}
    ]
  }')

ORDER_CODE=$(echo "$ORDER" | jq -r '.data.order.order_code')
echo "Заказ: $ORDER_CODE"

# 2. Оплачиваем
curl -s -X POST "$BASE_URL/webhook/payment" \
  -H "Content-Type: application/json" \
  -d "{
    \"event_id\": \"evt_multi_$(date +%s)\",
    \"order_id\": \"$ORDER_CODE\",
    \"status\": \"paid\",
    \"amount\": 2790
  }" > /dev/null

echo "Оплата отправлена"

# 3. Ждём выдачу
sleep 3

# 4. Проверяем
curl -s "$BASE_URL/orders/$ORDER_CODE" | jq .