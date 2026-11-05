// Interacciones y cambios de ancho del menú, sin servicios externos.
import vm from 'node:vm';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const source=fs.readFileSync(new URL('../public/assets/app.js',import.meta.url),'utf8');
const checks=[];
const verify=(name,run)=>{run();checks.push(name);};
const handlers={}, toggleEvents={}, navEvents={}, attributes={};
let resize;
const media={matches:true,addEventListener:(_,fn)=>{resize=fn;}};
const outside={closest:()=>null}, link={closest:selector=>selector==='a'?link:null};
const sidebar={contains:target=>[toggle,nav,link].includes(target)};
const label={};
const toggle={hidden:true,closest:()=>sidebar,setAttribute:(key,value)=>{attributes[key]=value;},
    addEventListener:(event,fn)=>{toggleEvents[event]=fn;},focus:()=>{document.activeElement=toggle;}};
const nav={hidden:false,contains:target=>target===link,addEventListener:(event,fn)=>{navEvents[event]=fn;}};
const document={activeElement:outside,
    getElementById:id=>({'navigation-toggle':toggle,'main-navigation':nav,'navigation-label':label}[id]??null),
    querySelectorAll:()=>[],addEventListener:(event,fn)=>{handlers[event]=fn;}};
vm.runInNewContext(source,{document,window:{matchMedia:()=>media}});
const clickToggle=()=>{toggleEvents.click();handlers.click({target:toggle});};
verify('Móvil: empieza cerrado y anuncia el control',()=>{
    assert(nav.hidden);assert(!toggle.hidden);assert.equal(attributes['aria-expanded'],'false');assert.equal(label.textContent,'Menú');
});
verify('Móvil: tocar Menú despliega las opciones',()=>{
    clickToggle();assert(!nav.hidden);assert.equal(attributes['aria-expanded'],'true');assert.equal(label.textContent,'Cerrar');
});
verify('Móvil: tocar Cerrar vuelve a plegar',()=>{
    clickToggle();assert(nav.hidden);assert.equal(attributes['aria-expanded'],'false');
});
verify('Teclado: Escape cierra y devuelve el foco',()=>{
    clickToggle();document.activeElement=link;handlers.keydown({key:'Escape'});
    assert(nav.hidden);assert.equal(document.activeElement,toggle);
});
verify('Móvil: tocar fuera cierra el menú',()=>{
    clickToggle();handlers.click({target:outside});assert(nav.hidden);
});
verify('Móvil: elegir una opción cierra el menú',()=>{
    clickToggle();navEvents.click({target:link});assert(nav.hidden);
});
verify('Escritorio: navegación visible y botón oculto',()=>{
    media.matches=false;resize();assert(!nav.hidden);assert(toggle.hidden);
});
verify('Cambiar a móvil: pliega y conserva un foco visible',()=>{
    document.activeElement=link;media.matches=true;resize();
    assert(nav.hidden);assert(!toggle.hidden);assert.equal(document.activeElement,toggle);
});
verify('Pasar de menú abierto a escritorio conserva navegación',()=>{
    clickToggle();media.matches=false;resize();assert(!nav.hidden);assert(toggle.hidden);
});
verify('Regresar a móvil comienza plegado',()=>{
    media.matches=true;resize();assert(nav.hidden);assert.equal(label.textContent,'Menú');
});
console.log(checks.length+' comprobaciones JavaScript correctas del menú móvil.');
