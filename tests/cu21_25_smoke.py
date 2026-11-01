"""Pruebas CU21-CU25. Solo servidor local y base VACÍA desechable *_test.

Ejecuta también las regresiones CU01-CU20. Usa las variables documentadas en
http_smoke.py. Los pacientes, multas, pagos y solicitudes son ficticios.
"""
import html
import json
from pathlib import Path
import re
import runpy
import secrets
import os
import subprocess
from concurrent.futures import ThreadPoolExecutor
import urllib.parse

ROOT=Path(__file__).resolve().parents[1]
ctx=runpy.run_path(str(ROOT/'tests/cu16_20_smoke.py'))
sql,check,Browser=(ctx[k] for k in ('sql','check','Browser'))
results=ctx['results']; baseline=len(results)
patient,other,receipt,doctor,admin=(ctx[k] for k in ('patient','other','receipt','doctor','admin'))
pid,other_id,receipt_id,admin_id,did,specialty=(ctx[k] for k in ('patient_id','other_id','receipt_id','admin_id','doc_id','specialty'))
patient_uid,doctor_uid=ctx['patient_user'],ctx['doc_user']
roles=ctx['roles']; prefix='cu21_'+secrets.token_hex(5)
date=sql("SELECT (current_date+4)::text AS fecha")[0]['fecha']
for day in range(1,8):
    sql("INSERT INTO horario(medico_id,dia_semana,hora_inicio,hora_fin) VALUES(?,?,'08:00','18:00')",[did,day])

def page(browser,path):
    status,body,_=browser.request(path)
    check(status==200,'Abre '+path)
    return body

def hidden(body,name):
    return html.unescape(re.search(r'name="'+name+r'" value="([^"]+)"',body).group(1))

def form(browser,path,**extra):
    body=page(browser,path)
    return {'csrf':hidden(body,'csrf'),'version':hidden(body,'version'),**extra}

def request_data(**extra):
    body=page(patient,'/urgencias/nueva')
    return {'csrf':hidden(body,'csrf'),'clave_envio':hidden(body,'clave_envio'),
            'motivo':'Solicitud ficticia para coordinar atención','contacto':'00000000',**extra}

def totals():
    return {table:int(sql('SELECT count(*) AS n FROM '+table)[0]['n']) for table in
            ('cita','solicitud_urgente','historial_solicitud_urgente','aviso','pago_multa','evento_administrativo')}

def urgent_id(response):
    check(response[0]==303,'Solicitud aceptada con redirección')
    return int(response[2]['Location'].rsplit('/',1)[1])

# Obtiene dos multas pendientes mediante CU17, sobre fixtures ya históricos.
eligible=sql("""SELECT c.id FROM cita c WHERE c.paciente_id=? AND c.medico_id=? AND c.estado='programada'
    AND c.fin<now() AND NOT EXISTS(SELECT 1 FROM ausencia_medico a WHERE a.medico_id=c.medico_id
        AND tstzrange(a.inicio,a.fin,'[)') && tstzrange(c.inicio,c.fin,'[)')) ORDER BY c.id DESC LIMIT 2""",[pid,did])
for row in eligible:
    cid=int(row['id'])
    data=ctx['payload'](receipt,cid,motivo_cambio='Ausencia ficticia verificada',paciente_ausente='1',medico_disponible='1')
    check(receipt.request('/citas/'+str(cid)+'/inasistencia',data)[0]==303,'Fixture de deuda mediante inasistencia verificada')
fines=sql("""SELECT mu.id,mu.monto FROM multa mu JOIN incumplimiento i ON i.id=mu.incumplimiento_id
    JOIN cita c ON c.id=i.cita_id WHERE c.paciente_id=? AND mu.estado='pendiente' ORDER BY mu.id""",[pid])
check(len(fines)==2 and not sql('SELECT activo FROM paciente WHERE id=?',[pid])[0]['activo'],
      'Paciente ficticio con ficha inactiva y dos deudas pendientes')

guest=Browser()
check(guest.request('/urgencias')[0]==303 and guest.request('/multas')[0]==303,
      'Los nuevos módulos requieren autenticación')
for path in ['/urgencias','/urgencias/nueva','/multas','/pagos','/reactivaciones']:
    check(doctor.request(path)[0]==403,'Médico no accede a '+path)
check(receipt.request('/urgencias/nueva')[0]==403 and admin.request('/urgencias/nueva')[0]==403,
      'CU21: solo el paciente crea su solicitud personal')

data=request_data()
before=totals()
invalid=dict(data,contacto='123')
check(patient.request('/urgencias/nueva',invalid)[0]==422 and totals()==before,'CU21: contacto inválido no crea registros parciales')
check(patient.request('/urgencias/nueva',dict(data,paciente_id=str(other_id)))[0]==403 and totals()==before,
      'CU21: paciente no solicita para otra ficha')
check(patient.request('/urgencias/nueva',dict(data,csrf='incorrecto'))[0]==419,'CU21: token CSRF obligatorio')
rid=urgent_id(patient.request('/urgencias/nueva',data))
row=sql('SELECT * FROM solicitud_urgente WHERE id=?',[rid])[0]
check(row['estado']=='pendiente' and row['cita_id'] is None and int(row['paciente_id'])==pid
      and totals()['cita']==before['cita'],'CU21: paciente inactivo y con deuda envía solicitud sin reservar turno')
check(totals()['historial_solicitud_urgente']==before['historial_solicitud_urgente']+1
      and totals()['aviso']==before['aviso']+1,'CU21: solicitud, historial y aviso se guardan juntos')
after=totals()
check(urgent_id(patient.request('/urgencias/nueva',data))==rid and totals()==after,
      'CU21: doble envío conserva una sola solicitud e historial')
check(patient.request('/urgencias/nueva',dict(data,motivo='Cambio después del envío'))[0]==422 and totals()==after,
      'CU21: reutilizar el formulario con otros datos no sobrescribe la solicitud')
check(other.request('/urgencias/'+str(rid))[0]==403 and ('SU-'+str(rid).zfill(6)) not in other.request('/urgencias')[1],
      'CU21: listado y detalle excluyen solicitudes de otros pacientes')
check('Todavía no existe una cita asignada' in patient.request('/urgencias/'+str(rid))[1],
      'La pantalla distingue solicitud pendiente de cita asignada')
check('SU-'+str(rid).zfill(6) in receipt.request('/urgencias')[1],
      'CU22: recepción ve la solicitud en su cola pendiente')

follow=form(receipt,'/urgencias/'+str(rid),accion='seguimiento',resolucion='Recepción inició la coordinación')
check(receipt.request('/urgencias/'+str(rid)+'/gestionar',follow)[0]==303
      and sql('SELECT estado FROM solicitud_urgente WHERE id=?',[rid])[0]['estado']=='pendiente',
      'CU22: registra seguimiento sin inventar un turno ni cerrar la solicitud')
before=totals()
check(receipt.request('/urgencias/'+str(rid)+'/gestionar',follow)[0]==422 and totals()==before,
      'CU22: formulario antiguo no sobrescribe una gestión posterior')
assign=form(receipt,'/urgencias/'+str(rid),accion='asignar',resolucion='Alternativa coordinada y aceptada; excepción urgente verificada',
            especialidad_id=str(specialty),medico_id=str(did),fecha=date,inicio=date+'T09:00')
check(receipt.request('/urgencias/'+str(rid)+'/gestionar',assign)[0]==422 and totals()==before,
      'CU22: asignar exige aceptación explícita del paciente')
assign['aceptacion_paciente']='1'
check(receipt.request('/urgencias/'+str(rid)+'/gestionar',assign)[0]==422 and totals()==before,
      'CU22: ficha restringida requiere excepción urgente autorizada')
assign['excepcion_autorizada']='1'
check(receipt.request('/urgencias/'+str(rid)+'/gestionar',assign)[0]==303,'CU22: asigna turno libre con aceptación y excepción verificadas')
linked=sql('SELECT * FROM solicitud_urgente WHERE id=?',[rid])[0]
appointment=int(linked['cita_id']); cita=sql('SELECT * FROM cita WHERE id=?',[appointment])[0]
check(linked['estado']=='gestionada' and cita['tipo']=='urgente' and cita['estado']=='programada'
      and int(cita['paciente_id'])==pid and int(cita['creado_por'])==receipt_id,
      'CU22: solicitud y cita urgente quedan vinculadas, Programada y con responsable')
check(not sql('SELECT activo FROM paciente WHERE id=?',[pid])[0]['activo']
      and not sql('SELECT fn_paciente_sin_deuda(?) AS libre',[pid])[0]['libre'],
      'La excepción urgente conserva la ficha inactiva y las deudas')
after=totals()
check(receipt.request('/urgencias/'+str(rid)+'/gestionar',assign)[0]==422 and totals()==after,
      'CU22: repetir asignación no crea otra cita ni duplica avisos')
check('CM-'+str(appointment).zfill(6) in patient.request('/urgencias/'+str(rid))[1],
      'CU21: paciente consulta la cita asignada a su solicitud')

rid2=urgent_id(patient.request('/urgencias/nueva',request_data(motivo='Otra solicitud ficticia')))
busy=form(receipt,'/urgencias/'+str(rid2),**{k:v for k,v in assign.items() if k not in ('version','csrf')})
before=totals()
check(receipt.request('/urgencias/'+str(rid2)+'/gestionar',busy)[0]==422 and totals()==before
      and sql('SELECT estado FROM solicitud_urgente WHERE id=?',[rid2])[0]['estado']=='pendiente',
      'CU22: turno ocupado conserva íntegra la solicitud pendiente')
close=form(receipt,'/urgencias/'+str(rid2),accion='cerrar',resolucion='Cierre coordinado con el paciente, sin asignación')
check(receipt.request('/urgencias/'+str(rid2)+'/gestionar',close)[0]==303
      and sql('SELECT estado,cita_id FROM solicitud_urgente WHERE id=?',[rid2])[0]=={'estado':'cerrada','cita_id':None},
      'CU22: cierra sin cita únicamente mediante una gestión explícita y trazable')

fault_data=request_data(motivo='Prueba de reversión de solicitud')
before=totals(); sql("ALTER TABLE aviso ADD CONSTRAINT prueba_cu21_fallo CHECK(tipo<>'solicitud_urgente') NOT VALID")
try: status=patient.request('/urgencias/nueva',fault_data)[0]
finally: sql('ALTER TABLE aviso DROP CONSTRAINT prueba_cu21_fallo')
check(status==500 and totals()==before,'CU21: fallo de aviso revierte solicitud e historial completos')
rid3=urgent_id(patient.request('/urgencias/nueva',request_data(motivo='Prueba de reversión de gestión')))
fault=form(receipt,'/urgencias/'+str(rid3),**{k:v for k,v in assign.items() if k not in ('version','csrf','inicio')},inicio=date+'T09:30')
before=totals(); sql("ALTER TABLE aviso ADD CONSTRAINT prueba_cu22_fallo CHECK(tipo<>'gestion_urgente') NOT VALID")
try: status=receipt.request('/urgencias/'+str(rid3)+'/gestionar',fault)[0]
finally: sql('ALTER TABLE aviso DROP CONSTRAINT prueba_cu22_fallo')
check(status==500 and totals()==before and sql('SELECT cita_id FROM solicitud_urgente WHERE id=?',[rid3])[0]['cita_id'] is None,
      'CU22: fallo del aviso revierte cita, vinculación y gestión')

body=page(patient,'/multas')
check('data-saldo="40.00"' in body and 'Paciente inactivo' in body,'CU24: paciente inactivo consulta su saldo pendiente íntegro')
check(patient.request('/multas?paciente_id='+str(other_id))[0]==403,'CU24: no permite consultar multas de otro paciente')
check('data-saldo="0"' in page(other,'/multas') or 'data-saldo="0.00"' in other.request('/multas')[1],
      'CU24: paciente sin deuda ve saldo cero')
check('data-saldo="40.00"' in page(receipt,'/multas?paciente_id='+str(pid)),
      'CU24: recepción selecciona una ficha y obtiene el mismo saldo')
check(patient.request('/pagos')[0]==403 and patient.request('/reactivaciones')[0]==403,
      'Paciente no registra pagos ni se reactiva a sí mismo')

f1,f2=[int(f['id']) for f in fines]
path='/multas/'+str(f1)+'/pagar'
check(patient.request(path)[0]==403 and doctor.request(path)[0]==403,'CU23: solo personal autorizado abre el pago')
pay=form(receipt,path,monto='20.00',comprobante=prefix+'-1',pago_recibido='1')
before=totals()
for invalid in ['19.99','20.01','0','20.001','-20','1e2']:
    check(receipt.request(path,dict(pay,monto=invalid))[0]==422 and totals()==before,'CU23: importe incorrecto rechazado: '+invalid)
check(receipt.request(path,{k:v for k,v in pay.items() if k!='pago_recibido'})[0]==422,
      'CU23: exige verificar que se recibió el pago presencial')
check(receipt.request(path,dict(pay,csrf='incorrecto'))[0]==419,'CU23: pago protegido por CSRF')
check(receipt.request(path,dict(pay,monto='20,00'))[0]==303,'CU23: registra importe exacto con coma decimal')
stored=sql('SELECT * FROM pago_multa WHERE multa_id=?',[f1])[0]
check(stored['monto']=='20.00' and int(stored['registrado_por'])==receipt_id and stored['comprobante']==prefix+'-1'
      and sql('SELECT estado FROM multa WHERE id=?',[f1])[0]['estado']=='pagada',
      'CU23: conserva importe decimal, responsable, fecha y comprobante del pago')
after=totals()
check(receipt.request(path,pay)[0]==422 and totals()==after,'CU23: doble envío no duplica pagos, avisos ni auditoría')
check(prefix+'-1' in receipt.request(path)[1] and 'Fecha del pago' in receipt.request(path)[1],
      'CU23: consultar un pago conserva su fecha y comprobante reales')
check(not sql('SELECT activo FROM paciente WHERE id=?',[pid])[0]['activo']
      and not sql('SELECT fn_paciente_sin_deuda(?) AS libre',[pid])[0]['libre'],
      'CU23: pagar una multa no reactiva la ficha ni borra la deuda restante')
pay2=form(receipt,'/multas/'+str(f2)+'/pagar',monto='20.00',comprobante=prefix+'-1',pago_recibido='1')
check(receipt.request('/multas/'+str(f2)+'/pagar',pay2)[0]==422 and totals()==after,
      'CU23: comprobante repetido se rechaza sin abono parcial')
pay2['comprobante']=prefix+'-2'; before=totals()
sql("ALTER TABLE aviso ADD CONSTRAINT prueba_cu23_fallo CHECK(tipo<>'pago_multa') NOT VALID")
try: status=receipt.request('/multas/'+str(f2)+'/pagar',pay2)[0]
finally: sql('ALTER TABLE aviso DROP CONSTRAINT prueba_cu23_fallo')
check(status==500 and totals()==before and sql('SELECT estado FROM multa WHERE id=?',[f2])[0]['estado']=='pendiente',
      'CU23: fallo del aviso revierte pago, multa y auditoría completos')

react_path='/pacientes/'+str(pid)+'/reactivar'
check(patient.request(react_path)[0]==403 and doctor.request(react_path)[0]==403,'CU25: paciente y médico no reactivan fichas')
react=form(receipt,react_path,motivo='Identidad y contacto verificados para prueba')
before=totals(); user_before=sql('SELECT * FROM usuario WHERE id=?',[patient_uid])[0]
check(receipt.request(react_path,react)[0]==422 and totals()==before,'CU25: identidad y contacto no verificados impiden reactivación')
sql("UPDATE paciente SET telefono='00000001' WHERE id=?",[pid])
check(receipt.request(react_path,dict(react,datos_verificados='1'))[0]==422
      and not sql('SELECT activo FROM paciente WHERE id=?',[pid])[0]['activo'],
      'CU25: formulario antiguo se rechaza al cambiar los datos del paciente')
react=form(receipt,react_path,motivo='Datos revisados y actualizados',datos_verificados='1')
check(receipt.request(react_path,react)[0]==303,'CU25: recepción reactiva ficha con verificación y motivo')
row=sql('SELECT activo,reactivado_en FROM paciente WHERE id=?',[pid])[0]
check(row['activo'] and row['reactivado_en'] is not None
      and sql('SELECT * FROM usuario WHERE id=?',[patient_uid])[0]==user_before,
      'CU25: registra fecha nueva y conserva rol, contraseña y habilitación de la cuenta')
check(not sql('SELECT fn_paciente_sin_deuda(?) AS libre',[pid])[0]['libre']
      and totals()['cita']==before['cita'] and totals()['pago_multa']==before['pago_multa'],
      'CU25: reactivar conserva citas, pagos y multas pendientes')
after=totals()
check(receipt.request(react_path,react)[0]==422 and totals()==after,
      'CU25: repetir el envío no renueva fecha ni duplica eventos de reactivación')
check(receipt.request('/multas/'+str(f2)+'/pagar',pay2)[0]==303,'CU23: registra el pago íntegro de la última deuda')
check(sql('SELECT fn_paciente_sin_deuda(?) AS libre',[pid])[0]['libre']
      and ('data-saldo="0"' in patient.request('/multas')[1] or 'data-saldo="0.00"' in patient.request('/multas')[1]),
      'CU23-CU25: paciente activo y sin multas pendientes queda habilitado para reservar')

booking={'csrf':patient.token('/panel'),'especialidad_id':str(specialty),'medico_id':str(did),
         'inicio':date+'T10:00','motivo':'Reserva ordinaria luego de regularizar'}
check(patient.request('/citas/nueva',booking)[0]==303,
      'Reserva ordinaria funciona después de reactivar y pagar todas las deudas')
check(prefix+'-2' in patient.request('/multas')[1] and 'Pagada' in patient.request('/multas')[1],
      'CU24: paciente ve comprobantes y estado pagado, conservando las advertencias')

# Revocación sobre sesiones y formularios abiertos, sin depender del permiso CU06.
pending_form=request_data(motivo='Permiso revocado')
for permission,path,browser in [('CU21','/urgencias',patient),('CU22','/urgencias',receipt),
                               ('CU23','/pagos',receipt),('CU24','/multas',patient),('CU25','/reactivaciones',receipt)]:
    role=roles['paciente'] if browser is patient else roles['recepcionista']
    sql('DELETE FROM rol_permiso WHERE rol_id=? AND permiso_id=(SELECT id FROM permiso WHERE codigo=?)',[role,permission])
    try:
        check(browser.request(path)[0]==403,'Retirar '+permission+' bloquea la siguiente petición')
        if permission=='CU21':
            check(browser.request('/urgencias/nueva',pending_form)[0]==403,'CU21: revocación invalida formulario ya abierto')
    finally: sql('INSERT INTO rol_permiso SELECT ?,id FROM permiso WHERE codigo=?',[role,permission])
sql("DELETE FROM rol_permiso WHERE rol_id=? AND permiso_id=(SELECT id FROM permiso WHERE codigo='CU06')",[roles['recepcionista']])
try:
    check(receipt.request('/reactivaciones')[0]==200 and receipt.request(react_path)[0]==200,
          'CU25 es independiente de CU06: autoriza su búsqueda y su formulario propios')
finally: sql("INSERT INTO rol_permiso SELECT ?,id FROM permiso WHERE codigo='CU06'",[roles['recepcionista']])

# Dos procesos independientes intentan cobrar una misma multa de un fixture.
incident=int(sql("INSERT INTO incumplimiento(cita_id,tipo) VALUES(?,'inasistencia') RETURNING id",[ctx['foreign']])[0]['id'])
race_fine=int(sql('INSERT INTO multa(incumplimiento_id,monto) VALUES(?,20) RETURNING id',[incident])[0]['id'])
race_data=form(receipt,'/multas/'+str(race_fine)+'/pagar',monto='20.00',comprobante=prefix+'-concurrente',pago_recibido='1')
code=('require getcwd()."/bootstrap.php"; $x=json_decode(stream_get_contents(STDIN),true);'
      'App\\Database::run("SELECT pg_sleep(0.15)");'
      'try { App\\FineService::pay($x["id"],$x["data"],$x["actor"]);echo "guardado"; }'
      'catch (App\\ValidationException $e) { echo "rechazado"; }')
def concurrent_pay(_):
    return subprocess.run([os.environ['PHP_BIN'],'-r',code],cwd=ROOT,
        input=json.dumps({'id':race_fine,'data':race_data,'actor':receipt_id}),capture_output=True,text=True,check=True).stdout
with ThreadPoolExecutor(max_workers=2) as pool: outcomes=list(pool.map(concurrent_pay,range(2)))
check(sorted(outcomes)==['guardado','rechazado']
      and int(sql('SELECT count(*) AS n FROM pago_multa WHERE multa_id=?',[race_fine])[0]['n'])==1,
      'CU23: pagos concurrentes con conexiones independientes generan un solo abono')

(ROOT/'tests/resultados_validacion_cu21_25.json').write_text(json.dumps(
    {'regresion':baseline,'nuevas':len(results)-baseline,'passed':len(results),'results':results},ensure_ascii=False,indent=2))
print(f'{len(results)-baseline} comprobaciones nuevas; {len(results)} comprobaciones HTTP y de servicios correctas en total.')
