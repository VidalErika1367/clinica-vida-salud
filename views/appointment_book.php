<div class="page-heading"><div><span class="eyebrow">CITAS MÉDICAS</span><h1>Programar cita</h1><p>Revisa el turno y guarda tu reserva.</p></div><a class="button quiet" href="/disponibilidad">Consultar otro turno</a></div>
<?php require BASE_PATH . '/views/_appointment_errors.php'; ?>
<section class="card form-card"><dl class="account-details patient-details">
<div><dt>Especialidad</dt><dd><?= e($specialty['nombre'] ?? 'No disponible') ?></dd></div>
<div><dt>Médico</dt><dd><?= e($doctor ? $doctor['nombres'].' '.$doctor['apellidos'] : 'No disponible') ?></dd></div>
<div><dt>Fecha y hora</dt><dd><?= e(clinic_datetime($start)) ?></dd></div><div><dt>Duración</dt><dd>30 minutos</dd></div>
</dl></section>
<?php if ($user['rol']!=='paciente'): ?><section class="card form-card"><form class="search-form" method="get" action="/citas/nueva">
<input type="hidden" name="especialidad_id" value="<?= (int) $specialtyId ?>"><input type="hidden" name="medico_id" value="<?= (int) $doctorId ?>"><input type="hidden" name="inicio" value="<?= e($start) ?>">
<label class="grow">Buscar paciente por CI, nombres o apellidos<input name="paciente_q" value="<?= e($patientQuery) ?>" maxlength="100"></label><button class="button secondary align-end" type="submit">Buscar</button>
</form><p class="field-help">Se muestran hasta 200 pacientes activos. Usa la búsqueda para localizar su ficha.</p></section><?php endif; ?>
<section class="card form-card"><form class="form-stack" method="post" action="/citas/nueva">
<?= csrf_field() ?><input type="hidden" name="especialidad_id" value="<?= (int) $specialtyId ?>"><input type="hidden" name="medico_id" value="<?= (int) $doctorId ?>"><input type="hidden" name="inicio" value="<?= e($start) ?>"><input type="hidden" name="paciente_q" value="<?= e($patientQuery) ?>">
<?php if ($user['rol']==='paciente'): ?><p><strong>Paciente:</strong> <?= e($patient ? $patient['nombres'].' '.$patient['apellidos'].' · '.$patient['ci'] : 'Sin ficha vinculada') ?></p>
<?php if (!$patient || !$patient['activo']): ?><div class="alert warning">Tu ficha no está activa para reservar. Solicita la revisión en recepción.</div><?php endif; ?>
<?php if ($user['tiene_deuda']): ?><div class="alert warning">Tienes multas pendientes. Regulariza el pago en recepción antes de una nueva reserva ordinaria.</div><?php endif; ?>
<?php else: ?><label>Paciente *<select name="paciente_id" required><option value="">Selecciona un paciente</option><?php foreach ($patients as $person): ?><option value="<?= (int) $person['id'] ?>" <?= (int) ($old['paciente_id'] ?? 0)===(int) $person['id'] ? 'selected' : '' ?>><?= e($person['ci'].' · '.$person['nombres'].' '.$person['apellidos']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<label>Motivo de consulta (opcional)<input name="motivo" value="<?= old($old,'motivo') ?>" maxlength="250"></label>
<p class="field-help">El turno se reserva al guardar. La cita quedará Programada para que luego confirmes tu asistencia.</p>
<div class="form-actions"><button class="button primary" type="submit" <?= !$free || ($user['rol']==='paciente' && (!$patient || !$patient['activo'] || $user['tiene_deuda'])) ? 'disabled' : '' ?>>Guardar reserva</button><a class="button quiet" href="/disponibilidad">Volver</a></div>
</form></section>
