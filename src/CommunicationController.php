<?php
declare(strict_types=1);
namespace App;

/**
 * Comunicación: vinculación con Telegram (CU27), avisos del sistema (CU28),
 * contador de la cita (CU29), contacto con recepción (CU30) y el webhook del bot.
 * Los avisos no contienen datos clínicos; el contacto se limita al canal oficial de recepción.
 */
final class CommunicationController
{
    /**
     * Exige sesión y el permiso indicado.
     *
     * @param string $permission código CU requerido
     * @return array actor autorizado
     * @throws HttpException 403 sin permiso
     */
    private static function actor(string $permission): array
    {
        return AppointmentService::actor((int) Auth::requireUser()['id'],$permission);
    }
    /**
     * Pantalla de vinculación con Telegram del paciente (CU27).
     *
     * @param string|null $link enlace de vinculación recién generado
     * @param array $errors errores de validación
     * @return void
     * @throws HttpException 403 sin permiso CU27
     */
    public static function telegram(?string $link=null, array $errors=[]): void
    {
        $user=self::actor('CU27');
        View::render('telegram',['title'=>'Avisos por Telegram','section'=>'telegram','user'=>$user,'errors'=>$errors,
            'configured'=>TelegramClient::configured(),'link'=>$link,'binding'=>TelegramService::link((int) $user['paciente_id'])],$errors ? 422 : 200);
    }
    /**
     * Genera el enlace de vinculación con Telegram (CU27). El enlace caduca.
     *
     * @return void
     * @throws HttpException 403 sin permiso CU27
     */
    public static function issue(): void
    {
        $user=self::actor('CU27');
        try { self::telegram(TelegramService::issue($_POST,(int) $user['id'])); }
        catch (ValidationException $e) { self::telegram(null,$e->errors); }
    }
    /**
     * Desactiva los avisos por Telegram del paciente (CU27). Los avisos siguen disponibles en la web.
     *
     * @return void
     * @throws HttpException 403 sin permiso CU27
     */
    public static function unlink(): void
    {
        $user=self::actor('CU27'); TelegramService::unlink((int) $user['id']);
        Session::flash('Avisos por Telegram desactivados. Tus avisos siguen disponibles en la web.'); redirect('/telegram');
    }
    /**
     * Lista de avisos del sistema con filtros por estado y paciente (CU28).
     *
     * @param array $errors errores a mostrar
     * @return void
     * @throws HttpException 422 si los filtros no son válidos
     */
    public static function notices(array $errors=[]): void
    {
        $user=self::actor('CU28');
        try { $data=NoticeService::listing($user,$_GET); }
        catch (ValidationException $e) { throw new HttpException(422,implode(' ',$e->errors)); }
        View::render('notices',$data+['title'=>'Avisos del sistema','section'=>'avisos','user'=>$user,'errors'=>$errors,
            'paginationPath'=>'/avisos','paginationQuery'=>['estado'=>$data['state'],'paciente_id'=>$data['patientId']]],$errors ? 422 : 200);
    }
    /**
     * Autoriza el reintento de un aviso fallido (CU28). No envía nada de inmediato: queda pendiente.
     *
     * @param int $id aviso
     * @return void
     * @throws HttpException 403 sin permiso CU28
     */
    public static function retry(int $id): void
    {
        $user=self::actor('CU28');
        try { NoticeService::retry($id,$_POST,(int) $user['id']); Session::flash('Reintento autorizado. El aviso quedó pendiente de envío.'); redirect('/avisos'); }
        catch (ValidationException $e) { self::notices($e->errors); }
    }
    /**
     * Muestra el contacto oficial de recepción por Telegram (CU30). No envía mensajes automáticamente.
     *
     * @return void
     * @throws HttpException 403 sin permiso CU30
     */
    public static function contact(): void
    {
        $user=self::actor('CU30');
        View::render('telegram_contact',['title'=>'Contactar con recepción','section'=>'contacto-recepcion','user'=>$user,'contact'=>TelegramService::contact()]);
    }
    /**
     * Devuelve en JSON los contadores de la cita para actualizar la pantalla (CU29).
     *
     * @param int $id cita
     * @return void
     * @throws HttpException 403 sin permiso CU29 o si la cita no es visible
     */
    public static function counter(int $id): void
    {
        $user=self::actor('CU29'); $data=CounterService::forAppointment($id,(int) $user['id']);
        header('Content-Type: application/json; charset=UTF-8'); echo json_encode($data,JSON_THROW_ON_ERROR);
    }
    /**
     * Recibe las actualizaciones de Telegram. No usa sesión ni CU: se autentica con el secreto del webhook.
     * Porqué: Telegram no envía cookies; el secreto impide que cualquiera publique mensajes falsos.
     *
     * @return void
     * @throws HttpException 403 si el secreto no coincide; 422 si el cuerpo no es un JSON válido
     */
    public static function webhook(): void
    {
        if (!TelegramService::webhookAuthorized($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? null)) { throw new HttpException(403,'Acceso no autorizado.'); }
        $raw=file_get_contents('php://input',false,null,0,65537);
        $data=is_string($raw) && strlen($raw)<=65536 ? json_decode($raw,true) : null;
        if (!is_array($data)) { throw new HttpException(422,'Actualización inválida.'); }
        TelegramService::update($data);
        header('Content-Type: application/json; charset=UTF-8'); echo '{"ok":true}';
    }
}
