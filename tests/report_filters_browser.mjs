// Cambios del formulario de reportes: sin dependencias externas ni red.
import vm from 'node:vm';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const source=fs.readFileSync(new URL('../public/assets/app.js',import.meta.url),'utf8');
let change;
const kind={value:'citas',addEventListener: (_,fn)=>{change=fn;}};
const fields=['appointments','citas','pacientes'].map(scope=>({
    dataset:{reportScope:scope},controls:[{},{}],
    querySelectorAll(){return this.controls;}
}));
const helps=['citas','pacientes','pagos'].map(scope=>({dataset:{reportHelp:scope}}));
const form={querySelector:()=>kind,querySelectorAll:s=>s==='[data-report-scope]'?fields:helps};
vm.runInNewContext(source,{document:{
    querySelectorAll:s=>s==='[data-report-filters]'?[form]:[],getElementById:()=>null
}});
function verify(value,visible){
    assert.equal(helps.filter(h=>!h.hidden).length,1);
    assert.equal(helps.find(h=>!h.hidden).dataset.reportHelp,value);
    for (const field of fields){
        const shown=visible.includes(field.dataset.reportScope);
        assert.equal(field.hidden,!shown);
        assert(field.controls.every(c=>c.disabled===!shown));
    }
}
verify('citas',['appointments','citas']);
kind.value='pacientes';change();verify('pacientes',['pacientes']);
kind.value='pagos';change();verify('pagos',['appointments']);
kind.value='citas';change();verify('citas',['appointments','citas']);
console.log('4 comprobaciones JavaScript: inicio, altas, pagos y retorno a citas.');
