# Instalação no Windows com XAMPP

## 1. Instalar o XAMPP
Instale uma versão atual do XAMPP com PHP 8.2 ou superior.

## 2. Copiar a pasta do sistema
Extraia `gmb_helpdesk_php` para:

`C:\xampp\htdocs\gmb_helpdesk_php`

A estrutura deve ficar semelhante a:

`C:\xampp\htdocs\gmb_helpdesk_php\public\index.php`

## 3. Iniciar Apache e MySQL
Abra o **XAMPP Control Panel** e clique em **Start** para:
- Apache
- MySQL

## 4. Criar o banco
Abra no navegador:

`http://localhost/phpmyadmin`

Vá em **Importar** e importe primeiro:

`C:\xampp\htdocs\gmb_helpdesk_php\sql\schema.sql`

Depois importe:

`C:\xampp\htdocs\gmb_helpdesk_php\sql\seed.sql`

## 5. Conferir configuração do banco
Abra:

`C:\xampp\htdocs\gmb_helpdesk_php\config\config.php`

No XAMPP padrão normalmente será:

- host: `127.0.0.1`
- banco: `gmb_helpdesk`
- usuário: `root`
- senha: vazia

## 6. Acessar o sistema
Abra:

`http://localhost/gmb_helpdesk_php/public/`

Login inicial:
- Usuário: `admin@gmb.local`
- Senha: `admin123`

## 7. Erros comuns
### Erro “could not find driver”
Ative `extension=pdo_mysql` no `php.ini` e reinicie o Apache.

### Erro “Access denied for user”
Confira usuário e senha do MySQL em `config/config.php`.

### Página não encontrada
Confirme se a pasta está em `C:\xampp\htdocs\gmb_helpdesk_php` e se existe `public\index.php`.

## 8. Testes recomendados
1. Criar cliente com contrato e mensalidade de R$ 500.
2. Criar cliente sem contrato.
3. Criar chamado remoto para cliente com contrato e confirmar cobrança zero.
4. Criar duas visitas presenciais no mesmo ciclo e validar cobrança apenas da segunda.
5. Adicionar material vendido a um chamado.
6. Solucionar/fechar os chamados.
7. Em Contas a Receber, fechar o ciclo usando o dia 15.
8. Confirmar mensalidade, serviços, materiais e visitas.
9. Registrar pagamento parcial e validar saldo/status.
10. Conferir o Dashboard.
