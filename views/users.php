<div class="page-heading"><div><span class="eyebrow">ADMINISTRACIÓN</span><h1>Usuarios</h1><p>Administra las cuentas y los accesos de la clínica.</p></div><a class="button primary" href="/usuarios/nuevo">+ Nuevo usuario</a></div>
<section class="card">
    <form class="search-form" method="get" action="/usuarios"><label class="sr-only" for="q">Buscar por usuario o correo</label><input id="q" name="q" value="<?= e($query) ?>" placeholder="Buscar por usuario o correo" maxlength="100"><button class="button secondary" type="submit">Buscar</button></form>
    <div class="table-scroll"><table><thead><tr><th>Usuario</th><th>Correo</th><th>Rol</th><th>Acceso</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody>
    <?php foreach ($users as $account): ?><tr><td><strong><?= e($account['nombre_usuario']) ?></strong></td><td><?= e($account['correo']) ?></td><td><?= e($account['rol_nombre']) ?></td><td><span class="badge <?= $account['habilitado'] ? 'enabled' : 'disabled' ?>"><?= $account['habilitado'] ? 'Habilitado' : 'Deshabilitado' ?></span></td><td><a class="table-action" href="/usuarios/<?= (int) $account['id'] ?>/editar">Editar<span class="sr-only"> <?= e($account['nombre_usuario']) ?></span></a></td></tr><?php endforeach; ?>
    <?php if (!$users): ?><tr><td colspan="5" class="empty-state">No se encontraron usuarios con esa búsqueda.</td></tr><?php endif; ?>
    </tbody></table></div><p class="field-help">Se muestran hasta 200 resultados. Utiliza la búsqueda para encontrar una cuenta.</p>
</section>
