"""Ajustes de sincronización con el documento y regresión CU01-CU30.

Solo servidor local y base VACÍA desechable *_test: hereda los controles de
http_smoke.py. Telegram usa el transporte simulado del bloque CU26-CU30.
"""
import html,json,re,runpy,secrets,urllib.parse
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
ctx=runpy.run_path(str(ROOT/'tests/cu26_30_smoke.py'))
sql,check,Browser,results,php=(ctx[k] for k in ('sql','check','Browser','results','php'))
admin,receipt,doctor,patient,other=(ctx[k] for k in ('admin','receipt','doctor','patient','other'))
base=len(results); receipt_id=ctx['receipt_id']; roles=ctx['ctx']['roles']
prefix='ajuste_'+secrets.token_hex(5)

def report(browser=receipt,**extra):
    data={'tipo':'pacientes','desde':'2020-02-11','hasta':'2020-02-11',**extra}
    return browser.request('/reportes?'+urllib.parse.urlencode(data))

def ids(body):
    return [int(x) for x in re.findall(r'data-patient-registration="(\d+)"',body)]

def totals(body):
    return {k:int(v) for k,v in re.findall(r'data-total="(registrados|activos|inactivos)">(\d+)<',body)}

# Altas sin cuenta ni citas: límites a medianoche en Bolivia, no en UTC.
rows=sql("""INSERT INTO paciente(ci,nombres,apellidos,telefono,activo,creado_en)
    SELECT ?||g::text,'Alta sin cita','Prueba','00000000',g%2=0,
        timestamptz '2020-02-11 04:00:00+00'+g*interval '1 minute'
    FROM generate_series(0,54) g RETURNING id,ci""",[prefix])
expected={int(r['id']) for r in rows}
edge=int(sql("""INSERT INTO paciente(ci,nombres,apellidos,telefono,activo,creado_en)
    VALUES(?,'Fin del día','Prueba','00000000',false,'2020-02-12 03:59:59+00')
    RETURNING id""",[prefix+'fin'])[0]['id'])
expected.add(edge)
for suffix,stamp in [('antes','2020-02-11 03:59:59+00'),('despues','2020-02-12 04:00:00+00')]:
    sql("""INSERT INTO paciente(ci,nombres,apellidos,telefono,creado_en)
        VALUES(?,'Fuera del período','Prueba','00000000',?)""",[prefix+suffix,stamp])
status,body,_=report()
check(status==200 and totals(body)=={'registrados':56,'activos':28,'inactivos':28},
    'Altas: incluye pacientes sin cuenta ni citas y calcula totales completos')
first=ids(body); second=ids(report(pagina='2')[1])
check(len(first)==50 and len(second)==6 and set(first).isdisjoint(second)
    and set(first+second)==expected,'Altas: paginación sin pérdidas ni duplicados y límites de Bolivia inclusivos')
check('11/02/2020 23:59' in body and first[0]==edge and first[-1]>second[-1],
    'Altas: fecha de alta local y orden reciente estable')
check('tipo=pacientes' in html.unescape(body) and 'pagina=2' in html.unescape(body),
    'Altas: la siguiente página conserva el tipo y el período')
for state in ['activos','inactivos']:
    page=report(estado_paciente=state)[1]
    check(totals(page)['registrados']==28 and totals(page)[state]==28 and len(ids(page))==28,
        'Altas: filtra el estado actual '+state)
check(totals(report(medico_id='inexistente',especialidad_id='malformado',estado='malformado')[1])['registrados']==56,
    'Altas: filtros anteriores de citas no excluyen fichas sin cita')
empty=report(desde='2010-01-01',hasta='2010-01-01')
check(empty[0]==200 and totals(empty[1])=={'registrados':0,'activos':0,'inactivos':0}
    and 'No hay pacientes registrados' in empty[1],'Altas: período vacío muestra cero y mensaje')
for bad in [{'desde':'2020-02-30'},{'hasta':'2019-01-01'},{'pagina':'0'},
            {'estado_paciente':'programada'},{'estado_paciente[]':'activos'}]:
    check(report(**bad)[0]==422,'Altas: rechaza filtro inválido '+next(iter(bad)))
check(report(admin)[0]==200 and report(patient)[0]==403 and report(doctor)[0]==403
    and report(Browser())[0]==303,'Altas: mantiene autenticación y alcance administrativo CU20')
sql("DELETE FROM rol_permiso WHERE rol_id=? AND permiso_id=(SELECT id FROM permiso WHERE codigo='CU20')",[roles['recepcionista']])
try: check(report()[0]==403,'Altas: retirar CU20 revoca una sesión abierta')
finally: sql("INSERT INTO rol_permiso SELECT ?,id FROM permiso WHERE codigo='CU20'",[roles['recepcionista']])
sql("DELETE FROM rol_permiso WHERE rol_id=? AND permiso_id=(SELECT id FROM permiso WHERE codigo='CU06')",[roles['recepcionista']])
try: check(report()[0]==200,'Altas: consultar CU20 no requiere administrar pacientes CU06')
finally: sql("INSERT INTO rol_permiso SELECT ?,id FROM permiso WHERE codigo='CU06'",[roles['recepcionista']])
# La ficha de un paciente con citas se filtra por su alta, no por sus reservas.
pid=ctx['pid']; previous=sql('SELECT creado_en FROM paciente WHERE id=?',[pid])[0]['creado_en']
try:
    sql("UPDATE paciente SET creado_en='2020-02-11 15:00:00-04' WHERE id=?",[pid])
    both=report()[1]+report(pagina='2')[1]
    check(pid in ids(both) and totals(report()[1])['registrados']==57,
        'Altas: la fecha de la reserva no cambia el período de alta de la ficha')
finally: sql('UPDATE paciente SET creado_en=? WHERE id=?',[previous,pid])
# Escape de datos del paciente en la nueva tabla.
sql("UPDATE paciente SET nombres=? WHERE id=?",['<script>alert(1)</script>',edge])
check('&lt;script&gt;alert(1)&lt;/script&gt;' in report()[1] and '<script>alert(1)</script>' not in report()[1],
    'Altas: los datos de la ficha se muestran escapados')

# El historial muestra el valor persistido, sin reemplazarlo por la fecha del turno.
cid=ctx['first']
row=sql("""SELECT to_char(creado_en AT TIME ZONE 'America/La_Paz','DD/MM/YYYY HH24:MI') AS creado,
    to_char(inicio AT TIME ZONE 'America/La_Paz','YYYY-MM-DD') AS dia FROM cita WHERE id=?""",[cid])[0]
query=urllib.parse.urlencode({'desde':row['dia'],'hasta':row['dia'],'paciente_id':pid})
status,body,_=patient.request('/historial?'+query)
code='CM-'+str(cid).zfill(6)
check(status==200 and f'data-created-for="{code}">{row["creado"]}<' in body,
    'Historial: muestra la fecha de creación almacenada de cada cita')
check(receipt.request('/historial?'+query)[0]==200 and other.request('/historial?'+query)[0]==403
    and doctor.request('/historial?'+query)[0]==403,'Historial: la nueva fecha respeta propiedad y permisos')
empty=patient.request('/historial?desde=2010-01-01&hasta=2010-01-01')[1]
check('colspan="8"' in empty,'Historial: lista vacía conserva las ocho columnas')
check('Fecha de registro</th>' not in receipt.request('/agenda?fecha='+row['dia'])[1]
    and 'Fecha de registro</th>' not in report(tipo='citas')[1],
    'Historial: agenda y reporte de citas mantienen sus columnas')

# Ubicación: información de la clínica, disponible incluso con ficha restringida.
check(Browser().request('/ubicacion')[0]==303,'Ubicación: requiere iniciar sesión')
for name,browser in [('administrador',admin),('recepción',receipt),('médico',doctor),('paciente',patient)]:
    status,body,_=browser.request('/ubicacion')
    check(status==200 and '<h1>Ubicación de la clínica</h1>' in body,
        'Ubicación: acceso de '+name)
was_active=sql('SELECT activo FROM paciente WHERE id=?',[pid])[0]['activo']
sql('UPDATE paciente SET activo=false WHERE id=?',[pid])
try: check(patient.request('/ubicacion')[0]==200,'Ubicación: una ficha inactiva conserva el acceso')
finally: sql('UPDATE paciente SET activo=? WHERE id=?',[was_active,pid])
code_location='echo json_encode(App\\ClinicInfo::location());'
check(php(code_location,extra={'CLINICA_DIRECCION':'','CLINICA_CIUDAD':'','CLINICA_MAPA_URL':''})==
    {'address':'','city':'','map':None},'Ubicación: sin configuración no inventa una dirección ni un mapa')
for link in ['https://maps.app.goo.gl/Prueba','https://www.google.com/maps?q=-17,-63',
             'https://www.openstreetmap.org/?mlat=-17&mlon=-63']:
    check(php(code_location,extra={'CLINICA_MAPA_URL':link})['map']==link,
        'Ubicación: admite enlace HTTPS de mapas '+urllib.parse.urlsplit(link).hostname)
for bad in ['javascript:alert(1)','http://maps.google.com/','https://maps.google.com.ejemplo.invalid/',
            'https://usuario@maps.google.com/','https://www.google.com/url?q=otro',
            'https://maps.google.com:444/','https://maps.google.com/\nmal']:
    check(php(code_location,extra={'CLINICA_MAPA_URL':bad})['map'] is None,
        'Ubicación: rechaza enlace inseguro '+bad.split(':')[0]+' '+str(len(bad)))
render='ob_start();App\\View::render("clinic_location",["user"=>App\\AppointmentService::actor($x["actor"],"CU19"),"title"=>"Ubicación de la clínica","section"=>"ubicacion","location"=>App\\ClinicInfo::location()]);echo json_encode(ob_get_clean());'
configured=php(render,{'actor':ctx['uid']},extra={'CLINICA_DIRECCION':'Dirección <de prueba>',
    'CLINICA_CIUDAD':'Ciudad de prueba','CLINICA_MAPA_URL':'https://maps.google.com/?q=Prueba&hl=es'})
check('Dirección &lt;de prueba&gt;' in configured and 'Ciudad de prueba' in configured
    and 'rel="noopener noreferrer"' in configured and 'q=Prueba&amp;hl=es' in configured,
    'Ubicación: publica configuración escapada y apertura segura del mapa')
missing=php(render,{'actor':ctx['uid']},extra={'CLINICA_DIRECCION':'','CLINICA_MAPA_URL':''})
check('La dirección todavía no está publicada' in missing and 'Abrir ubicación en el mapa' not in missing
    and 'Contactar con recepción' in missing,'Ubicación: sin datos muestra alternativa de recepción')

(ROOT/'tests/resultados_validacion_ajustes.json').write_text(json.dumps(
    {'regresion':base,'nuevas':len(results)-base,'passed':len(results),'results':results},ensure_ascii=False,indent=2))
print(f'{len(results)-base} nuevas comprobaciones; {len(results)} comprobaciones HTTP y de servicios correctas en total.')
