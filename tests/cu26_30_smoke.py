"""CU26-CU30 y regresión: solo servidor local y base vacía desechable *_test.
Los mensajes de Telegram usan transporte inyectado: nunca se contacta un bot real.
"""
import html,json,os,re,runpy,secrets,subprocess,urllib.parse
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
ROOT=Path(__file__).resolve().parents[1]
ctx=runpy.run_path(str(ROOT/'tests/cu21_25_smoke.py'))
sql,check,Browser,results=(ctx[k] for k in ('sql','check','Browser','results'))
receipt,admin,doctor=(ctx[k] for k in ('receipt','admin','doctor'))
receipt_id,admin_id=(ctx[k] for k in ('receipt_id','admin_id'))
base=len(results); prev=ctx['ctx']; create_account=prev['create_account']
uid,patient=create_account('paciente','paciente_cu26')
uid2,other=create_account('paciente','otro_cu26')
pid=int(sql('SELECT id FROM paciente WHERE usuario_id=?',[uid])[0]['id'])
pid2=int(sql('SELECT id FROM paciente WHERE usuario_id=?',[uid2])[0]['id'])
sp=ctx['specialty']; prefix='cu26_'+secrets.token_hex(5)
dids=[]
for suffix in ['a','b','c']:
    did=int(sql('INSERT INTO medico(registro_profesional,nombres,apellidos) VALUES(?,?,?) RETURNING id',[prefix+suffix,'Médico',suffix])[0]['id'])
    dids.append(did)
    sql('INSERT INTO medico_especialidad(medico_id,especialidad_id) VALUES(?,?)',[did,sp])
    for day in range(1,8): sql("INSERT INTO horario(medico_id,dia_semana,hora_inicio,hora_fin) VALUES(?,?,'08:00','18:00')",[did,day])
did,did2,did3=dids
date=sql("SELECT (current_date+8)::text AS d")[0]['d']

def hidden(body,name):
    return html.unescape(re.search('name="'+name+'" value="([^"]+)"',body).group(1))

def php(code,data=None,extra=None):
    pre='require getcwd()."/bootstrap.php";$x=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);'
    run=subprocess.run([os.environ['PHP_BIN'],'-r',pre+code],cwd=ROOT,env=dict(os.environ,**(extra or {})),
        input=json.dumps(data or {}),capture_output=True,text=True)
    if run.returncode: raise AssertionError(run.stderr)
    return json.loads(run.stdout)

def form(browser,path,**extra):
    status,body,_=browser.request(path);check(status==200,'Abre formulario '+path)
    payload={'csrf':hidden(body,'csrf'),**extra}
    for name in ['version','clave_envio']:
        if 'name="'+name+'"' in body: payload[name]=hidden(body,name)
    return payload

def book(time,doctor_id=did,patient_id=pid):
    return int(php('echo json_encode(App\\AppointmentService::book($x["data"],$x["actor"]));',
        {'actor':receipt_id,'data':{'paciente_id':str(patient_id),'medico_id':str(doctor_id),'especialidad_id':str(sp),'inicio':date+'T'+time}}))

def create_absence(start='09:00',end='10:00',doctor_id=did,**extra):
    return form(receipt,'/ausencias/nueva',medico_id=str(doctor_id),inicio=date+'T'+start,fin=date+'T'+end,motivo='Ausencia administrativa ficticia',**extra)

for path in ['/ausencias','/ausencias/nueva']:
    check(patient.request(path)[0]==403 and doctor.request(path)[0]==403,'CU26: paciente y médico no administran ausencias')
check(Browser().request('/ausencias')[0]==303,'CU26: requiere autenticación')
check(receipt.request('/telegram')[0]==403 and doctor.request('/telegram')[0]==403,'CU27: vinculación personal solo para pacientes')
check(doctor.request('/avisos')[0]==403 and receipt.request('/contacto-recepcion')[0]==403,'CU28/CU30: límites de rol')
first,second,edge=book('09:00'),book('09:30'),book('10:00')
data=create_absence()
check(receipt.request('/ausencias/nueva',dict(data,csrf='incorrecto'))[0]==419,'CU26: CSRF obligatorio')
for bad in [dict(data,fin=data['inicio']),dict(data,fin='2026-02-30T10:00'),dict(data,medico_id='99999999')]:
    check(receipt.request('/ausencias/nueva',bad)[0]==422,'CU26: intervalo o médico inválido rechazado')
r=receipt.request('/ausencias/nueva',data);check(r[0]==303,'CU26: guarda ausencia y redirige al detalle')
aid=int(r[2]['Location'].rsplit('/',1)[1]);path='/ausencias/'+str(aid)
body=receipt.request(path)[1]
check('CM-'+str(first).zfill(6) in body and 'CM-'+str(second).zfill(6) in body and 'CM-'+str(edge).zfill(6) not in body,
    'CU26: detecta ambos solapamientos y respeta el límite final exclusivo')
check(sql('SELECT estado FROM cita WHERE id=?',[first])[0]['estado']=='programada'
    and not sql('SELECT id FROM incumplimiento WHERE cita_id IN (?,?)',[first,second]),'CU26: registrar ausencia no cancela citas ni sanciona')
check(receipt.request('/ausencias/nueva',data)[0]==303 and int(sql('SELECT count(*) AS n FROM ausencia_medico WHERE clave_envio=?',[data['clave_envio']])[0]['n'])==1,
    'CU26: doble envío conserva una ausencia')
check(receipt.request('/ausencias/nueva',create_absence('09:30','10:30'))[0]==422,'CU26: rechaza ausencias superpuestas')
check(not sql("SELECT 1 FROM cita c WHERE c.id=? AND NOT EXISTS(SELECT 1 FROM ausencia_medico a WHERE a.medico_id=c.medico_id AND tstzrange(a.inicio,a.fin,'[)') && tstzrange(c.inicio,c.fin,'[)'))",[first]),'CU26: intervalo bloqueado en disponibilidad')
try: book('09:00');free=False
except AssertionError: free=True
check(free,'CU26: tampoco permite reservar el intervalo por petición directa')
manage=path+'/citas/'+str(first)
cancel=form(receipt,manage,accion='cancelar',motivo_cambio='Cancelación acordada por ausencia')
check(receipt.request(manage,cancel)[0]==422,'CU26: exige aceptación del paciente')
cancel['aceptacion_paciente']='1';before=sql('SELECT * FROM cita WHERE id=?',[first])[0]
sql("ALTER TABLE gestion_ausencia ADD CONSTRAINT prueba_fallo_cu26 CHECK(motivo<>'Cancelación acordada por ausencia') NOT VALID")
try: status=receipt.request(manage,cancel)[0]
finally: sql('ALTER TABLE gestion_ausencia DROP CONSTRAINT prueba_fallo_cu26')
check(status==500 and sql('SELECT * FROM cita WHERE id=?',[first])[0]==before,'CU26: fallo de historial revierte la cita completa y mantiene pendiente')
check(receipt.request(manage,cancel)[0]==303,'CU26: cancelación acordada guardada')
row=sql('SELECT estado,origen_cambio,justificada FROM cita WHERE id=?',[first])[0]
check(row=={'estado':'cancelada','origen_cambio':'clinica','justificada':True}
    and not sql('SELECT id FROM incumplimiento WHERE cita_id=?',[first]),'CU26: cancelación con origen Clínica sin multa')
check(receipt.request(manage,cancel)[0]==422,'CU26: repetir la gestión no duplica cambios')
# Una ficha inactiva no impide resolver una reserva previa que la clínica debe mover.
sql('UPDATE paciente SET activo=false WHERE id=?',[pid])
manage2=path+'/citas/'+str(second)
move=form(receipt,manage2+'?medico_id='+str(did2)+'&fecha='+date,accion='reprogramar',medico_id=str(did2),inicio=date+'T10:30',
    motivo_cambio='Sustitución de la misma especialidad acordada',aceptacion_paciente='1')
check(receipt.request(manage2,dict(move,medico_id=str(did),inicio=date+'T09:00'))[0]==422,'CU26: revalida el bloqueo al guardar')
check(receipt.request(manage2,move)[0]==303,'CU26: sustituye médico conservando especialidad aun con ficha inactiva')
row=sql('SELECT medico_id,especialidad_id,estado,origen_cambio FROM cita WHERE id=?',[second])[0]
check(int(row['medico_id'])==did2 and int(row['especialidad_id'])==sp and row['estado']=='programada' and row['origen_cambio']=='clinica',
    'CU26: cambio trazable, nueva confirmación y especialidad preservada')
check('No hay citas pendientes' in receipt.request(path)[1],'CU26: las citas gestionadas salen de pendientes y conservan historial')
check(not sql('SELECT activo FROM paciente WHERE id=?',[pid])[0]['activo'],'CU26: gestionar ausencia no reactiva la ficha')
sql('UPDATE paciente SET activo=true WHERE id=?',[pid])
# Guardado de ausencia y cola de avisos es atómico.
fault=create_absence('10:00','10:30')
sql("ALTER TABLE aviso ADD CONSTRAINT prueba_aviso_cu26 CHECK(tipo<>'ausencia_medico') NOT VALID")
try: status=receipt.request('/ausencias/nueva',fault)[0]
finally: sql('ALTER TABLE aviso DROP CONSTRAINT prueba_aviso_cu26')
check(status==500 and not sql('SELECT id FROM ausencia_medico WHERE clave_envio=?',[fault['clave_envio']]),'CU26: fallo de aviso revierte el bloqueo completo')

# CU29: horario basado en el servidor y privacidad del detalle.
url='/citas/'+str(edge)+'/contador'
status,body,_=patient.request(url);counter=json.loads(body)
check(status==200 and counter['activo'] and counter['fase']=='espera' and counter['fin']-counter['inicio']==1800000,
    'CU29: entrega inicio, fin y reloj del servidor para un bloque de 30 minutos')
check(other.request(url)[0]==403 and doctor.request(url)[0]==403,'CU29: otro paciente y el médico no obtienen el contador')
check('data-counter-url' in patient.request('/citas/'+str(edge))[1],'CU29: contador integrado en el detalle')
check('data-counter-url' not in patient.request('/citas/'+str(first))[1],'CU29: cita cancelada oculta el contador')
phases=php('$r=["inicio"=>"2026-11-02T09:00:00-04:00","fin"=>"2026-11-02T09:30:00-04:00","estado"=>"programada"];'
    '$a=[];foreach(["08:59:59","09:00:00","09:29:59","09:30:00"] as $t){$a[]=App\\CounterService::calculate($r,"2026-11-02T".$t."-04:00")["fase"];}echo json_encode($a);')
check(phases==['espera','turno','turno','finalizado'],'CU29: transiciones exactas sin iniciar manualmente el contador')
before=sql('SELECT estado FROM cita WHERE id=?',[edge])[0];patient.request(url)
check(sql('SELECT estado FROM cita WHERE id=?',[edge])[0]==before,'CU29: consultar no confirma, atiende ni marca inasistencia')

# CU27: código de un solo uso, caducidad, chat privado y revocación.
check('Activar avisos por Telegram' in patient.request('/telegram')[1],'CU27: muestra activación cuando el bot está configurado')
issue=form(patient,'/telegram',consentimiento='1')
check(patient.request('/telegram/activar',{'csrf':issue['csrf']})[0]==422,'CU27: exige autorización explícita')
def issue_token(browser=patient):
    r=browser.request('/telegram/activar',{'csrf':browser.token('/telegram'),'consentimiento':'1'})
    check(r[0]==200,'CU27: genera enlace temporal')
    return html.unescape(re.search('href="(https://t.me/[^\"]+\\?start=[a-f0-9]{64})"',r[1]).group(1)).split('start=')[1]
def update(token,update_id,chat=1234567,kind='private',sender=None):
    return php('echo json_encode(App\\TelegramService::update($x));',{'update_id':update_id,'message':{'chat':{'id':chat,'type':kind},'from':{'id':chat if sender is None else sender},'text':'/start '+token}})
token=issue_token();stored=sql('SELECT token_hash FROM token_vinculacion WHERE paciente_id=? AND usado_en IS NULL',[pid])
check(len(token)==64 and stored[0]['token_hash']!=token,'CU27: enlace aleatorio sin CI ni contraseña y código guardado como hash')
check(not update(token,100001,kind='group') and not update(token,100002,sender=987),'CU27: rechaza grupo y chat que no corresponde al remitente')
sql("UPDATE token_vinculacion SET caduca_en=now()-interval '1 second' WHERE paciente_id=?",[pid])
check(not update(token,100003),'CU27: código vencido rechazado')
token=issue_token();old=token;token=issue_token()
check(not update(old,100004),'CU27: generar un nuevo enlace invalida el anterior')
check(update(token,100005),'CU27: vincula el chat privado al paciente autenticado')
check(not update(token,100005) and not update(token,100006),'CU27: actualización y código reutilizados no duplican vinculación')
check('Telegram vinculado' in patient.request('/telegram')[1],'CU27: confirma el vínculo en la web')
check(not update(issue_token(other),100007),'CU27: el mismo chat no se vincula con otra ficha')
check(patient.request('/telegram/desactivar',{'csrf':patient.token('/telegram')})[0]==303
    and not sql('SELECT activo FROM vinculo_telegram WHERE paciente_id=?',[pid])[0]['activo'],'CU27: desactiva el consentimiento desde la web')
check(update(issue_token(),100008),'CU27: permite volver a autorizar el chat propio')
# El webhook no usa sesión ni CSRF, pero sí un secreto obligatorio y verificable.
check(Browser().request('/telegram/webhook',{},method='POST')[0]==403,'CU27: webhook rechaza peticiones sin secreto')
check(not php('echo json_encode(App\\TelegramService::webhookAuthorized("incorrecto"));'),'CU27: secreto incorrecto rechazado')

# CU28: avisos propios, errores, reintento autorizado y transporte externo aislado.
body=patient.request('/avisos')[1]
check('Telegram vinculado' in body and 'Cambio en su cita' in body,'CU28: reúne los avisos propios de citas y vinculación')
check(patient.request('/avisos?paciente_id='+str(pid2))[0]==403,'CU28: paciente no consulta otra ficha manipulando filtros')
check('Telegram vinculado' not in other.request('/avisos')[1],'CU28: excluye avisos de otro paciente')
notice=int(sql("INSERT INTO aviso(paciente_id,cita_id,clave_evento,tipo,texto) VALUES(?,?,?,'recordatorio','Dato ficticio interno') RETURNING id",[pid,edge,prefix])[0]['id'])
# Controla la cola para que la prueba no dependa de los avisos de otras regresiones.
sql("UPDATE aviso SET estado='omitido' WHERE id<>?",[notice])
before=sql('SELECT * FROM cita WHERE id=?',[edge])[0]
delivery=php('$c=new App\\TelegramClient(static function($m,$p){return ["ok"=>false];});echo json_encode(App\\TaskService::deliver($c,1));')
row=sql('SELECT * FROM aviso WHERE id=?',[notice])[0]
check(delivery=={'enviados':0,'errores':1} and row['estado']=='error' and row['enviado_en'] is None and int(row['intentos'])==1,
    'CU28: fallo no se presenta como entrega y registra el intento')
check(sql('SELECT * FROM cita WHERE id=?',[edge])[0]==before,'CU28: error de Telegram no modifica la cita')
retry={'csrf':receipt.token('/avisos'),'version':php('echo json_encode(App\\NoticeService::version(App\\Database::run("SELECT * FROM aviso WHERE id=?",[$x["id"]])->fetch()));',{'id':notice})}
check(receipt.request('/avisos/'+str(notice)+'/reintentar',retry)[0]==422,'CU28: exige revisar y autorizar un posible envío repetido')
retry['reintento_autorizado']='1'
check(patient.request('/avisos/'+str(notice)+'/reintentar',dict(retry,csrf=patient.token('/telegram')))[0]==403,'CU28: paciente no fuerza reintentos')
check(receipt.request('/avisos/'+str(notice)+'/reintentar',retry)[0]==303,'CU28: recepción vuelve a encolar el aviso con autorización')
check(receipt.request('/avisos/'+str(notice)+'/reintentar',retry)[0]==422,'CU28: doble reintento rechazado')
sent=php('$calls=[];$c=new App\\TelegramClient(static function($m,$p)use(&$calls){$calls[]=$p;return ["ok"=>true,"result"=>["message_id"=>88]];});'
    '$a=App\\TaskService::deliver($c,1);echo json_encode(["totals"=>$a,"calls"=>$calls]);')
row=sql('SELECT * FROM aviso WHERE id=?',[notice])[0]
check(sent['totals']=={'enviados':1,'errores':0} and row['estado']=='enviado' and row['enviado_en'] is not None and int(row['intentos'])==2,
    'CU28: éxito confirmado por API registra fecha y número de intentos')
check('Dato ficticio interno' not in sent['calls'][0]['text'] and 'CM-' in sent['calls'][0]['text'],
    'CU28: Telegram solo recibe asunto, referencia y acceso web; no datos médicos')
check(php('$c=new App\\TelegramClient(static fn($m,$p)=>["ok"=>true,"result"=>["message_id"=>1]]);echo json_encode(App\\TaskService::deliver($c));')=={'enviados':0,'errores':0},
    'CU28: un aviso enviado no vuelve a salir de la cola')

# CU30: contacto por WhatsApp estricto, sin mensajes automáticos ni cambios de citas.
# El servidor de pruebas se inicia con CLINICA_RECEPCION_WHATSAPP (tests/ejecutar_todo.ps1) con dos contactos ficticios.
WA_MSG='Hola%2C%20soy%20paciente%20de%20la%20Cl%C3%ADnica%20Vida%20Salud%20y%20quiero%20hacer%20una%20consulta.'
def wa_view(value):
    code=('$contacts=App\\ReceptionContact::whatsapp();ob_start();require getcwd()."/views/whatsapp_contact.php";echo json_encode(ob_get_clean());')
    return php(code,extra={'CLINICA_RECEPCION_WHATSAPP':value})
check('Contacto todavía no disponible' in wa_view('sin-numero') and 'Solicita a recepción su número de WhatsApp' in wa_view('1234'),'CU30: indica cuando no hay contacto publicado')
before=sql('SELECT * FROM cita WHERE paciente_id=? ORDER BY id',[pid]);body=patient.request('/contacto-recepcion')[1]
check('href="https://wa.me/59170000001?text='+WA_MSG+'"' in body and 'href="https://wa.me/59170000002?text='+WA_MSG+'"' in body
      and body.count('target="_blank" rel="noopener noreferrer"')>=2 and 'Recepción Prueba Uno' in body,'CU30: un botón por contacto abre WhatsApp con el mensaje prellenado')
check('t.me' not in body.split('<main',1)[-1],'CU30: la pantalla ya no usa Telegram')
check(sql('SELECT * FROM cita WHERE paciente_id=? ORDER BY id',[pid])==before,'CU30: abrir el contacto no envía mensaje ni cambia citas')
for bad in ['javascript:alert(1)','https://wa.me/59170000001','59170000001?text=otro','http://wa.me/59170000001','<script>alert(1)</script>:59170000001x','1234567','1234567890123456']:
    check(php('echo json_encode(App\\ReceptionContact::whatsapp());',extra={'CLINICA_RECEPCION_WHATSAPP':bad})==[],'CU30: rechaza enlaces ajenos, parámetros arbitrarios o números inválidos')
check([c['url'] for c in php('echo json_encode(App\\ReceptionContact::whatsapp());',extra={'CLINICA_RECEPCION_WHATSAPP':'Sara:+591 7000-0001,B:59170000001'})]==['https://wa.me/59170000001?text='+WA_MSG],
      'CU30: normaliza el número y no repite contactos')
# Revocación inmediata de permisos en una sesión ya abierta.
for permission,browser,path,role in [('CU26',receipt,'/ausencias','recepcionista'),('CU27',patient,'/telegram','paciente'),
    ('CU28',patient,'/avisos','paciente'),('CU29',patient,url,'paciente'),('CU30',patient,'/contacto-recepcion','paciente')]:
    sql('DELETE FROM rol_permiso WHERE rol_id=? AND permiso_id=(SELECT id FROM permiso WHERE codigo=?)',[ctx['roles'][role],permission])
    try: check(browser.request(path)[0]==403,'Retirar '+permission+' revoca el acceso inmediatamente')
    finally: sql('INSERT INTO rol_permiso SELECT ?,id FROM permiso WHERE codigo=?',[ctx['roles'][role],permission])

# Dos procesos independientes no envían la misma fila de la cola dos veces.
new_notice=int(sql("INSERT INTO aviso(paciente_id,clave_evento,tipo,texto) VALUES(?,?,'cambio_cita','Aviso concurrente') RETURNING id",[pid,prefix+'-race'])[0]['id'])
def concurrent_delivery(_):
    return php('$c=new App\\TelegramClient(static function($m,$p){App\\Database::run("SELECT pg_sleep(0.1)");return ["ok"=>true,"result"=>["message_id"=>99]];});echo json_encode(App\\TaskService::deliver($c,1));')
with ThreadPoolExecutor(max_workers=2) as pool: race=list(pool.map(concurrent_delivery,range(2)))
check(sum(x['enviados'] for x in race)==1 and int(sql('SELECT intentos FROM aviso WHERE id=?',[new_notice])[0]['intentos'])==1,
    'CU28: conexiones independientes compiten por la cola y solo una envía el aviso')
# /stop revoca el vínculo y los códigos aún abiertos.
future_token=issue_token()
check(php('echo json_encode(App\\TelegramService::update($x));',{'update_id':100009,'message':{'chat':{'id':1234567,'type':'private'},'from':{'id':1234567},'text':'/stop'}})
    and not sql('SELECT activo FROM vinculo_telegram WHERE paciente_id=?',[pid])[0]['activo']
    and not update(future_token,100010),'CU27: /stop revoca los avisos e invalida códigos pendientes')

# Mantenimiento sin visitas web: inactividad y recordatorios idempotentes.
sql("UPDATE paciente SET creado_en=now()-interval '2 years',reactivado_en=NULL WHERE id=?",[pid2])
sql("DELETE FROM integracion_estado WHERE clave='inactividad_dia'")
php('App\\TaskService::maintain();echo json_encode(true);')
check(not sql('SELECT activo FROM paciente WHERE id=?',[pid2])[0]['activo'],'Mantenimiento: ficha sin actividad un año se inactiva')
before=int(sql("SELECT count(*) AS n FROM evento_administrativo WHERE paciente_id=? AND accion='inactividad'",[pid2])[0]['n'])
php('App\\TaskService::maintain();echo json_encode(true);')
check(int(sql("SELECT count(*) AS n FROM evento_administrativo WHERE paciente_id=? AND accion='inactividad'",[pid2])[0]['n'])==before,'Mantenimiento: ejecución repetida no duplica inactividad')
# Solo fixtures en esta base desechable: mueve el turno al próximo bloque horario.
reminder=book('11:00',did3)
sql('ALTER TABLE cita DISABLE TRIGGER tr_validar_cita')
try: sql("WITH actor AS MATERIALIZED(SELECT set_config('app.actor_id',?::text,true)) UPDATE cita SET inicio=date_trunc('hour',now())+interval '1 hour',fin=date_trunc('hour',now())+interval '90 minutes' FROM actor WHERE id=?",[str(receipt_id),reminder])
finally: sql('ALTER TABLE cita ENABLE TRIGGER tr_validar_cita')
php('App\\TaskService::maintain();App\\TaskService::maintain();echo json_encode(true);')
check(int(sql("SELECT count(*) AS n FROM aviso WHERE cita_id=? AND tipo='recordatorio'",[reminder])[0]['n'])==1,'Mantenimiento: encola un solo recordatorio por cita, horario y ventana')
sql('ALTER TABLE cita DISABLE TRIGGER tr_validar_cita')
try: sql("WITH actor AS MATERIALIZED(SELECT set_config('app.actor_id',?::text,true)) UPDATE cita SET estado='cancelada',origen_cambio='clinica',justificada=true FROM actor WHERE id=?",[str(receipt_id),reminder])
finally: sql('ALTER TABLE cita ENABLE TRIGGER tr_validar_cita')
php('App\\TaskService::maintain();echo json_encode(true);')
check(sql("SELECT estado FROM aviso WHERE cita_id=? AND tipo='recordatorio'",[reminder])[0]['estado']=='omitido','Mantenimiento: cancela el envío de recordatorios de citas canceladas')

(ROOT/'tests/resultados_validacion_cu26_30.json').write_text(json.dumps({'regresion':base,'nuevas':len(results)-base,'passed':len(results),'results':results},ensure_ascii=False,indent=2))
print(f'{len(results)-base} nuevas comprobaciones; {len(results)} comprobaciones HTTP y de servicios correctas en total.')
