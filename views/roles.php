<div class="page-heading"><div><span class="eyebrow">ADMINISTRACIÓN</span><h1>Roles y permisos</h1><p>Selecciona las operaciones que podrá realizar cada rol y guarda los cambios.</p></div></div>
<?php if (isset($errors['general'])): ?><div class="alert error" role="alert"><?= e($errors['general']) ?></div><?php endif; ?>
<form action="/roles" method="post" class="roles-form">
    <?= csrf_field() ?>
    <input type="hidden" name="version" value="<?= e($version) ?>">
    <?php foreach ($roles as $role): ?><input type="hidden" name="roles_presentes[<?= e($role['codigo']) ?>]" value="1"><?php endforeach; ?>
    <section class="card">
        <p class="permissions-help">Cada rol puede realizar las operaciones que le corresponden. El inicio y cierre de sesión permanecen habilitados; Administración conserva la gestión de usuarios y permisos. Los permisos no permiten consultar datos ajenos.</p>
        <div class="table-scroll" tabindex="0" role="region" aria-label="Matriz de permisos por rol">
            <table class="permission-table"><thead><tr><th scope="col">Operación</th><?php foreach ($roles as $role): ?><th scope="col"><?= e($role['nombre']) ?></th><?php endforeach; ?></tr></thead><tbody>
            <?php foreach ($cases as $code => $name): ?>
                <tr><th scope="row"><span class="case-code"><?= e($code) ?></span><?= e($name) ?></th>
                <?php foreach ($roles as $role):
                    $roleCode = $role['codigo'];
                    $available = in_array($code, $policy[$roleCode]['disponibles'] ?? [], true);
                    $fixed = in_array($code, $policy[$roleCode]['obligatorios'] ?? [], true);
                    $checked = isset($matrix[$code][$roleCode]);
                ?>
                    <td>
                    <?php if ($available): ?>
                        <?php if ($fixed && $checked): ?><input type="hidden" name="permisos[<?= e($roleCode) ?>][]" value="<?= e($code) ?>"><?php endif; ?>
                        <label class="permission-check">
                            <input type="checkbox" name="permisos[<?= e($roleCode) ?>][]" value="<?= e($code) ?>"
                                <?= $checked ? 'checked' : '' ?> <?= $fixed ? 'disabled' : '' ?>
                                aria-label="<?= e($role['nombre'] . ': ' . $code . ' ' . $name) ?>">
                            <span><?= $fixed ? 'Siempre habilitado' : 'Permitir' ?></span>
                        </label>
                    <?php else: ?><span class="permission">No corresponde</span><?php endif; ?>
                    </td>
                <?php endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
    </section>
    <div class="form-actions">
        <button type="submit" class="button primary">Guardar permisos</button>
        <a href="/panel" class="button quiet">Volver a mi panel</a>
    </div>
</form>
