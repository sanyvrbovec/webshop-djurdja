-- ============================================================
-- ĐurđaShop — shema baze (pokreće installer)
-- Sve tablice utf8mb4_croatian_ci (ispravno sortiranje č/ć/đ/š/ž)
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
    k VARCHAR(100) NOT NULL PRIMARY KEY,
    v MEDIUMTEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_croatian_ci;

CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL UNIQUE,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_croatian_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_key VARCHAR(190) NOT NULL,
    ip VARCHAR(45) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_la_key (attempt_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_croatian_ci;

CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    djurdja_id VARCHAR(36) NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    description TEXT NULL,
    image VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    seo_title VARCHAR(190) NULL,
    seo_description VARCHAR(300) NULL,
    synced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_croatian_ci;

CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    djurdja_id VARCHAR(36) NOT NULL UNIQUE,
    category_id INT UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    short_description VARCHAR(500) NULL,
    description MEDIUMTEXT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    vat_rate DECIMAL(5,2) NOT NULL DEFAULT 25.00,
    unit VARCHAR(50) NOT NULL DEFAULT 'kom',
    barcode VARCHAR(64) NULL,
    is_service TINYINT(1) NOT NULL DEFAULT 0,
    stock_qty DECIMAL(12,2) NULL,
    track_stock TINYINT(1) NOT NULL DEFAULT 0,
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    seo_title VARCHAR(190) NULL,
    seo_description VARCHAR(300) NULL,
    is_orphaned TINYINT(1) NOT NULL DEFAULT 0,
    synced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_p_cat_vis (category_id, is_visible),
    INDEX idx_p_featured (is_featured, is_visible),
    CONSTRAINT fk_p_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_croatian_ci;

CREATE TABLE IF NOT EXISTS product_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    alt VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pi_product (product_id, sort_order),
    CONSTRAINT fk_pi_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_croatian_ci;

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

CREATE TABLE IF NOT EXISTS pages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(190) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    content MEDIUMTEXT NULL,
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    in_nav TINYINT(1) NOT NULL DEFAULT 0,
    in_footer TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    seo_title VARCHAR(190) NULL,
    seo_description VARCHAR(300) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_croatian_ci;

CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(20) NOT NULL UNIQUE,
    status ENUM('pending','confirmed','processing','shipped','delivered','cancelled','refunded') NOT NULL DEFAULT 'pending',
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('cod','bank_transfer','stripe') NOT NULL DEFAULT 'cod',
    payment_status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    payment_transaction_id VARCHAR(200) NULL,
    payment_data TEXT NULL,
    fiscal_status ENUM('none','pending','pending_retry','fiscalized','stornoed','failed','failed_expired') NOT NULL DEFAULT 'none',
    fiscal_mode VARCHAR(10) NULL,
    fiscal_receipt_number VARCHAR(30) NULL,
    fiscal_jir VARCHAR(64) NULL,
    fiscal_zki VARCHAR(64) NULL,
    fiscal_qr TEXT NULL,
    fiscal_storno_jir VARCHAR(64) NULL,
    fiscal_storno_receipt_number VARCHAR(30) NULL,
    fiscalized_at DATETIME NULL,
    fiscal_error VARCHAR(255) NULL,
    fiscal_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    fiscal_first_attempt_at DATETIME NULL,
    fiscal_next_retry_at DATETIME NULL,
    fiscal_last_error_code VARCHAR(64) NULL,
    customer_name VARCHAR(200) NOT NULL,
    customer_email VARCHAR(190) NOT NULL,
    customer_phone VARCHAR(40) NULL,
    address VARCHAR(255) NOT NULL DEFAULT '',
    city VARCHAR(100) NOT NULL DEFAULT '',
    postal_code VARCHAR(20) NOT NULL DEFAULT '',
    country VARCHAR(2) NOT NULL DEFAULT 'HR',
    note TEXT NULL,
    admin_note TEXT NULL,
    guest_token VARCHAR(64) NOT NULL,
    ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_o_status (status, created_at),
    INDEX idx_o_payment (payment_status),
    INDEX idx_o_fiscal_retry (fiscal_status, fiscal_next_retry_at),
    INDEX idx_o_guest (guest_token),
    INDEX idx_o_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_croatian_ci;

CREATE TABLE IF NOT EXISTS order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NULL,
    variant_id INT UNSIGNED NULL,
    djurdja_product_id VARCHAR(36) NULL,
    name VARCHAR(255) NOT NULL,
    variant_label VARCHAR(190) NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    vat_rate DECIMAL(5,2) NOT NULL DEFAULT 25.00,
    total DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_oi_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_croatian_ci;

CREATE TABLE IF NOT EXISTS payment_methods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    config TEXT NULL,
    fee_type ENUM('none','fixed','percent') NOT NULL DEFAULT 'none',
    fee_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    fiscal_auto TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_croatian_ci;

CREATE TABLE IF NOT EXISTS payment_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    provider VARCHAR(50) NOT NULL,
    transaction_id VARCHAR(200) NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
    status ENUM('initiated','pending','paid','failed','refunded','cancelled') NOT NULL DEFAULT 'initiated',
    raw_response TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pt_order (order_id),
    INDEX idx_pt_txn (transaction_id),
    CONSTRAINT fk_pt_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_croatian_ci;

CREATE TABLE IF NOT EXISTS fiscal_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    action ENUM('fiscalize','storno','retry','error','expired') NOT NULL,
    request_id VARCHAR(64) NULL,
    mode VARCHAR(10) NULL,
    receipt_number VARCHAR(30) NULL,
    jir VARCHAR(64) NULL,
    zki VARCHAR(64) NULL,
    response_status INT NULL,
    error_code VARCHAR(64) NULL,
    error_message TEXT NULL,
    raw_response LONGTEXT NULL,
    duration_ms INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fl_order (order_id),
    CONSTRAINT fk_fl_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_croatian_ci;

CREATE TABLE IF NOT EXISTS sync_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(30) NOT NULL,
    status ENUM('running','done','error') NOT NULL DEFAULT 'running',
    message VARCHAR(500) NULL,
    stats TEXT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_croatian_ci;

CREATE TABLE IF NOT EXISTS newsletter_subscribers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    token VARCHAR(64) NOT NULL,
    is_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_croatian_ci;

-- ============================================================
-- Seed: načini plaćanja
-- ============================================================
INSERT INTO payment_methods (code, name, description, is_active, sort_order, config, fee_type, fee_value, fiscal_auto) VALUES
('cod', 'Pouzeće', 'Plaćanje gotovinom prilikom preuzimanja pošiljke.', 1, 1,
 '{"instructions":"Platite dostavljaču prilikom preuzimanja paketa."}', 'none', 0.00, 1),
('stripe', 'Kartično plaćanje', 'Sigurno online plaćanje karticom (Visa, Mastercard).', 0, 2,
 '{"publishable_key":"","secret_key_enc":"","webhook_secret_enc":"","sandbox":true}', 'none', 0.00, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Verzija sheme (mora odgovarati Migrations::TARGET)
INSERT INTO settings (k, v) VALUES ('schema_version', '2')
ON DUPLICATE KEY UPDATE v = VALUES(v);

-- ============================================================
-- Seed: standardne stranice
-- ============================================================
INSERT INTO pages (slug, title, content, is_visible, in_nav, in_footer, sort_order) VALUES
('o-nama', 'O nama', '<p>Dobrodošli u našu web trgovinu! Podaci o tvrtki nalaze se u podnožju stranice i automatski se preuzimaju iz sustava MojaĐurđa.</p>', 1, 1, 1, 1),
('uvjeti-koristenja', 'Uvjeti korištenja', '<p>Ovdje unesite uvjete korištenja i opće uvjete poslovanja vaše trgovine. Uredite ovu stranicu u administraciji.</p>', 1, 0, 1, 2),
('zastita-privatnosti', 'Zaštita privatnosti', '<p>Ovdje unesite politiku privatnosti (GDPR). Podaci kupaca koriste se isključivo za obradu narudžbi.</p>', 1, 0, 1, 3),
('dostava-i-povrat', 'Dostava i povrat', '<p>Ovdje opišite uvjete dostave, rokove isporuke i postupak povrata robe.</p>', 1, 0, 1, 4)
ON DUPLICATE KEY UPDATE title = VALUES(title);
