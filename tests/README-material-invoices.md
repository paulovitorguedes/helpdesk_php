# Notas fiscais por compra

Execucao isolada, sem copiar configuracao ou dados reais:

- `php tests/materials.php <pasta-evidencias>`: 263 verificacoes HTTP/SQLite, incluindo 32 de NF e data padrao.
- `powershell -File tests/purchase-payables-mysql.ps1`: 117 verificacoes MariaDB novo em 13308, incluindo migracao NF sem modificar tabelas anteriores, PDF de 10 MiB com max_allowed_packet de 1 MiB, rollback, envio concorrente com exclusao, CHECK TABLE e dump/restauracao com bytes identicos.
- `node tests/material-invoices-feedback.js`: 44 verificacoes de preservacao do formulario em falha, sessao, rede, duplicidade e navegacao apenas no sucesso.

Cada compra admite varios PDFs imutaveis, com autor/data/nome/tamanho/SHA-256. Admin e Financeiro autorizados no servidor; Tecnico bloqueado. Validacao de extensao, MIME, assinatura e limite de 10 MiB; nao ha OCR nem conferencia da data impressa. Um arquivo PDF valido pode conter recursos ativos: o navegador recebe CSP restritiva e nosniff; nao ha garantia de sanitizacao interna do PDF.

Persistencia privada no MariaDB: metadados e partes binarias de ate 256 KiB, evitando modificar max_allowed_packet e garantindo inclusao/exclusao atomica por FK. Nao existe pasta publica de uploads. Downloads conferem hash antes de emitir bytes. O backup integral usa --hex-blob; restauracao sintetica verifica todas as tabelas por hash, inclusive partes binarias. Volume do banco cresce conforme os documentos; observar espaco e backups.

Anexar nao modifica contas, compras ou estoque, inclusive apos pagamento. Exclusao permitida do material sem venda/pagamento remove PDFs com a compra; bloqueio financeiro preserva documentos. Nova compra sem data usa hoje; datas de edicao e legado continuam sujeitas a validacao estrita.

Os testes da camada SQLite adaptam apenas FOR UPDATE; concorrencia e limites de pacote sao comprovados separadamente no MariaDB. Roteiros/fixtures nunca devem ser apontados para producao.
