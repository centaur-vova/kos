-- 004_multi_items.sql

-- Обновляем constraint для поддержки partially_delivered
ALTER TABLE orders DROP CONSTRAINT IF EXISTS valid_status;
ALTER TABLE orders ADD CONSTRAINT valid_status CHECK (status IN (
    'created','paid','delivering','delivered',
    'payment_failed','out_of_stock','delivery_failed',
    'partially_delivered'
));

-- Конвертируем price в price_cents (BIGINT)
ALTER TABLE products RENAME COLUMN price TO price_cents;
ALTER TABLE products ALTER COLUMN price_cents TYPE BIGINT USING (ROUND(price_cents * 100));

-- Убираем sku из orders
ALTER TABLE orders DROP COLUMN IF EXISTS sku;

-- Добавляем price_cents в orders
ALTER TABLE orders ADD COLUMN IF NOT EXISTS price_cents BIGINT NOT NULL DEFAULT 0;
UPDATE orders SET price_cents = ROUND(price_cents);
ALTER TABLE orders DROP COLUMN IF EXISTS price;

-- order_items с price_cents
CREATE TABLE IF NOT EXISTS order_items (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id UUID NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    sku TEXT NOT NULL REFERENCES products(sku),
    price_cents BIGINT NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending',
    delivered_code TEXT UNIQUE,
    provider TEXT,
    provider_request_id TEXT UNIQUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT valid_item_status CHECK (status IN (
        'pending','delivering','delivered','refunded',
        'out_of_stock','delivery_failed'
    ))
);

-- refunds с amount_cents
CREATE TABLE IF NOT EXISTS refunds (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_item_id UUID NOT NULL UNIQUE REFERENCES order_items(id) ON DELETE CASCADE,
    amount_cents BIGINT NOT NULL,
    status TEXT NOT NULL DEFAULT 'completed',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT valid_refund_status CHECK (status IN ('pending','completed','failed'))
);

-- order_events
CREATE TABLE IF NOT EXISTS order_events (
    id BIGSERIAL PRIMARY KEY,
    order_id UUID NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    event_type TEXT NOT NULL,
    event_data JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- supplier_queue
CREATE TABLE IF NOT EXISTS supplier_queue (
    id BIGSERIAL PRIMARY KEY,
    supplier_id TEXT NOT NULL,
    order_item_id UUID NOT NULL UNIQUE REFERENCES order_items(id) ON DELETE CASCADE,
    priority INT NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'queued',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT valid_queue_status CHECK (status IN ('queued','processing','completed','failed'))
);

-- Индексы
CREATE INDEX IF NOT EXISTS idx_order_items_order_id ON order_items(order_id);
CREATE INDEX IF NOT EXISTS idx_order_items_status ON order_items(status) WHERE status IN ('pending','delivering');
CREATE INDEX IF NOT EXISTS idx_order_items_delivered_code ON order_items(delivered_code) WHERE delivered_code IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_refunds_item_id ON refunds(order_item_id);
CREATE INDEX IF NOT EXISTS idx_order_events_order_id ON order_events(order_id);
CREATE INDEX IF NOT EXISTS idx_order_events_created ON order_events(created_at);
CREATE INDEX IF NOT EXISTS idx_supplier_queue_status ON supplier_queue(status) WHERE status = 'queued';
CREATE INDEX IF NOT EXISTS idx_supplier_queue_priority ON supplier_queue(priority DESC, created_at ASC);

-- Покрывающий индекс для витрины
CREATE INDEX IF NOT EXISTS idx_products_catalog_covering
ON products (type, price_cents)
INCLUDE (sku, name, currency, stock, reserved)
WHERE stock > reserved;