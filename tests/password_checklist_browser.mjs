// Lista de requisitos de contraseña en vivo (CU01 y CU04): ejecuta public/assets/app.js con un DOM simulado.
import vm from 'node:vm';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const source=fs.readFileSync(new URL('../public/assets/app.js',import.meta.url),'utf8');
const checks=[];
const verify=(name,run)=>{run();checks.push(name);};
const classes=()=>{const set=new Set();return {add:(...c)=>c.forEach(x=>set.add(x)),remove:(...c)=>c.forEach(x=>set.delete(x)),
    toggle:(c,on)=>{(on?set.add(c):set.delete(c));return on;},contains:c=>set.has(c)};};
// Crea la página simulada: un formulario con los dos campos y la lista de requisitos.
const build=optional=>{
    const listeners={password:{},confirmation:{}};
    const password={value:'',addEventListener:(e,fn)=>{listeners.password[e]=fn;}};
    const confirmation={value:'',addEventListener:(e,fn)=>{listeners.confirmation[e]=fn;}};
    const items=['length','upper','lower','number','special'].map(rule=>{
        const mark={textContent:'○'}, state={textContent:''};
        return {dataset:{rule},mark,state,classList:classes(),
            querySelector:s=>s==='.rule-mark'?mark:s==='[data-rule-state]'?state:null};
    });
    const limit={hidden:true}, match={textContent:'',classList:classes()};
    const form={querySelector:s=>s==='[name="password"]'?password:s==='[name="password_confirmation"]'?confirmation:null};
    const box={closest:s=>s==='form'?form:null,hasAttribute:n=>optional&&n==='data-optional',
        querySelector:s=>s==='[data-rule-limit]'?limit:s==='[data-password-match]'?match:null,
        querySelectorAll:s=>s==='[data-rule]'?items:[]};
    const document={hidden:false,getElementById:()=>null,addEventListener:()=>{},
        querySelectorAll:s=>s==='[data-password-rules]'?[box]:[]};
    vm.runInNewContext(source,{document,window:{matchMedia:()=>({matches:false,addEventListener:()=>{}})}});
    const type=(field,value)=>{(field==='password'?password:confirmation).value=value;listeners[field].input();};
    const marks=()=>items.map(i=>i.mark.textContent).join('');
    const met=()=>items.map(i=>i.classList.contains('is-met')?'1':'0').join('');
    return {items,limit,match,type,marks,met,password,confirmation};
};
const page=build(false);
verify('Inicio obligatorio: todos los requisitos pendientes con ✗ y texto',()=>{
    assert.equal(page.marks(),'✗✗✗✗✗');assert.equal(page.items[0].state.textContent,' (pendiente)');
    assert(page.items.every(i=>i.classList.contains('is-pending')));assert.equal(page.match.textContent,'');
});
verify('Escribir "abc": solo la minúscula se cumple',()=>{
    page.type('password','abc');assert.equal(page.met(),'00100');assert.equal(page.marks(),'✗✗✓✗✗');
});
verify('Cada requisito se marca por separado',()=>{
    page.type('password','ABCDEFGH');assert.equal(page.met(),'11000');
    page.type('password','12345678');assert.equal(page.met(),'10010');
    page.type('password','........');assert.equal(page.met(),'10001');
});
verify('Contraseña completa: todo ✓ con texto "cumplido"',()=>{
    page.type('password','Abcdef1!');assert.equal(page.marks(),'✓✓✓✓✓');assert.equal(page.met(),'11111');
    assert.equal(page.items[4].state.textContent,' (cumplido)');
});
verify('Falta mayúscula, minúscula, número o especial: lo marca pendiente',()=>{
    page.type('password','abcdef1!');assert.equal(page.met(),'10111');
    page.type('password','ABCDEF1!');assert.equal(page.met(),'11011');
    page.type('password','Abcdefg!');assert.equal(page.met(),'11101');
    page.type('password','Abcdef12');assert.equal(page.met(),'11110');
    page.type('password','Ab1!');assert.equal(page.met(),'01111');
});
verify('Letras con tilde y ñ cuentan como letras; el espacio es carácter especial',()=>{
    page.type('password','Ñandú 123');assert.equal(page.met(),'11111');
    page.type('password','ÁÉÍÓÚ 1a');assert.equal(page.met(),'11111');
    page.type('password','Ñandúñ12');assert.equal(page.met(),'11110');
});
verify('Límite de 72 bytes: avisa cuando se supera y no antes',()=>{
    page.type('password','a'.repeat(72));assert.equal(page.limit.hidden,true);
    page.type('password','a'.repeat(73));assert.equal(page.limit.hidden,false);
    page.type('password','ñ'.repeat(37));assert.equal(page.limit.hidden,false);
    page.type('password','ñ'.repeat(36));assert.equal(page.limit.hidden,true);
});
verify('Confirmación: pide repetir, detecta diferencia y coincidencia con ✗/✓',()=>{
    page.type('password','Abcdef1!');page.type('confirmation','');
    assert.equal(page.match.textContent,'Repite la contraseña para confirmarla.');
    page.type('confirmation','Abcdef1');assert.equal(page.match.textContent,'✗ Las contraseñas no coinciden.');
    assert(page.match.classList.contains('is-pending'));
    page.type('confirmation','Abcdef1!');assert.equal(page.match.textContent,'✓ Las contraseñas coinciden.');
    assert(page.match.classList.contains('is-met')&&!page.match.classList.contains('is-pending'));
    page.type('password','Abcdef1?');assert.equal(page.match.textContent,'✗ Las contraseñas no coinciden.');
});
const edit=build(true);
verify('Edición: con el campo vacío no se valida nada (○ neutro, sin mensajes)',()=>{
    assert.equal(edit.marks(),'○○○○○');assert(edit.items.every(i=>!i.classList.contains('is-pending')&&!i.classList.contains('is-met')));
    assert.equal(edit.items[0].state.textContent,'');assert.equal(edit.match.textContent,'');
});
verify('Edición: al escribir algo la lista empieza a validar y al vaciar vuelve a neutro',()=>{
    edit.type('password','abc');assert.equal(edit.marks(),'✗✗✓✗✗');
    edit.type('password','');assert.equal(edit.marks(),'○○○○○');assert.equal(edit.match.textContent,'');
    edit.type('confirmation','x');assert.equal(edit.match.textContent,'✗ Las contraseñas no coinciden.');
});
console.log(checks.length+' comprobaciones JavaScript de la lista de requisitos de contraseña.');
