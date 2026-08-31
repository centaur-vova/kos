CREATE INDEX idx_products_catalog ON products (type, price)
    WHERE (stock > reserved);
