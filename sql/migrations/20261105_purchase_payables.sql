ALTER TABLE payables
  MODIFY due_date DATE NULL,
  MODIFY payment_method ENUM('pix','debito','credito','dinheiro','boleto','transferencia') NULL DEFAULT NULL,
  MODIFY installments INT UNSIGNED NULL DEFAULT NULL,
  MODIFY current_installment INT UNSIGNED NULL DEFAULT NULL,
  MODIFY installment_value DECIMAL(12,2) NULL DEFAULT NULL,
  ADD material_purchase_id INT UNSIGNED NULL,
  ADD configuration_pending TINYINT(1) NOT NULL DEFAULT 0,
  ADD total_manually_changed TINYINT(1) NOT NULL DEFAULT 0,
  ADD UNIQUE KEY uq_payable_material_purchase (material_purchase_id),
  ADD CONSTRAINT fk_payable_material_purchase FOREIGN KEY (material_purchase_id) REFERENCES material_purchases(id);
