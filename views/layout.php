<!doctype html>
<html lang="es-BO">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · Clínica Vida Salud</title>
    <link rel="icon" type="image/svg+xml" href="/assets/marca.svg">
    <link rel="stylesheet" href="/assets/app.css"><script src="/assets/app.js" defer></script>
</head>
<body class="<?= $user ? 'app-body' : 'guest-body' ?>">
<?php if ($user): ?>
<aside class="sidebar">
    <a class="brand" href="/panel"><img src="/assets/marca.svg" alt="" width="38" height="38"><span>Vida Salud<small>Clínica · Área personal</small></span></a>
    <p class="nav-label">MI ESPACIO</p>
    <nav aria-label="Navegación principal">
        <a href="/panel" <?= $title === 'Mi panel' ? 'aria-current="page"' : '' ?>>Mi panel</a>
        <?php if ($user['rol'] === 'paciente' && in_array('CU07', $user['permisos'], true)): ?><a href="/mi-perfil" <?= ($section ?? '') === 'perfil' ? 'aria-current="page"' : '' ?>>Mi perfil</a><?php endif; ?>
        <?php if (in_array($user['rol'], ['administrador','recepcionista'], true) && in_array('CU06', $user['permisos'], true)): ?><a href="/pacientes" <?= ($section ?? '') === 'pacientes' ? 'aria-current="page"' : '' ?>>Pacientes</a><?php endif; ?>
        <?php if ($user['rol'] === 'administrador' && in_array('CU04', $user['permisos'], true)): ?><a href="/usuarios">Usuarios</a><?php endif; ?>
        <?php if ($user['rol'] === 'administrador' && in_array('CU05', $user['permisos'], true)): ?><a href="/roles">Roles y permisos</a><?php endif; ?>
        <?php if ($user['rol'] === 'administrador' && in_array('CU08',$user['permisos'],true)): ?><a href="/medicos" <?= ($section ?? '') === 'medicos' ? 'aria-current="page"' : '' ?>>Médicos</a><?php endif; ?>
        <?php if ($user['rol'] === 'administrador' && in_array('CU09',$user['permisos'],true)): ?><a href="/especialidades" <?= ($section ?? '') === 'especialidades' ? 'aria-current="page"' : '' ?>>Especialidades</a><?php endif; ?>
        <?php if ($user['rol'] === 'administrador' && in_array('CU10',$user['permisos'],true)): ?><a href="/horarios" <?= ($section ?? '') === 'horarios' ? 'aria-current="page"' : '' ?>>Horarios de atención</a><?php endif; ?>
        <?php if (App\AppointmentService::allowed($user,'CU11')): ?><a href="/disponibilidad" <?= ($section ?? '') === 'disponibilidad' ? 'aria-current="page"' : '' ?>>Consultar disponibilidad</a><?php endif; ?>
        <?php if (App\AppointmentService::canView($user)): ?><a href="/citas" <?= ($section ?? '')==='citas' ? 'aria-current="page"' : '' ?>><?= $user['rol']==='paciente' ? 'Mis citas' : 'Citas' ?></a><?php endif; ?>
        <?php foreach (['CU18'=>['agenda','Agenda médica'],'CU19'=>['historial','Historial de citas'],'CU20'=>['reportes','Reportes']] as $permission=>$link): ?>
        <?php if (App\AppointmentService::allowed($user,$permission)): ?><a href="/<?= e($link[0]) ?>" <?= ($section ?? '')===$link[0] ? 'aria-current="page"' : '' ?>><?= e($link[1]) ?></a><?php endif; ?>
        <?php endforeach; ?>
        <?php if (App\AppointmentService::allowed($user,'CU21') || App\AppointmentService::allowed($user,'CU22')): ?><a href="/urgencias" <?= ($section ?? '')==='urgencias' ? 'aria-current="page"' : '' ?>>Citas urgentes</a><?php endif; ?>
        <?php if (App\AppointmentService::allowed($user,'CU24')): ?><a href="/multas" <?= ($section ?? '')==='multas' ? 'aria-current="page"' : '' ?>><?= $user['rol']==='paciente' ? 'Mis advertencias y multas' : 'Advertencias y multas' ?></a><?php endif; ?>
        <?php if (App\AppointmentService::allowed($user,'CU23')): ?><a href="/pagos" <?= ($section ?? '')==='pagos' ? 'aria-current="page"' : '' ?>>Registrar pago de multa</a><?php endif; ?>
        <?php if (App\AppointmentService::allowed($user,'CU25')): ?><a href="/reactivaciones" <?= ($section ?? '')==='reactivaciones' ? 'aria-current="page"' : '' ?>>Reactivar pacientes</a><?php endif; ?>
        <?php foreach (['CU26'=>['ausencias','Ausencias médicas'],'CU27'=>['telegram','Avisos por Telegram'],'CU28'=>['avisos','Avisos del sistema'],'CU30'=>['contacto-recepcion','Contactar con recepción']] as $permission=>$link): ?>
        <?php if (App\AppointmentService::allowed($user,$permission)): ?><a href="/<?= e($link[0]) ?>" <?= ($section ?? '')===$link[0] ? 'aria-current="page"' : '' ?>><?= e($link[1]) ?></a><?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-bottom"><span class="status-dot"></span> Sesión protegida</div>
</aside>
<div class="app-shell">
    <header class="topbar"><span class="eyebrow">CLÍNICA VIDA SALUD</span><div class="account"><span><?= e($user['nombre_usuario']) ?><small><?= e($user['rol_nombre']) ?></small></span><form action="/salir" method="post"><?= csrf_field() ?><button class="button quiet" type="submit">Salir</button></form></div></header>
    <main class="main-content" id="contenido">
        <?php if ($flash = App\Session::flash()): ?><div class="alert success" role="status"><?= e($flash) ?></div><?php endif; ?>
        <?= $content ?>
    </main>
    <footer class="app-footer">Clínica Vida Salud · Santa Cruz de la Sierra</footer>
</div>
<?php else: ?>
    <main class="guest-content" id="contenido">
        <?php if ($flash = App\Session::flash()): ?><div class="alert success floating" role="status"><?= e($flash) ?></div><?php endif; ?>
        <?= $content ?>
    </main>
<?php endif; ?>
</body>
</html>
