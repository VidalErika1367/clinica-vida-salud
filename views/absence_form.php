<?php /* Formulario para registrar una ausencia médica (CU26). */ ?>
<div class="page-heading"><div><span class="eyebrow">ORGANIZACIÓN DIARIA</span><h1>Registrar ausencia del médico</h1><p>El intervalo bloquea reservas. Las citas existentes quedan pendientes de coordinación.</p></div></div>
<?php require BASE_PATH.'/views/_appointment_errors.php'; ?>
<section class="card form-card"><form class="form-stack" method="post" action="/ausencias/nueva"><?= csrf_field() ?><input type="hidden" name="clave_envio" value="<?= old($old,'clave_envio') ?>">
<label>Médico *<select name="medico_id" required><option value="">Selecciona un médico</option><?php foreach ($doctors as $doctor): ?><option value="<?= (int) $doctor['id'] ?>" <?= ($old['medico_id'] ?? '')===(string) $doctor['id'] ? 'selected' : '' ?>><?= e($doctor['nombres'].' '.$doctor['apellidos']) ?></option><?php endforeach; ?></select></label>
<label>Inicio de la ausencia *<input type="datetime-local" name="inicio" value="<?= old($old,'inicio') ?>" required></label>
<label>Fin de la ausencia *<input type="datetime-local" name="fin" value="<?= old($old,'fin') ?>" required></label>
<label>Motivo administrativo *<input name="motivo" maxlength="250" value="<?= old($old,'motivo') ?>" required></label>
<div class="form-actions"><button class="button primary" type="submit">Registrar ausencia</button><a class="button quiet" href="/ausencias">Volver</a></div></form></section>
