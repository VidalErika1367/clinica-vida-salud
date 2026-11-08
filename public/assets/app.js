// Menú móvil: botón de navegación, apertura y cierre, y foco al cerrar. Se usa en todas las pantallas.
const navigationToggle = document.getElementById('navigation-toggle');
const mainNavigation = document.getElementById('main-navigation');
if (navigationToggle && mainNavigation) {
    // Pantallas de 760 px o menos usan el menú plegable.
    const mobileNavigation = window.matchMedia('(max-width: 760px)');
    const navigationLabel = document.getElementById('navigation-label');
    const sidebar = navigationToggle.closest('.sidebar');
    let menuOpen = false;
    // Abre o cierra el menú móvil y actualiza aria-expanded y la etiqueta del botón.
    const showMenu = open => {
        menuOpen = mobileNavigation.matches && open;
        mainNavigation.hidden = mobileNavigation.matches && !menuOpen;
        navigationToggle.setAttribute('aria-expanded', String(!mainNavigation.hidden));
        navigationLabel.textContent = menuOpen ? 'Cerrar' : 'Menú';
    };
    // Recalcula el menú al cambiar el tamaño de pantalla; si el foco estaba dentro del menú, lo devuelve al botón.
    const updateNavigation = () => {
        const focusInside = mainNavigation.contains(document.activeElement);
        navigationToggle.hidden = !mobileNavigation.matches;
        showMenu(false);
        if (mobileNavigation.matches && focusInside) navigationToggle.focus();
    };
    // Eventos del menú: alternar con el botón, cerrar al elegir un enlace, con Escape o al hacer clic fuera.
    navigationToggle.addEventListener('click', () => showMenu(!menuOpen));
    mainNavigation.addEventListener('click', event => {
        if (mobileNavigation.matches && event.target.closest('a')) showMenu(false);
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && menuOpen) {
            showMenu(false);
            navigationToggle.focus();
        }
    });
    document.addEventListener('click', event => {
        if (menuOpen && !sidebar.contains(event.target)) showMenu(false);
    });
    if (mobileNavigation.addEventListener) mobileNavigation.addEventListener('change', updateNavigation);
    else mobileNavigation.addListener(updateNavigation);
    updateNavigation();
}

// Mostrar u ocultar contraseña en el formulario de ingreso (CU02).
// Cambia el tipo del campo entre "password" y "text" y actualiza el texto y la etiqueta accesible del botón.
document.querySelectorAll('[data-password-target]').forEach(button => {
    button.addEventListener('click', () => {
        const field = document.getElementById(button.dataset.passwordTarget);
        const visible = field.type === 'password';
        field.type = visible ? 'text' : 'password';
        button.textContent = visible ? 'Ocultar' : 'Ver';
        button.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
    });
});
// Requisitos de contraseña en vivo (CU01 y CU04): marca cada requisito mientras se escribe y comprueba la confirmación.
// Es solo una ayuda: la regla real la aplica el servidor (Validation::passwordProblems). Si el campo es opcional
// (edición de usuario) y está vacío, no se valida nada.
const passwordRules = {
    length: value => Array.from(value).length >= 8,
    upper: value => /\p{Lu}/u.test(value),
    lower: value => /\p{Ll}/u.test(value),
    number: value => /[0-9]/.test(value),
    special: value => /[^\p{L}\p{N}]/u.test(value)
};
// Cuenta los bytes UTF-8 de la contraseña (el servidor admite hasta 72).
const utf8Length = value => Array.from(value).reduce((total, char) => {
    const code = char.codePointAt(0);
    return total + (code < 0x80 ? 1 : code < 0x800 ? 2 : code < 0x10000 ? 3 : 4);
}, 0);
document.querySelectorAll('[data-password-rules]').forEach(box => {
    const form = box.closest('form');
    const password = form && form.querySelector('[name="password"]');
    const confirmation = form && form.querySelector('[name="password_confirmation"]');
    if (!password || !confirmation) return;
    const optional = box.hasAttribute('data-optional');
    const limit = box.querySelector('[data-rule-limit]');
    const match = box.querySelector('[data-password-match]');
    // Pone un elemento en estado cumplido (✓), pendiente (✗) o neutro (○); el estado también se dice con texto.
    const mark = (element, met, neutral) => {
        const symbol = element.querySelector('.rule-mark');
        const state = element.querySelector('[data-rule-state]');
        if (symbol) symbol.textContent = neutral ? '○' : met ? '✓' : '✗';
        if (state) state.textContent = neutral ? '' : met ? ' (cumplido)' : ' (pendiente)';
        element.classList.toggle('is-met', !neutral && met);
        element.classList.toggle('is-pending', !neutral && !met);
    };
    const refresh = () => {
        const value = password.value;
        const neutral = optional && value === '' && confirmation.value === '';
        box.querySelectorAll('[data-rule]').forEach(item => mark(item, passwordRules[item.dataset.rule](value), neutral));
        if (limit) limit.hidden = utf8Length(value) <= 72;
        if (neutral || (value === '' && confirmation.value === '')) {
            match.textContent = '';
            match.classList.remove('is-met', 'is-pending');
        } else if (confirmation.value === '') {
            match.textContent = 'Repite la contraseña para confirmarla.';
            match.classList.remove('is-met', 'is-pending');
        } else {
            const same = value === confirmation.value;
            match.textContent = same ? '✓ Las contraseñas coinciden.' : '✗ Las contraseñas no coinciden.';
            match.classList.toggle('is-met', same);
            match.classList.toggle('is-pending', !same);
        }
    };
    password.addEventListener('input', refresh);
    confirmation.addEventListener('input', refresh);
    refresh();
});
// Formulario de usuario (CU04): muestra y exige los datos de paciente solo si el rol elegido es paciente.
const roleSelect = document.getElementById('rol');
const patientFields = document.getElementById('patient-fields');
if (roleSelect && patientFields) {
    const update = () => {
        const isPatient = roleSelect.selectedOptions[0]?.dataset.role === 'paciente';
        patientFields.hidden = !isPatient;
        patientFields.querySelectorAll('input').forEach(field => { field.required = isPatient; });
    };
    roleSelect.addEventListener('change', update); update();
}
// Consulta de disponibilidad (CU11): al cambiar la especialidad se limpia el médico y se vuelve a buscar.
const specialtySelect = document.getElementById('availability-specialty');
if (specialtySelect) {
    specialtySelect.addEventListener('change', () => {
        const doctorSelect = document.getElementById('availability-doctor');
        if (doctorSelect) doctorSelect.value = '';
        document.getElementById('availability-search')?.requestSubmit();
    });
}

// Filtros de reportes (CU20): muestra solo los filtros que aplican al tipo de reporte elegido
// y desactiva los ocultos para que no se envíen.
document.querySelectorAll('[data-report-filters]').forEach(form => {
    const kind = form.querySelector('[name="tipo"]');
    const update = () => {
        form.querySelectorAll('[data-report-scope]').forEach(field => {
            const scope = field.dataset.reportScope;
            const visible = scope === kind.value || (scope === 'appointments' && kind.value !== 'pacientes');
            field.hidden = !visible;
            field.querySelectorAll('select, input').forEach(control => { control.disabled = !visible; });
        });
        form.querySelectorAll('[data-report-help]').forEach(help => {
            help.hidden = help.dataset.reportHelp !== kind.value;
        });
    };
    kind.addEventListener('change', update);
    update();
});

// Contador de la cita (CU29): muestra el tiempo hasta el turno y el tiempo restante.
// El reloj del servidor y performance.now() evitan depender del reloj del equipo.
document.querySelectorAll('[data-counter-url]').forEach(panel => {
    let start = Number(panel.dataset.start), end = Number(panel.dataset.end);
    let server = Number(panel.dataset.server), reference = performance.now();
    let lastSync = performance.now(), pending = false, stopped = false;
    const output = panel.querySelector('[data-counter-output]');
    const status = panel.querySelector('[data-counter-status]');
    // Formatea milisegundos como "d días · hh:mm:ss" (los días solo aparecen si hay al menos uno).
    const duration = ms => {
        const seconds = Math.max(0, Math.ceil(ms / 1000));
        const days = Math.floor(seconds / 86400);
        const clock = [Math.floor(seconds % 86400 / 3600), Math.floor(seconds % 3600 / 60), seconds % 60]
            .map(value => String(value).padStart(2, '0')).join(':');
        return (days ? `${days} día${days === 1 ? '' : 's'} · ` : '') + clock;
    };
    // Recalcula el texto con la hora del servidor más el tiempo transcurrido desde la última sincronización.
    // Si no hubo sincronización en 2 minutos, pide actualizar la página en lugar de mostrar un dato vencido.
    const draw = () => {
        if (stopped) return;
        const now = server + performance.now() - reference;
        if (performance.now() - lastSync > 120000) {
            output.textContent = 'Actualiza la página para comprobar el horario vigente.';
            return;
        }
        output.textContent = now < start ? `Tiempo hasta la cita: ${duration(start - now)}`
            : now < end ? `Tiempo restante del turno: ${duration(end - now)}` : 'Turno finalizado';
    };
    // Consulta a /citas/{id}/contador los tiempos del turno (CU29) y los ajusta.
    // Si la cita ya no está activa, oculta el panel y deja de actualizarlo. Evita solicitudes simultáneas con "pending".
    const sync = async () => {
        if (pending || document.hidden || stopped) return;
        pending = true;
        try {
            const response = await fetch(panel.dataset.counterUrl, {
                credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'},
                signal: AbortSignal.timeout(10000)
            });
            if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Sin acceso');
            const data = await response.json();
            if (!Number.isFinite(data.servidor) || !Number.isFinite(data.inicio) || !Number.isFinite(data.fin)) throw new Error('Horario inválido');
            if (!data.activo) { panel.hidden = true; stopped = true; return; }
            start = data.inicio; end = data.fin; server = data.servidor;
            reference = lastSync = performance.now();
            status.textContent = 'Horario de Bolivia. Horario comprobado con el servidor.';
            draw();
        } catch {
            status.textContent = 'No se pudo comprobar el horario actual. Revisa la conexión o actualiza la página.';
        } finally { pending = false; }
    };
    draw();
    // Refresca el texto cada segundo y sincroniza cada 30 segundos; ambos temporizadores se detienen al terminar el contador.
    const timer = setInterval(() => { if (stopped) { clearInterval(timer); return; } draw(); }, 1000);
    const poller = setInterval(() => { if (stopped) { clearInterval(poller); return; } sync(); }, 30000);
    // Al volver a mostrar la pestaña, se sincroniza de inmediato.
    document.addEventListener('visibilitychange', () => { if (!document.hidden) sync(); });
});
