<?php
declare(strict_types=1);
namespace App;

/**
 * Contactos de recepción por WhatsApp para la pantalla "Contactar con recepción" (CU30).
 * Los números vienen de CLINICA_RECEPCION_WHATSAPP; esta clase no envía mensajes, solo prepara enlaces wa.me.
 */
final class ReceptionContact
{
    /** Mensaje que WhatsApp deja escrito en el chat; el paciente decide si lo envía. */
    public const MESSAGE = 'Hola, soy paciente de la Clínica Vida Salud y quiero hacer una consulta.';
    /** Mensaje de la página "¿Olvidaste tu contraseña?": termina en espacio para que la persona escriba su CI. */
    public const FORGOT_MESSAGE = 'Hola, olvidé mi contraseña de la Clínica Vida Salud. Mi CI es: ';

    /**
     * Devuelve los contactos de WhatsApp válidos.
     *
     * Formato de CLINICA_RECEPCION_WHATSAPP: hasta 3 contactos separados por coma, cada uno como
     * "Etiqueta:numero" o solo "numero". El número lleva código de país; al escribirlo se aceptan +, espacios
     * y guiones, pero se normaliza a solo dígitos (entre 8 y 15). Los números inválidos se descartan y los
     * repetidos se muestran una sola vez. Porqué: cada persona de recepción atiende desde su propio número.
     *
     * @param string|null $message texto prellenado del chat; por omisión, MESSAGE
     * @return list<array{label:string,number:string,url:string}> contactos válidos (máximo 3); lista vacía si
     *         no hay ninguno. La etiqueta usa "Recepción" si falta o no es válida (más de 40 caracteres,
     *         etiquetas HTML o caracteres de control). El enlace es siempre https://wa.me/<dígitos>?text=<mensaje>
     */
    public static function whatsapp(?string $message = null): array
    {
        $contacts=[]; $seen=[];
        foreach (explode(',',trim(Config::get('CLINICA_RECEPCION_WHATSAPP'))) as $entry) {
            $label='';
            if (preg_match('/^([^:]*):(.*)$/su',$entry,$m)) { $label=trim($m[1]); $entry=$m[2]; }
            $entry=trim($entry);
            if (!preg_match('/^\+?[0-9][0-9 \-]*$/D',$entry)) { continue; }
            $number=preg_replace('/\D/','',$entry);
            if (strlen($number)<8 || strlen($number)>15 || isset($seen[$number])) { continue; }
            $seen[$number]=true;
            $valid=$label!=='' && mb_check_encoding($label,'UTF-8') && mb_strlen($label)<=40 && !preg_match('/[<>\x00-\x1F\x7F]/',$label);
            $contacts[]=['label'=>$valid ? $label : 'Recepción','number'=>$number,
                'url'=>'https://wa.me/'.$number.'?text='.rawurlencode($message ?? self::MESSAGE)];
            if (count($contacts)===3) { break; }
        }
        return $contacts;
    }
}
