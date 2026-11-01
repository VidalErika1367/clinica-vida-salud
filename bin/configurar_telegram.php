<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
try {
    $client=new App\TelegramClient(); $me=$client->call('getMe');
    if (strcasecmp((string) ($me['username'] ?? ''),App\Config::get('TELEGRAM_BOT_USERNAME'))!==0) { throw new RuntimeException('El nombre y el token corresponden a bots diferentes.'); }
    if (App\Config::get('TELEGRAM_MODO','polling')==='webhook') {
        $url=rtrim(App\Config::get('APP_URL'),'/'); $secret=App\Config::get('TELEGRAM_WEBHOOK_SECRET');
        if (!preg_match('#^https://[A-Za-z0-9.-]+$#D',$url) || !preg_match('/^[A-Za-z0-9_-]{32,256}$/D',$secret)) { throw new RuntimeException('Configura APP_URL HTTPS y un secreto aleatorio de al menos 32 caracteres.'); }
        $client->call('setWebhook',['url'=>$url.'/telegram/webhook','secret_token'=>$secret,'allowed_updates'=>['message']]);
    } else { $client->call('deleteWebhook'); }
    echo "Bot verificado y modo de recepción configurado. Inicia el proceso de tareas.\n";
} catch (Throwable $e) { fwrite(STDERR,"No se pudo configurar Telegram. Revisa el token, el nombre del bot, APP_URL y el modo.\n"); exit(1); }
