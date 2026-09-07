# GMB Help-Desk ERP — PHP + MySQL

Base funcional do sistema em PHP 8.2+, MySQL/MariaDB, Bootstrap 5 e Chart.js.

## Módulos incluídos
- Login e perfis Administrador, Técnico e Financeiro
- Clientes e contratos
- Materiais
- Chamados com serviço e material vendido
- Regras básicas de contrato: remoto sem cobrança, vistoria/orçamento sem cobrança e 1ª visita presencial gratuita por ciclo
- Contas a receber, fechamento do ciclo 16–15 e pagamento parcial
- Contas a pagar
- Dashboard anual
- Relatórios CSV compatíveis com Excel
- Tema claro/escuro

## Credenciais iniciais
- E-mail: `admin@gmb.local`
- Senha: `admin123`

## Requisitos
- PHP 8.2+
- MySQL 8 ou MariaDB 10.6+
- Apache com mod_rewrite opcional

## Banco
Importe nesta ordem:
1. `sql/schema.sql`
2. `sql/seed.sql`

## Configuração
Edite `config/config.php` se necessário. O padrão é:
- Banco: `gmb_helpdesk`
- Usuário: `root`
- Senha: vazia
- URL base: `/gmb_helpdesk_php/public`

## Importante
Esta entrega é uma base funcional para homologação. Antes de uso financeiro em produção, valide regras de faturamento, permissões, backup, HTTPS, auditoria e rotina de fechamento com dados reais de teste.
