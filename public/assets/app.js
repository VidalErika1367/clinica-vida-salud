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


// La elección del perfil organiza el formulario, pero NO concede permisos.
// El backend sigue autenticando y determinando los permisos reales.
document.querySelectorAll('[data-profile-login]').forEach(layout => {
    const allowed = {
        administrador: 'Administrador',
        recepcionista: 'Recepcionista',
        medico: 'Médico',
        paciente: 'Paciente'
    };
    const selectStep = layout.querySelector('[data-profile-step="select"]');
    const loginStep = layout.querySelector('[data-profile-step="login"]');
    const next = layout.querySelector('[data-profile-continue]');
    const back = layout.querySelector('[data-profile-back]');
    const roleInput = layout.querySelector('[data-profile-hidden-role]');
    const roleTitle = layout.querySelector('[data-profile-role-label]');
    const identity = layout.querySelector('[data-profile-identity]');
    const indicators = layout.querySelectorAll('[data-profile-indicator]');
    const choices = layout.querySelectorAll('[data-profile-option]');
    const patientOnly = layout.querySelectorAll('[data-profile-patient-only]');
    let selected = Object.hasOwn(allowed, layout.dataset.initialRole) ? layout.dataset.initialRole : '';

    const updateChoice = () => {
        choices.forEach(choice => choice.setAttribute('aria-pressed', String(choice.dataset.profileOption === selected)));
        roleInput.value = selected;
        roleTitle.textContent = allowed[selected] || 'usuario';
        patientOnly.forEach(item => { item.hidden = selected !== 'paciente'; });
        next.disabled = !selected;
    };
    const showStep = (step, focus = true) => {
        selectStep.hidden = step !== 'select';
        loginStep.hidden = step !== 'login';
        indicators.forEach(item => {
            if (item.dataset.profileIndicator === step) item.setAttribute('aria-current', 'step');
            else item.removeAttribute('aria-current');
            if (item.dataset.profileIndicator === 'select') item.dataset.done = String(step === 'login');
        });
        if (focus) {
            if (step === 'login') identity.focus();
            else (layout.querySelector('[data-profile-option][aria-pressed="true"]') || choices[0]).focus();
        }
    };
    choices.forEach(choice => choice.addEventListener('click', () => {
        selected = choice.dataset.profileOption;
        updateChoice();
    }));
    next.addEventListener('click', () => { if (selected) showStep('login'); });
    back.addEventListener('click', () => showStep('select'));
    updateChoice();
    showStep(layout.dataset.showLogin === 'true' ? 'login' : 'select', false);
});

// Comandos de voz en reportes (CU20). Todo ocurre en el navegador: el audio y el texto no se envían a ningún servidor.
// El servidor recibe los mismos parámetros GET que el formulario, más voz=1 para saber que vino de un comando.
const REPORT_MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const REPORT_STATES = {
    programada: 'programada', programadas: 'programada', confirmada: 'confirmada', confirmadas: 'confirmada',
    atendida: 'atendida', atendidas: 'atendida', cancelada: 'cancelada', canceladas: 'cancelada',
    inasistencia: 'inasistencia', inasistencias: 'inasistencia', vigente: 'vigente', vigentes: 'vigente'
};
const DOCTOR_TITLES = ['dr', 'dra', 'doctor', 'doctora'];
const PERIOD_LABELS = {
    'hoy': 'de hoy', 'ayer': 'de ayer', 'esta semana': 'de esta semana', 'semana pasada': 'de la semana pasada',
    'este mes': 'de este mes', 'mes pasado': 'del mes pasado', 'este ano': 'de este año'
};

// Quita tildes, pasa a minúsculas y separa por espacios: "Pediatría" → "pediatria".
const normalizarFrase = texto => String(texto).normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
// Las fechas se manejan como AAAA-MM-DD y los cálculos en UTC, para no depender de la zona del equipo.
const fechaUTC = texto => { const [a, m, d] = texto.split('-').map(Number); return new Date(Date.UTC(a, m - 1, d)); };
const textoFecha = dt => dt.toISOString().slice(0, 10);
const sumarDias = (texto, dias) => { const dt = fechaUTC(texto); dt.setUTCDate(dt.getUTCDate() + dias); return textoFecha(dt); };
const ultimoDia = (anio, mes) => new Date(Date.UTC(anio, mes + 1, 0)).getUTCDate(); // mes base 0
const dosDigitos = n => String(n).padStart(2, '0');
// Fecha de hoy en Bolivia (America/La_Paz), sin depender de la zona horaria del navegador.
const hoyBolivia = ms => {
    const partes = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {timeZone: 'America/La_Paz', year: 'numeric', month: '2-digit', day: '2-digit'})
        .formatToParts(new Date(ms)).map(p => [p.type, p.value]));
    return `${partes.year}-${partes.month}-${partes.day}`;
};
// Períodos con nombre según la fecha de hoy. Las semanas van de lunes a domingo.
const rangosReporte = hoy => {
    const dt = fechaUTC(hoy), anio = dt.getUTCFullYear(), mes = dt.getUTCMonth();
    const lunes = sumarDias(hoy, -((dt.getUTCDay() + 6) % 7));
    const rangoMes = (a, m) => [`${a}-${dosDigitos(m + 1)}-01`, `${a}-${dosDigitos(m + 1)}-${dosDigitos(ultimoDia(a, m))}`];
    const previo = new Date(Date.UTC(anio, mes - 1, 1));
    return {
        'hoy': [hoy, hoy], 'ayer': [sumarDias(hoy, -1), sumarDias(hoy, -1)],
        'esta semana': [lunes, sumarDias(lunes, 6)], 'semana pasada': [sumarDias(lunes, -7), sumarDias(lunes, -1)],
        'este mes': rangoMes(anio, mes), 'mes pasado': rangoMes(previo.getUTCFullYear(), previo.getUTCMonth()),
        'este ano': [`${anio}-01-01`, `${anio}-12-31`]
    };
};
// Período de la frase: "del 1 al 15 de noviembre", un nombre de período o un mes del año actual.
// Sin período dicho se usa este mes (encontrado: false).
const periodoDeFrase = (frase, hoy) => {
    const anio = fechaUTC(hoy).getUTCFullYear();
    const rango = /del (\d{1,2}) al (\d{1,2}) de ([a-z]+)/.exec(frase);
    if (rango && REPORT_MONTHS.includes(rango[3])) {
        const mes = REPORT_MONTHS.indexOf(rango[3]), d1 = Number(rango[1]), d2 = Number(rango[2]);
        if (d1 >= 1 && d1 <= d2 && d2 <= ultimoDia(anio, mes)) {
            return {desde: `${anio}-${dosDigitos(mes + 1)}-${dosDigitos(d1)}`, hasta: `${anio}-${dosDigitos(mes + 1)}-${dosDigitos(d2)}`, clave: null, encontrado: true};
        }
    }
    const rangos = rangosReporte(hoy);
    const clave = ['mes pasado', 'este mes', 'semana pasada', 'esta semana', 'este ano', 'ayer', 'hoy']
        .find(c => ` ${frase} `.includes(` ${c} `));
    if (clave) return {desde: rangos[clave][0], hasta: rangos[clave][1], clave, encontrado: true};
    const nombreMes = frase.split(' ').find(t => REPORT_MONTHS.includes(t));
    if (nombreMes) {
        const mes = REPORT_MONTHS.indexOf(nombreMes);
        return {desde: `${anio}-${dosDigitos(mes + 1)}-01`, hasta: `${anio}-${dosDigitos(mes + 1)}-${dosDigitos(ultimoDia(anio, mes))}`, clave: null, encontrado: true};
    }
    return {desde: rangos['este mes'][0], hasta: rangos['este mes'][1], clave: 'este mes', encontrado: false};
};
const tipoDeFrase = frase => {
    const tokens = frase.split(' ');
    if (tokens.includes('altas') || frase.includes('pacientes registrados') || frase.includes('pacientes nuevos')) return 'pacientes';
    if (tokens.includes('pagos') || tokens.includes('pago') || tokens.includes('multas')) return 'pagos';
    if (tokens.includes('citas') || tokens.includes('actividad')) return 'citas';
    return null;
};
const estadoDeFrase = frase => {
    let valor = 'todos', vigente = false;
    frase.split(' ').forEach(token => {
        if (!Object.hasOwn(REPORT_STATES, token)) return;
        if (REPORT_STATES[token] === 'vigente') vigente = true; else valor = REPORT_STATES[token];
    });
    return {valor, vigente};
};
// Especialidad: su nombre completo aparece en la frase (sin tildes ni mayúsculas).
const buscarEspecialidad = (frase, opciones) => {
    const hallazgos = opciones
        .map(o => ({value: o.value, nombre: normalizarFrase(o.label)}))
        .filter(o => o.value !== '' && o.nombre && ` ${frase} `.includes(` ${o.nombre} `))
        .sort((a, b) => b.nombre.length - a.nombre.length);
    return hallazgos[0]?.value ?? '';
};
const contieneSecuencia = (tokens, secuencia) => tokens.some((_, i) => secuencia.every((s, j) => tokens[i + j] === s));
// Médico: se comparan el nombre y el primer apellido, sin títulos ("Dr.", "Dra.").
const buscarMedico = (frase, opciones) => {
    const tokens = frase.split(' ');
    let mejor = null;
    for (const o of opciones) {
        if (o.value === '') continue;
        const clave = normalizarFrase(o.label).split(' ').filter(t => t && !DOCTOR_TITLES.includes(t)).slice(0, 2);
        if (clave.length && contieneSecuencia(tokens, clave) && (!mejor || clave.length > mejor.clave.length)) mejor = {value: o.value, clave};
    }
    return mejor?.value ?? '';
};

/**
 * Interpreta una frase de voz para el reporte de CU20. Función pura, sin acceso al DOM.
 * @param {string} frase texto reconocido
 * @param {{ahora?: number, medicos?: {value: string, label: string}[], especialidades?: {value: string, label: string}[]}} contexto
 *        ahora: instante en ms; medicos y especialidades: opciones reales de los select.
 * @returns {{ok: true, tipo: string, desde: string, hasta: string, estado: string, medico_id: string, especialidad_id: string}
 *          | {ok: false, motivo: 'no_entendido' | 'vigentes', escuchado: string}}
 */
const interpretarFraseReporte = (frase, {ahora = Date.now(), medicos = [], especialidades = []} = {}) => {
    const texto = normalizarFrase(frase);
    const hoy = hoyBolivia(ahora);
    const tipoExplicito = tipoDeFrase(texto);
    const tipo = tipoExplicito || 'citas';
    const periodo = periodoDeFrase(texto, hoy);
    const estado = estadoDeFrase(texto);
    // El servidor no tiene el estado «vigentes»: no se envía nada para no mostrar un dato distinto del pedido.
    if (estado.vigente) return {ok: false, motivo: 'vigentes', escuchado: frase};
    const medico = tipo === 'pacientes' ? '' : buscarMedico(texto, medicos);
    const especialidad = tipo === 'pacientes' ? '' : buscarEspecialidad(texto, especialidades);
    const entendido = tipoExplicito || periodo.encontrado || estado.valor !== 'todos' || medico || especialidad
        || texto.split(' ').some(t => t === 'reporte' || t === 'reportes');
    if (!entendido) return {ok: false, motivo: 'no_entendido', escuchado: frase};
    return {
        ok: true, tipo, desde: periodo.desde, hasta: periodo.hasta,
        estado: tipo === 'citas' ? estado.valor : 'todos', medico_id: medico, especialidad_id: especialidad
    };
};

// Nombre del período para la lectura: «de este mes», «del 1 al 15 de noviembre»…
const describirPeriodo = (desde, hasta, hoy) => {
    const rangos = rangosReporte(hoy);
    const clave = Object.keys(rangos).find(k => rangos[k][0] === desde && rangos[k][1] === hasta);
    if (clave) return PERIOD_LABELS[clave];
    const d1 = fechaUTC(desde), d2 = fechaUTC(hasta), mes = REPORT_MONTHS[d1.getUTCMonth()];
    if (d1.getUTCMonth() === d2.getUTCMonth() && d1.getUTCFullYear() === d2.getUTCFullYear()) {
        if (d1.getUTCDate() === 1 && d2.getUTCDate() === ultimoDia(d1.getUTCFullYear(), d1.getUTCMonth())) return `de ${mes}`;
        return `del ${d1.getUTCDate()} al ${d2.getUTCDate()} de ${mes}`;
    }
    const larga = dt => `${dt.getUTCDate()} de ${REPORT_MONTHS[dt.getUTCMonth()]} de ${dt.getUTCFullYear()}`;
    return `del ${larga(d1)} al ${larga(d2)}`;
};
const plural = (n, uno, varios) => `${n} ${n === 1 ? uno : varios}`;
const enumerar = partes => partes.length < 2 ? partes.join('') : `${partes.slice(0, -1).join(', ')} y ${partes[partes.length - 1]}`;
/**
 * Arma el texto que se lee en voz alta con los totales del reporte.
 * @param {{tipo: string, periodo: string, totales: Object<string, number>}} datos
 * @returns {string}
 */
const textoLecturaReporte = ({tipo, periodo, totales}) => {
    const n = clave => Number(totales[clave] ?? 0);
    if (tipo === 'pagos') {
        const monto = n('monto'), enteros = Math.trunc(monto), centavos = Math.round((monto - enteros) * 100);
        const dinero = plural(enteros, 'boliviano', 'bolivianos') + (centavos ? ` con ${plural(centavos, 'centavo', 'centavos')}` : '');
        return `Pagos ${periodo}: ${plural(n('pagos'), 'pago', 'pagos')} por ${dinero}.`;
    }
    if (tipo === 'pacientes') {
        return `Altas de pacientes ${periodo}: ${plural(n('registrados'), 'paciente registrado', 'pacientes registrados')}, `
            + `${plural(n('activos'), 'activo', 'activos')} y ${plural(n('inactivos'), 'inactivo', 'inactivos')}.`;
    }
    const estados = [['programadas', 'programada', 'programadas'], ['confirmadas', 'confirmada', 'confirmadas'],
        ['atendidas', 'atendida', 'atendidas'], ['canceladas', 'cancelada', 'canceladas'], ['inasistencias', 'inasistencia', 'inasistencias']];
    const partes = estados.filter(([clave]) => n(clave) > 0).map(([clave, uno, varios]) => plural(n(clave), uno, varios));
    return `Reporte de citas ${periodo}: ${plural(n('total'), 'cita', 'citas')}.` + (partes.length ? ` ${enumerar(partes)}.` : '');
};
// Convierte el texto mostrado en la página (formato de Bolivia, p. ej. "1.234,50") en número.
const numeroDeTexto = texto => Number(String(texto).trim().replace(/\./g, '').replace(',', '.'));
// Lee los totales que muestra la página en ese momento.
const leerTotalesPagina = (doc, ahora) => {
    const form = doc.getElementById('report-filters');
    const tipo = form.querySelector('[name="tipo"]').value;
    const periodo = describirPeriodo(form.querySelector('[name="desde"]').value, form.querySelector('[name="hasta"]').value, hoyBolivia(ahora));
    const totales = {};
    doc.querySelectorAll('[data-total]').forEach(el => { totales[el.dataset.total] = numeroDeTexto(el.textContent); });
    return textoLecturaReporte({tipo, periodo, totales});
};
// Lee en voz alta con la síntesis del navegador; sin soporte no hace nada.
const hablar = (texto, win) => {
    const sintesis = win.speechSynthesis;
    if (!sintesis || !texto || !win.SpeechSynthesisUtterance) return false;
    sintesis.cancel();
    const frase = new win.SpeechSynthesisUtterance(texto);
    frase.lang = 'es-BO';
    const voces = sintesis.getVoices?.() || [];
    const voz = voces.find(v => v.lang === 'es-BO') || voces.find(v => v.lang?.startsWith('es'));
    if (voz) frase.voice = voz;
    sintesis.speak(frase);
    return true;
};
const opcionesDe = select => select ? [...select.options].map(o => ({value: o.value, label: o.textContent.trim()})) : [];
// Pone los valores interpretados en los campos existentes. Los valores salen de los mismos select, nunca se inventan.
const rellenarFormulario = (form, r) => {
    const campo = nombre => form.querySelector(`[name="${nombre}"]`);
    campo('tipo').value = r.tipo;
    campo('tipo').dispatchEvent(new Event('change')); // el formulario activa o desactiva los filtros del tipo
    campo('desde').value = r.desde;
    campo('hasta').value = r.hasta;
    campo('estado').value = r.estado;
    campo('medico_id').value = r.medico_id;
    campo('especialidad_id').value = r.especialidad_id;
};

/**
 * Activa el botón de voz y la lectura de totales de la página de reportes.
 * Sin SpeechRecognition el botón queda oculto y no se muestra ningún error.
 * @param {Document} doc
 * @param {Window} win
 */
const initReportVoice = (doc, win) => {
    const form = doc.getElementById('report-filters');
    if (!form) return;
    const boton = doc.getElementById('voice-report-button');
    const estado = doc.getElementById('voice-report-status');
    const leer = doc.getElementById('read-report-totals');
    const puedeLeer = doc.querySelectorAll('[data-total]').length > 0 && !!win.speechSynthesis && !!win.SpeechSynthesisUtterance;
    if (puedeLeer && leer) {
        leer.hidden = false;
        leer.addEventListener('click', () => hablar(leerTotalesPagina(doc, Date.now()), win));
        // Si la página llegó desde un comando de voz (voz=1), lee los totales al cargar.
        if (new URLSearchParams(win.location?.search || '').get('voz') === '1') hablar(leerTotalesPagina(doc, Date.now()), win);
    }
    const Reconocimiento = win.SpeechRecognition || win.webkitSpeechRecognition;
    if (!Reconocimiento || !boton) return;
    boton.hidden = false;
    let reconocimiento = null, escuchando = false;
    const mostrar = texto => { if (estado) estado.textContent = texto; };
    const ponerEscuchando = on => {
        escuchando = on;
        boton.textContent = on ? 'Escuchando…' : '🎤 Hablar';
        boton.setAttribute('aria-pressed', String(on));
    };
    // Envía el reporte solo si la frase se entiende; si no, no se envía nada.
    const procesar = frase => {
        const r = interpretarFraseReporte(frase, {ahora: Date.now(), medicos: opcionesDe(form.querySelector('[name="medico_id"]')),
            especialidades: opcionesDe(form.querySelector('[name="especialidad_id"]'))});
        if (!r.ok) {
            mostrar(r.motivo === 'vigentes'
                ? 'El reporte no tiene el estado «vigentes». Prueba con «citas programadas» o «citas confirmadas».'
                : `No entendí. Prueba: «citas de hoy» o «pagos de este mes». Escuché: «${r.escuchado}».`);
            return;
        }
        rellenarFormulario(form, r);
        mostrar('Generando el reporte…');
        const params = new URLSearchParams(new FormData(form));
        params.set('voz', '1');
        win.location.assign(`${form.getAttribute('action')}?${params}`);
    };
    // Una sola frase, sin resultados intermedios. Si el navegador no tiene es-BO, prueba es-ES.
    const escuchar = idiomas => {
        const rec = new Reconocimiento();
        rec.lang = idiomas[0];
        rec.continuous = false;
        rec.interimResults = false;
        rec.maxAlternatives = 1;
        rec.onresult = event => { procesar(event.results[0][0].transcript); };
        rec.onerror = event => {
            if (event.error === 'language-not-supported' && idiomas.length > 1) { escuchar(idiomas.slice(1)); return; }
            if (event.error === 'aborted') return;
            const motivos = {
                'not-allowed': 'Permite el uso del micrófono en el navegador para pedir reportes por voz.',
                'service-not-allowed': 'Permite el uso del micrófono en el navegador para pedir reportes por voz.',
                'no-speech': 'No escuché ninguna frase. Vuelve a intentarlo.',
                'network': 'El reconocimiento de voz no está disponible ahora. Usa el formulario.'
            };
            mostrar(motivos[event.error] || 'No se pudo usar el micrófono. Usa el formulario.');
        };
        rec.onend = () => { if (reconocimiento === rec) { reconocimiento = null; ponerEscuchando(false); } };
        reconocimiento = rec;
        ponerEscuchando(true);
        mostrar('Escuchando… di tu frase, por ejemplo «citas de hoy».');
        try { rec.start(); } catch {
            reconocimiento = null;
            ponerEscuchando(false);
            mostrar('No se pudo iniciar el micrófono. Usa el formulario.');
        }
    };
    // Otro clic o Escape detienen la escucha sin procesar lo dicho.
    boton.addEventListener('click', () => {
        if (escuchando && reconocimiento) { reconocimiento.abort(); return; }
        escuchar(['es-BO', 'es-ES']);
    });
    doc.addEventListener('keydown', event => {
        if (event.key === 'Escape' && escuchando && reconocimiento) reconocimiento.abort();
    });
};
if (typeof document !== 'undefined' && document.getElementById('report-filters')) initReportVoice(document, window);

// Pruebas de Node (tests/voice_reports_browser.mjs): exporta las funciones puras. En el navegador no existe `module`.
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {normalizarFrase, interpretarFraseReporte, describirPeriodo, textoLecturaReporte, leerTotalesPagina, initReportVoice};
}
