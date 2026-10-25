<div class="page-heading"><div><span class="eyebrow">PACIENTES</span><h1><?= e($patient['nombres'] . ' ' . $patient['apellidos']) ?></h1><p><?= e(App\PatientService::code((int) $patient['id'])) ?> · Ficha del paciente</p></div><a class="button primary" href="/pacientes/<?= (int) $patient['id'] ?>/editar">Editar paciente</a></div>
<?php foreach (['general','version'] as $key): ?><?php if (isset($errors[$key])): ?><div class="alert error" role="alert"><?= e($errors[$key]) ?></div><?php endif; ?><?php endforeach; ?>
<div class="dashboard-grid">
    <section class="card"><div class="section-heading"><h2>Datos personales y de contacto</h2><span class="badge <?= $patient['activo'] ? 'enabled' : 'disabled' ?>"><?= $patient['activo'] ? 'Activo' : 'Inactivo' ?></span></div>
        <dl class="account-details patient-details">
        <?php foreach (['ci'=>'CI','fecha_nacimiento'=>'Nacimiento','sexo'=>'Sexo','telefono'=>'Teléfono','correo'=>'Correo','direccion'=>'Dirección'] as $key=>$label): ?>
            <div><dt><?= e($label) ?></dt><dd><?= e($patient[$key] ?: 'Sin registrar') ?></dd></div>
        <?php endforeach; ?>
        <div><dt>Cuenta de acceso</dt><dd><?= $patient['usuario_id'] ? e($patient['nombre_usuario'] . ($patient['cuenta_habilitada'] ? ' · Habilitada' : ' · Deshabilitada')) : 'Sin cuenta vinculada' ?></dd></div>
        </dl>
    </section>
    <section class="card"><h2>Situación administrativa</h2><dl class="account-details">
        <div><dt>Citas registradas</dt><dd><?= (int) $summary['citas'] ?></dd></div>
        <div><dt>Citas vigentes</dt><dd><?= (int) $summary['vigentes'] ?></dd></div>
        <div><dt>Multas pendientes</dt><dd><?= (int) $summary['multas_pendientes'] ?></dd></div>
        </dl><p class="field-help patient-note">El estado del paciente, su cuenta de acceso y las multas son condiciones independientes. Desactivarlo conserva los registros relacionados.</p>
    </section>
</div>
<?php if ($patient['activo']): ?>
<section class="card form-card"><h2>Desactivar paciente</h2><p>El paciente quedará inactivo para nuevas reservas. Sus citas vigentes no se cancelarán automáticamente y su historial se conservará.</p>
    <form class="form-stack" method="post" action="/pacientes/<?= (int) $patient['id'] ?>/desactivar">
        <?= csrf_field() ?><input type="hidden" name="version" value="<?= e($version) ?>">
        <label>Motivo de desactivación<input name="motivo" value="<?= e($reason) ?>" maxlength="250" required><?= field_error($errors,'motivo') ?></label>
        <div class="form-actions"><button class="button danger" type="submit">Desactivar paciente</button><a class="button quiet" href="/pacientes">Volver al listado</a></div>
    </form>
</section>
<?php else: ?>
<section class="card form-card"><h2>Paciente inactivo</h2><p>Su ficha y sus registros relacionados se conservan. Las nuevas reservas requieren su reactivación y no tener multas pendientes.</p><a class="button quiet" href="/pacientes">Volver al listado</a></section>
<?php endif; ?>
