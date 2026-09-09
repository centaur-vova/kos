-- 005_event_sourcing_immutability.sql

-- Добавляем version для отслеживания порядка событий
ALTER TABLE order_events ADD COLUMN IF NOT EXISTS version INT NOT NULL DEFAULT 1;

-- Запрет на изменение событий (история только дополняется)
CREATE RULE no_update_order_events AS ON UPDATE TO order_events
    DO INSTEAD NOTHING;

CREATE RULE no_delete_order_events AS ON DELETE TO order_events
    DO INSTEAD NOTHING;

-- Дополнительные индексы для быстрого восстановления состояния
CREATE INDEX IF NOT EXISTS idx_order_events_order_created
    ON order_events(order_id, created_at ASC);

CREATE INDEX IF NOT EXISTS idx_order_events_type
    ON order_events(event_type)
    WHERE event_type IN ('order.paid', 'item.refunded', 'item.delivered');

-- Комментарии для документации
COMMENT ON TABLE order_events IS 'Журнал событий заказа. Только добавление, без изменения и удаления.';
COMMENT ON COLUMN order_events.version IS 'Версия события для оптимистичной блокировки и порядка';