<?php
declare(strict_types=1);
namespace App;

/**
 * Reglas de validación de formularios: horarios (CU10), especialidades (CU09), médicos (CU08),
 * cuentas (CU01, CU04), pacientes (CU06), perfil de salud (CU07). Devuelven errores por campo.
 */
final class Validation
{
    /** Tipos de sangre admitidos en el perfil de salud. */
    public const BLOOD_TYPES = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];

    /**
     * Valida un horario: médico, día y horas en bloques de media hora dentro del mismo día.
     *
     * @param array $data datos del horario
     * @return array<string,string> errores por campo; vacío si es válido
     */
    public static function schedule(array $data): array
    {
        $errors=[];
        if (!preg_match('/^[1-9][0-9]{0,17}$/',$data['medico_id'] ?? '')) { $errors['medico_id']='Selecciona un médico activo.'; }
        if (!preg_match('/^[1-7]$/',$data['dia_semana'] ?? '')) { $errors['dia_semana']='Selecciona un día de lunes a domingo.'; }
        foreach (['hora_inicio','hora_fin'] as $key) {
            if (!preg_match('/^(?:[01][0-9]|2[0-3]):(?:00|30)$/',$data[$key] ?? '')) {
                $errors[$key]='Usa una hora válida terminada en :00 o :30.';
            }
        }
        if (!isset($errors['hora_inicio']) && !isset($errors['hora_fin']) && ($data['hora_fin'] ?? '')<=($data['hora_inicio'] ?? '')) {
            $errors['hora_fin']='La hora final debe ser posterior a la inicial, dentro del mismo día.';
        }
        return $errors;
    }

    /**
     * Valida nombre y descripción de una especialidad.
     *
     * @param array $data datos de la especialidad
     * @return array<string,string> errores por campo
     */
    public static function specialty(array $data): array
    {
        $errors=[];
        if (($data['nombre'] ?? '') === '' || mb_strlen($data['nombre'])>100 || preg_match('/[\x00-\x1F<>]/u',$data['nombre'])) {
            $errors['nombre']='Escribe un nombre de hasta 100 caracteres, sin etiquetas.';
        }
        if (mb_strlen($data['descripcion'] ?? '')>1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F<>]/u',$data['descripcion'] ?? '')) {
            $errors['descripcion']='Escribe una descripción de hasta 1000 caracteres, sin etiquetas.';
        }
        return $errors;
    }

    /**
     * Valida los datos profesionales y de contacto de un médico.
     *
     * @param array $data datos del médico
     * @return array<string,string> errores por campo
     */
    public static function doctor(array $data): array
    {
        $errors=[];
        foreach (['registro_profesional'=>50,'nombres'=>100,'apellidos'=>100] as $key=>$max) {
            if (($data[$key] ?? '') === '' || mb_strlen($data[$key])>$max || preg_match('/[\x00-\x1F<>]/u',$data[$key])) {
                $errors[$key]='Completa este dato con texto de hasta ' . $max . ' caracteres.';
            }
        }
        if (($data['telefono'] ?? '') !== '' && !self::validPhone($data['telefono'])) { $errors['telefono']='Escribe un teléfono válido.'; }
        if (($data['correo'] ?? '') !== '' && (!filter_var($data['correo'],FILTER_VALIDATE_EMAIL) || mb_strlen($data['correo'])>160)) {
            $errors['correo']='Escribe un correo válido de hasta 160 caracteres.';
        }
        return $errors;
    }

    /**
     * Comprueba un teléfono: entre 7 y 15 dígitos, con símbolos de formato permitidos.
     *
     * @param string $phone teléfono escrito
     * @return bool
     */
    public static function validPhone(string $phone): bool
    {
        $digits = preg_replace('/\D/', '', $phone);
        return preg_match('/^\+?[0-9 ()-]{7,30}$/', $phone) === 1 && strlen($digits) >= 7 && strlen($digits) <= 15;
    }
    /**
     * Política de contraseñas nuevas: de 8 caracteres a 72 bytes, con al menos una mayúscula, una minúscula,
     * un número y un carácter especial (cualquier carácter que no sea letra ni número; el espacio cuenta y
     * está permitido). Las letras con tilde y la ñ cuentan como letras. El máximo de 72 bytes evita que bcrypt
     * trunque la contraseña. Solo se usa al crear o cambiar contraseñas, nunca al iniciar sesión.
     *
     * @param string $password contraseña en claro, tal como la escribió la persona
     * @return list<string> mensajes que explican qué falta; lista vacía si la contraseña cumple la política
     */
    public static function passwordProblems(string $password): array
    {
        if ($password === '' || str_contains($password, "\0") || !mb_check_encoding($password, 'UTF-8')) {
            return ['La contraseña no es válida: usa solo texto, sin caracteres de control.'];
        }
        if (strlen($password) > 72) {
            return ['La contraseña no puede superar 72 bytes (las letras con tilde y la ñ ocupan 2 bytes). Acórtala.'];
        }
        $missing = [];
        if (mb_strlen($password) < 8) { $missing[] = 'al menos 8 caracteres'; }
        if (!preg_match('/\p{Lu}/u', $password)) { $missing[] = 'una letra mayúscula'; }
        if (!preg_match('/\p{Ll}/u', $password)) { $missing[] = 'una letra minúscula'; }
        if (!preg_match('/[0-9]/', $password)) { $missing[] = 'un número'; }
        if (!preg_match('/[^\p{L}\p{N}]/u', $password)) { $missing[] = 'un carácter especial (por ejemplo . _ - ! @ # $ % & * ?)'; }
        return $missing ? ['A la contraseña le falta: ' . implode(', ', $missing) . '.'] : [];
    }
    /**
     * Valida una cuenta de usuario. Al crear o cambiar la contraseña se aplica la política de passwordProblems();
     * el inicio de sesión no la aplica, para que las cuentas con contraseñas anteriores puedan seguir entrando.
     *
     * @param array $data datos de la cuenta
     * @param bool $passwordRequired si es false, una contraseña vacía significa "no cambiar"
     * @return array<string,string> errores por campo
     */
    public static function account(array $data, bool $passwordRequired = true): array
    {
        $errors = [];
        if (!preg_match('/^[a-z0-9_.-]{3,60}$/', $data['nombre_usuario'] ?? '')) {
            $errors['nombre_usuario'] = 'Usa entre 3 y 60 letras sin tildes, números, puntos, guiones o guion bajo.';
        }
        if (!filter_var($data['correo'] ?? '', FILTER_VALIDATE_EMAIL) || strlen($data['correo'] ?? '') > 160) {
            $errors['correo'] = 'Escribe un correo válido de hasta 160 caracteres.';
        }
        $password = $data['password'] ?? '';
        if ($passwordRequired || $password !== '') {
            $problems = self::passwordProblems($password);
            if ($problems) { $errors['password'] = $problems[0]; }
            if ($password !== ($data['password_confirmation'] ?? '')) { $errors['password_confirmation'] = 'Las contraseñas no coinciden.'; }
        }
        return $errors;
    }
    /**
     * Valida los datos mínimos de un paciente: CI, nombres, apellidos y teléfono.
     *
     * @param array $data datos del paciente
     * @return array<string,string> errores por campo
     */
    public static function patient(array $data): array
    {
        $errors = [];
        if (($data['ci'] ?? '') === '' || mb_strlen($data['ci']) > 30) { $errors['ci'] = 'Escribe el CI, de hasta 30 caracteres.'; }
        foreach (['nombres' => 'nombres', 'apellidos' => 'apellidos'] as $key => $label) {
            if (trim($data[$key] ?? '') === '' || mb_strlen($data[$key]) > 100 || preg_match('/[\x00-\x1F<>]/u', $data[$key])) {
                $errors[$key] = 'Escribe tus ' . $label . ', de hasta 100 caracteres.';
            }
        }
        $phone = $data['telefono'] ?? '';
        if (!self::validPhone($phone)) {
            $errors['telefono'] = 'Escribe un teléfono válido, con código de país si corresponde.';
        }
        return $errors;
    }
    /**
     * Normaliza identificadores: usuario y correo en minúsculas; CI en mayúsculas y con espacios simples.
     * Porqué: evita cuentas o pacientes duplicados por diferencias de escritura.
     *
     * @param array $data datos recibidos
     * @return array datos normalizados
     */
    public static function normalize(array $data): array
    {
        foreach (['nombre_usuario', 'correo'] as $key) { $data[$key] = mb_strtolower(trim($data[$key] ?? '')); }
        $data['ci'] = mb_strtoupper(preg_replace('/\s+/u', ' ', trim($data['ci'] ?? '')));
        return $data;
    }

    /**
     * Valida los datos administrativos completos del paciente, incluidos los opcionales.
     * La fecha de nacimiento no puede ser futura.
     *
     * @param array $data datos del paciente
     * @return array<string,string> errores por campo
     */
    public static function patientDetails(array $data): array
    {
        $errors = self::patient($data);
        if (preg_match('/[\x00-\x1F<>]/u', $data['ci'] ?? '')) {
            $errors['ci'] = 'El CI no puede contener etiquetas ni caracteres de control.';
        }
        $email = $data['correo'] ?? '';
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160)) {
            $errors['correo'] = 'Escribe un correo de contacto válido, de hasta 160 caracteres.';
        }
        foreach (['sexo'=>30, 'direccion'=>250] as $key => $maximum) {
            if (mb_strlen($data[$key] ?? '') > $maximum || preg_match('/[\x00-\x1F<>]/u', $data[$key] ?? '')) {
                $errors[$key] = 'Revisa el dato ingresado; admite hasta ' . $maximum . ' caracteres de texto.';
            }
        }
        $birth = $data['fecha_nacimiento'] ?? '';
        if ($birth !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $birth);
            $issues = \DateTimeImmutable::getLastErrors();
            if (!$date || ($issues && ($issues['warning_count'] || $issues['error_count']))
                || $date->format('Y-m-d') !== $birth || $birth > date('Y-m-d')
                || (int) substr($birth,0,4) < 1) {
                $errors['fecha_nacimiento'] = 'Escribe una fecha de nacimiento válida que no sea futura.';
            }
        }
        return $errors;
    }

    /**
     * Valida el perfil de salud y el contacto de emergencia. Si se llena un dato del contacto, deben llenarse los tres.
     *
     * @param array $data datos del perfil
     * @return array<string,string> errores por campo
     */
    public static function patientProfile(array $data): array
    {
        $errors = [];
        if (!self::validPhone($data['telefono'] ?? '')) {
            $errors['telefono'] = 'Escribe un teléfono válido, con código de país si corresponde.';
        }
        $email = $data['correo'] ?? '';
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160)) {
            $errors['correo'] = 'Escribe un correo de contacto válido, de hasta 160 caracteres.';
        }
        foreach (['direccion'=>250,'emergencia_nombre'=>160,'emergencia_parentesco'=>60] as $key=>$maximum) {
            if (mb_strlen($data[$key] ?? '') > $maximum || preg_match('/[\x00-\x1F<>]/u', $data[$key] ?? '')) {
                $errors[$key] = 'Escribe texto de hasta ' . $maximum . ' caracteres.';
            }
        }
        if (($data['tipo_sangre'] ?? '') !== '' && !in_array($data['tipo_sangre'], self::BLOOD_TYPES, true)) {
            $errors['tipo_sangre'] = 'Selecciona un tipo de sangre válido o No informado.';
        }
        foreach (['alergias','enfermedades_cronicas','medicamentos','antecedentes'] as $key) {
            if (mb_strlen($data[$key] ?? '') > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F<>]/u', $data[$key] ?? '')) {
                $errors[$key] = 'Escribe texto de hasta 2000 caracteres, sin etiquetas.';
            }
        }
        $contact = ['emergencia_nombre','emergencia_parentesco','emergencia_telefono'];
        if (array_filter($contact, static fn($key) => ($data[$key] ?? '') !== '')) {
            foreach ($contact as $key) {
                if (($data[$key] ?? '') === '') { $errors[$key] = 'Completa los tres datos del contacto o déjalos todos vacíos.'; }
            }
            if (($data['emergencia_telefono'] ?? '') !== '' && !self::validPhone($data['emergencia_telefono'])) {
                $errors['emergencia_telefono'] = 'Escribe un teléfono de emergencia válido.';
            }
        }
        return $errors;
    }
}
