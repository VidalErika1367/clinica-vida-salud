// Reloj simulado y transporte local: sin navegador externo, red ni datos reales.
import vm from 'node:vm';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const source = fs.readFileSync(new URL('../public/assets/app.js', import.meta.url), 'utf8');
let time = 0, calls = 0;
const output = {}, status = {}, timers = [], events = {};
const panel = {dataset: {start: '60000', end: '1860000', server: '0', counterUrl: '/citas/1/contador'},
    querySelector: selector => selector === '[data-counter-output]' ? output : status};
let response = {inicio: 60000, fin: 1860000, servidor: 0, activo: true};
const context = vm.createContext({
    document: {hidden: false, querySelectorAll: s => s === '[data-counter-url]' ? [panel] : [],
        getElementById: () => null, addEventListener: (event, fn) => { events[event] = fn; }},
    performance: {now: () => time}, setInterval: (fn, delay) => { timers.push({fn, delay}); return timers.length; },
    clearInterval: () => {}, AbortSignal: {timeout: () => undefined},
    fetch: async () => { calls++; return {ok: true, headers: {get: () => 'application/json'}, json: async () => response}; }
});
vm.runInContext(source, context);
assert.equal(output.textContent, 'Tiempo hasta la cita: 00:01:00');
time = 60000; timers.find(t => t.delay === 1000).fn();
assert.equal(output.textContent, 'Tiempo restante del turno: 00:30:00');
response = {inicio: 120000, fin: 1920000, servidor: 60000, activo: true};
await events.visibilitychange(); await new Promise(resolve => setImmediate(resolve));
assert.equal(output.textContent, 'Tiempo hasta la cita: 00:01:00');
response = {inicio: 120000, fin: 1920000, servidor: 1920000, activo: true};
await timers.find(t => t.delay === 30000).fn(); await new Promise(resolve => setImmediate(resolve));
assert.equal(output.textContent, 'Turno finalizado');
response.activo = false;
await events.visibilitychange(); await new Promise(resolve => setImmediate(resolve));
assert.equal(panel.hidden, true);
assert.equal(calls, 3);
console.log('5 comprobaciones JavaScript: espera, inicio automático, reprogramación, fin y cancelación.');
