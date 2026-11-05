# Integracao de compras e Contas a Pagar

`php tests/materials.php <pasta-evidencias>` executa 162 verificacoes HTTP/SQLite, incluindo geracao e configuracao, sincronizacao, total manual, exclusao conjunta, marcadores de pagamento, formularios antigos, perfis/CSRF e rollback. Fixture financeira foi ampliada; contas manuais preexistentes continuam sendo conferidas separadamente.

`tests/purchase-payables-mysql.ps1` cria uma instancia NOVA em 127.0.0.1:13308. Exige porta livre. Nao le config/config.php real nem copia registros reais. Usa somente estrutura das dezesseis tabelas da referencia e dados sinteticos. Ensaia migracao SQL, preservacao do legado, concorrencia real, rollback, paginas reais via HTTP e exportacao/restauracao em segundo banco, com hashes iguais. Sao 37 verificacoes. Opcao `-Preview` mantem uma previa sintetica ate criar o arquivo stop informado ou por no maximo 30 minutos; nao e rota de login da aplicacao real. Instancia e servidor HTTP encerrados ao final.

`tests/materials-mysql.ps1` continua testando os 25 cenarios de estoque/edicao concorrente em outra execucao exclusiva da mesma porta. Nao executar as duas suites simultaneamente. Regressao complementar: autorizacao (287), Clientes (48) e preservacao do formulario em JavaScript (44). Total tecnico desta entrega: 603 verificacoes aprovadas.

SQL de migracao: `sql/migrations/20261105_purchase_payables.sql`. Aplicar somente em implantacao preparada, com backup completo e conferencia do esquema. Nao executar contra producao para rodar testes.

Limite: esta entrega nao cria tela de baixa de pagamentos parciais, estorno, juros, multa ou comprovantes. Protege compra/exclusao se conta vinculada ja estiver marcada paga ou possuir data de pagamento, mesmo mantendo status pendente. Regras completas do registro de pagamentos continuam na proxima entrega. Contas vinculadas so podem ser excluidas junto do material elegivel, sem pagamentos/vendas. Compras antigas nao recebem vinculo retroativo.
