<?php
declare(strict_types=1);
function e(mixed $value): string { return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function input(string $key): string { return is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : ''; }
function raw_password(string $key = 'password'): string { return is_string($_POST[$key] ?? null) ? $_POST[$key] : ''; }
function redirect(string $path): never { header('Location: ' . $path, true, 303); exit; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(App\Session::token()) . '">'; }
function old(array $data, string $key): string { return e($data[$key] ?? ''); }
function field_error(array $errors, string $key): string { return isset($errors[$key]) ? '<span class="field-error">' . e($errors[$key]) . '</span>' : ''; }
function clinic_input(string $value, string $format='Y-m-d\TH:i'): string
{
    try { return (new DateTimeImmutable($value,new DateTimeZone('America/La_Paz')))->setTimezone(new DateTimeZone('America/La_Paz'))->format($format); }
    catch (Exception) { return ''; }
}
function clinic_datetime(string $value): string { return clinic_input($value,'d/m/Y H:i'); }
