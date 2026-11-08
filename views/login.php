<?php
// La selección de perfil es una ayuda visual; el servidor sigue identificando el rol real.
$perfilElegido = $_POST['perfil'] ?? '';
$perfilesValidos = ['administrador', 'recepcionista', 'medico', 'paciente'];
$perfilElegido = is_string($perfilElegido) && in_array($perfilElegido, $perfilesValidos, true)
    ? $perfilElegido : '';
$mostrarIngreso = isset($errors['general']);
?>
<div class="auth-layout profile-login-layout" data-profile-login data-initial-role="<?= e($perfilElegido) ?>" data-show-login="<?= $mostrarIngreso ? 'true' : 'false' ?>">
    <section class="profile-login-story" aria-label="Clínica Vida Salud">
        <a class="brand profile-login-brand" href="/ingresar">
            <img src="/assets/marca.svg" alt="" width="48" height="48">
            <span>Vida Salud<small>CLÍNICA</small></span>
        </a>
        <div class="profile-login-story-copy">
            <span class="profile-login-kicker">BIENVENIDO A VIDA SALUD</span>
            <h1>Accede a tu cuenta</h1>
            <p>Selecciona tu perfil e inicia sesión para continuar.</p>
        </div>
        <span class="profile-login-story-footer">Santa Cruz de la Sierra · Bolivia</span>
    </section>

    <section class="profile-login-content" aria-label="Acceso a tu cuenta">
        <div class="profile-login-progress" aria-label="Pasos para ingresar">
            <div class="profile-login-progress-item" data-profile-indicator="select" aria-current="step">
                <span class="profile-login-progress-circle">1</span><span>Selecciona tu perfil</span>
            </div>
            <span class="profile-login-progress-line" aria-hidden="true"></span>
            <div class="profile-login-progress-item" data-profile-indicator="login">
                <span class="profile-login-progress-circle">2</span><span>Inicia sesión</span>
            </div>
        </div>

        <div class="profile-login-step" data-profile-step="select" <?= $mostrarIngreso ? 'hidden' : '' ?>>
            <h2>¿Cómo quieres ingresar?</h2>
            <p class="profile-login-subtitle">Elige el tipo de cuenta que utilizas en la clínica.</p>
            <div class="profile-login-options" role="group" aria-label="Selecciona tu perfil">
                <button type="button" class="profile-login-option" data-profile-option="administrador" aria-pressed="false">
                    <svg viewBox="0 0 48 48" fill="none" aria-hidden="true"><path d="M24 5 39 11v12c0 10-6 17-15 21C15 40 9 33 9 23V11l15-6Z"/><path d="M24 13v22M16 23h16"/></svg>
                    <strong>Administrador</strong><span>Gestión del sistema</span>
                </button>
                <button type="button" class="profile-login-option" data-profile-option="recepcionista" aria-pressed="false">
                    <svg viewBox="0 0 48 48" fill="none" aria-hidden="true"><rect x="8" y="10" width="32" height="32" rx="4"/><path d="M19 10V7h10v3M15 21h10M15 28h18M15 35h18"/></svg>
                    <strong>Recepcionista</strong><span>Gestión de citas</span>
                </button>
                <button type="button" class="profile-login-option" data-profile-option="medico" aria-pressed="false">
                    <svg viewBox="0 0 48 48" fill="none" aria-hidden="true"><path d="M12 7v17a12 12 0 0 0 24 0V7M9 7h6M33 7h6M24 36v3a7 7 0 0 0 14 0v-4"/><circle cx="38" cy="31" r="4"/></svg>
                    <strong>Médico</strong><span>Agenda de atención</span>
                </button>
                <button type="button" class="profile-login-option" data-profile-option="paciente" aria-pressed="false">
                    <svg viewBox="0 0 48 48" fill="none" aria-hidden="true"><circle cx="24" cy="15" r="8"/><path d="M9 43v-5a15 15 0 0 1 30 0v5H9Z"/></svg>
                    <strong>Paciente</strong><span>Mis citas y perfil</span>
                </button>
            </div>
            <button type="button" class="button primary full profile-login-next" data-profile-continue disabled>
                Continuar <span aria-hidden="true">→</span>
            </button>
            <p class="profile-login-note">Tu acceso y permisos se verificarán al iniciar sesión.</p>
        </div>

        <div class="profile-login-step" data-profile-step="login" <?= $mostrarIngreso ? '' : 'hidden' ?>>
            <button type="button" class="profile-login-back" data-profile-back aria-label="Volver a seleccionar perfil">
                <span aria-hidden="true">←</span> Cambiar de perfil
            </button>
            <div class="profile-login-welcome">
                <span class="profile-login-role-label">INICIO DE SESIÓN</span>
                <h2>Accede como <span data-profile-role-label>usuario</span></h2>
                <p>Ingresa con tu usuario o correo registrado.</p>
            </div>
            <?php if (isset($errors['general'])): ?>
                <div class="alert error" role="alert"><?= e($errors['general']) ?></div>
            <?php endif; ?>
            <form action="/sesion" method="post" class="form-stack profile-login-form">
                <?= csrf_field() ?>
                <input type="hidden" name="perfil" data-profile-hidden-role value="<?= e($perfilElegido) ?>">
                <label>Usuario o correo
                    <input name="identidad" autocomplete="username" maxlength="160" value="<?= old($old ?? [], 'identidad') ?>" required data-profile-identity>
                </label>
                <label>Contraseña
                    <span class="password-input">
                        <input id="password" type="password" name="password" autocomplete="current-password" maxlength="72" required>
                        <button type="button" class="toggle-password" data-password-target="password" aria-label="Mostrar contraseña">Ver</button>
                    </span>
                </label>
                <button class="button primary full" type="submit">Ingresar <span aria-hidden="true">→</span></button>
            </form>
            <p class="form-bottom" data-profile-patient-only <?= $perfilElegido === 'paciente' ? '' : 'hidden' ?>>¿Es tu primera vez? <a href="/registro">Crea tu cuenta de paciente</a></p>
            <div class="help-note" data-profile-patient-only <?= $perfilElegido === 'paciente' ? '' : 'hidden' ?>>Si ya tienes un registro en la clínica y no puedes ingresar, consulta en recepción.</div>
        </div>
    </section>
</div>
