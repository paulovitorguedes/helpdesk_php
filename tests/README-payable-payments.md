# Pagamentos parciais e estornos

Regras confirmadas: saldo por parcela e pagamentos parciais; dados financeiros protegidos desde o primeiro pagamento; centavos restantes na ultima parcela; estorno com motivo/historico e protecao permanente.

- `php tests/materials.php <pasta-evidencias>`: 231 verificacoes HTTP/SQLite, incluindo pagamentos, quitacao, centavos, repeticao, CSRF/perfis, textos, estornos e rollback; contempla contas vinculadas e contas manuais.
- `tests/purchase-payables-mysql.ps1`: 75 verificacoes em MariaDB NOVO, exclusivo na porta 13308. Usa estrutura de referencia sem registros reais. Inclui migrações, preservacao sintetica, paginas reais, concorrencia entre dois pagamentos, envio repetido, dois estornos, pagamento versus exclusao e contas independentes, rollback e backup/restauracao com hashes iguais.
- Regressao complementar: 25 verificacoes de estoque/edicao em MariaDB isolado, 287 de autorizacao, 48 de Clientes, 44 de JavaScript da compra e 44 de JavaScript dos pagamentos. Total: 754 verificacoes aprovadas.

Nao executar suites MariaDB simultaneamente, nem apontar testes para producao. Nenhuma configuracao/credencial/linha real e copiada. Opcao -Preview oferece somente dados ficticios e encerra ao criar o arquivo stop informado (ou apos 30 minutos).

Contas com parcela atual avancada ou marcador antigo sem extrato exigem revisao explicita antes de novas baixas. Migracao nao cria pagamentos antigos nem considera parcelas anteriores quitadas por deducao. Dashboard conserva criterio de vencimento; pagamento nao acrescenta uma segunda despesa.

Calculos de baixa usam centavos inteiros. Plano preserva o valor-base configurado e ajusta a diferenca do total na ultima parcela. Plano cujo valor-base ultrapasse o total requer revisao, sem pagamento automatico. Pagamentos/estornos bloqueiam material e conta na mesma ordem; leituras atuais e READ COMMITTED somente na transacao da operacao evitam saldos antigos/conflitos entre contas independentes. Nenhuma configuracao global de isolamento e alterada.

Extrato possui valor/data/forma/observacao/autor, identificacao unica de requisicao e dados de estorno. Valor original nao e apagado ou reescrito pelo estorno. Bloqueio historico continua ativo mesmo quando todas as baixas forem estornadas. Arquivos SQL de migracao devem ser aplicados somente em implantacao revisada e com backup.
