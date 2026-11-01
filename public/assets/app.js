document.querySelectorAll('[data-password-target]').forEach(button => {
    button.addEventListener('click', () => {
        const field = document.getElementById(button.dataset.passwordTarget);
        const visible = field.type === 'password';
        field.type = visible ? 'text' : 'password';
        button.textContent = visible ? 'Ocultar' : 'Ver';
        button.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
    });
});
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
const specialtySelect = document.getElementById('availability-specialty');
if (specialtySelect) {
    specialtySelect.addEventListener('change', () => {
        const doctorSelect = document.getElementById('availability-doctor');
        if (doctorSelect) doctorSelect.value = '';
        document.getElementById('availability-search')?.requestSubmit();
    });
}

// El reloj del servidor y performance.now() evitan depender del reloj del equipo.
document.querySelectorAll('[data-counter-url]').forEach(panel => {
    let start = Number(panel.dataset.start), end = Number(panel.dataset.end);
    let server = Number(panel.dataset.server), reference = performance.now();
    let lastSync = performance.now(), pending = false, stopped = false;
    const output = panel.querySelector('[data-counter-output]');
    const status = panel.querySelector('[data-counter-status]');
    const duration = ms => {
        const seconds = Math.max(0, Math.ceil(ms / 1000));
        const days = Math.floor(seconds / 86400);
        const clock = [Math.floor(seconds % 86400 / 3600), Math.floor(seconds % 3600 / 60), seconds % 60]
            .map(value => String(value).padStart(2, '0')).join(':');
        return (days ? `${days} día${days === 1 ? '' : 's'} · ` : '') + clock;
    };
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
    const timer = setInterval(() => { if (stopped) { clearInterval(timer); return; } draw(); }, 1000);
    const poller = setInterval(() => { if (stopped) { clearInterval(poller); return; } sync(); }, 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) sync(); });
});
