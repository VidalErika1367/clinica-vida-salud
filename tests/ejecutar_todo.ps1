# Ejecuta cada batería sobre una base clinica_vida_salud_test recién creada y vacía.
# Uso (desde la raíz del proyecto): powershell -ExecutionPolicy Bypass -File tests\ejecutar_todo.ps1
# Nunca usa el puerto 8000 ni la base clinica_vida_salud: el servidor de pruebas escucha en 8011.
# Lee DB_HOST, DB_PORT, DB_USER y DB_PASSWORD de .env (o del entorno); solo cambia el nombre de la base.
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$port = 8011
$testDb = 'clinica_vida_salud_test'

# --- Configuración de la conexión (sin mostrar la contraseña) ---
$envFile = @{}
if (Test-Path "$root\.env") {
    foreach ($line in Get-Content "$root\.env" -Encoding UTF8) {
        if ($line -match '^\s*([A-Z_]+)\s*=\s*"?([^"#]*)"?\s*$') { $envFile[$Matches[1]] = $Matches[2].Trim() }
    }
}
function Get-Setting([string]$name, [string]$default = '') {
    $fromEnv = [Environment]::GetEnvironmentVariable($name)
    if ($fromEnv) { return $fromEnv }
    if ($envFile.ContainsKey($name) -and $envFile[$name]) { return $envFile[$name] }
    return $default
}
$dbHost = Get-Setting 'DB_HOST' '127.0.0.1'
$dbPort = Get-Setting 'DB_PORT' '5432'
$dbUser = Get-Setting 'DB_USER' 'postgres'
$dbPass = Get-Setting 'DB_PASSWORD'

# --- Herramientas ---
$php = if ($env:PHP_BIN) { $env:PHP_BIN } else { (Get-Command php -ErrorAction Stop).Source }
$python = (Get-Command python -ErrorAction Stop).Source
$node = (Get-Command node -ErrorAction Stop).Source
$psql = $env:PSQL_BIN
if (-not $psql) {
    $found = Get-ChildItem 'C:\Program Files\PostgreSQL\*\bin\psql.exe' -ErrorAction SilentlyContinue |
        Sort-Object { [int]($_.FullName -replace '.*PostgreSQL\\(\d+)\\.*', '$1') } -Descending | Select-Object -First 1
    $psql = if ($found) { $found.FullName } else { (Get-Command psql -ErrorAction Stop).Source }
}

# --- Entorno común de las pruebas ---
$env:PHP_BIN = $php
$env:PYTHONUTF8 = '1'
$env:PYTHONIOENCODING = 'utf-8'
$env:PGPASSWORD = $dbPass
$env:DB_HOST = $dbHost
$env:DB_PORT = $dbPort
$env:DB_NAME = $testDb
$env:DB_USER = $dbUser
$env:DB_PASSWORD = $dbPass
$env:APP_ENV = 'local'
$env:SESSION_SECURE = 'false'
$env:APP_URL = "http://127.0.0.1:$port"
$env:CLINICA_TEST_URL = "http://127.0.0.1:$port"
$env:TELEGRAM_BOT_TOKEN = '12345678:ficticioNoRealAAAAAAAAAAAAAAAA'
$env:TELEGRAM_BOT_USERNAME = 'vidasalud_prueba_bot'
$env:CLINICA_RECEPCION_WHATSAPP = 'Recepción Prueba Uno:59170000001,Recepción Prueba Dos:59170000002'  # CU30
$env:CLINICA_ADMIN_USER = 'admin_prueba'
$env:CLINICA_ADMIN_EMAIL = 'admin_prueba@example.test'
$env:CLINICA_ADMIN_PASSWORD = 'Prueba-' + [guid]::NewGuid().ToString('N') + '!'

if ($testDb -notlike '*_test') { throw 'La base de pruebas debe terminar en _test.' }
if (Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue) {
    throw "El puerto $port ya está en uso; no se inicia el servidor de pruebas."
}

function Invoke-Sql([string]$sql) {
    & $psql -h $dbHost -p $dbPort -U $dbUser -d postgres -v ON_ERROR_STOP=1 -q -c $sql | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "psql falló: $sql" }
}

$script:server = $null
function Stop-TestServer {
    if ($script:server -and -not $script:server.HasExited) {
        Stop-Process -Id $script:server.Id -Force -ErrorAction SilentlyContinue
        $script:server.WaitForExit(5000) | Out-Null
    }
    $script:server = $null
}

function Reset-Database {
    Invoke-Sql "DROP DATABASE IF EXISTS $testDb WITH (FORCE)"
    Invoke-Sql "CREATE DATABASE $testDb"
    & $php bin/instalar.php | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'bin/instalar.php falló.' }
    & $php bin/crear_admin.php | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'bin/crear_admin.php falló.' }
    Get-ChildItem "$root\storage\acceso-*.json" -ErrorAction SilentlyContinue | Remove-Item -Force
}

function Start-TestServer {
    $script:server = Start-Process -FilePath $php -ArgumentList @('-S', "127.0.0.1:$port", '-t', 'public', 'router.php') `
        -WorkingDirectory $root -PassThru -WindowStyle Hidden
    for ($i = 0; $i -lt 50; $i++) {
        try { Invoke-WebRequest "http://127.0.0.1:$port/ingresar" -UseBasicParsing -TimeoutSec 2 | Out-Null; return } catch { Start-Sleep -Milliseconds 200 }
    }
    throw 'El servidor de pruebas no respondió.'
}

$batteries = @(
    @{ Name = 'http_smoke'; File = 'tests\http_smoke.py'; Report = 'tests\resultados_validacion.json'; SetReport = $true },
    @{ Name = 'cu16_20'; File = 'tests\cu16_20_smoke.py'; Report = 'tests\resultados_validacion_cu16_20.json' },
    @{ Name = 'cu21_25'; File = 'tests\cu21_25_smoke.py'; Report = 'tests\resultados_validacion_cu21_25.json' },
    @{ Name = 'cu26_30'; File = 'tests\cu26_30_smoke.py'; Report = 'tests\resultados_validacion_cu26_30.json' },
    @{ Name = 'ajustes'; File = 'tests\ajustes_smoke.py'; Report = 'tests\resultados_validacion_ajustes.json' },
    @{ Name = 'menu_movil'; File = 'tests\menu_movil_smoke.py'; Report = 'tests\resultados_validacion_menu_movil.json' }
)
$summary = @()
$failed = $false

try {
    foreach ($b in $batteries) {
        Write-Host "=== $($b.Name) ===" -ForegroundColor Cyan
        Stop-TestServer
        Reset-Database
        Start-TestServer
        if ($b.SetReport) { $env:CLINICA_TEST_REPORT = "$root\$($b.Report)" } else { Remove-Item Env:CLINICA_TEST_REPORT -ErrorAction SilentlyContinue }
        $log = & $python $b.File 2>&1 | ForEach-Object { "$_" }
        $code = $LASTEXITCODE
        $log | Select-Object -Last 15 | ForEach-Object { Write-Host $_ }
        $passed = 0
        if (Test-Path "$root\$($b.Report)") {
            $passed = (Get-Content "$root\$($b.Report)" -Raw -Encoding UTF8 | ConvertFrom-Json).passed
        }
        if ($code -ne 0) { $failed = $true }
        $summary += [pscustomobject]@{ Bateria = $b.Name; Comprobaciones = $passed; Estado = $(if ($code -eq 0) { 'OK' } else { "FALLO ($code)" }) }
    }
}
finally {
    Stop-TestServer
}

Write-Host '=== unit.php ===' -ForegroundColor Cyan
$unit = & $php tests/unit.php 2>&1 | ForEach-Object { "$_" }
$unitCode = $LASTEXITCODE
$unit | Select-Object -Last 3 | ForEach-Object { Write-Host $_ }
$summary += [pscustomobject]@{ Bateria = 'unit.php'; Comprobaciones = ($unit | Select-Object -Last 1); Estado = $(if ($unitCode -eq 0) { 'OK' } else { "FALLO ($unitCode)" }) }
if ($unitCode -ne 0) { $failed = $true }

foreach ($mjs in 'counter_browser', 'report_filters_browser', 'mobile_menu_browser') {
    Write-Host "=== $mjs.mjs ===" -ForegroundColor Cyan
    $out = & $node "tests\$mjs.mjs" 2>&1 | ForEach-Object { "$_" }
    $code = $LASTEXITCODE
    $out | Select-Object -Last 3 | ForEach-Object { Write-Host $_ }
    $summary += [pscustomobject]@{ Bateria = "$mjs.mjs"; Comprobaciones = ($out | Select-Object -Last 1); Estado = $(if ($code -eq 0) { 'OK' } else { "FALLO ($code)" }) }
    if ($code -ne 0) { $failed = $true }
}

Write-Host ''
Write-Host '=== Total por batería (cada una incluye las anteriores de la cadena) ===' -ForegroundColor Cyan
$summary | Format-Table -AutoSize | Out-String | Write-Host
if ($failed) { Write-Host 'HAY FALLOS.' -ForegroundColor Red; exit 1 }
Write-Host 'Todo correcto.' -ForegroundColor Green
