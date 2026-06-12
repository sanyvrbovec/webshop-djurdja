-- v2: varijante proizvoda, SEO za kategorije, izbacivanje virmana

CREATE TABLE IF NOT EXISTS product_variants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    option1_name VARCHAR(60) NOT NULL DEFAULT '',
    option1_value VARCHAR(60) NOT NULL DEFAULT '',
    option2_name VARCHAR(60) NULL,
    option2_value VARCHAR(60) NULL,
    label VARCHAR(190) NOT NULL,
    sku VARCHAR(64) NULL,
    price DECIMAL(10,2) NULL,
    stock_qty DECIMAL(12,2) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pv_product (product_id, is_active, sort_order),
    CONSTRAINT fk_pv_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_croatian_ci;

ALTER TABLE order_items ADD COLUMN variant_id INT UNSIGNED NULL AFTER product_id;
ALTER TABLE order_items ADD COLUMN variant_label VARCHAR(190) NULL AFTER name;

ALTER TABLE categories ADD COLUMN seo_title VARCHAR(190) NULL;
ALTER TABLE categories ADD COLUMN seo_description VARCHAR(300) NULL;

DELETE FROM payment_methods WHERE code = 'bank_transfer';
