# Teste do formulario de Clientes

Execute `php tests/clients.php` pela linha de comando. Requer PDO SQLite, disponivel
no PHP local usado nesta entrega. Um argumento opcional informa uma pasta existente
para receber `resultado-clientes.json`.

O teste copia codigo para uma pasta temporaria, cria clientes e recebiveis ficticios
em SQLite e substitui a conexao apenas nessa copia. Nao le configuracao de producao,
nao conecta ao MySQL/MariaDB real e nao altera dados reais.

Verifica normalizacao, moeda brasileira, campos obrigatorios, permissao de perfil,
CSRF, duplicidade, preservacao de dados de contrato e do vencimento legado sem contrato.
O teste SQLite nao substitui homologacao na instalacao com MariaDB.

`php tests/clients.php --serve` abre previa temporaria com dados ficticios por ate
30 minutos; a saida informa URL e arquivo de parada. Somente o roteador de testes
oferece o acesso sintetico; nenhum endpoint de teste e instalado em public da aplicacao.

O teste de permissoes continua disponivel em `php tests/authorization.php`.
