<?php
declare(strict_types=1);
namespace App;

/**
 * Perfil de salud y contacto de emergencia del propio paciente (CU07).
 * Solo accesible con rol paciente; no permite ver ni cambiar los datos de otro paciente.
 */
final class ProfileController
{
    /**
     * Muestra el perfil del paciente autenticado (CU07).
     *
     * @return void
     * @throws HttpException 403 si no es paciente o no tiene permiso CU07
     */
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

    /**
     * Guarda los cambios del perfil del paciente (CU07). Usa control de versión para no sobrescribir cambios ajenos.
     *
     * @return void
     * @throws HttpException 403 si no es paciente o no tiene permiso CU07
     */
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
