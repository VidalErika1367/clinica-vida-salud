<div class="page-heading"><div><span class="eyebrow">ORGANIZACIÓN DIARIA</span><h1>Agenda médica</h1><p>Consulta las citas asignadas y las ausencias registradas para una fecha.</p></div></div>
<?php require BASE_PATH . '/views/_appointment_errors.php'; ?>
<?php if ($user['rol']==='medico' && !$doctors): ?><div class="alert warning">Tu cuenta todavía no está vinculada a un médico. Solicita a administración que revise la vinculación.</div><?php endif; ?>
<section class="card"><form class="schedule-search" method="get" action="/agenda">
<label>Fecha<input type="date" name="fecha" value="<?= e($old['fecha'] ?? $filters['desde']) ?>" required></label>
<label>Médico<select name="medico_id">
<?php if ($user['rol']!=='medico'): ?><option value="">Todos los médicos</option><?php endif; ?>
<?php foreach ($doctors as $doctor): ?><option value="<?= (int) $doctor['id'] ?>" <?= (string) ($old['medico_id'] ?? $filters['medico_id'] ?? '')===(string) $doctor['id'] ? 'selected' : '' ?>><?= e($doctor['nombre']) ?><?= $doctor['activo'] ? '' : ' · Inactivo' ?></option><?php endforeach; ?></select></label>
<label>Estado<select name="estado"><?php foreach ($states as $value=>$label): ?><option value="<?= e($value) ?>" <?= ($old['estado'] ?? $filters['estado'])===$value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<button class="button primary" type="submit">Consultar agenda</button></form>
<?php require BASE_PATH . '/views/_appointment_listing.php'; ?></section>
<section class="card"><h2>Ausencias registradas</h2>
<?php if (!$absenceItems): ?><p>No hay ausencias registradas para este período y médico. La disponibilidad de turnos se consulta en su apartado.</p><?php else: ?>
<div class="table-scroll"><table><thead><tr><th scope="col">Médico</th><th scope="col">Desde</th><th scope="col">Hasta</th><th scope="col">Motivo</th></tr></thead><tbody>
<?php foreach ($absenceItems as $absence): ?><tr><td><?= e($absence['medico']) ?></td><td><?= e(clinic_datetime($absence['inicio'])) ?></td><td><?= e(clinic_datetime($absence['fin'])) ?></td><td><?= e($absence['motivo']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
