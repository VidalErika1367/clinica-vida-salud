$ErrorActionPreference = 'Continue'
Write-Host 'CLINICA VIDA SALUD - REVISION DEL EQUIPO'
Write-Host 'Este archivo solamente revisa programas. No instala ni modifica nada.'
$phpCmd = Get-Command php -ErrorAction SilentlyContinue
if ($phpCmd) {
    Write-Host "PHP en PATH: $($phpCmd.Source)"
    & $phpCmd.Source -v
    $modulos = @(& $phpCmd.Source -m)
    foreach ($modulo in @('pdo_pgsql', 'mbstring')) {
        if ($modulos -contains $modulo) { Write-Host "${modulo}: SI" } else { Write-Host "${modulo}: NO" }
    }
} elseif (Test-Path 'C:\xampp\php\php.exe') {
    Write-Host 'PHP encontrado en C:\xampp\php\php.exe'
    & 'C:\xampp\php\php.exe' -v
} else { Write-Host 'No se encontro PHP en PATH ni en la carpeta usual de XAMPP.' }
$pgCmd = Get-Command psql -ErrorAction SilentlyContinue
if ($pgCmd) { & $pgCmd.Source --version }
elseif (Test-Path 'C:\Program Files\PostgreSQL') { Get-ChildItem 'C:\Program Files\PostgreSQL' -Directory | ForEach-Object { Write-Host "PostgreSQL encontrado en $($_.FullName)" } }
else { Write-Host 'No se encontro PostgreSQL en las ubicaciones revisadas.' }
$codeCmd = Get-Command code -ErrorAction SilentlyContinue
if ($codeCmd) { Write-Host 'VS Code encontrado.' } else { Write-Host 'El comando code no esta en PATH; VS Code puede estar instalado igualmente.' }
Write-Host 'Fin de la revision.'
