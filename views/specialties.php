<div class="page-heading"><div><span class="eyebrow">ADMINISTRACIÓN</span><h1>Especialidades</h1><p>Administra las especialidades de la clínica.</p></div><a class="button primary" href="/especialidades/nueva">+ Nueva especialidad</a></div>
<section class="card">
<?php $searchAction='/especialidades'; $searchLabel='Buscar por nombre'; require BASE_PATH . '/views/_catalog_search.php'; ?>
<div class="table-scroll"><table><thead><tr><th scope="col">Nombre</th><th scope="col">Descripción</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead><tbody>
<?php foreach ($items as $item): ?><tr><td><strong><?= e($item['nombre']) ?></strong></td><td class="catalog-description"><?= e($item['descripcion'] ?: 'Sin descripción') ?></td><td><span class="badge <?= $item['activo'] ? 'enabled' : 'disabled' ?>"><?= $item['activo'] ? 'Activo' : 'Inactivo' ?></span></td><td><div class="row-actions"><a class="table-action" href="/especialidades/<?= (int) $item['id'] ?>">Ver detalle</a><a class="table-action" href="/especialidades/<?= (int) $item['id'] ?>/editar">Editar</a></div></td></tr><?php endforeach; ?>
<?php if (!$items): ?><tr><td class="empty-state" colspan="4">No se encontraron especialidades con esos filtros.</td></tr><?php endif; ?>
</tbody></table></div><p class="field-help">Se muestran hasta 200 resultados. Los registros se conservan al desactivarlos.</p>
</section>
