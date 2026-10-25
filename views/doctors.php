<div class="page-heading"><div><span class="eyebrow">ADMINISTRACIÓN</span><h1>Médicos</h1><p>Administra sus datos y especialidades.</p></div><a class="button primary" href="/medicos/nuevo">+ Nuevo médico</a></div>
<section class="card">
<?php $searchAction='/medicos'; $searchLabel='Buscar por registro profesional, nombres o apellidos'; require BASE_PATH . '/views/_catalog_search.php'; ?>
<div class="table-scroll"><table><thead><tr><th scope="col">Código</th><th scope="col">Registro profesional</th><th scope="col">Médico</th><th scope="col">Especialidades disponibles</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead><tbody>
<?php foreach ($items as $item): ?><tr>
<td><?= e(App\DoctorService::code((int) $item['id'])) ?></td><td><?= e($item['registro_profesional']) ?></td><td><strong><?= e($item['nombres'] . ' ' . $item['apellidos']) ?></strong></td><td class="catalog-description"><?= e($item['especialidades_actuales'] ?: 'Sin especialidades disponibles') ?></td><td><span class="badge <?= $item['activo'] ? 'enabled' : 'disabled' ?>"><?= $item['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
<td><div class="row-actions"><a class="table-action" href="/medicos/<?= (int) $item['id'] ?>">Ver ficha</a><a class="table-action" href="/medicos/<?= (int) $item['id'] ?>/editar">Editar</a></div></td>
</tr><?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="6" class="empty-state">No se encontraron médicos con esos filtros.</td></tr><?php endif; ?>
</tbody></table></div><p class="field-help">Se muestran hasta 200 resultados. Desactivar conserva sus citas e historial.</p>
</section>
