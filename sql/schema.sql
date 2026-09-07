CREATE DATABASE IF NOT EXISTS gmb_helpdesk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE gmb_helpdesk;

CREATE TABLE IF NOT EXISTS users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('admin','tecnico','financeiro') NOT NULL DEFAULT 'tecnico',
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS clients (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(190) NOT NULL,
 document VARCHAR(30) NULL,
 phone VARCHAR(40) NULL,
 email VARCHAR(190) NULL,
 address TEXT NULL,
 status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
 standard_km DECIMAL(10,2) NOT NULL DEFAULT 0,
 has_contract TINYINT(1) NOT NULL DEFAULT 0,
 monthly_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
 extra_visit_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
 due_day TINYINT UNSIGNED NOT NULL DEFAULT 20,
 contract_notes TEXT NULL,
 notes TEXT NULL,
 deleted_at DATETIME NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_clients_name(name), INDEX idx_clients_document(document), INDEX idx_clients_status(status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS materials (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 purchase_date DATE NOT NULL,
 description VARCHAR(190) NOT NULL,
 cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
 supplier VARCHAR(190) NULL,
 payment_method ENUM('pix','debito','credito','dinheiro') NOT NULL DEFAULT 'pix',
 installments INT UNSIGNED NULL,
 installment_value DECIMAL(12,2) NULL,
 notes TEXT NULL,
 deleted_at DATETIME NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_material_desc(description), INDEX idx_material_supplier(supplier), INDEX idx_material_date(purchase_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tickets (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_no VARCHAR(30) NOT NULL UNIQUE,
 occurred_at DATETIME NOT NULL,
 client_id INT UNSIGNED NOT NULL,
 complainant VARCHAR(190) NULL,
 request_text TEXT NOT NULL,
 service_type ENUM('remoto','presencial','vistoria','preventiva','orcamento') NOT NULL,
 notes TEXT NULL,
 status ENUM('aberto','pendente','solucionado','fechado') NOT NULL DEFAULT 'aberto',
 solution_text TEXT NULL,
 service_charged TINYINT(1) NOT NULL DEFAULT 0,
 service_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
 extra_visit_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
 materials_sold TINYINT(1) NOT NULL DEFAULT 0,
 billed_at DATETIME NULL,
 receivable_id INT UNSIGNED NULL,
 created_by INT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_ticket_client FOREIGN KEY(client_id) REFERENCES clients(id),
 CONSTRAINT fk_ticket_user FOREIGN KEY(created_by) REFERENCES users(id),
 INDEX idx_ticket_date(occurred_at), INDEX idx_ticket_status(status), INDEX idx_ticket_client(client_id), INDEX idx_ticket_billed(billed_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_materials (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_id INT UNSIGNED NOT NULL,
 material_id INT UNSIGNED NULL,
 description VARCHAR(190) NOT NULL,
 quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
 unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
 total_price DECIMAL(12,2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
 CONSTRAINT fk_tm_ticket FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
 CONSTRAINT fk_tm_material FOREIGN KEY(material_id) REFERENCES materials(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS receivables (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 client_id INT UNSIGNED NOT NULL,
 competence VARCHAR(7) NOT NULL,
 cycle_start DATE NOT NULL,
 cycle_end DATE NOT NULL,
 due_date DATE NOT NULL,
 monthly_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
 services_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
 materials_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
 extra_visits_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
 discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
 advances_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
 previous_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
 total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
 paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
 status ENUM('pendente','parcial','pago') NOT NULL DEFAULT 'pendente',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_receivable_client_comp(client_id,competence),
 CONSTRAINT fk_rec_client FOREIGN KEY(client_id) REFERENCES clients(id),
 INDEX idx_rec_due(due_date), INDEX idx_rec_status(status)
) ENGINE=InnoDB;

ALTER TABLE tickets ADD CONSTRAINT fk_ticket_receivable FOREIGN KEY(receivable_id) REFERENCES receivables(id);

CREATE TABLE IF NOT EXISTS payments (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 receivable_id INT UNSIGNED NOT NULL,
 payment_date DATE NOT NULL,
 amount DECIMAL(12,2) NOT NULL,
 payment_method ENUM('pix','debito','credito','dinheiro','boleto','transferencia') NOT NULL DEFAULT 'pix',
 notes TEXT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_pay_rec FOREIGN KEY(receivable_id) REFERENCES receivables(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS advances (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 client_id INT UNSIGNED NOT NULL,
 amount DECIMAL(12,2) NOT NULL,
 advance_date DATE NOT NULL,
 reference_text VARCHAR(190) NULL,
 consumed_at DATETIME NULL,
 receivable_id INT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_adv_client FOREIGN KEY(client_id) REFERENCES clients(id),
 CONSTRAINT fk_adv_rec FOREIGN KEY(receivable_id) REFERENCES receivables(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS discounts (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 client_id INT UNSIGNED NOT NULL,
 amount DECIMAL(12,2) NOT NULL,
 discount_date DATE NOT NULL,
 reference_text VARCHAR(190) NULL,
 consumed_at DATETIME NULL,
 receivable_id INT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_dis_client FOREIGN KEY(client_id) REFERENCES clients(id),
 CONSTRAINT fk_dis_rec FOREIGN KEY(receivable_id) REFERENCES receivables(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payables (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 due_date DATE NOT NULL,
 description VARCHAR(190) NOT NULL,
 category VARCHAR(120) NULL,
 supplier VARCHAR(190) NULL,
 total_amount DECIMAL(12,2) NOT NULL,
 payment_method ENUM('pix','debito','credito','dinheiro','boleto','transferencia') NOT NULL DEFAULT 'pix',
 installments INT UNSIGNED NOT NULL DEFAULT 1,
 current_installment INT UNSIGNED NOT NULL DEFAULT 1,
 installment_value DECIMAL(12,2) NOT NULL DEFAULT 0,
 status ENUM('pendente','pago') NOT NULL DEFAULT 'pendente',
 paid_at DATE NULL,
 notes TEXT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_payable_due(due_date), INDEX idx_payable_supplier(supplier), INDEX idx_payable_category(category)
) ENGINE=InnoDB;
