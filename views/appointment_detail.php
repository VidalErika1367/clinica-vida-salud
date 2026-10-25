<div class="page-heading"><div><span class="eyebrow">CITAS MÉDICAS</span><h1>Cita <?= e(App\AppointmentService::code((int) $appointment['id'])) ?></h1><p>Datos de la reserva y registro de cambios.</p></div><span class="pill"><?= e(ucfirst($appointment['estado'])) ?></span></div>
<?php require BASE_PATH . '/views/_appointment_errors.php'; ?>
<section class="card form-card"><dl class="account-details patient-details">
<?php foreach (['paciente'=>'Paciente','medico'=>'Médico','especialidad'=>'Especialidad'] as $key=>$label): ?><div><dt><?= e($label) ?></dt><dd><?= e($appointment[$key]) ?></dd></div><?php endforeach; ?>
<div><dt>Fecha y hora</dt><dd><?= e(clinic_datetime($appointment['inicio'])) ?> a <?= e(clinic_input($appointment['fin'],'H:i')) ?></dd></div>
<div><dt>Motivo de consulta</dt><dd><?= e($appointment['motivo'] ?: 'Sin registrar') ?></dd></div>
<?php if ($appointment['motivo_cambio']): ?><div><dt>Motivo del cambio</dt><dd><?= e($appointment['motivo_cambio']) ?></dd></div><div><dt>Origen del cambio</dt><dd><?= $appointment['origen_cambio']==='clinica' ? 'Clínica' : 'Paciente' ?></dd></div><?php endif; ?>
</dl>
<div class="form-actions appointment-actions">
<?php if (App\AppointmentService::allowed($user,'CU13') && $appointment['estado']==='programada' && $appointment['futura']): ?><form method="post" action="/citas/<?= (int) $appointment['id'] ?>/confirmar"><?= csrf_field() ?><input type="hidden" name="version" value="<?= e($version) ?>"><button class="button primary" type="submit">Confirmar asistencia</button></form><?php endif; ?>
<?php if (in_array($appointment['estado'],['programada','confirmada'],true) && $appointment['vigente']): ?>
<?php if (App\AppointmentService::allowed($user,'CU14') && ($user['rol']!=='paciente' || $appointment['futura'])): ?><a class="button secondary" href="/citas/<?= (int) $appointment['id'] ?>/reprogramar">Reprogramar cita</a><?php endif; ?>
<?php if (App\AppointmentService::allowed($user,'CU15')): ?><a class="button quiet" href="/citas/<?= (int) $appointment['id'] ?>/cancelar">Cancelar cita</a><?php endif; ?>
<?php endif; ?>
<a class="button quiet" href="/citas">Volver a citas</a>
</div></section>
<?php if ($incident): ?><div class="alert <?= $incident['monto'] ? 'warning' : 'success' ?>">
<?php if ($incident['monto']): ?>Esta cita tiene una multa de Bs <?= e(number_format((float) $incident['monto'],2,',','.')) ?>, <?= e($incident['estado']==='pendiente' ? 'pendiente de pago en recepción' : 'pagada') ?>.
<?php else: ?>Se registró la primera advertencia por incumplimiento. Esta incidencia no genera multa.<?php endif; ?>
</div><?php endif; ?>
<section class="card"><h2>Registro de cambios</h2><ol class="appointment-history">
<?php foreach ($history as $event): ?>
<?php $before=$event['datos_antes'] ? json_decode($event['datos_antes'],true,512,JSON_THROW_ON_ERROR) : null; $after=json_decode($event['datos_despues'],true,512,JSON_THROW_ON_ERROR);
$moved=$before && ($before['inicio']!==$after['inicio'] || $before['medico_id']!==$after['medico_id']); ?>
<li><strong><?= e(clinic_datetime($event['creado_en'])) ?> · <?= !$before ? 'Creada' : ($moved ? 'Reprogramada' : e(ucfirst($after['estado']))) ?></strong>
<?php if ($moved): ?><p>Turno anterior: <?= e(clinic_datetime($before['inicio'])) ?> · <?= e($event['medico_anterior']) ?><br>Nuevo turno: <?= e(clinic_datetime($after['inicio'])) ?> · <?= e($event['medico_nuevo']) ?></p><?php endif; ?>
<?php if ($after['motivo_cambio'] && ($moved || $after['estado']==='cancelada')): ?><p><?= e($after['motivo_cambio']) ?> · <?= $after['origen_cambio']==='clinica' ? 'Origen: Clínica' : 'Origen: Paciente' ?></p><?php endif; ?>
</li><?php endforeach; ?></ol><p class="field-help">Se muestran los últimos 20 cambios de esta cita.</p></section>
