"""Prueba sobre una instalación de desarrollo y base vacía terminada en _test.

1. Configura DB_* para la base de pruebas y ejecuta bin/instalar.php.
2. Crea el primer administrador con CLINICA_ADMIN_USER, CLINICA_ADMIN_EMAIL y
   CLINICA_ADMIN_PASSWORD en el entorno, usando bin/crear_admin.php.
3. Inicia php -S 127.0.0.1:8000 -t public router.php con ese mismo entorno.
4. CLINICA_TEST_URL debe apuntar a ese servidor local. PHP_BIN es opcional.
5. Ejecuta python tests/http_smoke.py. No usar sobre datos reales.
"""
import base64
import copy
import http.cookiejar
import html
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
BASE = os.environ.get('CLINICA_TEST_URL', 'http://127.0.0.1:8000').rstrip('/')
if not os.environ.get('DB_NAME', '').endswith('_test'):
    raise SystemExit('La base de pruebas debe terminar en _test.')
if urllib.parse.urlparse(BASE).hostname not in ('127.0.0.1', 'localhost'):
    raise SystemExit('Estas pruebas solo pueden ejecutarse en un servidor local.')
PHP = os.environ.get('PHP_BIN') or shutil.which('php')
ADMIN_USER = os.environ['CLINICA_ADMIN_USER']
ADMIN_PASS = os.environ['CLINICA_ADMIN_PASSWORD']
ADMIN_EMAIL = os.environ['CLINICA_ADMIN_EMAIL']
PREFIX = 'prueba_' + secrets.token_hex(4)
PASSWORD = secrets.token_urlsafe(20)
results = []


def check(condition, name):
    results.append({'name': name, 'passed': bool(condition)})
    print(('[OK] ' if condition else '[FALLO] ') + name, flush=True)
    if not condition:
        raise AssertionError(name)


def sql(query, values=None):
    code = ('require getcwd()."/bootstrap.php";'
            '$x=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);'
            'echo json_encode(App\\Database::run($x["query"],$x["values"])->fetchAll(),JSON_THROW_ON_ERROR);')
    run = subprocess.run([PHP, '-r', code], cwd=ROOT, input=json.dumps({'query': query, 'values': values or []}),
                         text=True, capture_output=True, check=True)
    return json.loads(run.stdout)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Browser:
    def __init__(self):
        self.cookies = http.cookiejar.CookieJar()
        self.open = urllib.request.build_opener(urllib.request.ProxyHandler({}),
            urllib.request.HTTPCookieProcessor(self.cookies), NoRedirect()).open

    def request(self, path, data=None, method=None):
        encoded = urllib.parse.urlencode(data).encode() if data is not None else None
        req = urllib.request.Request(BASE + path, encoded, method=method)
        try:
            response = self.open(req, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.code, response.read().decode('utf-8'), response.headers

    def token(self, path='/ingresar'):
        status, body, _ = self.request(path)
        if status != 200:
            raise AssertionError('No se abrió el formulario ' + path)
        return html.unescape(re.search(r'name="csrf" value="([^"]+)"', body).group(1))

    def login(self, username, password):
        token = self.token()
        previous = next((c.value for c in self.cookies if c.name == 'vida_salud'), '')
        result = self.request('/sesion', {'csrf': token, 'identidad': username, 'password': password})
        current = next((c.value for c in self.cookies if c.name == 'vida_salud'), '')
        return result, previous, current


def register_data(username, email, ci):
    return {'nombre_usuario': username, 'correo': email, 'ci': ci, 'nombres': 'María', 'apellidos': 'Ejemplo',
            'telefono': '+591 70000000', 'password': PASSWORD, 'password_confirmation': PASSWORD}


guest = Browser()
check(guest.request('/panel')[0] == 303, 'Panel privado exige iniciar sesión')
check(guest.request('/salir', {})[0] == 303, 'Cerrar una sesión ya finalizada devuelve al inicio')
token = guest.token()
check(guest.request('/sesion', {'csrf': 'incorrecto', 'identidad': ADMIN_USER, 'password': ADMIN_PASS})[0] == 419,
      'Formulario sin token válido rechazado')
patient_name = PREFIX + '_paciente'
data = register_data(patient_name, patient_name + '@example.invalid', PREFIX + '-CI')
data.update(csrf=guest.token('/registro'), rol_id='1', habilitado='1')
check(guest.request('/registro', data)[0] == 303, 'Registro de paciente completo')
row = sql('SELECT u.id,r.codigo FROM usuario u JOIN rol r ON r.id=u.rol_id WHERE u.nombre_usuario=?', [patient_name])[0]
patient_user_id = int(row['id'])
check(row['codigo'] == 'paciente', 'Alterar rol_id en el registro público no crea un administrador')
check(len(sql('SELECT id FROM paciente WHERE usuario_id=?', [patient_user_id])) == 1, 'Cuenta vinculada a un único paciente')
before = int(sql('SELECT count(*) AS n FROM usuario')[0]['n'])
dup = register_data(PREFIX + '_duplicado', PREFIX + '_otro@example.invalid', data['ci'])
dup['csrf'] = guest.token('/registro')
check(guest.request('/registro', dup)[0] == 422, 'CI duplicado rechazado')
check(int(sql('SELECT count(*) AS n FROM usuario')[0]['n']) == before, 'Un CI repetido no deja una cuenta parcial')
dup = register_data(PREFIX + '_correo', data['correo'].upper(), PREFIX + '-CI2')
dup['csrf'] = guest.token('/registro')
check(guest.request('/registro', dup)[0] == 422, 'Correo repetido con mayúsculas rechazado')
invalid = register_data(PREFIX + '_invalido', PREFIX + '_invalido@example.invalid', PREFIX + '-CI3')
invalid['csrf'] = guest.token('/registro'); invalid['password_confirmation'] = 'No coincide con la contraseña'
invalid_response = guest.request('/registro', invalid)
check(invalid_response[0] == 422 and PASSWORD not in invalid_response[1], 'Formulario inválido no devuelve la contraseña al navegador')
result, _, _ = guest.login("' OR 1=1 --", 'valor de prueba incorrecto')
check(result[0] == 422, 'Intento de inyección en acceso rechazado')
patient = Browser()
result, old_sid, new_sid = patient.login(data['correo'].upper(), PASSWORD)
check(result[0] == 303, 'Ingreso por correo sin distinguir mayúsculas')
check(bool(old_sid) and old_sid != new_sid, 'Identificador de sesión cambia después de ingresar')
check('httponly' in str(result[2].get('Set-Cookie')).lower() and 'samesite=lax' in str(result[2].get('Set-Cookie')).lower(),
      'Cookie de sesión HttpOnly y SameSite')
status, body, headers = patient.request('/panel')
check(status == 200 and 'María' in body and 'Paciente' in body, 'Panel muestra el paciente autenticado')
check('no-store' in headers.get('Cache-Control', ''), 'Las páginas privadas no se almacenan en caché')
check(patient.request('/usuarios')[0] == 403, 'Paciente sin acceso a administración de usuarios')
check(patient.request('/usuarios/1/editar')[0] == 403, 'Paciente no edita una cuenta usando su identificador')
check(patient.request('/roles')[0] == 403, 'Paciente no consulta la matriz administrativa')
check(patient.request('/salir')[0] == 404 and patient.request('/panel')[0] == 200, 'Una petición GET no cierra la sesión')
logout_token = re.search(r'name="csrf" value="([^"]+)"', body).group(1)
check(patient.request('/salir', {'csrf': logout_token})[0] == 303 and patient.request('/panel')[0] == 303,
      'Cerrar sesión invalida el acceso anterior')
long_password = Browser()
check(long_password.login(patient_name, PASSWORD + 'x' * 80)[0][0] == 422, 'Acceso rechaza contraseñas mayores de 72 bytes')
admin = Browser()
check(admin.login(ADMIN_USER, ADMIN_PASS)[0][0] == 303, 'Inicio de sesión del administrador')
check(admin.request('/roles')[0] == 200, 'Administrador consulta roles y permisos')
check(admin.request('/usuarios')[0] == 200, 'Administrador consulta usuarios')
role_rows = sql('SELECT id,codigo FROM rol')
roles = {x['codigo']: int(x['id']) for x in role_rows}
admin_id = int(sql('SELECT id FROM usuario WHERE nombre_usuario=?', [ADMIN_USER])[0]['id'])
for role in ['recepcionista', 'medico']:
    payload = {'csrf': admin.token('/usuarios/nuevo'), 'nombre_usuario': PREFIX + '_' + role,
               'correo': PREFIX + '_' + role + '@example.invalid', 'rol_id': str(roles[role]), 'habilitado': '1',
               'password': PASSWORD, 'password_confirmation': PASSWORD}
    check(admin.request('/usuarios/nuevo', payload)[0] == 303, 'Administrador crea cuenta de ' + role)
    employee = Browser()
    check(employee.login(payload['nombre_usuario'], PASSWORD)[0][0] == 303 and employee.request('/panel')[0] == 200,
          role.capitalize() + ' inicia sesión y accede a su panel')
    check(employee.request('/usuarios')[0] == 403, role.capitalize() + ' no accede a administración de usuarios')
edit = {'csrf': admin.token(f'/usuarios/{admin_id}/editar'), 'nombre_usuario': ADMIN_USER, 'correo': ADMIN_EMAIL,
        'rol_id': str(roles['administrador']), 'password': '', 'password_confirmation': ''}
check(admin.request(f'/usuarios/{admin_id}/editar', edit)[0] == 422, 'No se deshabilita al último administrador')
edit.update(csrf=admin.token(f'/usuarios/{admin_id}/editar'), habilitado='1', rol_id=str(roles['recepcionista']))
check(admin.request(f'/usuarios/{admin_id}/editar', edit)[0] == 422, 'No se cambia el rol del último administrador')

# CU05: guardar la matriz como lo haría un navegador, incluidos los campos fijos.
def matrix_form():
    status, body, _ = admin.request('/roles')
    if status != 200:
        raise AssertionError('No se abrió Roles y permisos')
    payload = []
    for tag in re.findall(r'<input\b[^>]*>', body):
        attrs = dict(re.findall(r'([a-zA-Z_][\w-]*)="([^"]*)"', tag))
        if attrs.get('type') == 'checkbox' and ('checked' not in tag or 'disabled' in tag):
            continue
        if attrs.get('type') not in ('hidden', 'checkbox'):
            continue
        payload.append((html.unescape(attrs['name']), html.unescape(attrs.get('value', ''))))
    return payload


def selected_pairs():
    return sorted((r['rol'], r['permiso']) for r in sql(
        'SELECT r.codigo AS rol,p.codigo AS permiso FROM rol r JOIN rol_permiso rp ON rp.rol_id=r.id JOIN permiso p ON p.id=rp.permiso_id'))

matrix = matrix_form()
check(any(k == 'version' and len(v) == 64 for k,v in matrix), 'Matriz incluye versión para evitar sobrescribir otra edición')
check(any(k == 'permisos[administrador][]' and v == 'CU05' for k,v in matrix),
      'El formulario conserva los permisos administrativos fijos')
original_pairs = selected_pairs()
check(admin.request('/roles', [(k,v) for k,v in matrix if k != 'csrf'])[0] == 419,
      'No se guarda la matriz sin protección CSRF')
for role in ['paciente', 'recepcionista', 'medico']:
    account = Browser()
    name = patient_name if role == 'paciente' else PREFIX + '_' + role
    account.login(name, PASSWORD)
    token = account.token('/panel')
    altered = [(k, token if k == 'csrf' else v) for k,v in matrix]
    check(account.request('/roles', altered)[0] == 403, role.capitalize() + ' no modifica la matriz mediante POST')

# El cambio debe afectar una operación ya implementada: registro público CU01.
without_registration = [(k,v) for k,v in matrix if not (k == 'permisos[paciente][]' and v == 'CU01')]
check(admin.request('/roles', without_registration)[0] == 303, 'Administrador guarda un cambio válido de permisos')
check(('paciente', 'CU01') not in selected_pairs(), 'El permiso retirado queda guardado en PostgreSQL')
registration_guest = Browser()
check(registration_guest.request('/registro')[0] == 403, 'Retirar CU01 bloquea el formulario de registro público')
blocked_registration = register_data(PREFIX + '_bloqueado', PREFIX + '_bloqueado@example.invalid', PREFIX + '-BLOQUEADO')
blocked_registration['csrf'] = registration_guest.token()
check(registration_guest.request('/registro', blocked_registration)[0] == 403,
      'Retirar CU01 también bloquea el envío directo de un registro')
check(admin.request('/roles', matrix)[0] == 422 and ('paciente', 'CU01') not in selected_pairs(),
      'Un formulario antiguo no sobrescribe permisos guardados después')
restore = matrix_form(); restore.append(('permisos[paciente][]', 'CU01'))
check(admin.request('/roles', restore)[0] == 303 and selected_pairs() == original_pairs,
      'Administrador vuelve a habilitar el permiso y restaura la matriz')
check(Browser().request('/registro')[0] == 200, 'El registro público vuelve a estar disponible al habilitar CU01')
check(int(sql("SELECT count(*) AS n FROM evento_administrativo WHERE actor_id=? AND accion='actualizar_permiso'", [admin_id])[0]['n']) == 2,
      'Cada cambio de permiso conserva responsable y evento de auditoría')
invalid = matrix_form()
invalid = [(k,v) for k,v in invalid if not (k == 'permisos[administrador][]' and v == 'CU05')]
check(admin.request('/roles', invalid)[0] == 422 and selected_pairs() == original_pairs,
      'No se elimina el último acceso a la administración de permisos')
invalid = matrix_form(); invalid.append(('permisos[paciente][]', 'CU04'))
check(admin.request('/roles', invalid)[0] == 422 and selected_pairs() == original_pairs,
      'No se concede gestión de usuarios al rol Paciente mediante datos manipulados')
invalid = [(k,v) for k,v in matrix_form() if not k.startswith('roles_presentes[medico]')]
check(admin.request('/roles', invalid)[0] == 422 and selected_pairs() == original_pairs,
      'Una matriz incompleta se rechaza sin modificar los permisos existentes')
invalid = matrix_form(); invalid.append(('permisos[medico][]', 'CU99'))
check(admin.request('/roles', invalid)[0] == 422, 'Un permiso desconocido es rechazado')
check(admin.request('/roles', matrix_form())[0] == 303 and selected_pairs() == original_pairs,
      'Guardar sin cambios conserva la matriz y los accesos')

sql('UPDATE paciente SET activo=false WHERE usuario_id=?', [patient_user_id])
inactive = Browser()
check(inactive.login(patient_name, PASSWORD)[0][0] == 303, 'Paciente inactivo conserva acceso a su cuenta')
check('inactivo para nuevas reservas' in inactive.request('/panel')[1], 'El panel distingue la inactividad administrativa')
check('password_hash' not in admin.request('/usuarios')[1], 'La tabla de usuarios no muestra hashes')
sql('UPDATE usuario SET habilitado=false WHERE id=?', [patient_user_id])
check(inactive.request('/panel')[0] == 303, 'Deshabilitar una cuenta revoca el acceso de su sesión abierta')
disabled = Browser()
check(disabled.login(patient_name, PASSWORD)[0][0] == 422, 'Usuario deshabilitado no inicia sesión')
sql('DELETE FROM rol_permiso WHERE rol_id=? AND permiso_id=(SELECT id FROM permiso WHERE codigo=?)', [roles['administrador'], 'CU04'])
check(admin.request('/usuarios')[0] == 403, 'Cambios de permisos se aplican en la siguiente petición')
sql('INSERT INTO rol_permiso(rol_id,permiso_id) SELECT ?,id FROM permiso WHERE codigo=?', [roles['administrador'], 'CU04'])
check(guest.request('/.env')[0] == 404 and guest.request('/bootstrap.php')[0] == 404, 'Configuración privada fuera del acceso web')
check(guest.request('/assets/app.css')[0] == 200, 'Hoja de estilos disponible')
reception_id = int(sql('SELECT id FROM usuario WHERE nombre_usuario=?', [PREFIX + '_recepcionista'])[0]['id'])
reception = Browser()
reception.login(PREFIX + '_recepcionista', PASSWORD)
edit_reception = {'csrf': admin.token(f'/usuarios/{reception_id}/editar'), 'nombre_usuario': PREFIX + '_recepcionista',
                  'correo': PREFIX + '_recepcionista@example.invalid', 'rol_id': str(roles['recepcionista']),
                  'password': '', 'password_confirmation': ''}
check(admin.request(f'/usuarios/{reception_id}/editar', edit_reception)[0] == 303,
      'Administrador deshabilita una cuenta desde el formulario')
check(reception.request('/panel')[0] == 303, 'Deshabilitación por formulario revoca la sesión abierta')
edit_reception.update(csrf=admin.token(f'/usuarios/{reception_id}/editar'), habilitado='1')
check(admin.request(f'/usuarios/{reception_id}/editar', edit_reception)[0] == 303,
      'Administrador vuelve a habilitar la cuenta')
reception = Browser()
check(reception.login(PREFIX + '_recepcionista', PASSWORD)[0][0] == 303,
      'Edición sin nueva contraseña conserva la contraseña anterior')
new_password = secrets.token_urlsafe(20)
edit_reception.update(csrf=admin.token(f'/usuarios/{reception_id}/editar'), password=new_password, password_confirmation=new_password)
check(admin.request(f'/usuarios/{reception_id}/editar', edit_reception)[0] == 303,
      'Administrador cambia la contraseña de una cuenta')
check(reception.request('/panel')[0] == 303, 'Cambio de contraseña revoca las sesiones anteriores')
reception = Browser()
check(reception.login(PREFIX + '_recepcionista', new_password)[0][0] == 303,
      'La nueva contraseña permite iniciar sesión')

# CU06: datos administrativos, permisos e historial conservado.
check(admin.request('/pacientes')[0] == 200 and reception.request('/pacientes')[0] == 200,
      'Administrador y recepción consultan el listado de pacientes')
check(reception.request('/pacientes/nuevo')[0] == 200, 'Recepción abre el alta de pacientes')
users_before = int(sql('SELECT count(*) AS n FROM usuario')[0]['n'])
clinical = {'ci': PREFIX + '-CLINICA', 'nombres':'Elena', 'apellidos':'Prueba Clínica',
            'fecha_nacimiento':'2000-02-29', 'sexo':'Femenino', 'telefono':'+591 70000001',
            'correo':PREFIX + '_contacto@example.invalid', 'direccion':'Calle de prueba 12',
            'csrf':reception.token('/pacientes/nuevo'), 'usuario_id':str(admin_id), 'activo':'0'}
created = reception.request('/pacientes/nuevo', clinical)
check(created[0] == 303, 'Recepción registra un paciente con datos personales y de contacto')
clinical_id = int(created[2]['Location'].rsplit('/',1)[1])
row = sql('SELECT * FROM paciente WHERE id=?', [clinical_id])[0]
check(row['ci'] == clinical['ci'].upper() and row['usuario_id'] is None and row['activo'],
      'Alta normaliza CI, inicia activa e ignora una vinculación de usuario manipulada')
check(int(sql('SELECT count(*) AS n FROM usuario')[0]['n']) == users_before,
      'Registrar una ficha no crea una cuenta de acceso adicional')
check('Elena' in reception.request(f'/pacientes/{clinical_id}')[1], 'Recepción consulta la ficha registrada')
for query in [clinical['ci'], 'Elena', 'Prueba Clínica']:
    status, body, _ = reception.request('/pacientes?' + urllib.parse.urlencode({'q':query}))
    check(status == 200 and 'Elena' in body, 'Búsqueda de paciente: ' + query)
check('No se encontraron pacientes' in reception.request('/pacientes?q=sin_resultados_' + PREFIX)[1],
      'Búsqueda sin coincidencias muestra un listado vacío')
patients_before = int(sql('SELECT count(*) AS n FROM paciente')[0]['n'])
duplicate = dict(clinical, ci='  '+clinical['ci'].lower()+'  ', csrf=admin.token('/pacientes/nuevo'))
check(admin.request('/pacientes/nuevo', duplicate)[0] == 422
      and int(sql('SELECT count(*) AS n FROM paciente')[0]['n']) == patients_before,
      'CI equivalente duplicado se rechaza sin crear registros parciales')
for field,value in [('fecha_nacimiento','2001-02-30'),('fecha_nacimiento','2999-01-01'),('correo','correo-invalido'),('telefono','12')]:
    invalid_patient = dict(clinical, csrf=admin.token('/pacientes/nuevo'),ci=PREFIX+'-INVALIDO')
    invalid_patient[field] = value
    check(admin.request('/pacientes/nuevo', invalid_patient)[0] == 422, 'Paciente rechaza dato inválido: ' + field + ' ' + value)
check(admin.request('/pacientes/nuevo', clinical | {'csrf':'invalido'})[0] == 419,
      'El registro de pacientes exige CSRF')
sql('UPDATE usuario SET habilitado=true WHERE id=?', [patient_user_id])
patient_access = Browser(); patient_access.login(patient_name, PASSWORD)
medical_access = Browser(); medical_access.login(PREFIX + '_medico', PASSWORD)
for role,browser in [('Paciente',patient_access),('Médico',medical_access)]:
    check(browser.request('/pacientes')[0] == 403 and browser.request(f'/pacientes/{clinical_id}')[0] == 403,
          role + ' no consulta el módulo administrativo de pacientes')
    check(browser.request('/pacientes/nuevo',clinical | {'csrf':browser.token('/panel')})[0] == 403,
          role + ' no registra pacientes mediante un envío directo')
check(guest.request('/pacientes')[0] == 303, 'Consulta de pacientes exige iniciar sesión')
check(admin.request('/pacientes/999999999')[0] == 404, 'Ficha de paciente inexistente devuelve 404')


def patient_payload(browser, patient_id):
    status, body, _ = browser.request(f'/pacientes/{patient_id}/editar')
    if status != 200:
        raise AssertionError('No se abrió la edición de paciente')
    row = sql('SELECT * FROM paciente WHERE id=?',[patient_id])[0]
    payload = {k:row.get(k) or '' for k in ['ci','nombres','apellidos','fecha_nacimiento','sexo','telefono','correo','direccion']}
    payload['version'] = re.search(r'name="version" value="([^"]+)"',body).group(1)
    payload['csrf'] = re.search(r'name="csrf" value="([^"]+)"',body).group(1)
    return payload


edit_patient = patient_payload(reception,clinical_id)
old_patient_edit = dict(edit_patient)
edit_patient.update(telefono='+591 70000002',direccion='Dirección actualizada')
check(reception.request(f'/pacientes/{clinical_id}/editar',edit_patient)[0] == 303,
      'Recepción actualiza los datos del paciente')
old_patient_edit.update(direccion='Edición antigua')
check(reception.request(f'/pacientes/{clinical_id}/editar',old_patient_edit)[0] == 422
      and sql('SELECT direccion FROM paciente WHERE id=?',[clinical_id])[0]['direccion'] == 'Dirección actualizada',
      'Una edición antigua no sobrescribe los cambios del paciente')
linked_id = int(sql('SELECT id FROM paciente WHERE usuario_id=?',[patient_user_id])[0]['id'])
linked = patient_payload(reception,linked_id)
linked.update(correo=PREFIX+'_contacto_distinto@example.invalid',telefono='+591 70000003',usuario_id=str(admin_id),activo='1')
check(reception.request(f'/pacientes/{linked_id}/editar',linked)[0] == 303,
      'Se actualiza el contacto de un paciente vinculado a una cuenta')
linked_row = sql('SELECT usuario_id,activo,correo FROM paciente WHERE id=?',[linked_id])[0]
check(int(linked_row['usuario_id']) == patient_user_id and not linked_row['activo']
      and sql('SELECT correo FROM usuario WHERE id=?',[patient_user_id])[0]['correo'] == data['correo'],
      'Editar paciente no cambia su cuenta, correo de acceso ni reactiva su estado')
check(Browser().login(patient_name,PASSWORD)[0][0] == 303, 'La cuenta conserva su contraseña tras editar la ficha')

# Se cargan relaciones ficticias para comprobar su conservación, sin implementar el módulo de citas.
sql('UPDATE paciente SET activo=true WHERE id=?',[linked_id])
fixture_doctor = int(sql('INSERT INTO medico(registro_profesional,nombres,apellidos) VALUES(?,?,?) RETURNING id',
                         [PREFIX+'-MEDICO','Profesional','Prueba'])[0]['id'])
fixture_specialty = int(sql('INSERT INTO especialidad(nombre) VALUES(?) RETURNING id',[PREFIX+' especialidad'])[0]['id'])
sql('INSERT INTO medico_especialidad(medico_id,especialidad_id) VALUES(?,?)',[fixture_doctor,fixture_specialty])
for day in range(1,8):
    sql("INSERT INTO horario(medico_id,dia_semana,hora_inicio,hora_fin) VALUES(?,?,'08:00','18:00')",[fixture_doctor,day])
sql("CALL sp_programar_cita(?,?,?,(current_date+7+time '10:00') AT TIME ZONE 'America/La_Paz',?)",
    [linked_id,fixture_doctor,fixture_specialty,admin_id])
fixture_cita = int(sql('SELECT id FROM cita WHERE paciente_id=? ORDER BY id DESC LIMIT 1',[linked_id])[0]['id'])
incident = int(sql("INSERT INTO incumplimiento(cita_id,tipo) VALUES(?,'cancelacion_tardia') RETURNING id",[fixture_cita])[0]['id'])
sql('INSERT INTO multa(incumplimiento_id,monto) VALUES(?,20)',[incident])
counts_before = {t:int(sql('SELECT count(*) AS n FROM '+t)[0]['n']) for t in ['cita','historial_cita','aviso','multa']}
detail_status, detail_body, _ = reception.request(f'/pacientes/{linked_id}')
version = re.search(r'name="version" value="([^"]+)"',detail_body).group(1)
csrf = reception.token(f'/pacientes/{linked_id}')
check('Citas vigentes' in detail_body and 'Multas pendientes' in detail_body,
      'La ficha informa de las citas y multas relacionadas')
check(reception.request(f'/pacientes/{linked_id}/desactivar',{'csrf':csrf,'version':version,'motivo':''})[0] == 422,
      'Desactivar paciente exige registrar un motivo')
check(reception.request(f'/pacientes/{linked_id}/desactivar')[0] == 404
      and sql('SELECT activo FROM paciente WHERE id=?',[linked_id])[0]['activo'],
      'Una petición GET no desactiva al paciente')
check(reception.request(f'/pacientes/{linked_id}/desactivar',{'csrf':csrf,'version':version,'motivo':'Solicitud administrativa de prueba'})[0] == 303,
      'Recepción desactiva al paciente con motivo')
check(not sql('SELECT activo FROM paciente WHERE id=?',[linked_id])[0]['activo']
      and sql('SELECT habilitado FROM usuario WHERE id=?',[patient_user_id])[0]['habilitado'],
      'La desactivación del paciente conserva habilitada su cuenta de acceso')
check(all(int(sql('SELECT count(*) AS n FROM '+t)[0]['n']) == count for t,count in counts_before.items())
      and sql('SELECT estado FROM cita WHERE id=?',[fixture_cita])[0]['estado'] == 'programada',
      'Desactivar conserva citas, historial, avisos y multas sin cancelar turnos futuros')
check(Browser().login(patient_name,PASSWORD)[0][0] == 303, 'El paciente desactivado puede seguir iniciando sesión')
check(int(sql("SELECT count(*) AS n FROM evento_administrativo WHERE paciente_id=? AND accion='desactivar_paciente'",[linked_id])[0]['n']) == 1,
      'La desactivación conserva un evento asociado al paciente y su responsable')
check(reception.request(f'/pacientes/{linked_id}/desactivar',{'csrf':csrf,'version':version,'motivo':'Operación repetida'})[0] == 303
      and int(sql("SELECT count(*) AS n FROM evento_administrativo WHERE paciente_id=? AND accion='desactivar_paciente'",[linked_id])[0]['n']) == 1,
      'Repetir la desactivación no duplica el evento')
check('Elena' not in reception.request('/pacientes?estado=inactivos')[1]
      and data['ci'].upper() in reception.request('/pacientes?estado=inactivos')[1],
      'El filtro separa pacientes activos e inactivos')
# La revocación de CU06 debe afectar también a una sesión que ya estaba abierta.
form = matrix_form()
without_cu06 = [(k,v) for k,v in form if not (k=='permisos[recepcionista][]' and v=='CU06')]
check(admin.request('/roles',without_cu06)[0] == 303 and reception.request('/pacientes')[0] == 403,
      'Retirar CU06 impide gestionar pacientes en una sesión de recepción abierta')
check(reception.request(f'/pacientes/{clinical_id}/editar',edit_patient)[0] == 403,
      'La revocación de CU06 también bloquea formularios abiertos antes del cambio')
restore_cu06 = matrix_form();restore_cu06.append(('permisos[recepcionista][]','CU06'))
check(admin.request('/roles',restore_cu06)[0] == 303 and reception.request('/pacientes')[0] == 200,
      'Restituir CU06 permite volver a gestionar pacientes')

# CU07: el propietario proviene de la sesión y los tres conjuntos de datos se guardan juntos.
PROFILE_FIELDS = ['telefono','correo','direccion','tipo_sangre','alergias','enfermedades_cronicas',
                  'medicamentos','antecedentes','emergencia_nombre','emergencia_parentesco','emergencia_telefono']


def profile_row(patient_id):
    return sql('SELECT p.*,s.tipo_sangre,s.alergias,s.enfermedades_cronicas,s.medicamentos,s.antecedentes,'
               'c.nombre AS emergencia_nombre,c.parentesco AS emergencia_parentesco,c.telefono AS emergencia_telefono '
               'FROM paciente p LEFT JOIN perfil_salud s ON s.paciente_id=p.id '
               'LEFT JOIN contacto_emergencia c ON c.paciente_id=p.id WHERE p.id=?',[patient_id])[0]


def profile_payload(browser, patient_id=linked_id):
    status, body, _ = browser.request('/mi-perfil')
    if status != 200:
        raise AssertionError('No se abrió Mi perfil')
    row = profile_row(patient_id)
    payload = {key:row.get(key) or '' for key in PROFILE_FIELDS}
    for key in ['csrf','version']:
        payload[key] = html.unescape(re.search(r'name="'+key+r'" value="([^"]+)"',body).group(1))
    return payload


check(guest.request('/mi-perfil')[0] == 303, 'Mi perfil exige una sesión autenticada')
check(guest.request('/mi-perfil',{'csrf':guest.token()})[0] == 303,
      'No se guardan cambios del perfil sin iniciar sesión')
for role,browser in [('Administrador',admin),('Recepción',reception),('Médico',medical_access)]:
    check(browser.request('/mi-perfil')[0] == 403
          and browser.request('/mi-perfil',{'csrf':browser.token('/panel')})[0] == 403,
          role + ' no utiliza el perfil de un paciente aunque tenga otros permisos')
status, body, _ = patient_access.request('/mi-perfil')
check(status == 200 and data['ci'].upper() in body and 'Contacto de emergencia' in body
      and 'Información médica básica' in body and clinical['ci'].upper() not in body,
      'Paciente consulta su propio perfil, incluso estando inactivo y con deuda')
check('name="ci"' not in body and 'name="nombres"' not in body and 'href="/mi-perfil"' in patient_access.request('/panel')[1],
      'Mi perfil aparece en el panel y mantiene protegidos los datos de identidad')
for selector,value in [('paciente_id',clinical_id),('usuario_id',admin_id)]:
    check(patient_access.request('/mi-perfil?' + urllib.parse.urlencode({selector:value}))[0] == 403,
          'El perfil deniega un identificador ajeno enviado en ' + selector)
check(patient_access.request('/mi-perfil/' + str(clinical_id))[0] == 404,
      'No existe una ruta para consultar un perfil mediante el id de otro paciente')
original_profile = profile_row(linked_id)
original_account = sql('SELECT correo,password_hash,rol_id,habilitado FROM usuario WHERE id=?',[patient_user_id])[0]
staff_open_form = patient_payload(reception,linked_id)
form = profile_payload(patient_access)
check(patient_access.request('/mi-perfil',dict(form,paciente_id=str(clinical_id)))[0] == 403
      and profile_row(linked_id) == original_profile,
      'Un identificador de paciente manipulado no cambia ningún perfil')
check(patient_access.request('/mi-perfil',dict(form,ci=PREFIX+'-CAMBIADO'))[0] == 422
      and profile_row(linked_id) == original_profile,
      'El paciente no cambia su CI mediante un envío directo')
check(patient_access.request('/mi-perfil',dict(form,csrf='invalido'))[0] == 419,
      'Guardar perfil exige protección CSRF')
form.update(telefono='+591 70000005',correo=PREFIX+'_perfil@example.invalid',direccion='Domicilio ficticio & patio',
            emergencia_nombre='Contacto de ejemplo',emergencia_parentesco='Familiar',emergencia_telefono='+591 70000006',
            tipo_sangre='O+',alergias='Información ficticia de prueba',enfermedades_cronicas='Dato ficticio',
            medicamentos='Dato de prueba',antecedentes='Primera línea de prueba.\nSegunda línea de prueba.',
            activo='1',rol_id=str(roles['administrador']),habilitado='0',password='Intento de cambiar credenciales')
check(patient_access.request('/mi-perfil',form)[0] == 303,
      'El paciente guarda contacto, emergencia y salud en la misma operación')
saved_profile = profile_row(linked_id)
check(all(saved_profile[key] == form[key] for key in PROFILE_FIELDS),
      'Los datos de contacto, salud y emergencia quedan guardados en PostgreSQL')
check(saved_profile['ci'] == original_profile['ci'] and not saved_profile['activo']
      and saved_profile['usuario_id'] == original_profile['usuario_id']
      and sql('SELECT correo,password_hash,rol_id,habilitado FROM usuario WHERE id=?',[patient_user_id])[0] == original_account,
      'Editar perfil no cambia identidad, rol, contraseña, correo de acceso ni reactiva al paciente')
check(all(int(sql('SELECT count(*) AS n FROM '+t)[0]['n']) == count for t,count in counts_before.items())
      and sql('SELECT estado FROM cita WHERE id=?',[fixture_cita])[0]['estado'] == 'programada',
      'Actualizar el perfil conserva citas, historial, avisos y multas')
saved_body = patient_access.request('/mi-perfil')[1]
check('Domicilio ficticio &amp; patio' in saved_body and form['antecedentes'] in saved_body
      and form['emergencia_nombre'] in saved_body and 'value="O+" selected' in saved_body,
      'El formulario vuelve a mostrar los datos guardados con salida HTML escapada')
audit = sql("SELECT motivo FROM evento_administrativo WHERE paciente_id=? AND accion='actualizar_perfil'",[linked_id])
check(len(audit) == 1 and 'alergias' in audit[0]['motivo'] and form['alergias'] not in audit[0]['motivo']
      and form['emergencia_nombre'] not in audit[0]['motivo'],
      'La auditoría registra el cambio de perfil sin copiar datos médicos ni contactos')
check(patient_access.request('/mi-perfil',dict(form,direccion='Formulario antiguo'))[0] == 422
      and profile_row(linked_id) == saved_profile,
      'Un formulario de perfil antiguo no sobrescribe cambios guardados')
check(reception.request(f'/pacientes/{linked_id}/editar',staff_open_form)[0] == 422,
      'Un cambio de contacto del paciente invalida la ficha administrativa abierta antes')
form = profile_payload(patient_access)
staff_change = patient_payload(reception,linked_id); staff_change['direccion'] = 'Contacto actualizado por recepción'
check(reception.request(f'/pacientes/{linked_id}/editar',staff_change)[0] == 303
      and patient_access.request('/mi-perfil',dict(form,direccion='Dato anterior'))[0] == 422,
      'Un cambio de recepción impide sobrescribir el contacto desde un perfil antiguo')
for field,value in [('tipo_sangre','Z+'),('emergencia_telefono',''),('alergias','x'*2001),('medicamentos','<script>prueba</script>')]:
    previous = profile_row(linked_id)
    invalid_profile = dict(profile_payload(patient_access));invalid_profile[field] = value
    invalid_profile['telefono'] = '+591 70000007'
    check(patient_access.request('/mi-perfil',invalid_profile)[0] == 422 and profile_row(linked_id) == previous,
          'Perfil rechaza sin cambios parciales: ' + field)
invalid_profile = profile_payload(patient_access);invalid_profile.pop('alergias');invalid_profile['alergias[]']='dato'
check(patient_access.request('/mi-perfil',invalid_profile)[0] == 422,
      'El perfil rechaza una estructura manipulada de datos médicos')
form = profile_payload(patient_access)
audit_before = int(sql("SELECT count(*) AS n FROM evento_administrativo WHERE accion='actualizar_perfil'")[0]['n'])
check(patient_access.request('/mi-perfil',form)[0] == 303
      and int(sql("SELECT count(*) AS n FROM evento_administrativo WHERE accion='actualizar_perfil'")[0]['n']) == audit_before,
      'Guardar un perfil sin cambios no duplica su auditoría')
form.update(emergencia_nombre='',emergencia_parentesco='',emergencia_telefono='',alergias='',tipo_sangre='')
check(patient_access.request('/mi-perfil',form)[0] == 303
      and not sql('SELECT paciente_id FROM contacto_emergencia WHERE paciente_id=?',[linked_id])
      and profile_row(linked_id)['alergias'] is None and profile_row(linked_id)['tipo_sangre'] is None,
      'El paciente puede retirar su contacto de emergencia y limpiar datos opcionales')
form = profile_payload(patient_access)
form.update(emergencia_nombre='Otro contacto de ejemplo',emergencia_parentesco='Amistad',emergencia_telefono='+591 70000008',tipo_sangre='A-')
check(patient_access.request('/mi-perfil',form)[0] == 303
      and len(sql('SELECT paciente_id FROM perfil_salud WHERE paciente_id=?',[linked_id])) == 1
      and len(sql('SELECT paciente_id FROM contacto_emergencia WHERE paciente_id=?',[linked_id])) == 1,
      'Actualizar varias veces conserva un perfil y un contacto por paciente')

# Un segundo propietario solo ve su propia ficha.
other_name = PREFIX+'_otro_paciente'
other_data = register_data(other_name,other_name+'@example.invalid',PREFIX+'-OTRO-PERFIL')
other_data['csrf'] = guest.token('/registro')
check(guest.request('/registro',other_data)[0] == 303, 'Se crea otro paciente ficticio para comprobar aislamiento')
other_patient = Browser();other_patient.login(other_name,PASSWORD)
other_body = other_patient.request('/mi-perfil')[1]
check(other_data['ci'].upper() in other_body and PREFIX+'_perfil@example.invalid' not in other_body
      and 'Otro contacto de ejemplo' not in other_body,
      'Otra cuenta de paciente no recibe el contacto ni la información del primer perfil')

# La transacción revierte también si la auditoría falla después de escribir las tres tablas.
form = profile_payload(patient_access);previous = profile_row(linked_id)
form.update(direccion='No debe guardarse',emergencia_nombre='No debe guardarse',antecedentes='No debe guardarse')
sql("ALTER TABLE evento_administrativo ADD CONSTRAINT prueba_fallo_perfil CHECK(accion<>'actualizar_perfil') NOT VALID")
try:
    failed_save = patient_access.request('/mi-perfil',form)
finally:
    sql('ALTER TABLE evento_administrativo DROP CONSTRAINT prueba_fallo_perfil')
check(failed_save[0] == 500 and profile_row(linked_id) == previous,
      'Un fallo de base revierte contacto, emergencia y salud de forma atómica')

form = profile_payload(patient_access)
matrix = matrix_form()
without_profile = [(k,v) for k,v in matrix if not (k=='permisos[paciente][]' and v=='CU07')]
check(admin.request('/roles',without_profile)[0] == 303 and patient_access.request('/mi-perfil')[0] == 403
      and 'href="/mi-perfil"' not in patient_access.request('/panel')[1],
      'Retirar CU07 bloquea el perfil y retira su enlace en una sesión abierta')
check(patient_access.request('/mi-perfil',form)[0] == 403 and profile_row(linked_id) == previous,
      'Retirar CU07 bloquea también un formulario de perfil abierto antes')
restore_profile = matrix_form();restore_profile.append(('permisos[paciente][]','CU07'))
check(admin.request('/roles',restore_profile)[0] == 303 and patient_access.request('/mi-perfil')[0] == 200,
      'Restituir CU07 permite volver a actualizar el perfil')
sql('UPDATE paciente SET usuario_id=NULL WHERE id=?',[linked_id])
try:
    check(patient_access.request('/mi-perfil')[0] == 404
          and patient_access.request('/mi-perfil',form)[0] == 404,
          'Una cuenta sin ficha vinculada no consulta ni modifica otro paciente')
finally:
    sql('UPDATE paciente SET usuario_id=? WHERE id=?',[patient_user_id,linked_id])

# CU08-CU09: catálogos y asociaciones conservados.
check(admin.request('/medicos')[0] == 200 and admin.request('/especialidades')[0] == 200,
      'Administrador abre médicos y especialidades')
check(guest.request('/medicos')[0] == 303 and guest.request('/especialidades')[0] == 303,
      'Los catálogos administrativos exigen iniciar sesión')
for role,browser in [('Recepción',reception),('Médico',medical_access),('Paciente',patient_access)]:
    for resource,create in [('medicos','nuevo'),('especialidades','nueva')]:
        check(browser.request('/'+resource)[0] == 403
              and browser.request('/'+resource+'/'+create,{'csrf':browser.token('/panel')})[0] == 403,
              role + ' no consulta ni modifica el catálogo de ' + resource)


def specialty_payload(specialty_id):
    status,body,_ = admin.request(f'/especialidades/{specialty_id}/editar')
    if status != 200: raise AssertionError('No se abrió la especialidad')
    row = sql('SELECT * FROM especialidad WHERE id=?',[specialty_id])[0]
    payload = {'nombre':row['nombre'],'descripcion':row['descripcion'] or '','activo':'1' if row['activo'] else '0'}
    for key in ['csrf','version']:
        payload[key] = html.unescape(re.search(r'name="'+key+r'" value="([^"]+)"',body).group(1))
    return payload


specialty_ids = []
for suffix,active in [('general','1'),('pediatria','1'),('inactiva','0')]:
    payload = {'csrf':admin.token('/especialidades/nueva'),'nombre':'  '+PREFIX+'   '+suffix+'  ',
               'descripcion':'Descripción ficticia & prueba','activo':active}
    response = admin.request('/especialidades/nueva',payload)
    check(response[0] == 303,'Registrar especialidad ' + suffix)
    specialty_ids.append(int(response[2]['Location'].rsplit('/',1)[1]))
first_specialty,second_specialty,inactive_specialty = specialty_ids
check(sql('SELECT nombre FROM especialidad WHERE id=?',[first_specialty])[0]['nombre'] == PREFIX+' general',
      'El nombre de especialidad queda normalizado en la base')
specialty_count = len(sql('SELECT id FROM especialidad'))
duplicate_specialty = {'csrf':admin.token('/especialidades/nueva'),'nombre':' '+PREFIX.upper()+'    GENERAL ','descripcion':'','activo':'1'}
check(admin.request('/especialidades/nueva',duplicate_specialty)[0] == 422
      and len(sql('SELECT id FROM especialidad')) == specialty_count,
      'No se duplica una especialidad con mayúsculas y espacios diferentes')
check(admin.request('/especialidades/nueva',dict(duplicate_specialty,nombre=PREFIX.upper()+' INACTIVA'))[0] == 422,
      'El nombre de una especialidad inactiva también se conserva único')
check('Descripción ficticia &amp; prueba' in admin.request(f'/especialidades/{first_specialty}')[1],
      'Detalle de especialidad escapa la descripción')
check(PREFIX+' pediatria' in admin.request('/especialidades?q='+PREFIX+'%20pediatria')[1]
      and PREFIX+' inactiva' not in admin.request('/especialidades?estado=activos')[1],
      'Búsqueda y filtro de especialidades separan los registros')
check(admin.request('/especialidades/nueva',{'csrf':admin.token('/especialidades/nueva'),'nombre':'<script>','descripcion':''})[0] == 422,
      'Especialidad con etiquetas inválidas rechazada')
edited_specialty = specialty_payload(first_specialty);stale_specialty = dict(edited_specialty)
edited_specialty['descripcion']='Descripción actualizada'
check(admin.request(f'/especialidades/{first_specialty}/editar',edited_specialty)[0] == 303,
      'Administrador edita una especialidad')
check(admin.request(f'/especialidades/{first_specialty}/editar',stale_specialty)[0] == 422
      and sql('SELECT descripcion FROM especialidad WHERE id=?',[first_specialty])[0]['descripcion'] == 'Descripción actualizada',
      'Edición antigua de especialidad no sobrescribe cambios')
check(admin.request('/especialidades/nueva',dict(duplicate_specialty,csrf='incorrecto'))[0] == 419,
      'Alta de especialidad exige CSRF')


def doctor_post(path,payload,browser=admin):
    pairs = [(k,v) for k,v in payload.items() if k != 'especialidades']
    pairs += [('especialidades[]',str(value)) for value in payload.get('especialidades',[])]
    return browser.request(path,pairs)


def doctor_payload(doctor_id):
    status,body,_ = admin.request(f'/medicos/{doctor_id}/editar')
    if status != 200: raise AssertionError('No se abrió el médico')
    row = sql('SELECT * FROM medico WHERE id=?',[doctor_id])[0]
    payload = {k:row[k] or '' for k in ['registro_profesional','nombres','apellidos','telefono','correo']}
    payload.update(usuario_id=str(row['usuario_id']) if row['usuario_id'] else '',
                   activo='1' if row['activo'] else '0',
                   especialidades=[int(x['especialidad_id']) for x in sql(
                       'SELECT me.especialidad_id FROM medico_especialidad me JOIN especialidad e ON e.id=me.especialidad_id '
                       'WHERE me.medico_id=? AND me.activo AND e.activo ORDER BY e.id',[doctor_id])])
    for key in ['csrf','version']:
        payload[key] = html.unescape(re.search(r'name="'+key+r'" value="([^"]+)"',body).group(1))
    return payload


doctor_account_id = int(sql('SELECT id FROM usuario WHERE nombre_usuario=?',[PREFIX+'_medico'])[0]['id'])
account_before = sql('SELECT * FROM usuario WHERE id=?',[doctor_account_id])[0]
doctor_data = {'csrf':admin.token('/medicos/nuevo'),'registro_profesional':'  '+PREFIX+'-reg  ',
               'nombres':'Rosa','apellidos':'Profesional Ejemplo','telefono':'00000000',
               'correo':PREFIX.upper()+'_DOCTOR@EXAMPLE.INVALID','usuario_id':str(doctor_account_id),
               'activo':'1','especialidades':[first_specialty,second_specialty]}
created = doctor_post('/medicos/nuevo',doctor_data)
check(created[0] == 303,'Administrador registra médico con varias especialidades y cuenta de Médico')
doctor_id = int(created[2]['Location'].rsplit('/',1)[1])
doctor_row = sql('SELECT * FROM medico WHERE id=?',[doctor_id])[0]
check(doctor_row['registro_profesional'] == (PREFIX+'-reg').upper()
      and doctor_row['correo'] == (PREFIX+'_doctor@example.invalid')
      and int(doctor_row['usuario_id']) == doctor_account_id,
      'Registro profesional, correo y cuenta vinculada se guardan correctamente')
check(len(sql('SELECT * FROM medico_especialidad WHERE medico_id=? AND activo',[doctor_id])) == 2
      and sql('SELECT * FROM usuario WHERE id=?',[doctor_account_id])[0] == account_before,
      'Varias especialidades no duplican ni modifican la cuenta de acceso')
check('Rosa' in medical_access.request('/panel')[1],
      'La cuenta médica muestra los datos del profesional vinculado')
check('Rosa' in admin.request('/medicos?q='+urllib.parse.quote(PREFIX+'-REG'))[1]
      and 'Rosa' in admin.request('/medicos?q=Profesional')[1],
      'Médicos se buscan por registro profesional y apellido')
doctors_before = len(sql('SELECT id FROM medico'))
invalid_doctor = dict(doctor_data,csrf=admin.token('/medicos/nuevo'),usuario_id='')
check(doctor_post('/medicos/nuevo',invalid_doctor)[0] == 422
      and len(sql('SELECT id FROM medico')) == doctors_before,
      'Registro profesional equivalente no crea otro médico')
for field,value in [('especialidades',[]),('especialidades',[inactive_specialty]),('especialidades',[999999999]),
                    ('usuario_id',str(admin_id)),('usuario_id',str(doctor_account_id)),('telefono','12'),('correo','invalido')]:
    invalid_doctor = dict(doctor_data,csrf=admin.token('/medicos/nuevo'),registro_profesional=PREFIX+'-otro-reg',usuario_id='')
    invalid_doctor[field] = value
    check(doctor_post('/medicos/nuevo',invalid_doctor)[0] == 422
          and len(sql('SELECT id FROM medico')) == doctors_before,
          'Médico rechaza sin alta parcial: '+field+' '+str(value))
check(admin.request('/medicos/nuevo',{'csrf':admin.token('/medicos/nuevo'),'registro_profesional':PREFIX+'-array',
      'nombres':'Prueba','apellidos':'Prueba','activo':'1','especialidades':'1'})[0] == 422,
      'Lista manipulada de especialidades rechazada')
check(doctor_post('/medicos/nuevo',dict(doctor_data,csrf='incorrecto'))[0] == 419,
      'Alta de médico exige CSRF')
check(admin.request('/medicos/999999999')[0] == 404 and admin.request('/especialidades/999999999')[0] == 404,
      'Detalles de médico y especialidad inexistentes devuelven 404')
edit_doctor = doctor_payload(doctor_id);old_doctor_form = dict(edit_doctor)
edit_doctor['telefono']='00000001'
check(doctor_post(f'/medicos/{doctor_id}/editar',edit_doctor)[0] == 303,
      'Administrador actualiza contacto del médico')
check(doctor_post(f'/medicos/{doctor_id}/editar',old_doctor_form)[0] == 422
      and sql('SELECT telefono FROM medico WHERE id=?',[doctor_id])[0]['telefono'] == '00000001',
      'Edición antigua de médico no sobrescribe cambios')

# Preparación SQL de horario y cita: sus pantallas siguen pendientes.
other_patient_id = int(sql('SELECT p.id FROM paciente p JOIN usuario u ON u.id=p.usuario_id WHERE u.nombre_usuario=?',[other_name])[0]['id'])
for day in range(1,8):
    sql("INSERT INTO horario(medico_id,dia_semana,hora_inicio,hora_fin) VALUES(?,?,'08:00','18:00')",[doctor_id,day])
sql("CALL sp_programar_cita(?,?,?,(current_date+9+time '11:00') AT TIME ZONE 'America/La_Paz',?)",
    [other_patient_id,doctor_id,first_specialty,admin_id])
doctor_cita = int(sql('SELECT id FROM cita WHERE medico_id=?',[doctor_id])[0]['id'])
protected_counts = {t:len(sql('SELECT id FROM '+t)) for t in ['cita','historial_cita','aviso','multa']}


def booking_rejected(specialty_id):
    try:
        sql("CALL sp_programar_cita(?,?,?,(current_date+10+time '11:00') AT TIME ZONE 'America/La_Paz',?)",
            [other_patient_id,doctor_id,specialty_id,admin_id])
        return False
    except subprocess.CalledProcessError:
        return True


edit_doctor = doctor_payload(doctor_id);edit_doctor['especialidades']=[second_specialty]
check(doctor_post(f'/medicos/{doctor_id}/editar',edit_doctor)[0] == 303
      and not sql('SELECT activo FROM medico_especialidad WHERE medico_id=? AND especialidad_id=?',[doctor_id,first_specialty])[0]['activo'],
      'Retirar una especialidad conserva la asociación como histórica')
check('Pendiente de revisión' in admin.request(f'/medicos/{doctor_id}')[1]
      and sql('SELECT estado FROM cita WHERE id=?',[doctor_cita])[0]['estado'] == 'programada',
      'La cita conserva su turno y queda visible para revisión al retirar la especialidad')
check(booking_rejected(first_specialty),'Asociación retirada impide nuevas reservas en esa especialidad')
edit_doctor = doctor_payload(doctor_id);edit_doctor['especialidades']=[first_specialty,second_specialty]
check(doctor_post(f'/medicos/{doctor_id}/editar',edit_doctor)[0] == 303
      and len(sql('SELECT * FROM medico_especialidad WHERE medico_id=?',[doctor_id])) == 2
      and 'Pendiente de revisión' not in admin.request(f'/medicos/{doctor_id}')[1],
      'Restituir una asociación la reactiva sin duplicarla')
edit_doctor = doctor_payload(doctor_id);edit_doctor['activo']='0'
check(doctor_post(f'/medicos/{doctor_id}/editar',edit_doctor)[0] == 303
      and 'Pendiente de revisión' in admin.request(f'/medicos/{doctor_id}')[1],
      'Desactivar médico conserva la cita y muestra su revisión pendiente')
check(booking_rejected(first_specialty)
      and sql('SELECT habilitado FROM usuario WHERE id=?',[doctor_account_id])[0]['habilitado'],
      'Médico inactivo no recibe nuevas reservas y conserva su cuenta habilitada')
check('Rosa' in admin.request('/medicos?estado=inactivos')[1]
      and 'Rosa' not in admin.request('/medicos?estado=activos')[1],
      'Filtro de médicos distingue activos e inactivos')
edit_doctor = doctor_payload(doctor_id);edit_doctor['activo']='1'
check(doctor_post(f'/medicos/{doctor_id}/editar',edit_doctor)[0] == 303,
      'Administrador vuelve a activar al médico')
stale_doctor = doctor_payload(doctor_id)
specialty_edit = specialty_payload(first_specialty);specialty_edit['activo']='0'
check(admin.request(f'/especialidades/{first_specialty}/editar',specialty_edit)[0] == 303
      and 'Pendiente de revisión' in admin.request(f'/especialidades/{first_specialty}')[1],
      'Desactivar especialidad mantiene sus médicos y muestra las citas para revisión')
check(booking_rejected(first_specialty)
      and sql('SELECT activo FROM medico_especialidad WHERE medico_id=? AND especialidad_id=?',[doctor_id,first_specialty])[0]['activo'],
      'Especialidad inactiva bloquea reservas sin borrar asociaciones')
check(doctor_post(f'/medicos/{doctor_id}/editar',stale_doctor)[0] == 422,
      'Cambiar el estado de una especialidad invalida el formulario antiguo del médico')
contact_edit = doctor_payload(doctor_id);contact_edit['telefono']='00000002'
check(doctor_post(f'/medicos/{doctor_id}/editar',contact_edit)[0] == 303
      and sql('SELECT activo FROM medico_especialidad WHERE medico_id=? AND especialidad_id=?',[doctor_id,first_specialty])[0]['activo'],
      'Editar contacto conserva una asociación vigente cuya especialidad está temporalmente inactiva')
specialty_edit = specialty_payload(first_specialty);specialty_edit['activo']='1'
check(admin.request(f'/especialidades/{first_specialty}/editar',specialty_edit)[0] == 303
      and 'Pendiente de revisión' not in admin.request(f'/medicos/{doctor_id}')[1],
      'Reactivar especialidad conserva y habilita sus asociaciones vigentes')
check(all(len(sql('SELECT id FROM '+t)) == n for t,n in protected_counts.items())
      and sql('SELECT estado FROM cita WHERE id=?',[doctor_cita])[0]['estado'] == 'programada',
      'Cambios de médico, especialidad y asociaciones conservan citas, historial, avisos y multas')
check(admin.request(f'/medicos/{doctor_id}/eliminar')[0] == 404
      and admin.request(f'/especialidades/{first_specialty}/eliminar')[0] == 404,
      'No hay operaciones de eliminación física de médicos ni especialidades')

# La ficha médica y sus asociaciones se revierten juntas ante un error.
previous_doctor = sql('SELECT * FROM medico WHERE id=?',[doctor_id])
previous_associations = sql('SELECT * FROM medico_especialidad WHERE medico_id=? ORDER BY especialidad_id',[doctor_id])
form = doctor_payload(doctor_id);form.update(telefono='00000009',especialidades=[second_specialty])
sql("ALTER TABLE evento_administrativo ADD CONSTRAINT prueba_fallo_medico CHECK(accion<>'actualizar_medico') NOT VALID")
try:
    failed_doctor = doctor_post(f'/medicos/{doctor_id}/editar',form)
finally:
    sql('ALTER TABLE evento_administrativo DROP CONSTRAINT prueba_fallo_medico')
check(failed_doctor[0] == 500 and sql('SELECT * FROM medico WHERE id=?',[doctor_id]) == previous_doctor
      and sql('SELECT * FROM medico_especialidad WHERE medico_id=? ORDER BY especialidad_id',[doctor_id]) == previous_associations,
      'Fallo de auditoría revierte médico y asociaciones sin cambios parciales')

for permission,resource,form_path,post in [
        ('CU08','medicos',f'/medicos/{doctor_id}/editar',doctor_payload(doctor_id)),
        ('CU09','especialidades',f'/especialidades/{first_specialty}/editar',specialty_payload(first_specialty))]:
    matrix = matrix_form()
    removed = [(k,v) for k,v in matrix if not (k=='permisos[administrador][]' and v==permission)]
    check(admin.request('/roles',removed)[0] == 303 and admin.request('/'+resource)[0] == 403,
          'Revocar '+permission+' bloquea el catálogo en una sesión abierta')
    sent = doctor_post(form_path,post) if permission == 'CU08' else admin.request(form_path,post)
    check(sent[0] == 403,'Revocar '+permission+' bloquea también un formulario abierto')
    restore = matrix_form();restore.append(('permisos[administrador][]',permission))
    check(admin.request('/roles',restore)[0] == 303 and admin.request('/'+resource)[0] == 200,
          'Restituir '+permission+' recupera el acceso al catálogo')

# CU10: horarios semanales, exclusión de tramos y protección de las reservas.
check(admin.request('/horarios')[0] == 200 and admin.request('/horarios/nuevo')[0] == 200,
      'Administrador consulta y registra horarios de atención')
check(guest.request('/horarios')[0] == 303,'Horarios exigen iniciar sesión')
for role,browser in [('Recepción',reception),('Médico',medical_access),('Paciente',patient_access)]:
    check(browser.request('/horarios')[0] == 403
          and browser.request('/horarios/nuevo',{'csrf':browser.token('/panel')})[0] == 403,
          role+' no administra horarios')

# Este profesional no necesita cuenta para disponer de un horario.
schedule_doctor_data = dict(doctor_data,csrf=admin.token('/medicos/nuevo'),registro_profesional=PREFIX+'-horarios',
                           nombres='Profesional',apellidos='Horarios Prueba',usuario_id='',especialidades=[first_specialty])
created_schedule_doctor = doctor_post('/medicos/nuevo',schedule_doctor_data)
check(created_schedule_doctor[0] == 303,'Crear médico sin cuenta para configurar horarios')
schedule_doctor = int(created_schedule_doctor[2]['Location'].rsplit('/',1)[1])


def new_schedule(**changes):
    payload = {'csrf':admin.token('/horarios/nuevo'),'medico_id':str(schedule_doctor),'dia_semana':'1',
               'hora_inicio':'08:00','hora_fin':'12:00','activo':'1'}
    payload.update(changes)
    return payload


def schedule_payload(schedule_id):
    status,body,_ = admin.request(f'/horarios/{schedule_id}/editar')
    if status != 200: raise AssertionError('No se abrió el horario')
    row = sql('SELECT * FROM horario WHERE id=?',[schedule_id])[0]
    payload = {'medico_id':str(row['medico_id']),'dia_semana':str(row['dia_semana']),
               'hora_inicio':row['hora_inicio'][:5],'hora_fin':row['hora_fin'][:5],'activo':'1' if row['activo'] else '0'}
    for key in ['csrf','version']:
        payload[key] = html.unescape(re.search(r'name="'+key+r'" value="([^"]+)"',body).group(1))
    return payload


created_schedule = admin.request('/horarios/nuevo',new_schedule())
check(created_schedule[0] == 303,'Alta de horario semanal en intervalos de media hora')
schedule_id = int(re.search(r'/horarios/(\d+)/editar',created_schedule[2]['Location']).group(1))
adjacent = admin.request('/horarios/nuevo',new_schedule(hora_inicio='12:00',hora_fin='14:00'))
check(adjacent[0] == 303,'Tramos contiguos se permiten sin considerarse superpuestos')
adjacent_id = int(re.search(r'/horarios/(\d+)/editar',adjacent[2]['Location']).group(1))
tuesday = admin.request('/horarios/nuevo',new_schedule(dia_semana='2'))
check(tuesday[0] == 303,'El mismo tramo puede registrarse otro día de la semana')
tuesday_id = int(re.search(r'/horarios/(\d+)/editar',tuesday[2]['Location']).group(1))
hours_count = len(sql('SELECT id FROM horario'))
check(admin.request('/horarios/nuevo',new_schedule(hora_inicio='11:00',hora_fin='13:00'))[0] == 422
      and len(sql('SELECT id FROM horario')) == hours_count,
      'Tramos activos superpuestos se rechazan sin alta parcial')
for field,value in [('hora_inicio','08:15'),('hora_fin','08:00'),('hora_inicio','25:00'),
                    ('dia_semana','8'),('medico_id','999999999')]:
    invalid_schedule = new_schedule();invalid_schedule[field]=value
    check(admin.request('/horarios/nuevo',invalid_schedule)[0] == 422
          and len(sql('SELECT id FROM horario')) == hours_count,'Horario rechaza dato inválido: '+field+' '+value)
sql('UPDATE medico SET activo=false WHERE id=?',[schedule_doctor])
try:
    check(admin.request('/horarios/nuevo',new_schedule(dia_semana='3'))[0] == 422,
          'No se guarda un horario para médico inactivo')
finally:
    sql('UPDATE medico SET activo=true WHERE id=?',[schedule_doctor])
check(admin.request('/horarios/nuevo',new_schedule(csrf='incorrecto'))[0] == 419,'Horarios requieren protección CSRF')
altered_schedule = new_schedule();altered_schedule.pop('dia_semana');altered_schedule['dia_semana[]']='1'
check(admin.request('/horarios/nuevo',altered_schedule)[0] == 422,'Estructura manipulada del horario rechazada')
inactive_schedule = admin.request('/horarios/nuevo',new_schedule(hora_inicio='09:00',hora_fin='10:00',activo='0'))
check(inactive_schedule[0] == 303,'Horario inactivo puede conservar un tramo que coincide con otro activo')
inactive_schedule_id = int(re.search(r'/horarios/(\d+)/editar',inactive_schedule[2]['Location']).group(1))
form = schedule_payload(inactive_schedule_id);form['activo']='1'
check(admin.request(f'/horarios/{inactive_schedule_id}/editar',form)[0] == 422
      and not sql('SELECT activo FROM horario WHERE id=?',[inactive_schedule_id])[0]['activo'],
      'Activar un horario superpuesto se rechaza conservando su estado anterior')
filtered = admin.request('/horarios?'+urllib.parse.urlencode({'medico_id':schedule_doctor,'dia_semana':1,'estado':'activos'}))[1]
check(f'/horarios/{schedule_id}/editar' in filtered and f'/horarios/{tuesday_id}/editar' not in filtered
      and f'/horarios/{inactive_schedule_id}/editar' not in filtered,'Filtros de horario combinan médico, día y estado')
check(admin.request('/horarios/999999999/editar')[0] == 404,'Horario inexistente devuelve 404')
form = schedule_payload(schedule_id);stale = dict(form);form['hora_inicio']='08:30'
check(admin.request(f'/horarios/{schedule_id}/editar',form)[0] == 303,'Editar horario sin afectar reservas')
check(admin.request(f'/horarios/{schedule_id}/editar',stale)[0] == 422
      and sql('SELECT hora_inicio FROM horario WHERE id=?',[schedule_id])[0]['hora_inicio'][:5] == '08:30',
      'Un horario antiguo no sobrescribe cambios recientes')
form = schedule_payload(tuesday_id);form['activo']='0'
check(admin.request(f'/horarios/{tuesday_id}/editar',form)[0] == 303
      and not sql('SELECT activo FROM horario WHERE id=?',[tuesday_id])[0]['activo'],
      'Horario sin reservas puede desactivarse conservando el registro')
form = schedule_payload(tuesday_id);form['activo']='1'
check(admin.request(f'/horarios/{tuesday_id}/editar',form)[0] == 303,'Horario puede reactivarse si no produce conflictos')

# El lunes se calcula en la zona de la clínica; el alta de la cita sigue siendo preparación SQL.
sql("CALL sp_programar_cita(?,?,?,(date_trunc('week',current_date::timestamp)::date+14+time '11:00') AT TIME ZONE 'America/La_Paz',?)",
    [other_patient_id,schedule_doctor,first_specialty,admin_id])
schedule_cita = int(sql('SELECT id FROM cita WHERE medico_id=? ORDER BY id DESC',[schedule_doctor])[0]['id'])
schedule_protected_counts = {t:len(sql('SELECT id FROM '+t)) for t in ['cita','historial_cita','aviso','multa']}
current_schedule = sql('SELECT * FROM horario WHERE id=?',[schedule_id])
for changes in [{'hora_fin':'11:00'},{'activo':'0'},{'dia_semana':'3'},
                {'medico_id':str(doctor_id),'hora_inicio':'19:00','hora_fin':'20:00'}]:
    form = schedule_payload(schedule_id);form.update(changes)
    response = admin.request(f'/horarios/{schedule_id}/editar',form)
    check(response[0] == 422 and 'Citas que necesitan gestión' in response[1]
          and ('CM-'+str(schedule_cita).zfill(6)) in response[1]
          and sql('SELECT * FROM horario WHERE id=?',[schedule_id]) == current_schedule,
          'Cambio incompatible se rechaza y muestra la cita afectada: '+str(changes))
form = schedule_payload(schedule_id);form['hora_inicio']='09:00'
check(admin.request(f'/horarios/{schedule_id}/editar',form)[0] == 303,
      'Cambio que conserva el turno reservado se permite')
check(all(len(sql('SELECT id FROM '+t)) == n for t,n in schedule_protected_counts.items())
      and sql('SELECT estado FROM cita WHERE id=?',[schedule_cita])[0]['estado'] == 'programada',
      'Los cambios de horario conservan citas, historial, avisos y multas')
sql("CALL sp_programar_cita(?,?,?,(date_trunc('week',current_date::timestamp)::date+14+time '11:30') AT TIME ZONE 'America/La_Paz',?)",
    [other_patient_id,schedule_doctor,first_specialty,admin_id])
boundary_cita = int(sql('SELECT id FROM cita WHERE medico_id=? ORDER BY id DESC',[schedule_doctor])[0]['id'])
form = schedule_payload(schedule_id);form['hora_fin']='11:30'
response = admin.request(f'/horarios/{schedule_id}/editar',form)
check(response[0] == 422 and ('CM-'+str(boundary_cita).zfill(6)) in response[1]
      and ('CM-'+str(schedule_cita).zfill(6)) not in response[1],
      'Protección comprueba el final completo de la cita y muestra solo las afectadas')

form = schedule_payload(schedule_id);previous = sql('SELECT * FROM horario WHERE id=?',[schedule_id])
form['hora_inicio']='09:30'
sql("ALTER TABLE evento_administrativo ADD CONSTRAINT prueba_fallo_horario CHECK(accion<>'actualizar_horario') NOT VALID")
try:
    failed_schedule = admin.request(f'/horarios/{schedule_id}/editar',form)
finally:
    sql('ALTER TABLE evento_administrativo DROP CONSTRAINT prueba_fallo_horario')
check(failed_schedule[0] == 500 and sql('SELECT * FROM horario WHERE id=?',[schedule_id]) == previous,
      'Fallo de auditoría revierte por completo el cambio de horario')
form = schedule_payload(schedule_id)
matrix = matrix_form()
removed = [(k,v) for k,v in matrix if not (k=='permisos[administrador][]' and v=='CU10')]
check(admin.request('/roles',removed)[0] == 303 and admin.request('/horarios')[0] == 403
      and 'href="/horarios"' not in admin.request('/panel')[1],
      'Revocar CU10 bloquea horarios y oculta su enlace en una sesión abierta')
check(admin.request(f'/horarios/{schedule_id}/editar',form)[0] == 403,'Revocar CU10 bloquea también el formulario abierto')
restore = matrix_form();restore.append(('permisos[administrador][]','CU10'))
check(admin.request('/roles',restore)[0] == 303 and admin.request('/horarios')[0] == 200,'Restituir CU10 recupera la gestión de horarios')
check(admin.request(f'/horarios/{schedule_id}/eliminar')[0] == 404,'Los horarios no se eliminan físicamente por la interfaz')

# CU11-CU15: consulta de turnos y recorrido completo de la reserva.
booking_date = sql("SELECT (date_trunc('week',current_date::timestamp)::date+21)::text AS fecha")[0]['fecha']


def availability_url(doctor=schedule_doctor,specialty=first_specialty,date=booking_date):
    return '/disponibilidad?'+urllib.parse.urlencode({'especialidad_id':specialty,'medico_id':doctor,'fecha':date})


def free_starts(browser=other_patient,doctor=schedule_doctor,specialty=first_specialty,date=booking_date):
    status,body,_ = browser.request(availability_url(doctor,specialty,date))
    if status != 200: raise AssertionError('No se consultó disponibilidad')
    return [urllib.parse.parse_qs(urllib.parse.urlsplit(html.unescape(link)).query)['inicio'][0]
            for link in re.findall(r'href="(/citas/nueva\?[^"]+)"',body)]


def booking_payload(browser,start,doctor=schedule_doctor,specialty=first_specialty,patient_id=None):
    payload = {'especialidad_id':str(specialty),'medico_id':str(doctor),'inicio':start,'motivo':'Reserva ficticia de prueba'}
    path = '/citas/nueva?'+urllib.parse.urlencode(payload)
    payload['csrf'] = browser.token(path)
    if patient_id is not None: payload['paciente_id']=str(patient_id)
    return payload


def appointment_payload(browser,appointment_id,action='cancelar'):
    status,body,_ = browser.request(f'/citas/{appointment_id}/{action}')
    if status != 200: raise AssertionError('No se abrió el formulario de cita')
    return {key:html.unescape(re.search(r'name="'+key+r'" value="([^"]+)"',body).group(1))
            for key in ['csrf','version']}


def appointment_counts():
    return {t:len(sql('SELECT id FROM '+t)) for t in ['cita','historial_cita','aviso','incumplimiento','multa']}


check(guest.request('/disponibilidad')[0] == 303 and guest.request('/citas')[0] == 303,
      'Disponibilidad y gestión de citas requieren iniciar sesión')
check(medical_access.request('/disponibilidad')[0] == 403 and medical_access.request('/citas')[0] == 403,
      'La cuenta médica no utiliza operaciones de reservas de pacientes o recepción')
check(patient_access.request(availability_url())[0] == 200,
      'Paciente inactivo conserva consulta de disponibilidad')
check(reception.request(availability_url())[0] == 200 and admin.request(availability_url())[0] == 200,
      'Recepción y administración consultan disponibilidad')
starts = free_starts()
check(len(starts) == 10 and len(starts) == len(set(starts))
      and booking_date+'T09:00' in starts and booking_date+'T13:30' in starts
      and booking_date+'T14:00' not in starts,
      'Los tramos contiguos se dividen en turnos únicos de 30 minutos con fin exclusivo')
for invalid_date in ['2000-01-01','2027-02-29','fecha']:
    check(other_patient.request(availability_url(date=invalid_date))[0] == 422,
          'Disponibilidad rechaza fecha inválida o pasada: '+invalid_date)
check(other_patient.request(availability_url(specialty=inactive_specialty))[0] == 422,
      'No se consulta una especialidad inactiva')
check(other_patient.request(availability_url(specialty=second_specialty))[0] == 422,
      'El médico debe atender la especialidad seleccionada')
existing_date = sql("SELECT (inicio AT TIME ZONE 'America/La_Paz')::date::text AS fecha FROM cita WHERE id=?",[doctor_cita])[0]['fecha']
check(existing_date+'T11:00' not in free_starts(doctor=doctor_id,specialty=second_specialty,date=existing_date),
      'Una reserva de otra especialidad también ocupa el horario del mismo médico')
absence_id = int(sql('INSERT INTO ausencia_medico(medico_id,inicio,fin,motivo,registrado_por) VALUES(?,?,?,?,?) RETURNING id',
    [schedule_doctor,booking_date+'T10:45:00-04:00',booking_date+'T11:15:00-04:00','Ausencia ficticia de prueba',admin_id])[0]['id'])
starts = free_starts()
check(booking_date+'T10:30' not in starts and booking_date+'T11:00' not in starts
      and booking_date+'T10:00' in starts and booking_date+'T11:30' in starts,
      'La ausencia descuenta todos los bloques con solapamiento, incluidos los parciales')
today = sql("SELECT (now() AT TIME ZONE 'America/La_Paz')::date::text AS fecha")[0]['fecha']
now_local = sql("""SELECT to_char(now() AT TIME ZONE 'America/La_Paz','YYYY-MM-DD"T"HH24:MI:SS') AS ahora""")[0]['ahora']
check(all(value>now_local for value in free_starts(doctor=doctor_id,date=today)),
      'La consulta del día actual no ofrece horas pasadas')

main_start = booking_date+'T09:00'
before = appointment_counts()
payload = booking_payload(other_patient,main_start)
payload.update(estado='atendida',creado_por=str(admin_id),fin=booking_date+'T12:00')
created = other_patient.request('/citas/nueva',payload)
check(created[0] == 303,'Paciente programa su propia cita desde un turno disponible')
appointment_id = int(created[2]['Location'].rsplit('/',1)[1])
row = sql('SELECT * FROM cita WHERE id=?',[appointment_id])[0]
other_user_id = int(sql('SELECT id FROM usuario WHERE nombre_usuario=?',[other_name])[0]['id'])
check(row['estado'] == 'programada' and row['tipo'] == 'ordinaria' and int(row['paciente_id']) == other_patient_id
      and int(row['creado_por']) == other_user_id
      and sql("SELECT fin=inicio+interval '30 minutes' AS exacto FROM cita WHERE id=?",[appointment_id])[0]['exacto'],
      'El servidor fija paciente propio, autor, estado Programada y duración de 30 minutos')
check(appointment_counts()['cita'] == before['cita']+1
      and appointment_counts()['historial_cita'] == before['historial_cita']+1
      and appointment_counts()['aviso'] == before['aviso']+1
      and sql('SELECT estado FROM aviso WHERE cita_id=?',[appointment_id])[0]['estado'] == 'pendiente',
      'Reserva, historial y aviso pendiente quedan persistidos juntos')
check('Programada' in other_patient.request(f'/citas/{appointment_id}')[1]
      and 'CM-'+str(appointment_id).zfill(6) in other_patient.request('/citas')[1],
      'El paciente consulta el código y estado de su reserva')
check(main_start not in free_starts(),'Guardar la reserva retira el turno de la disponibilidad')
before = appointment_counts()
check(other_patient.request('/citas/nueva',payload)[0] == 422 and appointment_counts() == before,
      'Repetir la reserva no duplica la cita ni sus avisos')
check(patient_access.request(f'/citas/{appointment_id}')[0] == 403,
      'Otro paciente no consulta la cita por su identificador')
check('CM-'+str(appointment_id).zfill(6) not in patient_access.request('/citas?estado=todos')[1],
      'El listado de citas filtra por el propietario autenticado')
for action in ['confirmar','reprogramar','cancelar']:
    foreign = {'csrf':patient_access.token('/panel'),'version':'ajena','motivo_cambio':'Prueba',
               'medico_id':str(schedule_doctor),'inicio':booking_date+'T13:00'}
    check(patient_access.request(f'/citas/{appointment_id}/{action}',foreign)[0] == 403,
          'Paciente ajeno no realiza '+action+' mediante POST')

own_other_start = booking_date+'T09:30'
forged = booking_payload(other_patient,own_other_start,patient_id=clinical_id)
check(other_patient.request('/citas/nueva',forged)[0] == 403,'Paciente no reserva para otra ficha manipulando paciente_id')
forged = booking_payload(other_patient,own_other_start);forged['tipo']='urgente'
check(other_patient.request('/citas/nueva',forged)[0] == 403,'El formulario ordinario no permite forzar una cita urgente')
inactive_request = {'csrf':patient_access.token('/panel'),'especialidad_id':str(first_specialty),
                    'medico_id':str(schedule_doctor),'inicio':own_other_start}
check(patient_access.request('/citas/nueva',inactive_request)[0] == 422,'Paciente inactivo no registra una nueva reserva')
sql('UPDATE paciente SET activo=true WHERE id=?',[linked_id])
try:
    check(patient_access.request('/citas/nueva',inactive_request)[0] == 422,
          'Paciente activo con multa pendiente no registra una nueva reserva ordinaria')
finally:
    sql('UPDATE paciente SET activo=false WHERE id=?',[linked_id])
for bad_start in [booking_date+'T10:30',booking_date+'T14:00',booking_date+'T08:15','2000-01-01T09:00']:
    direct = {'csrf':other_patient.token('/panel'),'especialidad_id':str(first_specialty),
              'medico_id':str(schedule_doctor),'inicio':bad_start}
    before = appointment_counts()
    check(other_patient.request('/citas/nueva',direct)[0] == 422 and appointment_counts() == before,
          'Reserva rechaza ausencia, horario o instante inválido: '+bad_start)
check(other_patient.request('/citas/nueva',dict(payload,csrf='incorrecto'))[0] == 419,
      'Programar cita exige protección CSRF')
staff_payload = booking_payload(reception,own_other_start,patient_id=clinical_id)
staff_created = reception.request('/citas/nueva',staff_payload)
check(staff_created[0] == 303,'Recepción programa una cita para un paciente activo verificado')
staff_appointment = int(staff_created[2]['Location'].rsplit('/',1)[1])
stale_slot = booking_payload(other_patient,booking_date+'T12:00')
staff_slot = booking_payload(reception,booking_date+'T12:00',patient_id=clinical_id)
check(reception.request('/citas/nueva',staff_slot)[0] == 303
      and other_patient.request('/citas/nueva',stale_slot)[0] == 422,
      'Un turno ocupado después de abrir el formulario se rechaza al guardar')

form = appointment_payload(other_patient,appointment_id)
before = appointment_counts()
check(other_patient.request(f'/citas/{appointment_id}/confirmar',form)[0] == 303
      and sql('SELECT estado FROM cita WHERE id=?',[appointment_id])[0]['estado'] == 'confirmada',
      'Paciente confirma asistencia y conserva su reserva')
check(main_start not in free_starts() and appointment_counts()['historial_cita'] == before['historial_cita']+1
      and appointment_counts()['aviso'] == before['aviso']+1,
      'Confirmar conserva el turno ocupado y registra historial y aviso')
before = appointment_counts()
check(other_patient.request(f'/citas/{appointment_id}/confirmar',form)[0] == 422 and appointment_counts() == before,
      'Confirmación repetida o antigua no duplica cambios')
check(other_patient.request(f'/citas/{appointment_id}/confirmar')[0] == 404,
      'Una petición GET no confirma la asistencia')

form = appointment_payload(other_patient,appointment_id,'reprogramar')
form.update(medico_id=str(schedule_doctor),especialidad_id=str(first_specialty),fecha=booking_date,
            inicio=own_other_start,motivo_cambio='Cambio ficticio de fecha')
old_row = sql('SELECT * FROM cita WHERE id=?',[appointment_id])[0];before=appointment_counts()
check(other_patient.request(f'/citas/{appointment_id}/reprogramar',form)[0] == 422
      and sql('SELECT * FROM cita WHERE id=?',[appointment_id])[0] == old_row and appointment_counts() == before,
      'Reprogramar a un turno ocupado conserva íntegra la reserva anterior')
check(other_patient.request(f'/citas/{appointment_id}/reprogramar',dict(form,medico_id=str(doctor_id)))[0] == 403,
      'El paciente solicita cambios de médico mediante recepción')
check(other_patient.request(f'/citas/{appointment_id}/reprogramar',dict(form,especialidad_id=str(second_specialty)))[0] == 422,
      'Reprogramar no cambia la especialidad de la cita')
check(other_patient.request(f'/citas/{appointment_id}/reprogramar',dict(form,motivo_cambio=''))[0] == 422,
      'Reprogramar exige indicar el motivo')
check(other_patient.request(f'/citas/{appointment_id}/reprogramar',dict(form,inicio=main_start))[0] == 422,
      'Reprogramar exige un turno distinto al actual')
check(other_patient.request(f'/citas/{appointment_id}/reprogramar',dict(form,inicio=booking_date+'T13:00',origen_cambio='clinica'))[0] == 403,
      'Paciente no justifica un cambio como causa de la clínica')
stale_change = dict(form)
form['inicio']=booking_date+'T13:00'
check(other_patient.request(f'/citas/{appointment_id}/reprogramar',form)[0] == 303,
      'Paciente reprograma su cita a otro turno libre')
row = sql('SELECT * FROM cita WHERE id=?',[appointment_id])[0]
check(row['estado']=='programada' and int(row['paciente_id'])==other_patient_id
      and row['creado_en']==old_row['creado_en'] and row['creado_por']==old_row['creado_por'],
      'Reprogramación vuelve a Programada y conserva identidad y autoría originales')
history = sql('SELECT datos_antes,datos_despues FROM historial_cita WHERE cita_id=? ORDER BY id DESC LIMIT 1',[appointment_id])[0]
history_before=json.loads(history['datos_antes']);history_after=json.loads(history['datos_despues'])
check(history_before['inicio']!=history_after['inicio'] and history_before['estado']=='confirmada'
      and main_start in free_starts() and booking_date+'T13:00' not in free_starts(),
      'Historial conserva el turno anterior; el cambio libera uno y ocupa el otro')
check(other_patient.request(f'/citas/{appointment_id}/reprogramar',stale_change)[0] == 422,
      'Un formulario antiguo no vuelve a mover la cita')
staff_change = appointment_payload(reception,appointment_id,'reprogramar')
staff_change.update(medico_id=str(doctor_id),especialidad_id=str(first_specialty),fecha=booking_date,
                    inicio=booking_date+'T13:30',motivo_cambio='Cambio de la clínica aceptado',origen_cambio='clinica')
check(reception.request(f'/citas/{appointment_id}/reprogramar',staff_change)[0] == 422,
      'Recepción debe registrar aceptación del paciente para cambiar de médico')
staff_change['aceptacion_medico']='1'
check(reception.request(f'/citas/{appointment_id}/reprogramar',staff_change)[0] == 303
      and int(sql('SELECT medico_id FROM cita WHERE id=?',[appointment_id])[0]['medico_id']) == doctor_id,
      'Recepción reprograma con otro médico de la misma especialidad y aceptación')
check(sql("SELECT id FROM evento_administrativo WHERE paciente_id=? AND accion='cambio_medico_aceptado'",[other_patient_id]),
      'La aceptación del cambio de médico conserva un evento verificable')
form = appointment_payload(other_patient,appointment_id)
check(other_patient.request(f'/citas/{appointment_id}/confirmar',form)[0] == 303,
      'El paciente puede ratificar el nuevo turno después de un cambio originado por la clínica')
form = appointment_payload(other_patient,appointment_id);form['motivo_cambio']='Cancelación anticipada de prueba'
check(other_patient.request(f'/citas/{appointment_id}/cancelar',form)[0] == 303
      and sql('SELECT estado FROM cita WHERE id=?',[appointment_id])[0]['estado'] == 'cancelada',
      'Paciente cancela su reserva conservando el registro')
check(booking_date+'T13:30' in free_starts(doctor=doctor_id)
      and not sql('SELECT id FROM incumplimiento WHERE cita_id=?',[appointment_id]),
      'Cancelación anticipada libera el turno sin incidencia')
before = appointment_counts()
for action in ['confirmar','reprogramar','cancelar']:
    terminal = appointment_payload(other_patient,appointment_id)
    terminal.update(motivo_cambio='Prueba de estado terminal',medico_id=str(doctor_id),inicio=booking_date+'T14:00')
    check(other_patient.request(f'/citas/{appointment_id}/{action}',terminal)[0] == 422 and appointment_counts() == before,
          'Una cita cancelada no permite '+action+' ni duplica registros')

# Dato histórico de preparación, fuera de los flujos de alta: permite probar citas vencidas.
sql(f"""DO $$ BEGIN
    PERFORM set_config('app.actor_id','{admin_id}',true);
    ALTER TABLE cita DISABLE TRIGGER tr_validar_cita;
    INSERT INTO cita(paciente_id,medico_id,especialidad_id,inicio,fin,creado_por,creado_en,motivo)
    VALUES({other_patient_id},{schedule_doctor},{first_specialty},
      date_trunc('day',now())-interval '2 days'+interval '11 hours',
      date_trunc('day',now())-interval '2 days'+interval '11 hours 30 minutes',
      {admin_id},now()-interval '3 days','Dato histórico de prueba');
    ALTER TABLE cita ENABLE TRIGGER tr_validar_cita;
END $$""")
expired_id = int(sql("SELECT id FROM cita WHERE motivo='Dato histórico de prueba' ORDER BY id DESC LIMIT 1")[0]['id'])
for action in ['confirmar','reprogramar','cancelar']:
    expired_form = appointment_payload(other_patient,expired_id)
    expired_form.update(motivo_cambio='Prueba vencida',medico_id=str(schedule_doctor),inicio=booking_date+'T11:30')
    check(other_patient.request(f'/citas/{expired_id}/{action}',expired_form)[0] == 422,
          'Cita vencida no permite '+action+' por el flujo ordinario')

# Incidencias: se preparan reservas antiguas con turnos próximos y se cancelan por web.
penalty_name=PREFIX+'_sanciones'
penalty_data=register_data(penalty_name,penalty_name+'@example.invalid',PREFIX+'-SANCIONES')
penalty_data['csrf']=guest.token('/registro')
check(guest.request('/registro',penalty_data)[0] == 303,'Preparar paciente ficticio sin incidencias previas')
penalty_browser=Browser();penalty_browser.login(penalty_name,PASSWORD)
penalty_patient=int(sql('SELECT p.id FROM paciente p JOIN usuario u ON u.id=p.usuario_id WHERE u.nombre_usuario=?',[penalty_name])[0]['id'])
penalty_doctor_data=dict(doctor_data,csrf=admin.token('/medicos/nuevo'),registro_profesional=PREFIX+'-sanciones',
                        usuario_id='',especialidades=[first_specialty])
penalty_doc_response=doctor_post('/medicos/nuevo',penalty_doctor_data)
penalty_doc=int(penalty_doc_response[2]['Location'].rsplit('/',1)[1])
for day in range(1,8):
    sql("INSERT INTO horario(medico_id,dia_semana,hora_inicio,hora_fin) VALUES(?,?,'00:00','23:30')",[penalty_doc,day])
penalty_citas=[]
for offset in range(6):
    result=sql("""WITH actor AS(SELECT set_config('app.actor_id',?::text,true))
        INSERT INTO cita(paciente_id,medico_id,especialidad_id,inicio,fin,creado_por,creado_en)
        SELECT ?,?,?,date_trunc('hour',now())+make_interval(hours=>?)+interval '2 hours',
          date_trunc('hour',now())+make_interval(hours=>?)+interval '2 hours 30 minutes',?,now()-interval '3 days'
        FROM actor RETURNING id""",[str(admin_id),penalty_patient,penalty_doc,first_specialty,offset,offset,admin_id])
    penalty_citas.append(int(result[0]['id']))

def incidence_counts(patient_id):
    return [int(sql('SELECT count(*) AS n FROM incumplimiento i JOIN cita c ON c.id=i.cita_id WHERE c.paciente_id=?',[patient_id])[0]['n']),
            int(sql('SELECT count(*) AS n FROM multa m JOIN incumplimiento i ON i.id=m.incumplimiento_id '
                    'JOIN cita c ON c.id=i.cita_id WHERE c.paciente_id=?',[patient_id])[0]['n'])]


first_cancel=appointment_payload(penalty_browser,penalty_citas[0]);first_cancel['motivo_cambio']='Primera cancelación tardía de prueba'
check(penalty_browser.request(f'/citas/{penalty_citas[0]}/cancelar',first_cancel)[0] == 303
      and incidence_counts(penalty_patient)==[1,0],
      'Primera cancelación tardía válida genera una advertencia sin multa')
check('primera advertencia' in penalty_browser.request(f'/citas/{penalty_citas[0]}')[1],
      'El detalle informa la advertencia registrada')
standby_start=booking_date+'T15:00'
standby=booking_payload(penalty_browser,standby_start,doctor=penalty_doc)
standby_response=penalty_browser.request('/citas/nueva',standby)
check(standby_response[0] == 303,'Una primera advertencia permite nuevas reservas')
standby_id=int(standby_response[2]['Location'].rsplit('/',1)[1])
second_cancel=appointment_payload(penalty_browser,penalty_citas[1]);second_cancel['motivo_cambio']='Segunda cancelación tardía de prueba'
check(penalty_browser.request(f'/citas/{penalty_citas[1]}/cancelar',second_cancel)[0] == 303
      and incidence_counts(penalty_patient)==[2,1],
      'Segunda incidencia válida registra una multa pendiente')
check(sql('SELECT m.estado FROM multa m JOIN incumplimiento i ON i.id=m.incumplimiento_id WHERE i.cita_id=?',[penalty_citas[1]])[0]['estado']=='pendiente',
      'La multa queda pendiente sin registrar un pago ficticio')
blocked=booking_payload(penalty_browser,booking_date+'T16:00',doctor=penalty_doc)
check(penalty_browser.request('/citas/nueva',blocked)[0] == 422
      and penalty_browser.request('/mi-perfil')[0] == 200 and penalty_browser.request('/disponibilidad')[0] == 200,
      'Multa bloquea nuevas reservas ordinarias conservando perfil y consulta')
standby_confirm=appointment_payload(penalty_browser,standby_id)
check(penalty_browser.request(f'/citas/{standby_id}/confirmar',standby_confirm)[0] == 303,
      'La multa no impide confirmar una reserva existente')
for index,extra in [(2,{'origen_cambio':'clinica'}),(3,{'justificada':'1'}),
                    (4,{'aviso_cancelacion_en':sql("""SELECT to_char((now()-interval '2 days') AT TIME ZONE 'America/La_Paz','YYYY-MM-DD"T"HH24:MI') AS aviso""")[0]['aviso']})]:
    cancel_form=appointment_payload(reception,penalty_citas[index])
    cancel_form.update(motivo_cambio='Causa verificada por recepción',**extra)
    check(reception.request(f'/citas/{penalty_citas[index]}/cancelar',cancel_form)[0] == 303
          and incidence_counts(penalty_patient)==[2,1],
          'Cancelación autorizada sin nueva sanción: '+str(extra))
absence_cita=sql('SELECT inicio,fin FROM cita WHERE id=?',[penalty_citas[5]])[0]
sql('INSERT INTO ausencia_medico(medico_id,inicio,fin,motivo,registrado_por) VALUES(?,?,?,?,?)',
    [penalty_doc,absence_cita['inicio'],absence_cita['fin'],'Ausencia médica verificada',admin_id])
absence_cancel=appointment_payload(penalty_browser,penalty_citas[5]);absence_cancel['motivo_cambio']='Médico ausente'
check(penalty_browser.request(f'/citas/{penalty_citas[5]}/cancelar',absence_cancel)[0] == 303
      and incidence_counts(penalty_patient)==[2,1],
      'Ausencia médica registrada evita sancionar al paciente')
fresh_start=sql("""SELECT to_char((date_trunc('hour',now())+interval '12 hours') AT TIME ZONE 'America/La_Paz','YYYY-MM-DD"T"HH24:MI') AS inicio""")[0]['inicio']
fresh_booking=other_patient.request('/citas/nueva',booking_payload(other_patient,fresh_start,doctor=penalty_doc))
check(fresh_booking[0] == 303,'Reserva próxima puede registrarse con horario válido')
fresh_id=int(fresh_booking[2]['Location'].rsplit('/',1)[1])
fresh_cancel=appointment_payload(other_patient,fresh_id);fresh_cancel['motivo_cambio']='Revisión de reserva próxima'
review_before=len(sql("SELECT id FROM evento_administrativo WHERE paciente_id=? AND accion='revisar_incidencia'",[other_patient_id]))
check(other_patient.request(f'/citas/{fresh_id}/cancelar',fresh_cancel)[0] == 303
      and not sql('SELECT id FROM incumplimiento WHERE cita_id=?',[fresh_id])
      and len(sql("SELECT id FROM evento_administrativo WHERE paciente_id=? AND accion='revisar_incidencia'",[other_patient_id])) == review_before+1,
      'Reserva hecha dentro del plazo se deriva a revisión sin sanción automática')

# La cola de avisos es parte de la transacción de base, separada del envío externo.
fault_start=booking_date+'T17:00'
fault_payload=booking_payload(other_patient,fault_start,doctor=penalty_doc);before=appointment_counts()
sql("ALTER TABLE aviso ADD CONSTRAINT prueba_fallo_reserva CHECK(tipo<>'cambio_cita') NOT VALID")
try:
    fault_response=other_patient.request('/citas/nueva',fault_payload)
finally:
    sql('ALTER TABLE aviso DROP CONSTRAINT prueba_fallo_reserva')
check(fault_response[0] == 500 and appointment_counts()==before,
      'Fallo al guardar el aviso en base revierte reserva e historial sin datos parciales')

permission_start=booking_date+'T18:00'
permission_booking=other_patient.request('/citas/nueva',booking_payload(other_patient,permission_start,doctor=penalty_doc))
permission_cita=int(permission_booking[2]['Location'].rsplit('/',1)[1])
for permission in ['CU11','CU12','CU13','CU14','CU15']:
    csrf=other_patient.token('/panel')
    form=appointment_payload(other_patient,permission_cita)
    form.update(motivo_cambio='Prueba de permisos',medico_id=str(penalty_doc),inicio=booking_date+'T19:00')
    book_form=booking_payload(other_patient,booking_date+'T19:00',doctor=penalty_doc)
    matrix=matrix_form();removed=[(k,v) for k,v in matrix if not(k=='permisos[paciente][]' and v==permission)]
    check(admin.request('/roles',removed)[0] == 303,'Retirar permiso de citas '+permission)
    if permission=='CU11':
        denied=other_patient.request(availability_url())
    elif permission=='CU12':
        denied=other_patient.request('/citas/nueva',book_form)
    else:
        action={'CU13':'confirmar','CU14':'reprogramar','CU15':'cancelar'}[permission]
        denied=other_patient.request(f'/citas/{permission_cita}/{action}',form)
    check(denied[0] == 403,'Retirar '+permission+' afecta la siguiente petición y los formularios abiertos')
    restore=matrix_form();restore.append(('permisos[paciente][]',permission))
    check(admin.request('/roles',restore)[0] == 303,'Restituir permiso de citas '+permission)
check(other_patient.request(f'/citas/{permission_cita}/cancelar',{'csrf':'invalido','motivo_cambio':'Prueba'})[0] == 419,
      'Cambios de citas exigen token CSRF válido')
check(other_patient.request('/citas/999999999')[0] == 404
      and other_patient.request(f'/citas/{permission_cita}/eliminar')[0] == 404,
      'Cita inexistente devuelve 404 y no hay eliminación física')

throttled = Browser()
for _ in range(8):
    throttled.request('/sesion', {'csrf': throttled.token(), 'identidad': PREFIX + '_fallos', 'password': 'contraseña incorrecta'})
check(throttled.request('/sesion', {'csrf': throttled.token(), 'identidad': PREFIX + '_fallos', 'password': 'contraseña incorrecta'})[0] == 429,
      'Se limita el exceso de intentos de acceso')
out = os.environ.get('CLINICA_TEST_REPORT')
if out:
    Path(out).write_text(json.dumps({'passed': len(results), 'results': results}, ensure_ascii=False, indent=2))
print(f'{len(results)} comprobaciones HTTP correctas.')
