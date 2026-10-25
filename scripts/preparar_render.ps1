param(
    [Parameter(Mandatory=$true)]
    [string]$ProjectPath
)
$ErrorActionPreference = 'Stop'
$sourceRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$targetRoot = (Resolve-Path -LiteralPath $ProjectPath).Path
if ($sourceRoot.TrimEnd('\') -eq $targetRoot.TrimEnd('\')) {
    throw 'Extrae el paquete en otra carpeta y usa como destino tu proyecto original.'
}
foreach ($required in @('bootstrap.php','public\index.php','src\Migrator.php','.env')) {
    if (-not (Test-Path -LiteralPath (Join-Path $targetRoot $required) -PathType Leaf)) {
        throw "No es la carpeta del proyecto instalado: falta $required."
    }
}
$relativeFiles = @(
    'Dockerfile', '.dockerignore', '.gitattributes', 'render.yaml', 'DESPLEGAR_RENDER.md',
    'deploy\apache.conf', 'deploy\ports.conf', 'deploy\php.ini', 'deploy\start.sh',
    'bin\preparar_servidor.php', 'scripts\preparar_render.ps1'
)
$backupRoot = Join-Path $targetRoot ('storage\respaldo-render-' + (Get-Date -Format 'yyyyMMdd-HHmmss-fff'))
$plan = @()
foreach ($relative in $relativeFiles) {
    $source = Join-Path $sourceRoot $relative
    if (-not (Test-Path -LiteralPath $source -PathType Leaf)) { throw "El paquete esta incompleto: falta $relative." }
    $destination = Join-Path $targetRoot $relative
    $backup = Join-Path $backupRoot $relative
    $existed = Test-Path -LiteralPath $destination -PathType Leaf
    if ($existed) {
        New-Item -ItemType Directory -Path (Split-Path $backup) -Force | Out-Null
        Copy-Item -LiteralPath $destination -Destination $backup -Force
    }
    $plan += [PSCustomObject]@{Source=$source; Destination=$destination; Backup=$backup; Existed=$existed}
}
$copied = @()
try {
    foreach ($item in $plan) {
        New-Item -ItemType Directory -Path (Split-Path $item.Destination) -Force | Out-Null
        $copied += $item
        Copy-Item -LiteralPath $item.Source -Destination $item.Destination -Force
    }
} catch {
    foreach ($item in $copied) {
        if ($item.Existed) {
            Copy-Item -LiteralPath $item.Backup -Destination $item.Destination -Force
        } elseif (Test-Path -LiteralPath $item.Destination -PathType Leaf) {
            Remove-Item -LiteralPath $item.Destination
        }
    }
    throw
}
Write-Host 'Archivos para Render preparados correctamente.'
Write-Host 'Se conservan .env, el repositorio de Git y la base de datos local.'
Write-Host 'El siguiente paso es subir estos archivos con git add, git commit y git push.'
