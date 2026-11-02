# Verificacao de permissoes

Execute `php tests/authorization.php` pela linha de comando.

O teste copia apenas os arquivos PHP de public/src para um diretorio temporario,
cria configuracao ficticia e sessoes sinteticas e inicia um servidor local temporario.
Nao le a configuracao real do banco e nao conecta ao banco da aplicacao.
Nenhum arquivo e criado em public da instalacao real. O servidor e encerrado ao final.

Um argumento opcional informa um diretorio existente para receber
`resultado-autorizacao.json`; sem argumento, as evidencias ficam na pasta temporaria.
Os arquivos temporarios contem apenas codigo, configuracao ficticia, logs e sessoes de teste.

Cobertura: matriz de autorizacao, menu, redirecionamentos e recusas GET/POST
das paginas reais antes de acesso ao banco. Nao comprova login com senha,
persistencia de operacoes permitidas ou regras financeiras; homologar esses fluxos separadamente.
