<?php
declare(strict_types=1);
namespace App;

final class CommunicationController
{
    private static function actor(string $permission): array
    {
        return AppointmentService::actor((int) Auth::requireUser()['id'],$permission);
    }
    public static function telegram(?string $link=null, array $errors=[]): void
    {
        $user=self::actor('CU27');
        View::render('telegram',['title'=>'Avisos por Telegram','section'=>'telegram','user'=>$user,'errors'=>$errors,
            'configured'=>TelegramClient::configured(),'link'=>$link,'binding'=>TelegramService::link((int) $user['paciente_id'])],$errors ? 422 : 200);
    }
    public static function issue(): void
    {
        $user=self::actor('CU27');
        try { self::telegram(TelegramService::issue($_POST,(int) $user['id'])); }
        catch (ValidationException $e) { self::telegram(null,$e->errors); }
    }
    public static function unlink(): void
    {
        $user=self::actor('CU27'); TelegramService::unlink((int) $user['id']);
        Session::flash('Avisos por Telegram desactivados. Tus avisos siguen disponibles en la web.'); redirect('/telegram');
    }
    public static function notices(array $errors=[]): void
    {
        $user=self::actor('CU28');
        try { $data=NoticeService::listing($user,$_GET); }
        catch (ValidationException $e) { throw new HttpException(422,implode(' ',$e->errors)); }
        View::render('notices',$data+['title'=>'Avisos del sistema','section'=>'avisos','user'=>$user,'errors'=>$errors,
            'paginationPath'=>'/avisos','paginationQuery'=>['estado'=>$data['state'],'paciente_id'=>$data['patientId']]],$errors ? 422 : 200);
    }
    public static function retry(int $id): void
    {
        $user=self::actor('CU28');
        try { NoticeService::retry($id,$_POST,(int) $user['id']); Session::flash('Reintento autorizado. El aviso quedó pendiente de envío.'); redirect('/avisos'); }
        catch (ValidationException $e) { self::notices($e->errors); }
    }
    public static function contact(): void
    {
        $user=self::actor('CU30');
        View::render('telegram_contact',['title'=>'Contactar con recepción','section'=>'contacto-recepcion','user'=>$user,'contact'=>TelegramService::contact()]);
    }
    public static function counter(int $id): void
    {
        $user=self::actor('CU29'); $data=CounterService::forAppointment($id,(int) $user['id']);
        header('Content-Type: application/json; charset=UTF-8'); echo json_encode($data,JSON_THROW_ON_ERROR);
    }
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
