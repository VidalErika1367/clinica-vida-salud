<div class="page-heading"><div><span class="eyebrow">PACIENTES</span><h1>Pacientes</h1><p>Busca y administra sus datos personales y de contacto.</p></div><a class="button primary" href="/pacientes/nuevo">+ Nuevo paciente</a></div>
<section class="card">
    <form class="patient-search" method="get" action="/pacientes">
        <label>Buscar por CI, nombres o apellidos<input name="q" value="<?= e($query) ?>" maxlength="100" placeholder="CI, nombre o apellido"></label>
        <label>Estado<select name="estado"><?php foreach (['todos'=>'Todos','activos'=>'Activos','inactivos'=>'Inactivos'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $state === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <button class="button secondary" type="submit">Buscar</button>
    </form>
    <div class="table-scroll"><table><thead><tr><th scope="col">Código</th><th scope="col">CI</th><th scope="col">Paciente</th><th scope="col">Teléfono</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead><tbody>
    <?php foreach ($patients as $person): ?><tr>
        <td><?= e(App\PatientService::code((int) $person['id'])) ?></td>
        <td><?= e($person['ci']) ?></td>
        <td><strong><?= e($person['nombres'] . ' ' . $person['apellidos']) ?></strong></td>
        <td><?= e($person['telefono']) ?></td>
        <td><span class="badge <?= $person['activo'] ? 'enabled' : 'disabled' ?>"><?= $person['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
        <td><div class="row-actions"><a class="table-action" href="/pacientes/<?= (int) $person['id'] ?>">Ver ficha<span class="sr-only"> de <?= e($person['nombres']) ?></span></a><a class="table-action" href="/pacientes/<?= (int) $person['id'] ?>/editar">Editar<span class="sr-only"> <?= e($person['nombres']) ?></span></a></div></td>
    </tr><?php endforeach; ?>
    <?php if (!$patients): ?><tr><td colspan="6" class="empty-state">No se encontraron pacientes con esos filtros.</td></tr><?php endif; ?>
    </tbody></table></div>
    <p class="field-help">Se muestran hasta 200 resultados. Utiliza los filtros para localizar una ficha.</p>
</section>
