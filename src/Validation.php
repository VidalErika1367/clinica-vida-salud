<?php
declare(strict_types=1);
namespace App;

final class Validation
{
    public const BLOOD_TYPES = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];

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

    private static function validPhone(string $phone): bool
    {
        $digits = preg_replace('/\D/', '', $phone);
        return preg_match('/^\+?[0-9 ()-]{7,30}$/', $phone) === 1 && strlen($digits) >= 7 && strlen($digits) <= 15;
    }
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
            if (str_contains($password, "\0") || mb_strlen($password) < 12 || strlen($password) > 72) {
                $errors['password'] = 'Usa al menos 12 caracteres y como máximo 72 bytes. Puedes escribir una frase.';
            }
            if ($password !== ($data['password_confirmation'] ?? '')) { $errors['password_confirmation'] = 'Las contraseñas no coinciden.'; }
        }
        return $errors;
    }
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
    public static function normalize(array $data): array
    {
        foreach (['nombre_usuario', 'correo'] as $key) { $data[$key] = mb_strtolower(trim($data[$key] ?? '')); }
        $data['ci'] = mb_strtoupper(preg_replace('/\s+/u', ' ', trim($data['ci'] ?? '')));
        return $data;
    }

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
