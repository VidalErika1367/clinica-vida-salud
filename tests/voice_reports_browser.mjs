// Comandos de voz de reportes (CU20): intérprete, texto de lectura y botón. Sin red ni servicios externos.
import vm from 'node:vm';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const source = fs.readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');
const quietDocument = {getElementById: () => null, querySelectorAll: () => [], addEventListener: () => {}};
// Entorno mínimo: app.js se ejecuta como en el navegador, con las mismas funciones globales que usa el código de voz.
class FakeEvent { constructor(type) { this.type = type; } }
class FakeFormData { constructor(form) { this.pairs = Object.keys(form.campos).map(name => [name, form.campos[name].value]); } [Symbol.iterator]() { return this.pairs[Symbol.iterator](); } }
const sandbox = {module: {exports: {}}, document: quietDocument, window: {matchMedia: () => ({matches: false, addEventListener() {}})},
    URLSearchParams, FormData: FakeFormData, Event: FakeEvent};
vm.runInNewContext(source, sandbox);
const api = sandbox.module.exports;
// Lunes 16/11/2026, 15:00 en Bolivia (UTC-4). Así "esta semana" es del 16 al 22 y "mes pasado" es octubre.
const AHORA = new Date('2026-11-16T15:00:00-04:00').getTime();
const medicos = [{value: '7', label: 'Marcelo Antelo Suárez'}, {value: '9', label: 'Gabriela Rocha Paz'}];
const especialidades = [{value: '1', label: 'Medicina General'}, {value: '2', label: 'Pediatría'}, {value: '3', label: 'Ginecología'}];
const interpretar = (frase, ahora = AHORA) => JSON.parse(JSON.stringify(api.interpretarFraseReporte(frase, {ahora, medicos, especialidades})));
let checks = 0;
const verify = (name, run) => { run(); checks++; };

verify('Ejemplos del intérprete: tipo, período, estado, médico y especialidad', () => {
    const casos = [
        ['reporte de citas de hoy', {tipo: 'citas', desde: '2026-11-16', hasta: '2026-11-16', estado: 'todos', medico_id: '', especialidad_id: ''}],
        ['citas canceladas de esta semana', {tipo: 'citas', desde: '2026-11-16', hasta: '2026-11-22', estado: 'cancelada'}],
        ['citas atendidas de Pediatría este mes', {tipo: 'citas', desde: '2026-11-01', hasta: '2026-11-30', estado: 'atendida', especialidad_id: '2'}],
        ['citas del doctor Marcelo Antelo de noviembre', {tipo: 'citas', desde: '2026-11-01', hasta: '2026-11-30', medico_id: '7'}],
        ['pagos de este mes', {tipo: 'pagos', desde: '2026-11-01', hasta: '2026-11-30', estado: 'todos'}],
        ['altas de pacientes del mes pasado', {tipo: 'pacientes', desde: '2026-10-01', hasta: '2026-10-31', medico_id: '', especialidad_id: ''}],
        ['inasistencias de la semana pasada', {tipo: 'citas', desde: '2026-11-09', hasta: '2026-11-15', estado: 'inasistencia'}],
        ['citas del 1 al 15 de noviembre', {tipo: 'citas', desde: '2026-11-01', hasta: '2026-11-15'}]
    ];
    for (const [frase, esperado] of casos) {
        const r = interpretar(frase);
        assert.equal(r.ok, true, `«${frase}» debe entenderse`);
        for (const [campo, valor] of Object.entries(esperado)) assert.equal(r[campo], valor, `«${frase}»: ${campo}`);
    }
});
verify('Sin tipo dicho se usa citas; sin período se usa este mes', () => {
    const r = interpretar('programadas');
    assert.equal(r.tipo, 'citas'); assert.equal(r.estado, 'programada');
    assert.equal(r.desde, '2026-11-01'); assert.equal(r.hasta, '2026-11-30');
});
verify('El día sale de la zona de Bolivia, no de UTC', () => {
    // 17/11 02:00 UTC es 16/11 22:00 en La Paz: el reporte de hoy sigue siendo del 16.
    const r = interpretar('citas de hoy', new Date('2026-11-17T02:00:00Z').getTime());
    assert.equal(r.desde, '2026-11-16');
});
verify('Frase no reconocida: no se envía nada y se devuelve lo escuchado', () => {
    const r = interpretar('hola que tal');
    assert.equal(r.ok, false); assert.equal(r.motivo, 'no_entendido'); assert.equal(r.escuchado, 'hola que tal');
});
verify('«vigentes» no existe en el servidor: no se envía', () => {
    const r = interpretar('citas vigentes de hoy');
    assert.equal(r.ok, false); assert.equal(r.motivo, 'vigentes');
});
verify('Texto de lectura en voz alta con los totales', () => {
    assert.equal(api.textoLecturaReporte({tipo: 'citas', periodo: 'de este mes', totales: {total: 12, atendidas: 8, canceladas: 3, inasistencias: 1}}),
        'Reporte de citas de este mes: 12 citas. 8 atendidas, 3 canceladas y 1 inasistencia.');
    assert.equal(api.textoLecturaReporte({tipo: 'pagos', periodo: 'de este mes', totales: {pagos: 4, monto: 80}}),
        'Pagos de este mes: 4 pagos por 80 bolivianos.');
    assert.equal(api.textoLecturaReporte({tipo: 'pacientes', periodo: 'del mes pasado', totales: {registrados: 5, activos: 4, inactivos: 1}}),
        'Altas de pacientes del mes pasado: 5 pacientes registrados, 4 activos y 1 inactivo.');
});
verify('Período en la lectura: nombres relativos y rango con días', () => {
    assert.equal(api.describirPeriodo('2026-11-16', '2026-11-16', '2026-11-16'), 'de hoy');
    assert.equal(api.describirPeriodo('2026-11-01', '2026-11-30', '2026-11-16'), 'de este mes');
    assert.equal(api.describirPeriodo('2026-11-01', '2026-11-15', '2026-11-16'), 'del 1 al 15 de noviembre');
});
verify('Lectura de la página: toma los totales que muestra el reporte', () => {
    const campos = {tipo: {value: 'pagos'}, desde: {value: '2026-11-01'}, hasta: {value: '2026-11-30'}};
    const doc = {
        getElementById: id => id === 'report-filters' ? {querySelector: sel => campos[sel.match(/name="(\w+)"/)[1]]} : null,
        querySelectorAll: sel => sel === '[data-total]' ? [{dataset: {total: 'pagos'}, textContent: '4'}, {dataset: {total: 'monto'}, textContent: '80,00'}] : []
    };
    assert.equal(api.leerTotalesPagina(doc, AHORA), 'Pagos de este mes: 4 pagos por 80 bolivianos.');
});

// Botón de voz: stubs del DOM y de SpeechRecognition.
const fakeElement = () => ({hidden: false, textContent: '', listeners: {}, setAttribute() {}, addEventListener(ev, fn) { this.listeners[ev] = fn; }});
const crearPagina = ({conVoz}) => {
    const boton = {...fakeElement(), hidden: true, textContent: '🎤 Hablar'};
    const estado = fakeElement();
    const leer = {...fakeElement(), hidden: true};
    const campos = {
        tipo: {value: 'citas', dispatchEvent() {}},
        desde: {value: '2026-11-16'}, hasta: {value: '2026-11-16'}, estado: {value: 'todos'},
        medico_id: {value: '', options: [{value: '', textContent: 'Todos los médicos'}, {value: '7', textContent: 'Marcelo Antelo Suárez'}]},
        especialidad_id: {value: '', options: [{value: '', textContent: 'Todas'}, {value: '2', textContent: 'Pediatría'}]}
    };
    const form = {campos, getAttribute: () => '/reportes', querySelector: sel => campos[sel.match(/name="(\w+)"/)[1]]};
    const doc = {
        getElementById: id => ({'report-filters': form, 'voice-report-button': boton, 'voice-report-status': estado, 'read-report-totals': leer})[id] ?? null,
        querySelectorAll: () => [],
        addEventListener: (ev, fn) => { doc.keydown = fn; }
    };
    const navegaciones = [];
    const instancias = [];
    class FakeRecognition {
        constructor() { this.abortado = false; instancias.push(this); }
        start() {}
        abort() { this.abortado = true; this.onerror?.({error: 'aborted'}); this.onend?.(); }
    }
    const win = {location: {search: '', assign: url => navegaciones.push(url)}};
    if (conVoz) win.SpeechRecognition = FakeRecognition;
    api.initReportVoice(doc, win);
    return {boton, estado, leer, campos, doc, navegaciones, instancias};
};

verify('Sin SpeechRecognition el botón queda oculto y no se añade ningún manejador', () => {
    const {boton, leer} = crearPagina({conVoz: false});
    assert.equal(boton.hidden, true);
    assert.deepEqual(Object.keys(boton.listeners), []);
    assert.equal(leer.hidden, true);
});
verify('Con SpeechRecognition el botón aparece', () => {
    const {boton} = crearPagina({conVoz: true});
    assert.equal(boton.hidden, false);
    assert.equal(typeof boton.listeners.click, 'function');
});
verify('Frase no entendida: aviso como texto, sin envío', () => {
    const p = crearPagina({conVoz: true});
    p.boton.listeners.click();
    assert.equal(p.boton.textContent, 'Escuchando…');
    p.instancias[0].onresult({results: [[{transcript: '<b>hola</b> que tal'}]]});
    assert.equal(p.navegaciones.length, 0);
    assert.match(p.estado.textContent, /No entendí\. Prueba: «citas de hoy» o «pagos de este mes»/);
    assert.ok(p.estado.textContent.includes('<b>hola</b>'), 'lo escuchado se muestra literal');
});
verify('Frase entendida: rellena los campos y envía con voz=1', () => {
    const p = crearPagina({conVoz: true});
    p.boton.listeners.click();
    p.instancias[0].onresult({results: [[{transcript: 'citas canceladas del doctor Marcelo Antelo de esta semana'}]]});
    assert.equal(p.navegaciones.length, 1);
    const url = new URL(p.navegaciones[0], 'http://localhost');
    assert.equal(url.pathname, '/reportes');
    assert.equal(url.searchParams.get('voz'), '1');
    assert.equal(url.searchParams.get('estado'), 'cancelada');
    assert.equal(url.searchParams.get('medico_id'), '7');
    assert.equal(url.searchParams.get('desde'), '2026-11-16');
    assert.equal(url.searchParams.get('hasta'), '2026-11-22');
});
verify('Otro clic detiene la escucha', () => {
    const p = crearPagina({conVoz: true});
    p.boton.listeners.click();
    p.boton.listeners.click();
    assert.equal(p.instancias[0].abortado, true);
    assert.equal(p.boton.textContent, '🎤 Hablar');
    assert.equal(p.navegaciones.length, 0);
});
verify('Escape detiene la escucha', () => {
    const p = crearPagina({conVoz: true});
    p.boton.listeners.click();
    p.doc.keydown({key: 'Escape'});
    assert.equal(p.instancias[0].abortado, true);
    assert.equal(p.boton.textContent, '🎤 Hablar');
});

console.log(`${checks} comprobaciones JavaScript: intérprete, lectura de totales, frases no reconocidas y botón de voz.`);
