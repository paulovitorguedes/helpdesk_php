CREATE TABLE material_purchase_invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    material_purchase_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invoice_request (request_token),
    KEY idx_invoice_purchase (material_purchase_id, id),
    CONSTRAINT fk_invoice_purchase FOREIGN KEY (material_purchase_id) REFERENCES material_purchases(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_author FOREIGN KEY (uploaded_by) REFERENCES users(id),
    CONSTRAINT ck_invoice_size CHECK (size_bytes > 0 AND size_bytes <= 10485760)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE material_purchase_invoice_parts (
    invoice_id INT UNSIGNED NOT NULL,
    part_number SMALLINT UNSIGNED NOT NULL,
    pdf_bytes MEDIUMBLOB NOT NULL,
    PRIMARY KEY (invoice_id, part_number),
    CONSTRAINT fk_invoice_part FOREIGN KEY (invoice_id) REFERENCES material_purchase_invoices(id) ON DELETE CASCADE,
    CONSTRAINT ck_invoice_part_size CHECK (OCTET_LENGTH(pdf_bytes) > 0 AND OCTET_LENGTH(pdf_bytes) <= 262144)
) ENGINE=InnoDB;
