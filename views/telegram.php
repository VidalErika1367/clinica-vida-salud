<div class="page-heading"><div><span class="eyebrow">AVISOS PERSONALES</span><h1>Avisos por Telegram</h1><p>Vincula tu chat privado para recibir avisos de la clínica.</p></div></div>
<?php require BASE_PATH.'/views/_appointment_errors.php'; ?>
<section class="card form-card">
<?php if ($binding && $binding['activo']): ?><h2>Telegram vinculado</h2><p>Autorización registrada el <?= e(clinic_datetime($binding['consentido_en'])) ?>.</p><form method="post" action="/telegram/desactivar"><?= csrf_field() ?><button class="button quiet" type="submit">Desactivar avisos por Telegram</button></form>
<?php else: ?><h2>Telegram sin vincular</h2><p>Tus avisos siguen disponibles en la web.</p><?php endif; ?>
<?php if (!$configured): ?><p class="alert warning">La clínica todavía no habilitó su bot de avisos.</p>
<?php elseif ($link): ?><div class="alert success">Enlace creado. Ábrelo y pulsa Iniciar en el bot para completar la vinculación. Caduca en 10 minutos y funciona una sola vez.</div><a class="button primary" href="<?= e($link) ?>" target="_blank" rel="noopener noreferrer">Abrir el bot de la clínica</a><p><a href="/telegram">Comprobar vinculación</a></p>
<?php else: ?><form class="form-stack" method="post" action="/telegram/activar"><?= csrf_field() ?><label class="catalog-check"><input type="checkbox" name="consentimiento" value="1" required> Autorizo recibir avisos de mis citas y gestiones de la clínica en mi chat privado de Telegram.</label><p class="field-help">El enlace es personal. Compártelo únicamente con tu propia cuenta de Telegram.</p><button class="button primary" type="submit"><?= $binding && $binding['activo'] ? 'Vincular otro chat propio' : 'Activar avisos por Telegram' ?></button></form><?php endif; ?>
</section>
