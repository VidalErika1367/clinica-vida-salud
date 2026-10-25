<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
$checks = [];
$check = function (bool $ok, string $label) use (&$checks): void { $checks[] = [$ok, $label]; };
$account = ['nombre_usuario'=>'ika_prueba','correo'=>'ika@example.invalid','password'=>'Una frase para probar 2026','password_confirmation'=>'Una frase para probar 2026'];
$check(App\Validation::account($account) === [], 'Cuenta con datos válidos');
$bad = $account; $bad['nombre_usuario'] = '<script>';
$check(isset(App\Validation::account($bad)['nombre_usuario']), 'Usuario con caracteres no permitidos rechazado');
$bad = $account; $bad['correo'] = 'sin-correo';
$check(isset(App\Validation::account($bad)['correo']), 'Correo inválido rechazado');
$bad = $account; $bad['password'] = $bad['password_confirmation'] = 'corta';
$check(isset(App\Validation::account($bad)['password']), 'Contraseña demasiado corta rechazada');
$bad = $account; $bad['password'] = $bad['password_confirmation'] = str_repeat('a',73);
$check(isset(App\Validation::account($bad)['password']), 'No se permite el truncamiento de contraseñas mayores de 72 bytes');
$bad = $account; $bad['password_confirmation'] = 'Otra frase distinta';
$check(isset(App\Validation::account($bad)['password_confirmation']), 'Confirmación diferente rechazada');
$bad = $account; $bad['password'] = $bad['password_confirmation'] = '';
$check(App\Validation::account($bad, false) === [], 'Edición conserva la contraseña cuando queda vacía');
$patient = ['ci'=>'123456 SC','nombres'=>'María José','apellidos'=>'Vidal','telefono'=>'+591 70000000'];
$check(App\Validation::patient($patient) === [], 'Datos de paciente con tildes aceptados');
$bad = $patient; $bad['telefono'] = '123';
$check(isset(App\Validation::patient($bad)['telefono']), 'Teléfono insuficiente rechazado');
$bad = $patient; $bad['nombres'] = '<img src=x>';
$check(isset(App\Validation::patient($bad)['nombres']), 'Etiquetas en nombres rechazadas');
$normalized = App\Validation::normalize(['nombre_usuario'=>'  IKA  ','correo'=>' IKA@Example.Invalid ','ci'=>' 123  sc ']);
$check($normalized['nombre_usuario']==='ika' && $normalized['correo']==='ika@example.invalid' && $normalized['ci']==='123 SC', 'Normalización de identificadores');
$check(e('<script>"&') === '&lt;script&gt;&quot;&amp;', 'Salida HTML escapada');
$hash = password_hash($account['password'], PASSWORD_DEFAULT);
$check($hash !== $account['password'] && password_verify($account['password'], $hash), 'Contraseña guardada como hash verificable');
$check(!password_verify('Otra frase de prueba', $hash), 'Hash rechaza otra contraseña');

$matrixA = ['CU05'=>['administrador'=>true], 'CU02'=>['paciente'=>true]];
$matrixB = ['CU02'=>['paciente'=>true], 'CU05'=>['administrador'=>true]];
$check(App\RoleService::version($matrixA) === App\RoleService::version($matrixB), 'La versión de permisos no depende del orden de filas');
$matrixB['CU01']['paciente'] = true;
$check(App\RoleService::version($matrixA) !== App\RoleService::version($matrixB), 'La versión cambia al modificar un permiso');
$policy = App\RoleService::policy(); $selected = []; $present = [];
foreach ($policy as $role => $rules) { $selected[$role] = $rules['disponibles']; $present[$role] = '1'; }
$check(count(App\RoleService::validateSelection($selected, $present)) === 4, 'Matriz de los cuatro roles válida');
$rejected = static function ($selection, $present): bool {
    try { App\RoleService::validateSelection($selection, $present); return false; }
    catch (App\ValidationException) { return true; }
};
$bad = $selected; $bad['paciente'][] = 'CU04';
$check($rejected($bad, $present), 'El paciente no recibe una operación administrativa');
$bad = $selected; $bad['administrador'] = ['CU02', 'CU03'];
$check($rejected($bad, $present), 'Se protege el acceso a usuarios y permisos');
$bad = $selected; $bad['medico'] = 'CU18';
$check($rejected($bad, $present), 'Permisos con estructura inválida rechazados');


$details = $patient + ['correo'=>'contacto@example.invalid','fecha_nacimiento'=>'2000-02-29','sexo'=>'Femenino','direccion'=>'Calle de prueba'];
$check(App\Validation::patientDetails($details) === [], 'Datos administrativos de paciente válidos');
foreach (['2001-02-29','2999-01-01','fecha'] as $birth) {
    $bad = $details; $bad['fecha_nacimiento'] = $birth;
    $check(isset(App\Validation::patientDetails($bad)['fecha_nacimiento']), 'Fecha de nacimiento inválida rechazada: ' . $birth);
}
$bad = $details; $bad['correo'] = $bad['fecha_nacimiento'] = $bad['sexo'] = $bad['direccion'] = '';
$check(App\Validation::patientDetails($bad) === [], 'Datos opcionales de paciente pueden quedar vacíos');
$bad = $details; $bad['direccion'] = str_repeat('a',251);
$check(isset(App\Validation::patientDetails($bad)['direccion']), 'Dirección demasiado larga rechazada');
$bad = $details; $bad['ci'] = '<script>';
$check(isset(App\Validation::patientDetails($bad)['ci']), 'CI con etiquetas rechazado');
$check(App\PatientService::version(['ci'=>'UNO','activo'=>true]) !== App\PatientService::version(['ci'=>'UNO','activo'=>false]), 'Cambiar el estado del paciente modifica la versión de la ficha');

$profile = App\ProfileService::values(['telefono'=>'+591 70000000']);
$check(App\Validation::patientProfile($profile) === [], 'Perfil admite datos de salud y emergencia sin informar');
foreach (App\Validation::BLOOD_TYPES as $blood) {
    $check(App\Validation::patientProfile(array_replace($profile,['tipo_sangre'=>$blood])) === [], 'Perfil acepta grupo sanguíneo ' . $blood);
}
$bad = array_replace($profile,['tipo_sangre'=>'Z+']);
$check(isset(App\Validation::patientProfile($bad)['tipo_sangre']), 'Grupo sanguíneo desconocido rechazado');
$bad = array_replace($profile,['emergencia_nombre'=>'Contacto de prueba']);
$check(isset(App\Validation::patientProfile($bad)['emergencia_telefono'])
    && isset(App\Validation::patientProfile($bad)['emergencia_parentesco']), 'Contacto de emergencia incompleto rechazado');
$bad = array_replace($profile,['emergencia_nombre'=>'Contacto de prueba','emergencia_parentesco'=>'Familiar','emergencia_telefono'=>'123']);
$check(isset(App\Validation::patientProfile($bad)['emergencia_telefono']), 'Teléfono de emergencia insuficiente rechazado');
$check(App\Validation::patientProfile(array_replace($bad,['emergencia_telefono'=>'+591 70000001'])) === [], 'Contacto de emergencia completo aceptado');
$check(App\Validation::patientProfile(array_replace($profile,['antecedentes'=>"Dato de prueba.\nSegunda línea."])) === [], 'Datos médicos básicos permiten varias líneas');
$check(isset(App\Validation::patientProfile(array_replace($profile,['alergias'=>str_repeat('a',2001)]))['alergias']), 'Dato médico demasiado largo rechazado');
$check(isset(App\Validation::patientProfile(array_replace($profile,['alergias'=>'<script>prueba</script>']))['alergias']), 'Etiquetas en datos médicos rechazadas');
$first = ['id'=>1,'ci'=>'DEMO','usuario_id'=>1,'alergias'=>'Dato de prueba'];
$check(App\ProfileService::version($first) !== App\ProfileService::version(array_replace($first,['alergias'=>'Otro dato'])), 'Cambiar salud modifica la versión del perfil');
$check(App\ProfileService::version($first) !== App\ProfileService::version(array_replace($first,['emergencia_nombre'=>'Contacto'])), 'Cambiar emergencia modifica la versión del perfil');

$check(App\SpecialtyService::name('  Medicina   General  ') === 'Medicina General','Nombre de especialidad elimina espacios sobrantes');
$check(App\Validation::specialty(['nombre'=>'Pediatría','descripcion'=>'Descripción de prueba']) === [],'Especialidad válida con tildes');
$check(isset(App\Validation::specialty(['nombre'=>'','descripcion'=>''])['nombre']),'Nombre vacío de especialidad rechazado');
$check(isset(App\Validation::specialty(['nombre'=>'Prueba','descripcion'=>str_repeat('a',1001)])['descripcion']),'Descripción de especialidad demasiado larga rechazada');
$doctor=['registro_profesional'=>'REG-PRUEBA','nombres'=>'Rosa','apellidos'=>'Ejemplo','telefono'=>'','correo'=>''];
$normalizedDoctor=App\DoctorService::data(['registro_profesional'=>' reg-prueba ','especialidades'=>['1'],'correo'=>' PRUEBA@EXAMPLE.INVALID ']);
$check($normalizedDoctor['registro_profesional']==='REG-PRUEBA' && $normalizedDoctor['correo']==='prueba@example.invalid'
    && $normalizedDoctor['especialidades']===['1'],'Médico normaliza identificadores con las extensiones declaradas del proyecto');
$check(App\Validation::doctor($doctor) === [],'Contacto de médico puede quedar sin informar');
foreach (['registro_profesional'=>'','telefono'=>'123','correo'=>'invalido','nombres'=>'<script>'] as $key=>$value) {
    $check(isset(App\Validation::doctor(array_replace($doctor,[$key=>$value]))[$key]),'Dato inválido de médico rechazado: ' . $key);
}

$schedule=['medico_id'=>'1','dia_semana'=>'1','hora_inicio'=>'08:00','hora_fin'=>'12:00'];
$check(App\Validation::schedule($schedule)===[],'Horario semanal válido');
$check(App\Validation::schedule(array_replace($schedule,['hora_inicio'=>'08:30','hora_fin'=>'09:00']))===[],'Horario de un bloque de 30 minutos válido');
foreach (['hora_inicio'=>'08:15','hora_fin'=>'08:00','dia_semana'=>'8','medico_id'=>'0'] as $key=>$value) {
    $check(isset(App\Validation::schedule(array_replace($schedule,[$key=>$value]))[$key]),'Horario rechaza dato inválido: ' . $key);
}
$check(isset(App\Validation::schedule(array_replace($schedule,['hora_inicio'=>'23:30','hora_fin'=>'00:30']))['hora_fin']),'Tramo que cruza de día rechazado');
$check(isset(App\Validation::schedule(array_replace($schedule,['hora_inicio'=>'25:00']))['hora_inicio']),'Hora inexistente rechazada');
$check(isset(App\Validation::schedule(array_replace($schedule,['hora_inicio'=>'08:00:30']))['hora_inicio']),'Segundos fuera de alineación rechazados');

$check(App\AvailabilityService::dateValid('2028-02-29'),'Disponibilidad acepta fecha bisiesta válida');
$check(!App\AvailabilityService::dateValid('2027-02-29'),'Disponibilidad rechaza fecha inexistente');
$check(!App\AvailabilityService::dateValid('0000-01-01'),'Disponibilidad rechaza año cero');
$check(App\AvailabilityService::start('2026-10-26T08:30')->format('P')==='-04:00','Turno interpretado en la zona de la clínica');
foreach (['2026-10-26T08:15','2026-02-30T08:00','2026-10-26T25:00','2026-10-26T08:00Z'] as $invalidStart) {
    try { App\AvailabilityService::start($invalidStart); $ok=false; } catch (App\ValidationException) { $ok=true; }
    $check($ok,'Turno inválido rechazado: '.$invalidStart);
}
$check(App\AppointmentService::reason(['motivo'=>''],'motivo',false)==='','Motivo de reserva puede quedar vacío');
try { App\AppointmentService::reason(['motivo_cambio'=>''],'motivo_cambio'); $ok=false; } catch (App\ValidationException) { $ok=true; }
$check($ok,'Cambios de cita requieren motivo');
try { App\AppointmentService::reason(['motivo'=>'<script>'],'motivo',false); $ok=false; } catch (App\ValidationException) { $ok=true; }
$check($ok,'Motivo con etiquetas rechazado');

foreach ($checks as [$ok,$label]) { echo ($ok ? '[OK] ' : '[FALLO] ') . $label . PHP_EOL; }
echo count($checks) . ' comprobaciones.' . PHP_EOL;
exit(count(array_filter($checks,fn($x)=>!$x[0])) ? 1 : 0);
