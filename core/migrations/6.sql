-- v6: Ocjene i recenzije proizvoda. Prijavljeni kupac može ostaviti jednu recenziju
-- po proizvodu; "verified" = ima plaćenu narudžbu s tim artiklom. Vlasnik moderira
-- (pending → approved/rejected); javno se prikazuju samo 'approved'.
CREATE TABLE IF NOT EXISTS product_reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NULL,
    author_name VARCHAR(120) NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comment TEXT NULL,
    verified TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pr_product (product_id, status),
    UNIQUE KEY uk_pr_cust (product_id, customer_id),
    CONSTRAINT fk_pr_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_croatian_ci;
