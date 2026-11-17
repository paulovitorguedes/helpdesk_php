param([string]$EvidenceDirectory = (Join-Path ([System.IO.Path]::GetTempPath()) ('helpdesk-financial-mariadb-' + [guid]::NewGuid().ToString('N'))), [switch]$Preview)
$ErrorActionPreference='Stop'
$testRoot=$EvidenceDirectory
if(Test-Path -LiteralPath $testRoot){throw 'Diretorio de teste ja existe; nao sobrescrever.'}
$connection=[System.Net.Sockets.TcpClient]::new()
try {$connection.Connect('127.0.0.1',13308);throw 'Porta de teste ocupada.'} catch [System.Net.Sockets.SocketException] {} finally {$connection.Dispose()}
New-Item -ItemType Directory -Path $testRoot | Out-Null
$testData=Join-Path $testRoot 'data'
& 'C:\xampp\mysql\bin\mysql_install_db.exe' "--datadir=$testData" '--port=13308' '--silent'
if($LASTEXITCODE -ne 0){throw 'Inicializacao de banco novo falhou.'}
$testArguments=@('--no-defaults','--basedir=C:/xampp/mysql',"--datadir=$testData",'--port=13308','--bind-address=127.0.0.1','--event-scheduler=OFF','--skip-slave-start','--skip-log-bin','--max-allowed-packet=1M','--console')
$server=Start-Process -FilePath 'C:\xampp\mysql\bin\mysqld.exe' -ArgumentList $testArguments -WorkingDirectory $testRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $testRoot 'server-stdout.log') -RedirectStandardError (Join-Path $testRoot 'server-stderr.log')
try {
    $ready=$false
    for($attempt=0;$attempt -lt 40;$attempt++){
        Start-Sleep -Milliseconds 500
        $server.Refresh();if($server.HasExited){break}
        $connection=[System.Net.Sockets.TcpClient]::new()
        try{$connection.Connect('127.0.0.1',13308);$ready=$true}catch{}finally{$connection.Dispose()}
        if($ready){break}
    }
    if(!$ready){throw 'Servidor isolado nao iniciou.'}
    $env:HELPDESK_MATERIAL_MYSQL_TEST_PORT='13308'
    $env:HELPDESK_MATERIAL_MYSQL_TEST_FOLDER=$testRoot
    $phpArguments=@((Join-Path $PSScriptRoot 'purchase-payables-mysql.php'))
    if($Preview){$phpArguments+='--serve'}
    & 'C:\xampp\php\php.exe' @phpArguments
    if($LASTEXITCODE -ne 0){throw 'Teste de concorrencia falhou.'}
    if(!$server.WaitForExit(10000)){throw 'Servidor isolado nao encerrou normalmente.'}
} finally {
    Remove-Item Env:HELPDESK_MATERIAL_MYSQL_TEST_PORT -ErrorAction SilentlyContinue
    Remove-Item Env:HELPDESK_MATERIAL_MYSQL_TEST_FOLDER -ErrorAction SilentlyContinue
    $server.Refresh()
    if(!$server.HasExited){Stop-Process -InputObject $server}
}
