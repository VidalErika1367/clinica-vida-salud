[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$ProjectPath)
$ErrorActionPreference='Stop'
$sourceRoot=Split-Path -Parent $PSScriptRoot
$targetRoot=(Resolve-Path -LiteralPath $ProjectPath).Path
if ($sourceRoot -eq $targetRoot) { throw 'Ejecuta el actualizador desde la carpeta extraida en Descargas.' }
foreach ($required in @('bootstrap.php','.env')) {
    if (!(Test-Path -LiteralPath (Join-Path $targetRoot $required) -PathType Leaf)) {
        throw 'ProjectPath no corresponde al proyecto original configurado.'
    }
}
function Get-NormalizedHash([string]$Path) {
    $value=([IO.File]::ReadAllText($Path,[Text.Encoding]::UTF8)).Replace(([string][char]13+[char]10),[string][char]10)
    $sha=[Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($value)))).Replace('-','').ToLowerInvariant() }
    finally { $sha.Dispose() }
}
$files=[ordered]@{
    'views\layout.php' = @{ Old='75a2df13aa012ca22dd69212b47658de20d1553f29618b895e2c7a2cc41a5262'; New='a4fbce4e6fb631788a9442089a1ad669a6491b9a3cd48ebcbcd361720cbce8e7' }
    'public\assets\app.css' = @{ Old='c3ad3112a2e705ed5bbc90a413d6b47c007b3071cb0359e609b24451caf3bad8'; New='17457d40cd667838c8ec8bed2f27a5651c02be4f272e98ff4dc7332c6d0875cf' }
    'public\assets\app.js' = @{ Old='61106f190261adf2e9c89b70025edc42f366ec910472b87503e495ae96a3d230'; New='1bee98dff74230aee662ac4d7d9ae3d5bf9f3c7d2aee179731e766a072b71678' }
}
foreach ($relative in $files.Keys) {
    $source=Join-Path $sourceRoot $relative
    $target=Join-Path $targetRoot $relative
    if (!(Test-Path -LiteralPath $source -PathType Leaf) -or !(Test-Path -LiteralPath $target -PathType Leaf)) {
        throw "Falta el archivo requerido: $relative"
    }
    if ((Get-NormalizedHash $source) -ne $files[$relative].New) { throw "El paquete no supera la comprobacion: $relative" }
    $actual=Get-NormalizedHash $target
    if ($actual -ne $files[$relative].Old -and $actual -ne $files[$relative].New) {
        throw "Hay cambios propios en $relative. Se detuvo la copia para conservarlos."
    }
}
$backupRoot=Join-Path $targetRoot ('storage\respaldo-menu-'+(Get-Date -Format 'yyyyMMdd-HHmmss-fff'))
New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null
foreach ($relative in $files.Keys) {
    $backup=Join-Path $backupRoot $relative
    New-Item -ItemType Directory -Path (Split-Path -Parent $backup) -Force | Out-Null
    Copy-Item -LiteralPath (Join-Path $targetRoot $relative) -Destination $backup -Force
}
try {
    foreach ($relative in $files.Keys) {
        Copy-Item -LiteralPath (Join-Path $sourceRoot $relative) -Destination (Join-Path $targetRoot $relative) -Force
        if ((Get-NormalizedHash (Join-Path $targetRoot $relative)) -ne $files[$relative].New) {
            throw "No se pudo verificar la copia: $relative"
        }
    }
} catch {
    foreach ($relative in $files.Keys) {
        Copy-Item -LiteralPath (Join-Path $backupRoot $relative) -Destination (Join-Path $targetRoot $relative) -Force
    }
    throw
}
Write-Host 'Menu movil actualizado. Se conservaron la configuracion y las bases de datos.'
Write-Host "Respaldo de los tres archivos: $backupRoot"
Write-Host 'Inicia PHP y actualiza la pagina del celular. El boton Menu debe mostrar y ocultar las opciones.'

