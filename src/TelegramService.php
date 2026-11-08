<?php
declare(strict_types=1);
namespace App;
use App\Repositories\AuditRepository;
use App\Repositories\PatientRepository;
use App\Repositories\UserRepository;

/**
 * Vinculación del paciente con Telegram (CU27): token de un solo uso con caducidad de 10 minutos,
 * consentimiento explícito, desactivación y procesamiento de comandos del bot (/start, /stop).
 */
final class TelegramService
{
    /**
     * Estado de la vinculación del paciente.
     *
     * @param int $patient paciente
     * @return array|false datos de activo y consentimiento, o false si nunca se vinculó
     */
    public static function link(int $patient): array|false
    {
        return Database::run('SELECT activo,consentido_en FROM vinculo_telegram WHERE paciente_id=?',[$patient])->fetch();
    }
    /**
     * Genera un enlace de vinculación de un solo uso (CU27). Invalida los enlaces anteriores del paciente.
     * Porqué: solo se guarda el hash del token; el token en claro viaja únicamente en el enlace que se entrega al paciente.
     *
     * @param array $input debe incluir el consentimiento explícito
     * @param int $user usuario paciente
     * @return string enlace https://t.me con el token
     * @throws ValidationException si el bot no está configurado o falta el consentimiento
     * @throws HttpException 403 si la cuenta no tiene ficha de paciente
     */
    public static function issue(array $input, int $user): string
    {
        if (!TelegramClient::configured()) { throw new ValidationException(['general'=>'La clínica todavía no habilitó su bot de avisos.']); }
        CareSupport::confirm($input,'consentimiento','Autoriza los avisos antes de vincular Telegram.');
        return CareSupport::transaction(function () use ($user): string {
            UserRepository::lockAccounts(); $actor=AppointmentService::actor($user,'CU27'); $id=(int) $actor['paciente_id'];
            if (!$id) { throw new HttpException(403,'La cuenta no tiene ficha de paciente.'); }
            PatientRepository::find($id,true);
            Database::run('UPDATE token_vinculacion SET usado_en=now() WHERE paciente_id=? AND usado_en IS NULL',[$id]);
            $token=bin2hex(random_bytes(32));
            Database::run("INSERT INTO token_vinculacion(token_hash,paciente_id,caduca_en) VALUES(?,?,now()+interval '10 minutes')",[hash('sha256',$token),$id]);
            AuditRepository::record($user,'autorizar_telegram','Autoriza vincular los avisos con su chat privado de Telegram.',$id);
            return 'https://t.me/'.Config::get('TELEGRAM_BOT_USERNAME').'?start='.$token;
        });
    }
    /**
     * Desactiva los avisos por Telegram del paciente (CU27) e invalida los tokens pendientes.
     *
     * @param int $user usuario paciente
     * @return void
     * @throws HttpException 403 si la cuenta no tiene ficha de paciente
     */
    public static function unlink(int $user): void
    {
        CareSupport::transaction(function () use ($user): void {
            UserRepository::lockAccounts(); $actor=AppointmentService::actor($user,'CU27'); $id=(int) $actor['paciente_id'];
            if (!$id) { throw new HttpException(403,'La cuenta no tiene ficha de paciente.'); }
            PatientRepository::find($id,true);
            Database::run('UPDATE vinculo_telegram SET activo=false WHERE paciente_id=?',[$id]);
            Database::run('UPDATE token_vinculacion SET usado_en=now() WHERE paciente_id=? AND usado_en IS NULL',[$id]);
            AuditRepository::record($user,'desactivar_telegram','Desactiva el envío de avisos por Telegram.',$id);
        });
    }
    /**
     * Verifica el secreto del webhook con comparación en tiempo constante.
     *
     * @param mixed $header valor de la cabecera X-Telegram-Bot-Api-Secret-Token
     * @return bool true si el bot está configurado y el secreto coincide
     */
    public static function webhookAuthorized(mixed $header): bool
    {
        $secret=Config::get('TELEGRAM_WEBHOOK_SECRET');
        return TelegramClient::configured() && preg_match('/^[A-Za-z0-9_-]{32,256}$/D',$secret)
            && is_string($header) && hash_equals($secret,$header);
    }
    /**
     * Procesa una actualización de Telegram: vincula el chat con un token válido o atiende /stop.
     * Porqué: cada update_id se registra una sola vez (idempotencia); solo se aceptan chats privados
     * cuyo remitente coincide con el chat, y el token se consume al usarse.
     *
     * Solo se invoca desde el webhook autenticado o el proceso CLI de Telegram.
     *
     * @param array $update actualización recibida
     * @return bool true si se aplicó un cambio
     * @throws HttpException 422 si el identificador de la actualización no es válido
     */
    public static function update(array $update): bool
    {
        $id=$update['update_id'] ?? null;
        $message=is_array($update['message'] ?? null) ? $update['message'] : [];
        if (!is_int($id) || $id<0) { throw new HttpException(422,'Actualización inválida.'); }
        $chat=$message['chat']['id'] ?? null; $from=$message['from']['id'] ?? null; $text=$message['text'] ?? '';
        return CareSupport::transaction(function () use ($id,$message,$chat,$from,$text): bool {
            UserRepository::lockAccounts();
            if (!Database::run('INSERT INTO telegram_actualizacion(update_id) VALUES(?) ON CONFLICT DO NOTHING RETURNING update_id',[$id])->fetch()) { return false; }
            if (($message['chat']['type'] ?? '')!=='private' || !is_int($chat) || $chat<=0 || $chat!==$from || !is_string($text)) { return false; }
            if ($text==='/stop') {
                $row=Database::run('SELECT v.paciente_id,p.usuario_id FROM vinculo_telegram v JOIN paciente p ON p.id=v.paciente_id WHERE v.chat_id=?',[$chat])->fetch();
                if ($row) {
                    PatientRepository::find((int) $row['paciente_id'],true);
                    Database::run('UPDATE vinculo_telegram SET activo=false WHERE chat_id=?',[$chat]);
                    Database::run('UPDATE token_vinculacion SET usado_en=now() WHERE paciente_id=? AND usado_en IS NULL',[(int) $row['paciente_id']]);
                    if ($row['usuario_id']) { AuditRepository::record((int) $row['usuario_id'],'desactivar_telegram','Revoca los avisos mediante /stop.',(int) $row['paciente_id']); }
                }
                return true;
            }
            if (!preg_match('/^\/start(?:@[A-Za-z0-9_]+)? ([a-f0-9]{64})$/D',$text,$m)) { return false; }
            $hash=hash('sha256',$m[1]);
            $token=Database::run('SELECT * FROM token_vinculacion WHERE token_hash=? AND usado_en IS NULL AND caduca_en>now()',[$hash])->fetch();
            if (!$token) { return false; }
            $patient=PatientRepository::find((int) $token['paciente_id'],true);
            if (!$patient || !$patient['usuario_id']) { return false; }
            try { AppointmentService::actor((int) $patient['usuario_id'],'CU27'); }
            catch (HttpException) { return false; }
            $bound=Database::run('SELECT paciente_id FROM vinculo_telegram WHERE chat_id=?',[$chat])->fetchColumn();
            if ($bound && (int) $bound!==(int) $patient['id']) { return false; }
            Database::run('INSERT INTO vinculo_telegram(paciente_id,chat_id) VALUES(?,?) ON CONFLICT(paciente_id)
                DO UPDATE SET chat_id=EXCLUDED.chat_id,activo=true,consentido_en=now()',[(int) $patient['id'],$chat]);
            Database::run('UPDATE token_vinculacion SET usado_en=now() WHERE paciente_id=? AND usado_en IS NULL',[(int) $patient['id']]);
            Database::run("INSERT INTO aviso(paciente_id,clave_evento,tipo,texto) VALUES(?,?,'telegram_vinculado',
                'Su cuenta quedó vinculada a los avisos de Clínica Vida Salud. Puede desactivarlos en la web o enviar /stop al bot.')",[(int) $patient['id'],'telegram:'.$id]);
            AuditRepository::record((int) $patient['usuario_id'],'vincular_telegram','Confirma la vinculación de su chat privado mediante código de un solo uso.',(int) $patient['id']);
            return true;
        });
    }
}
