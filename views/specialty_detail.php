<div class="page-heading"><div><span class="eyebrow">ESPECIALIDADES</span><h1><?= e($item['nombre']) ?></h1><p>Detalle de la especialidad</p></div><a class="button primary" href="/especialidades/<?= (int) $item['id'] ?>/editar">Editar especialidad</a></div>
<section class="card"><div class="section-heading"><h2>Datos de la especialidad</h2><span class="badge <?= $item['activo'] ? 'enabled' : 'disabled' ?>"><?= $item['activo'] ? 'Activo' : 'Inactivo' ?></span></div><p class="preserve-lines"><?= e($item['descripcion'] ?: 'Sin descripción') ?></p><a class="button quiet" href="/especialidades">Volver al listado</a></section>
<section class="card"><h2>Médicos relacionados</h2>
<?php if (!$doctors): ?><p>No hay médicos asociados.</p><?php endif; ?>
<ul class="catalog-relations"><?php foreach ($doctors as $doctor): ?><li><?= e($doctor['nombres'] . ' ' . $doctor['apellidos']) ?> · <?= $doctor['asignada'] ? 'Asociación vigente' : 'Asociación histórica' ?> · <?= $doctor['activo'] ? 'Médico activo' : 'Médico inactivo' ?></li><?php endforeach; ?></ul></section>
<?php require BASE_PATH . '/views/_future_appointments.php'; ?>
