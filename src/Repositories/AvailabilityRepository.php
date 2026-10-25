<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Database;

final class AvailabilityRepository
{
    public static function clock(): array
    {
        return Database::run("SELECT now() AS ahora,(now() AT TIME ZONE 'America/La_Paz')::date AS hoy")->fetch();
    }
    public static function doctors(int $specialty): array
    {
        return Database::run('SELECT m.id,m.nombres,m.apellidos FROM medico m
            JOIN medico_especialidad me ON me.medico_id=m.id JOIN especialidad e ON e.id=me.especialidad_id
            WHERE e.id=? AND e.activo AND m.activo AND me.activo ORDER BY m.apellidos,m.nombres,m.id',[$specialty])->fetchAll();
    }
    public static function eligible(int $specialty, int $doctor): bool
    {
        return (bool) Database::run('SELECT 1 FROM medico m JOIN medico_especialidad me ON me.medico_id=m.id
            JOIN especialidad e ON e.id=me.especialidad_id WHERE m.id=? AND e.id=?
            AND m.activo AND me.activo AND e.activo',[$doctor,$specialty])->fetchColumn();
    }
    public static function slots(int $specialty, int $doctor, string $date, ?int $ignore=null): array
    {
        return Database::run("WITH tramos AS (
            SELECT (?::date+h.hora_inicio) AT TIME ZONE 'America/La_Paz' AS inicio,
                   (?::date+h.hora_fin) AT TIME ZONE 'America/La_Paz' AS fin
            FROM horario h JOIN medico m ON m.id=h.medico_id
            JOIN medico_especialidad me ON me.medico_id=m.id JOIN especialidad e ON e.id=me.especialidad_id
            WHERE m.id=? AND e.id=? AND h.activo AND m.activo AND me.activo AND e.activo
            AND h.dia_semana=extract(isodow FROM ?::date))
            SELECT t.inicio,t.inicio+interval '30 minutes' AS fin FROM tramos r
            CROSS JOIN LATERAL generate_series(r.inicio,r.fin-interval '30 minutes',interval '30 minutes') t(inicio)
            WHERE t.inicio>now() AND NOT EXISTS(SELECT 1 FROM cita c WHERE c.medico_id=?
                AND c.id<>? AND c.estado IN ('programada','confirmada','atendida')
                AND tstzrange(c.inicio,c.fin,'[)') && tstzrange(t.inicio,t.inicio+interval '30 minutes','[)'))
            AND NOT EXISTS(SELECT 1 FROM ausencia_medico a WHERE a.medico_id=?
                AND tstzrange(a.inicio,a.fin,'[)') && tstzrange(t.inicio,t.inicio+interval '30 minutes','[)'))
            ORDER BY t.inicio",[$date,$date,$doctor,$specialty,$date,$doctor,$ignore ?? 0,$doctor])->fetchAll();
    }
}
