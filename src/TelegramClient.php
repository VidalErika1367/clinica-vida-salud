<?php
declare(strict_types=1);
namespace App;

final class TelegramClient
{
    /** El transporte inyectado se usa exclusivamente en pruebas sin mensajes reales. */
    public function __construct(private readonly ?\Closure $transport=null) {}
    public static function configured(): bool
    {
        return (bool) preg_match('/^[0-9]{5,}:[A-Za-z0-9_-]{20,}$/D',Config::get('TELEGRAM_BOT_TOKEN'))
            && (bool) preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/D',Config::get('TELEGRAM_BOT_USERNAME'));
    }
    public function call(string $method, array $parameters=[]): mixed
    {
        if (!in_array($method,['getMe','getUpdates','sendMessage','setWebhook','deleteWebhook','getWebhookInfo'],true)) {
            throw new \InvalidArgumentException('Operación de Telegram no admitida.');
        }
        if ($this->transport) { $response=($this->transport)($method,$parameters); }
        else {
            if (!self::configured()) { throw new \RuntimeException('Telegram no está configurado.'); }
            $context=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\n",
                'content'=>json_encode($parameters,JSON_THROW_ON_ERROR),'timeout'=>$method==='getUpdates' ? 35 : 10,'ignore_errors'=>true,
                'follow_location'=>0],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
            // No se registran la URL con token, el cuerpo recibido ni errores de red que puedan incluirlos.
            $raw=@file_get_contents('https://api.telegram.org/bot'.Config::get('TELEGRAM_BOT_TOKEN').'/'.$method,false,$context);
            $response=is_string($raw) ? json_decode($raw,true) : null;
        }
        if (!is_array($response) || ($response['ok'] ?? null)!==true || !array_key_exists('result',$response)) {
            throw new \RuntimeException('Telegram no confirmó la operación. Revisa la conexión, la configuración y el acceso al bot.');
        }
        return $response['result'];
    }
}
