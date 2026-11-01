<div class="page-heading"><div><span class="eyebrow">CITAS URGENTES</span><h1>Solicitar cita urgente</h1><p>Envía tu solicitud para que recepción coordine una alternativa contigo.</p></div><a class="button quiet" href="/urgencias">Mis solicitudes</a></div>
<?php require BASE_PATH . '/views/_appointment_errors.php'; ?>
<section class="card form-card"><p class="help-note">Enviar esta solicitud no reserva un turno ni garantiza atención inmediata. Recepción revisará tu solicitud y coordinará contigo. Puedes solicitar esta gestión aunque tengas restricciones para reservas ordinarias.</p>
<form class="form-stack" method="post" action="/urgencias/nueva"><?= csrf_field() ?><input type="hidden" name="clave_envio" value="<?= old($old,'clave_envio') ?>">
<label>Paciente<input value="<?= e($patient['nombres'].' '.$patient['apellidos']) ?>" readonly></label>
<label>Motivo breve *<textarea name="motivo" maxlength="500" required><?= old($old,'motivo') ?></textarea></label>
<label>Teléfono para contactarte *<input type="tel" name="contacto" value="<?= old($old,'contacto') ?>" maxlength="30" required></label>
<div class="form-actions"><button class="button primary" type="submit">Enviar solicitud</button><a class="button quiet" href="/urgencias">Volver</a></div></form></section>
