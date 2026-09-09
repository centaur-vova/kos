-- 006_cleanup.sql

-- Удаляем неиспользуемые таблицы
DROP TABLE IF EXISTS deliveries;
DROP TABLE IF EXISTS keys_pool;
DROP TABLE IF EXISTS supplier_queue;

-- Удаляем соответствующие индексы
DROP INDEX IF EXISTS idx_deliveries_order_id;
DROP INDEX IF EXISTS idx_keys_pool_order_id;
DROP INDEX IF EXISTS idx_keys_pool_available;
DROP INDEX IF EXISTS idx_supplier_queue_status;
DROP INDEX IF EXISTS idx_supplier_queue_priority;

-- Удаляем колонки из orders, которые переехали в order_items
ALTER TABLE orders DROP COLUMN IF EXISTS delivered_code;
ALTER TABLE orders DROP COLUMN IF EXISTS provider;
ALTER TABLE orders DROP COLUMN IF EXISTS provider_request_id;