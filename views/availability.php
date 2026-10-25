<div class="page-heading"><div><span class="eyebrow">CITAS MÉDICAS</span><h1>Consultar disponibilidad</h1><p>Elige una especialidad, un médico y una fecha.</p></div></div>
<?php require BASE_PATH . '/views/_appointment_errors.php'; ?>
<?php if ($user['rol']==='paciente' && !$user['paciente_activo']): ?><div class="alert warning">Puedes consultar los horarios. Tu ficha está inactiva para nuevas reservas; solicita su reactivación en recepción.</div><?php endif; ?>
<section class="card">
<form class="availability-search" method="get" action="/disponibilidad" id="availability-search">
<label>Especialidad<select name="especialidad_id" id="availability-specialty" required><option value="">Selecciona una especialidad</option><?php foreach ($specialties as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $specialtyId===(int) $item['id'] ? 'selected' : '' ?>><?= e($item['nombre']) ?></option><?php endforeach; ?></select></label>
<label>Médico<select name="medico_id" id="availability-doctor"><option value="">Selecciona un médico</option><?php foreach ($doctors as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $doctorId===(int) $item['id'] ? 'selected' : '' ?>><?= e($item['nombres'].' '.$item['apellidos']) ?></option><?php endforeach; ?></select></label>
<label>Fecha<input type="date" name="fecha" value="<?= e($date) ?>" min="<?= e($today) ?>" required></label>
<button class="button primary" type="submit">Buscar turnos</button></form>
<p class="field-help">Los turnos duran 30 minutos. La disponibilidad se vuelve a comprobar al guardar la reserva.</p>
</section>
<section class="card"><h2>Horarios libres</h2>
<?php if (!$specialtyId): ?><p>Selecciona una especialidad para consultar sus médicos.</p>
<?php elseif (!$doctors): ?><p>No hay médicos activos disponibles para esta especialidad. Consulta otra especialidad o comunícate con recepción.</p>
<?php elseif (!$doctorId): ?><p>Selecciona un médico y la fecha de atención.</p>
<?php elseif (!$slots): ?><p>No hay turnos libres para esta selección. Prueba otra fecha o médico.</p>
<?php else: ?><div class="slot-grid"><?php foreach ($slots as $slot): ?>
<?php $params=['especialidad_id'=>$specialtyId,'medico_id'=>$doctorId,'inicio'=>clinic_input($slot['inicio'])]; ?>
<?php if (App\AppointmentService::allowed($user,'CU12')): ?><a class="slot-card" href="/citas/nueva?<?= e(http_build_query($params)) ?>"><strong><?= e(clinic_input($slot['inicio'],'H:i')) ?>–<?= e(clinic_input($slot['fin'],'H:i')) ?></strong><span>Elegir turno</span></a>
<?php else: ?><div class="slot-card"><strong><?= e(clinic_input($slot['inicio'],'H:i')) ?>–<?= e(clinic_input($slot['fin'],'H:i')) ?></strong><span>Disponible</span></div><?php endif; ?>
<?php endforeach; ?></div><?php endif; ?>
</section>
