"""Regresión CU01-CU30 y ajustes; solo servidor local y base vacía *_test."""
import hashlib,json,runpy
from html.parser import HTMLParser
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
ctx=runpy.run_path(str(ROOT/'tests/ajustes_smoke.py'))
check,Browser,results=(ctx[k] for k in ('check','Browser','results'))
baseline=len(results)

class Navigation(HTMLParser):
    def __init__(self):
        super().__init__();self.inside=False;self.links=[];self.controls=[]
    def handle_starttag(self,tag,attrs):
        values=dict(attrs)
        if tag=='nav' and values.get('id')=='main-navigation':self.inside=True
        if tag=='a' and self.inside:self.links.append(values.get('href'))
        if tag=='button' and values.get('id')=='navigation-toggle':self.controls.append(values)
    def handle_endtag(self,tag):
        if tag=='nav':self.inside=False

cases=[
    ('administrador',ctx['admin'],{'/usuarios','/roles','/reportes'},set()),
    ('recepción',ctx['receipt'],{'/reportes','/pagos','/agenda'},{'/usuarios','/roles'}),
    ('médico',ctx['doctor'],{'/agenda'},{'/reportes','/pagos','/historial'}),
    ('paciente',ctx['patient'],{'/historial','/mi-perfil'},{'/reportes','/usuarios','/pagos','/agenda'}),
]
for name,browser,expected,forbidden in cases:
    status,body,_=browser.request('/panel')
    parsed=Navigation();parsed.feed(body)
    check(status==200 and len(parsed.controls)==1
        and parsed.controls[0].get('aria-controls')=='main-navigation'
        and 'hidden' in parsed.controls[0],
        'Menú: control accesible y progresivo para '+name)
    check(expected.issubset(parsed.links) and not forbidden.intersection(parsed.links)
        and '/ubicacion' in parsed.links,'Menú: conserva opciones y límites del rol '+name)
guest=Browser().request('/ingresar')
check(guest[0]==200 and 'id="navigation-toggle"' not in guest[1],
    'Menú: el acceso público conserva su pantalla de inicio')
body=cases[0][1].request('/panel')[1]
for asset in ['app.css','app.js']:
    digest=hashlib.sha256((ROOT/'public/assets'/asset).read_bytes()).hexdigest()[:12]
    check('/assets/'+asset+'?v='+digest in body,'Menú: versión de '+asset+' evita reutilizar la copia anterior en el celular')
(ROOT/'tests/resultados_validacion_menu_movil.json').write_text(json.dumps(
    {'regresion':baseline,'nuevas':len(results)-baseline,'passed':len(results),'results':results},ensure_ascii=False,indent=2))
print(f'{len(results)-baseline} nuevas comprobaciones; {len(results)} comprobaciones HTTP y servicios correctas en total.')
