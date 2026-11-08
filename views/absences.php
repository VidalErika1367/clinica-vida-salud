<?php /* Listado de ausencias médicas (CU26). */ ?>
<div class="page-heading"><div><span class="eyebrow">ORGANIZACIÓN DIARIA</span><h1>Ausencias médicas</h1><p>Bloquea intervalos y coordina las citas afectadas con cada paciente.</p></div><a class="button primary" href="/ausencias/nueva">Registrar ausencia</a></div>
<section class="card"><div class="table-scroll"><table><thead><tr><th>Médico</th><th>Desde</th><th>Hasta</th><th>Motivo administrativo</th><th>Pendientes</th><th>Acción</th></tr></thead><tbody>
<?php foreach ($listing['items'] as $row): ?><tr><td><?= e($row['medico']) ?></td><td><?= e(clinic_datetime($row['inicio'])) ?></td><td><?= e(clinic_datetime($row['fin'])) ?></td><td><?= e($row['motivo']) ?></td><td><?= (int) $row['pendientes'] ?></td><td><a href="/ausencias/<?= (int) $row['id'] ?>">Revisar citas</a></td></tr><?php endforeach; ?>
<?php if (!$listing['items']): ?><tr><td colspan="6" class="empty">No hay ausencias registradas.</td></tr><?php endif; ?></tbody></table></div>
<?php require BASE_PATH.'/views/_appointment_pagination.php'; ?></section>
