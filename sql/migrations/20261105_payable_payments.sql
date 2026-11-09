ALTER TABLE payables ADD payment_history_locked TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE payable_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payable_id INT UNSIGNED NOT NULL,
    installment_number INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method ENUM('pix','debito','credito','dinheiro','boleto','transferencia') NOT NULL,
    notes TEXT NULL,
    request_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reversed_at DATETIME NULL,
    reversed_by INT UNSIGNED NULL,
    reversal_reason TEXT NULL,
    UNIQUE KEY uq_payable_payment_request (request_token),
    KEY idx_payable_payment_installment (payable_id, installment_number),
    CONSTRAINT fk_pp_account FOREIGN KEY (payable_id) REFERENCES payables(id),
    CONSTRAINT fk_pp_author FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_pp_reversal_author FOREIGN KEY (reversed_by) REFERENCES users(id),
    CONSTRAINT ck_pp_positive CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
