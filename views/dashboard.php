<div class="page-heading"><div><span class="eyebrow">MI ESPACIO</span><h1>Hola, <?= e($user['nombre']) ?>.</h1><p>Te damos la bienvenida a Clínica Vida Salud.</p></div><span class="pill"><?= e($user['rol_nombre']) ?></span></div>
<?php if ($user['rol'] === 'paciente' && $user['paciente_id'] && !$user['paciente_activo']): ?>
<div class="alert warning">Tu registro está inactivo para nuevas reservas. Solicita su reactivación en recepción. Puedes seguir accediendo a tu cuenta.</div>
<?php endif; ?>
<?php if (in_array($user['rol'], ['administrador','recepcionista'], true) && in_array('CU06', $user['permisos'], true)): ?>
<section class="card quick-action"><div><h2>Gestiona los datos de los pacientes</h2><p>Busca sus fichas, actualiza sus datos y consulta su estado administrativo.</p></div><a class="button primary" href="/pacientes">Gestionar pacientes →</a></section>
<?php endif; ?>
<?php if ($user['tiene_deuda']): ?><div class="alert warning">Tienes multas pendientes. Regulariza el pago en recepción para habilitar nuevas reservas ordinarias.</div><?php endif; ?>
<?php if ($user['rol'] === 'paciente' && in_array('CU07', $user['permisos'], true)): ?>
<section class="card quick-action"><div><h2>Mantén tu perfil al día</h2><p>Actualiza tu contacto, tus datos de emergencia y tu información médica básica.</p></div><a class="button primary" href="/mi-perfil">Mi perfil →</a></section>
<?php endif; ?>
<?php if ($stats): ?>
<div class="stats-grid">
    <article class="stat"><span>Usuarios registrados</span><strong><?= e($stats['total']) ?></strong><small>Cuentas de la clínica</small></article>
    <article class="stat"><span>Accesos habilitados</span><strong><?= e($stats['habilitados']) ?></strong><small>Pueden iniciar sesión</small></article>
    <article class="stat"><span>Accesos deshabilitados</span><strong><?= e($stats['deshabilitados']) ?></strong><small>Conservan sus registros</small></article>
</div>
<?php endif; ?>
<div class="dashboard-grid">
    <section class="card"><div class="section-heading"><h2>Tu cuenta</h2><span class="badge enabled">Sesión activa</span></div><dl class="account-details"><div><dt>Usuario</dt><dd><?= e($user['nombre_usuario']) ?></dd></div><div><dt>Correo</dt><dd><?= e($user['correo']) ?></dd></div><div><dt>Tipo de acceso</dt><dd><?= e($user['rol_nombre']) ?></dd></div></dl></section>
    <section class="card soft"><span class="eyebrow">ACCESO PERSONAL</span><h2>Tu información, bajo tu control.</h2><p>Al terminar, cierra tu sesión, especialmente si usas un equipo compartido.</p><form action="/salir" method="post"><?= csrf_field() ?><button class="button secondary" type="submit">Cerrar sesión</button></form></section>
</div>
<?php if ($user['rol'] === 'administrador' && in_array('CU04', $user['permisos'], true)): ?>
<section class="card quick-action"><div><h2>Organiza los accesos de tu equipo</h2><p>Registra cuentas, asigna su rol y mantén sus datos actualizados.</p></div><a class="button primary" href="/usuarios">Gestionar usuarios →</a></section>
<?php endif; ?>
<?php if ($user['rol'] === 'administrador' && (in_array('CU08',$user['permisos'],true) || in_array('CU09',$user['permisos'],true))): ?>
<section class="card"><h2>Médicos y especialidades</h2><p>Organiza los profesionales y las especialidades que atienden.</p><div class="form-actions">
<?php if (in_array('CU08',$user['permisos'],true)): ?><a class="button primary" href="/medicos">Médicos</a><?php endif; ?>
<?php if (in_array('CU09',$user['permisos'],true)): ?><a class="button secondary" href="/especialidades">Especialidades</a><?php endif; ?>
</div></section>
<?php endif; ?>
<?php if ($user['rol']==='administrador' && in_array('CU10',$user['permisos'],true)): ?>
<section class="card quick-action"><div><h2>Organiza los horarios de atención</h2><p>Define los tramos semanales de cada médico en intervalos de media hora.</p></div><a class="button primary" href="/horarios">Horarios de atención →</a></section>
<?php endif; ?>
<?php if (App\AppointmentService::allowed($user,'CU11')): ?>
<section class="card quick-action"><div><h2>Encuentra un horario disponible</h2><p>Consulta por especialidad, médico y fecha para elegir tu turno.</p></div><a class="button primary" href="/disponibilidad">Consultar disponibilidad →</a></section>
<?php endif; ?>
