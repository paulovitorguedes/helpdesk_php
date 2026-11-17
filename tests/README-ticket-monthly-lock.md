# Trava mensal de edicao de Chamados

Primeira subentrega preparatoria ao custo por origem. Sem migracao ou alteracao automatica de competencias/saldos. A regra geral antiga de bloquear qualquer recebimento, inclusive parcial, permanece nesta subentrega; sua liberacao exige validacao financeira conjunta e continua pendente.

A trava mensal resolve competencia por parcela/conta existente. Sem parcela propria, em chamado fechado/solucionado, usa o mesmo enquadramento por cliente e intervalo cycle_start/cycle_end utilizado na tela financeira atual. Nao usa o mes da abertura como competencia nem redistribui dados. Ciclos ausentes/sobrepostos ou vinculos inconsistentes exigem conferencia para alterar campos protegidos. Chamado aberto/pendente sem faturamento nao recebe competencia por suposicao, mesmo se ja houver parcelas apenas previstas sem vinculo ao recebivel.

Ao atingir o dia 01 do mes seguinte, somente Reclamante, Solicitacao e Solucao aplicada podem ser atualizados. Consulta e gravacao verificam no servidor; gravacao revalida sob bloqueio transacional, antes e depois das validacoes do formulario. O caminho textual nao recria itens/parcelas, nao muda estado, materiais, datas, estoque, pagamentos ou recebiveis. Campos protegidos ficam desabilitados na interface, mas a protecao nao depende de JavaScript.

Testes isolados:

- `php tests/materials.php <pasta-evidencias>`: 288 verificacoes HTTP/SQLite, incluindo regressao de compras, pagamentos e NF; 25 dirigidas a competencia mensal/textos.
- `powershell -File tests/purchase-payables-mysql.ps1`: 145 verificacoes MariaDB novo na porta 13308, incluindo os mesmos casos HTTP, encerramento concorrente, CHECK TABLE e restauracao de backup com PDFs/hex-blob e limite de pacote de 1 MiB.
- `node tests/ticket-edit-lock.js`: 16 verificacoes da disponibilidade dos tres textos e desabilitacao dos demais controles.

As suites congelam a data em 21/10/2026 apenas nas copias descartaveis do helper para manter as fixtures anteriores reproduziveis. O arquivo de producao usa a data atual no fuso configurado em bootstrap; nao possui variavel de ambiente ou parametro HTTP para alterar esse relogio. Testes diretos cobrem dia 30/09, 01/10, acesso tardio e mudanca de ano.

Esta entrega nao implementa fechamento financeiro completo, transporte de saldo anterior, bloqueio de pagamentos/descontos/estornos em Contas a Receber, custo por camadas, confirmacao de origem ou datas efetivas de status. A protecao mensal e dos chamados existentes. Criacao retroativa de cobranca e recalculo de ciclos pertencem aos proximos passos financeiros, sem ampliar este escopo por suposicao.
