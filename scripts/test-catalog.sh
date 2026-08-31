#!/bin/bash
set -e

BASE_URL="http://localhost:8080"

echo "=== Тест: каталог ==="

# Получаем каталог
CATALOG=$(curl -s "$BASE_URL/catalog")

# Количество товаров
PRODUCTS=$(echo "$CATALOG" | jq '.data.products | length')
echo "Товаров в каталоге: $PRODUCTS"

# Все с available > 0
AVAILABLE=$(echo "$CATALOG" | jq '[.data.products[] | select(.available > 0)] | length')
echo "Доступных товаров: $AVAILABLE"

if [ "$PRODUCTS" -eq "$AVAILABLE" ]; then
  echo "✅ ТЕСТ ПРОЙДЕН: все товары доступны"
  exit 0
else
  echo "❌ ТЕСТ ПРОВАЛЕН: есть недоступные товары"
  exit 1
fi