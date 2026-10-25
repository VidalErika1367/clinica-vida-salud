<?php
declare(strict_types=1);
namespace App\Repositories;

use App\Database;

final class ProfileRepository
{
    public static function forUser(int $userId, bool $lock = false): array|false
    {
        return Database::run('SELECT p.*,s.tipo_sangre,s.alergias,s.enfermedades_cronicas,
            s.medicamentos,s.antecedentes,c.nombre AS emergencia_nombre,
            c.parentesco AS emergencia_parentesco,c.telefono AS emergencia_telefono
            FROM paciente p LEFT JOIN perfil_salud s ON s.paciente_id=p.id
            LEFT JOIN contacto_emergencia c ON c.paciente_id=p.id WHERE p.usuario_id=?'
            . ($lock ? ' FOR UPDATE OF p' : ''), [$userId])->fetch();
    }

    public static function save(int $patientId, array $data): void
    {
        $nullable = static fn(string $key): ?string => $data[$key] === '' ? null : $data[$key];
        Database::run('UPDATE paciente SET telefono=?,correo=?,direccion=? WHERE id=?',
            [$data['telefono'],$nullable('correo'),$nullable('direccion'),$patientId]);
        Database::run('INSERT INTO perfil_salud(paciente_id,tipo_sangre,alergias,enfermedades_cronicas,
            medicamentos,antecedentes) VALUES(?,?,?,?,?,?) ON CONFLICT(paciente_id) DO UPDATE SET
            tipo_sangre=EXCLUDED.tipo_sangre,alergias=EXCLUDED.alergias,
            enfermedades_cronicas=EXCLUDED.enfermedades_cronicas,
            medicamentos=EXCLUDED.medicamentos,antecedentes=EXCLUDED.antecedentes',
            [$patientId,$nullable('tipo_sangre'),$nullable('alergias'),$nullable('enfermedades_cronicas'),
             $nullable('medicamentos'),$nullable('antecedentes')]);
        if ($data['emergencia_nombre'] === '') {
            Database::run('DELETE FROM contacto_emergencia WHERE paciente_id=?', [$patientId]);
        } else {
            Database::run('INSERT INTO contacto_emergencia(paciente_id,nombre,parentesco,telefono)
                VALUES(?,?,?,?) ON CONFLICT(paciente_id) DO UPDATE SET nombre=EXCLUDED.nombre,
                parentesco=EXCLUDED.parentesco,telefono=EXCLUDED.telefono',
                [$patientId,$data['emergencia_nombre'],$data['emergencia_parentesco'],$data['emergencia_telefono']]);
        }
    }
}
