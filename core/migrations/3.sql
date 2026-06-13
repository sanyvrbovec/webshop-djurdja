-- v3: računi kupaca, blog, jednostrani raskid ugovora (ZZP 19.6.2026.),
--     varijante iz đurđe (djurdja_variant_id)

CREATE TABLE IF NOT EXISTS customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(200) NOT NULL,
    phone VARCHAR(40) NULL,
    address VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    postal_code VARCHAR(20) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_croatian_ci;

ALTER TABLE orders ADD COLUMN customer_id INT UNSIGNED NULL AFTER guest_token;
ALTER TABLE orders ADD INDEX idx_o_customer (customer_id);
ALTER TABLE orders ADD COLUMN withdrawal_requested_at DATETIME NULL;
ALTER TABLE orders ADD COLUMN withdrawal_reason VARCHAR(500) NULL;

CREATE TABLE IF NOT EXISTS blog_posts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    excerpt VARCHAR(500) NULL,
    content MEDIUMTEXT NULL,
    cover_image VARCHAR(255) NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    published_at DATETIME NULL,
    seo_title VARCHAR(190) NULL,
    seo_description VARCHAR(300) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_bp_pub (is_published, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_croatian_ci;

ALTER TABLE product_variants ADD COLUMN djurdja_variant_id VARCHAR(36) NULL;
ALTER TABLE product_variants ADD UNIQUE INDEX idx_pv_djid (djurdja_variant_id);

INSERT IGNORE INTO pages (slug, title, content, is_visible, in_nav, in_footer, sort_order) VALUES
('pravo-na-popravak', 'Pravo na popravak', '<p>Sukladno izmjenama Zakona o zaštiti potrošača (od 31. srpnja 2026.), za određene kategorije proizvoda (hladnjaci, perilice, uređaji s baterijama, pametni telefoni, tableti i sl.) imate pravo na popravak. Popravak se ne smije odbiti samo zato što je proizvod ranije popravljao neovlašteni serviser ili vi sami.</p><p>Za ostvarivanje prava na popravak kontaktirajte nas putem podataka u podnožju stranice.</p><p><em>Vlasniče trgovine: prilagodite ovaj tekst svojoj ponudi — ako ne prodajete navedene kategorije robe, stranicu možete sakriti u administraciji (Stranice).</em></p>', 1, 0, 1, 5);
