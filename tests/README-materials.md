# Regras basicas de Materiais

Executar por CLI: `php tests/materials.php [diretorio-de-evidencias]`.

A suite copia as paginas e helpers reais para pasta temporaria, substitui somente a conexao por SQLite e cria registros ficticios. Nao le/copia config/config.php e nao se conecta ao banco de producao. O roteador aceita apenas cli-server e variaveis exclusivas de teste.

Cobertura: inteiros e limites das colunas, datas validas, cronologia por material com igualdade permitida, exclusao de material comprado sem venda com remocao de compras/entradas, bloqueio por uso/saida historica mesmo apos devolucao ou remocao do item, atomicidade em falha de gravacao/exclusao, perfis/CSRF, venda gratuita, baixa em chamado aberto, ajustes/repeticao/cancelamento e saldo insuficiente. Verifica preservacao de recebiveis/pagaveis manuais da fixture: ainda nao existe vinculo entre compra e obrigacao na aplicacao atual.

O adaptador remove FOR UPDATE somente na copia de teste porque SQLite nao suporta essa clausula. Esta suite nao comprova concorrencia do MariaDB. Em producao, nova compra e exclusao bloqueiam a mesma linha de material usada pelos chamados. Nao ha migracao ou alteracao de registros legados nesta entrega.

Concorrencia real: executar `tests/materials-mysql.ps1` no PowerShell com XAMPP em C:/xampp. O script inicializa um MariaDB novo em pasta temporaria, sem instalar servico e sem copiar dados/configuracao reais. Usa somente 127.0.0.1:13308 e encerra o servidor ao final. A suite `materials-mysql.php` exige variaveis exclusivas do teste e verifica duas operacoes concorrentes por processos distintos: compra posterior que invalida data de outra compra e saida que impede exclusao concorrente. Tambem verifica exclusao com compra sem venda e integridade de FK. Nao executar essa suite contra uma instancia pessoal ou de producao; a porta e exclusiva da instancia nova criada pelo script.

Integracao automatica com Contas a Pagar, edicao da ultima compra, custos por origem, regras financeiras de cancelamento e PDFs de NF pertencem a entregas seguintes, com testes e validacoes proprios.

Atualizacao: edicao da ultima compra agora e coberta por `materials-purchase-edit-cases.php` (HTTP/SQLite) e `materials-purchase-edit-mysql-cases.php` (MariaDB novo). A suite HTTP executa 111 verificacoes; a de MariaDB, 25. Confere correcao de data sem ultrapassar precedente, igualdade, quantidade/custo/entrada atomicos, protecao apos saida/devolucao, campos textuais, formulario antigo, compra concorrente, venda concorrente, rollback, preservacao do custo legado ao corrigir somente data e limites decimais reais. Integracao financeira, registro/bloqueio por pagamentos e NF permanecem para as subentregas seguintes; esta entrega nao consulta nem altera Contas a Pagar.

Exclusao sem sair do formulario: a suite testa resposta JSON 409 sem Location/flash adicional quando bloqueada, invariantes dos dados, sucesso JSON pelo Financeiro e CSRF 419 preservado. O caminho HTML tradicional permanece disponivel como fallback. A confirmacao existente continua exigida antes do envio pelo navegador.

Previa visual: `php tests/materials.php --serve` inicia somente ambiente ficticio por ate 30 minutos e informa URL/arquivo de parada. A rota de login sintetico so existe no roteador de teste quando a variavel exclusiva de preview esta ativa. Nao e instalada em public nem usada no banco real.
