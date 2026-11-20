<?php /* Vista para imprimir o guardar como PDF el reporte administrativo (CU20). Sin menú ni formularios.
   La renderiza ReportExportController::imprimir: recibe $titulo, $filtrosTexto, $generado, $usuario, $cabecera, $filas y $totales.
   El CSS de impresión está en app.css y el botón lo maneja app.js (data-print-report): la CSP no permite scripts en línea. */ ?>
<!doctype html>
<html lang="es-BO">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> · Clínica Vida Salud</title>
    <link rel="icon" type="image/svg+xml" href="/assets/marca.svg">
    <link rel="stylesheet" href="/assets/app.css?v=<?= e(substr(hash_file('sha256',BASE_PATH.'/public/assets/app.css'),0,12)) ?>">
    <script src="/assets/app.js?v=<?= e(substr(hash_file('sha256',BASE_PATH.'/public/assets/app.js'),0,12)) ?>" defer></script>
</head>
<body class="print-report">
<main class="print-doc">
    <header class="print-head">
        <img src="/assets/marca.svg" alt="" width="44" height="44">
        <div>
            <strong>Clínica Vida Salud</strong>
            <span>Reporte administrativo</span>
        </div>
        <button class="button primary no-print" type="button" data-print-report>Imprimir / Guardar como PDF</button>
    </header>

    <h1><?= e($titulo) ?></h1>
    <dl class="print-meta">
        <?php foreach ($filtrosTexto as [$etiqueta, $valor]): ?>
        <div><dt><?= e($etiqueta) ?></dt><dd><?= e($valor) ?></dd></div>
        <?php endforeach; ?>
        <div><dt>Generado</dt><dd><?= e($generado) ?> · Hora de Bolivia</dd></div>
        <div><dt>Generado por</dt><dd><?= e($usuario) ?></dd></div>
    </dl>

    <table class="print-table">
        <thead>
            <tr><?php foreach ($cabecera as $titulo_columna): ?><th scope="col"><?= e($titulo_columna) ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
            <?php if (!$filas): ?>
            <tr><td colspan="<?= count($cabecera) ?>">No hay registros para estos filtros.</td></tr>
            <?php endif; ?>
            <?php foreach ($filas as $fila): ?>
            <tr><?php foreach ($fila as $celda): ?><td><?= e($celda) ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr><?php foreach ($totales as $celda): ?><td><?= e($celda) ?></td><?php endforeach; ?></tr>
        </tfoot>
    </table>
</main>
</body>
</html>
