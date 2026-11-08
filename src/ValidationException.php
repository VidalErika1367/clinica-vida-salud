<?php
declare(strict_types=1);
namespace App;
/**
 * Error de validación de datos de formulario o reglas de negocio.
 * Los controladores lo capturan para volver a mostrar el formulario con los mensajes (código 422).
 */
final class ValidationException extends \RuntimeException
{
    /**
     * @param array<string,string> $errors mensajes por campo; la clave "general" aplica al formulario completo
     */
    public function __construct(public readonly array $errors) { parent::__construct('Revisa los datos del formulario.'); }
}
