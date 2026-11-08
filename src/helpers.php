<?php
declare(strict_types=1);
/**
 * Funciones auxiliares globales para vistas y controladores. Se cargan desde bootstrap.php.
 */

/**
 * Escapa un valor para HTML. Es la defensa principal contra XSS en las vistas.
 *
 * @param mixed $value valor a mostrar (null se convierte en cadena vacía)
 * @return string texto seguro para HTML
 */
function e(mixed $value): string { return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
/**
 * Lee un campo de texto del formulario POST, sin espacios sobrantes.
 *
 * @param string $key nombre del campo
 * @return string valor o cadena vacía si no es texto
 */
function input(string $key): string { return is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : ''; }
/**
 * Lee una contraseña del POST sin recortar espacios: son parte de la clave.
 *
 * @param string $key nombre del campo
 * @return string contraseña tal como se escribió
 */
function raw_password(string $key = 'password'): string { return is_string($_POST[$key] ?? null) ? $_POST[$key] : ''; }
/**
 * Redirige con 303 (See Other) y termina la ejecución. Porqué: 303 evita reenviar un POST al recargar.
 *
 * @param string $path ruta interna que comienza con "/"
 * @return never
 */
function redirect(string $path): never { header('Location: ' . $path, true, 303); exit; }
/**
 * Genera el campo oculto con el token CSRF para incluir en cada formulario POST.
 *
 * @return string etiqueta input oculta
 */
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(App\Session::token()) . '">'; }
/**
 * Devuelve el valor previo de un campo para repoblar el formulario tras un error.
 *
 * @param array $data valores previos
 * @param string $key campo
 * @return string valor escapado
 */
function old(array $data, string $key): string { return e($data[$key] ?? ''); }
/**
 * Muestra el mensaje de error de un campo junto al control, si existe.
 *
 * @param array $errors errores por campo
 * @param string $key campo
 * @return string HTML del mensaje o cadena vacía
 */
function field_error(array $errors, string $key): string { return isset($errors[$key]) ? '<span class="field-error">' . e($errors[$key]) . '</span>' : ''; }
/**
 * Convierte una fecha almacenada a la zona horaria de la clínica y la formatea para un control de formulario.
 *
 * @param string $value fecha de la base de datos
 * @param string $format formato de salida
 * @return string fecha formateada, o cadena vacía si no es válida
 */
function clinic_input(string $value, string $format='Y-m-d\TH:i'): string
{
    try { return (new DateTimeImmutable($value,new DateTimeZone('America/La_Paz')))->setTimezone(new DateTimeZone('America/La_Paz'))->format($format); }
    catch (Exception) { return ''; }
}
/**
 * Muestra una fecha y hora de la clínica en formato boliviano (día/mes/año hora:minuto).
 *
 * @param string $value fecha de la base de datos
 * @return string fecha formateada
 */
function clinic_datetime(string $value): string { return clinic_input($value,'d/m/Y H:i'); }
