<?php
declare(strict_types=1);
namespace App;

final class ClinicInfo
{
    public static function location(): array
    {
        $address=trim(Config::get('CLINICA_DIRECCION'));
        $city=trim(Config::get('CLINICA_CIUDAD','Santa Cruz de la Sierra, Bolivia'));
        $map=trim(Config::get('CLINICA_MAPA_URL'));
        $parts=parse_url($map);
        $valid=$map!=='' && strlen($map)<=2048 && !preg_match('/[\x00-\x20]/',$map)
            && is_array($parts) && ($parts['scheme'] ?? '')==='https'
            && !isset($parts['user']) && !isset($parts['pass'])
            && (!isset($parts['port']) || $parts['port']===443);
        if ($valid) {
            $host=strtolower($parts['host'] ?? ''); $path=$parts['path'] ?? '';
            $valid=in_array($host,['www.openstreetmap.org','openstreetmap.org','maps.google.com','maps.app.goo.gl'],true)
                || (in_array($host,['www.google.com','google.com'],true) && preg_match('#^/maps(?:/|$)#D',$path));
        }
        return ['address'=>$address,'city'=>$city,'map'=>$valid ? $map : null];
    }
}
