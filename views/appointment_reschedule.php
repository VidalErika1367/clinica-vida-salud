<div class="page-heading"><div><span class="eyebrow">CITAS MÉDICAS</span><h1>Reprogramar cita</h1><p><?= e(App\AppointmentService::code((int) $appointment['id'])) ?> · <?= e($appointment['paciente']) ?></p></div><a class="button quiet" href="/citas/<?= (int) $appointment['id'] ?>">Volver a la cita</a></div>
<?php require BASE_PATH . '/views/_appointment_errors.php'; ?>
<section class="card"><h2>Turno actual</h2><p><?= e(clinic_datetime($appointment['inicio'])) ?> · <?= e($appointment['medico']) ?> · <?= e($appointment['especialidad']) ?></p>
<?php if ($late): ?><div class="alert warning">El turno original está dentro del plazo configurado de <?= (int) $policy['plazo_cancelacion_horas'] ?> horas para cancelaciones. La solicitud de reprogramación conservará su trazabilidad para revisión.</div><?php endif; ?>
<form class="availability-search" method="get" action="/citas/<?= (int) $appointment['id'] ?>/reprogramar">
<?php if ($user['rol']==='paciente'): ?><input type="hidden" name="medico_id" value="<?= (int) $doctorId ?>"><p>Para cambiar de médico, comunícate con recepción.</p>
<?php else: ?><label>Médico de la misma especialidad<select name="medico_id" required><?php foreach ($doctors as $doctor): ?><option value="<?= (int) $doctor['id'] ?>" <?= $doctorId===(int) $doctor['id'] ? 'selected' : '' ?>><?= e($doctor['nombres'].' '.$doctor['apellidos']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<label>Nueva fecha<input type="date" name="fecha" value="<?= e($date) ?>" min="<?= e($today) ?>" required></label><button class="button secondary" type="submit">Buscar turnos</button>
</form></section>
<section class="card form-card"><form class="form-stack" method="post" action="/citas/<?= (int) $appointment['id'] ?>/reprogramar">
<?= csrf_field() ?><input type="hidden" name="version" value="<?= e($version) ?>"><input type="hidden" name="medico_id" value="<?= (int) $doctorId ?>"><input type="hidden" name="especialidad_id" value="<?= (int) $appointment['especialidad_id'] ?>"><input type="hidden" name="fecha" value="<?= e($date) ?>">
<fieldset class="specialty-choices"><legend>Nuevo turno</legend><div class="slot-grid">
<?php foreach ($slots as $slot): ?><?php $value=clinic_input($slot['inicio']); ?><label class="slot-choice"><input type="radio" name="inicio" value="<?= e($value) ?>" <?= ($old['inicio'] ?? '')===$value ? 'checked' : '' ?> required><span><?= e(clinic_input($slot['inicio'],'H:i')) ?>–<?= e(clinic_input($slot['fin'],'H:i')) ?></span></label><?php endforeach; ?>
</div><?php if (!$slots): ?><p>No hay turnos libres. Prueba otra fecha o consulta con recepción.</p><?php endif; ?></fieldset>
<label>Motivo del cambio *<input name="motivo_cambio" value="<?= old($old,'motivo_cambio') ?>" maxlength="250" required></label>
<?php if ($user['rol']!=='paciente' && $doctorId!==(int) $appointment['medico_id']): ?><label class="check-label"><input type="checkbox" name="aceptacion_medico" value="1" <?= ($old['aceptacion_medico'] ?? '')==='1' ? 'checked' : '' ?> required> El paciente aceptó el cambio de médico</label><?php endif; ?>
<?php require BASE_PATH . '/views/_appointment_context.php'; ?>
<p class="field-help">Al guardar, el turno anterior se libera y la cita queda Programada para ratificar la nueva fecha. Si el nuevo turno se ocupa antes, se conserva la reserva actual.</p>
<div class="form-actions"><button class="button primary" type="submit" <?= !$slots || !in_array($appointment['estado'],['programada','confirmada'],true) || !$appointment['vigente'] ? 'disabled' : '' ?>>Guardar reprogramación</button><a class="button quiet" href="/citas/<?= (int) $appointment['id'] ?>">Volver</a></div>
</form></section>
