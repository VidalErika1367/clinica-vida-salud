<?php
declare(strict_types=1);
namespace App;

final class ProfileController
{
    public static function form(): void
    {
        $user = Auth::requireRolePermission('CU07', ['paciente']);
        $profile = ProfileService::findForUser((int) $user['id'], false, $_GET);
        self::render($profile, ProfileService::values($profile));
    }

    private static function render(array $profile, array $old, array $errors = [], ?string $version = null): void
    {
        View::render('profile', ['title'=>'Mi perfil','section'=>'perfil','profile'=>$profile,
            'old'=>$old,'errors'=>$errors,'version'=>$version ?? ProfileService::version($profile)], $errors ? 422 : 200);
    }

    public static function save(): void
    {
        $user = Auth::requireRolePermission('CU07', ['paciente']);
        try {
            ProfileService::save($_POST, (int) $user['id']);
            Session::flash('Los cambios de tu perfil se guardaron.');
            redirect('/mi-perfil');
        } catch (ValidationException $e) {
            $profile = ProfileService::findForUser((int) $user['id']);
            $stale = isset($e->errors['version']);
            self::render($profile, ProfileService::values($stale ? $profile : $_POST), $e->errors,
                $stale ? null : $_POST['version']);
        }
    }
}
