param(
    [Parameter(Mandatory=$true)]
    [string]$ProjectPath
)
$ErrorActionPreference = 'Stop'
$sourceRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$targetRoot = (Resolve-Path -LiteralPath $ProjectPath).Path
if ($sourceRoot.TrimEnd('\') -eq $targetRoot.TrimEnd('\')) {
    throw 'Extrae el paquete nuevo en otra carpeta y usa como destino el proyecto que ya funciona.'
}
foreach ($required in @('bootstrap.php','.env','database\migrations\001_esquema.sql')) {
    if (-not (Test-Path -LiteralPath (Join-Path $targetRoot $required) -PathType Leaf)) {
        throw "No es la carpeta del proyecto instalado: falta $required."
    }
}
foreach ($migration in @('001_esquema.sql','002_reglas.sql','003_catalogos.sql','004_acceso.sql')) {
    $relative = Join-Path 'database\migrations' $migration
    $original = Get-FileHash -LiteralPath (Join-Path $targetRoot $relative) -Algorithm SHA256
    $provided = Get-FileHash -LiteralPath (Join-Path $sourceRoot $relative) -Algorithm SHA256
    if ($original.Hash -ne $provided.Hash) {
        throw 'Los scripts de la base son distintos. Revisa el proyecto antes de actualizar su codigo.'
    }
}
# Solo se reemplaza codigo; no se lee ni se copia .env, storage o la base.
$codeFolders = @('src','views','public','config','bin','tests','scripts')
$files = @()
foreach ($folder in $codeFolders) {
    $files += Get-ChildItem -LiteralPath (Join-Path $sourceRoot $folder) -File -Recurse |
        Where-Object { $_.Extension -ne '.pyc' -and $_.FullName -notmatch '[\\/]__pycache__[\\/]' }
}
foreach ($name in @('bootstrap.php','router.php','README.md','VALIDACION.md','TRAZABILIDAD.md','ACTUALIZAR.md')) {
    $files += Get-Item -LiteralPath (Join-Path $sourceRoot $name)
}
$backupRoot = Join-Path $targetRoot ('storage\respaldo-codigo-' + (Get-Date -Format 'yyyyMMdd-HHmmss-fff'))
$plan = @()
foreach ($file in $files) {
    $relative = $file.FullName.Substring($sourceRoot.Length).TrimStart([char[]]@('\','/'))
    $destination = Join-Path $targetRoot $relative
    $backup = Join-Path $backupRoot $relative
    $existed = Test-Path -LiteralPath $destination -PathType Leaf
    if ($existed) {
        New-Item -ItemType Directory -Path (Split-Path $backup) -Force | Out-Null
        Copy-Item -LiteralPath $destination -Destination $backup -Force
    }
    $plan += [PSCustomObject]@{Source=$file.FullName; Destination=$destination; Backup=$backup; Existed=$existed}
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
Write-Host 'Codigo actualizado. Se conserva la configuracion y la misma base de datos.'
Write-Host "Respaldo del codigo anterior: $backupRoot"
Write-Host 'Vuelve a iniciar PHP. Abre Consultar disponibilidad y Citas con una cuenta autorizada.'
