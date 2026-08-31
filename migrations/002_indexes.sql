-- Индексы

-- Каталог: витрина доступных товаров
CREATE INDEX IF NOT EXISTS idx_products_catalog_available
    ON products (type, price)
    WHERE (stock > reserved);

-- Сверка: поиск зависших заказов
CREATE INDEX IF NOT EXISTS idx_orders_stuck_states
    ON orders (status, paid_at)
    WHERE status IN ('created', 'paid', 'delivering', 'delivery_failed', 'out_of_stock');

-- Сортировка заказов по дате
CREATE INDEX IF NOT EXISTS idx_orders_created ON orders(created_at DESC);

-- Внешние ключи
CREATE INDEX IF NOT EXISTS idx_payments_order_id ON payments(order_id);
CREATE INDEX IF NOT EXISTS idx_deliveries_order_id ON deliveries(order_id);
CREATE INDEX IF NOT EXISTS idx_keys_pool_order_id ON keys_pool(order_id);

-- Пул ключей
CREATE INDEX IF NOT EXISTS idx_keys_pool_available ON keys_pool(status)
    WHERE status = 'available';