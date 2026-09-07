USE gmb_helpdesk;
-- senha: admin123
INSERT INTO users(name,email,password_hash,role,active)
SELECT 'Administrador','admin@gmb.local','$2y$12$RgEafHyooQtnmrqn42OOzOnnp.0Nd2rSwhT6OIGe1seET43uXSF0e','admin',1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email='admin@gmb.local');

INSERT INTO clients(name,document,phone,email,status,has_contract,monthly_fee,extra_visit_fee,due_day,notes)
SELECT 'Cliente Demonstração Contrato','12.345.678/0001-99','(31) 99999-0001','contrato@demo.local','ativo',1,500,150,20,'Cliente de demonstração'
WHERE NOT EXISTS (SELECT 1 FROM clients WHERE name='Cliente Demonstração Contrato');
INSERT INTO clients(name,document,phone,email,status,has_contract,monthly_fee,extra_visit_fee,due_day,notes)
SELECT 'Cliente Demonstração Avulso','123.456.789-00','(31) 99999-0002','avulso@demo.local','ativo',0,0,0,20,'Cliente sem contrato'
WHERE NOT EXISTS (SELECT 1 FROM clients WHERE name='Cliente Demonstração Avulso');

INSERT INTO materials(purchase_date,description,cost_price,supplier,payment_method,notes)
SELECT CURDATE(),'Cabo de Rede CAT6',25.00,'Fornecedor Demo','pix','Material de demonstração'
WHERE NOT EXISTS (SELECT 1 FROM materials WHERE description='Cabo de Rede CAT6');
