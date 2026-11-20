<?php
declare(strict_types=1);
namespace App;

use App\Repositories\AuditRepository;

/**
 * Exportación de reportes administrativos (CU20): descarga CSV compatible con Excel y una vista limpia
 * para imprimir o guardar como PDF. Usan los mismos filtros y validación que /reportes, pero incluyen
 * todas las filas sin paginar. Solo lectura: no cambian datos.
 */
final class ReportExportController
{
    /** Nombres de los reportes, como aparecen en la pantalla. */
    private const TIPOS = ['citas'=>'Actividad de citas','pacientes'=>'Pacientes registrados','pagos'=>'Pagos de multas'];

    /**
     * Descarga el reporte en CSV (GET /reportes/exportar?formato=csv). Separador «;», BOM UTF-8 para Excel
     * en español y una fila final con los totales.
     *
     * @return void
     * @throws HttpException 422 si el formato no es csv; 403 sin permiso CU20
     */
    public static function csv(): void
    {
        $actor = Auth::requireUser();
        if (($_GET['formato'] ?? '') !== 'csv') { throw new HttpException(422, 'Formato de exportación no válido.'); }
        $data = self::datos($actor);
        [$cabecera, $filas, $totales] = self::tabla($data);
        $f = $data['filters'];
        AuditRepository::record((int) $actor['id'], 'exportar_reporte_csv',
            'Exportó el reporte de ' . self::TIPOS[$data['type']] . ' del ' . $f['desde'] . ' al ' . $f['hasta'] . ' en CSV.');
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="reporte_' . $data['type'] . '_' . $f['desde'] . '_' . $f['hasta'] . '.csv"');
        echo "\xEF\xBB\xBF"; // BOM UTF-8: Excel reconoce las tildes y la ñ.
        $salida = fopen('php://output', 'w');
        fputcsv($salida, $cabecera, ';');
        foreach ($filas as $fila) { fputcsv($salida, array_map(fn($v) => self::celda($v), $fila), ';'); }
        fputcsv($salida, array_map(fn($v) => self::celda($v), $totales), ';');
        fclose($salida);
    }

    /**
     * Vista para imprimir o guardar como PDF (GET /reportes/imprimir). Sin menú; el botón llama a window.print().
     *
     * @return void
     * @throws HttpException 403 sin permiso CU20
     */
    public static function imprimir(): void
    {
        $actor = Auth::requireUser();
        $data = self::datos($actor);
        [$cabecera, $filas, $totales] = self::tabla($data);
        AuditRepository::record((int) $actor['id'], 'imprimir_reporte',
            'Abrió la vista para imprimir el reporte de ' . self::TIPOS[$data['type']] . ' del ' . $data['filters']['desde'] . ' al ' . $data['filters']['hasta'] . '.');
        $titulo = self::TIPOS[$data['type']];
        $filtrosTexto = self::filtrosTexto($data);
        $generado = clinic_datetime($data['generatedAt']);
        $usuario = (string) ($actor['nombre_usuario'] ?? '');
        require BASE_PATH . '/views/appointment_reportes_imprimir.php';
    }

    /**
     * Valida los filtros con la misma regla de /reportes y devuelve el reporte completo (sin paginar).
     * Si los filtros no son válidos, vuelve a /reportes para mostrar los errores junto a los datos escritos.
     *
     * @param array $actor usuario autenticado
     * @return array datos de AppointmentReadService::report
     */
    private static function datos(array $actor): array
    {
        try {
            return AppointmentReadService::report($_GET, (int) $actor['id'], true);
        } catch (ValidationException $e) {
            redirect('/reportes?' . http_build_query(array_filter($_GET, 'is_string')));
        }
    }

    /**
     * Arma las columnas, las filas y la fila de totales; la exportación y la impresión usan la misma tabla.
     *
     * @param array $data reporte completo
     * @return array [cabecera, filas, totales]
     */
    private static function tabla(array $data): array
    {
        $items = $data['listing']['items'];
        $s = $data['summary'];
        return match ($data['type']) {
            'citas' => [
                ['Inicio', 'Fin', 'Estado', 'Tipo', 'CI', 'Paciente', 'Médico', 'Especialidad'],
                array_map(fn($i) => [clinic_datetime($i['inicio']), clinic_datetime($i['fin']), $i['estado'], $i['tipo'],
                    $i['ci'], $i['paciente'], $i['medico'], $i['especialidad']], $items),
                ['TOTALES', 'Citas: ' . $s['total'], 'Programadas: ' . $s['programadas'], 'Confirmadas: ' . $s['confirmadas'],
                    'Atendidas: ' . $s['atendidas'], 'Canceladas: ' . $s['canceladas'], 'Inasistencias: ' . $s['inasistencias'],
                    'Pacientes con citas: ' . $s['pacientes']],
            ],
            'pagos' => [
                ['Fecha de pago', 'Monto (Bs)', 'Comprobante', 'Cita', 'Paciente', 'Médico'],
                array_map(fn($i) => [clinic_datetime($i['pagado_en']), self::monto($i['monto']), $i['comprobante'],
                    $i['cita_id'], $i['paciente'], $i['medico']], $items),
                ['TOTALES', 'Pagos: ' . $data['listing']['total'], 'Monto total (Bs): ' . self::monto($data['listing']['monto']), '', '', ''],
            ],
            'pacientes' => [
                ['Fecha de alta', 'CI', 'Nombres', 'Apellidos', 'Estado'],
                array_map(fn($i) => [clinic_datetime($i['creado_en']), $i['ci'], $i['nombres'], $i['apellidos'],
                    $i['activo'] ? 'Activo' : 'Inactivo'], $items),
                ['TOTALES', 'Registrados: ' . $data['listing']['total'], 'Activos: ' . $data['listing']['activos'],
                    'Inactivos: ' . $data['listing']['inactivos'], '', ''],
            ],
        };
    }

    /**
     * Texto de cada filtro aplicado, para el encabezado de la vista de impresión.
     *
     * @param array $data reporte completo
     * @return array pares [etiqueta, valor]
     */
    private static function filtrosTexto(array $data): array
    {
        $f = $data['filters'];
        $t = $data['type'];
        $lista = [
            ['Período', self::fecha($f['desde']) . ' al ' . self::fecha($f['hasta'])],
        ];
        if ($t === 'citas') { $lista[] = ['Estado', AppointmentReadService::STATES[$f['estado']]]; }
        if ($t === 'pacientes') {
            $lista[] = ['Estado del paciente', ['todos'=>'Todos','activos'=>'Activos','inactivos'=>'Inactivos'][$f['estado_paciente']]];
        } else {
            $lista[] = ['Médico', self::nombreDe($data['doctors'], $f['medico_id'], 'Todos los médicos')];
            $lista[] = ['Especialidad', self::nombreDe($data['specialties'], $f['especialidad_id'], 'Todas las especialidades')];
        }
        return $lista;
    }

    /**
     * Nombre de un registro del catálogo por su id.
     *
     * @param array $catalogo filas con id y nombre
     * @param int|null $id id buscado
     * @param string $todos texto si no hay filtro
     * @return string nombre o el texto por defecto
     */
    private static function nombreDe(array $catalogo, ?int $id, string $todos): string
    {
        if ($id === null) { return $todos; }
        foreach ($catalogo as $fila) {
            if ((int) $fila['id'] === $id) { return (string) $fila['nombre']; }
        }
        return $todos;
    }

    /**
     * Fecha Y-m-d a dd/mm/aaaa.
     *
     * @param string $fecha fecha validada
     * @return string fecha en formato de Bolivia
     */
    private static function fecha(string $fecha): string
    {
        return (new \DateTimeImmutable($fecha))->format('d/m/Y');
    }

    /**
     * Importe con coma decimal, como lo muestra la pantalla de pagos.
     *
     * @param string|int|float|null $monto importe de la base de datos
     * @return string importe, p. ej. "80,00"
     */
    private static function monto(string|int|float|null $monto): string
    {
        return str_replace('.', ',', number_format((float) $monto, 2, '.', ''));
    }

    /**
     * Protege las celdas CSV contra la inyección de fórmulas: un valor que empieza por = + - @ (o tabulador
     * y retorno de carro) se antepone con una comilla simple para que Excel lo muestre como texto.
     *
     * @param mixed $valor contenido de la celda
     * @return string texto seguro para la celda
     */
    private static function celda(mixed $valor): string
    {
        $texto = (string) ($valor ?? '');
        return $texto !== '' && strpbrk($texto[0], "=+-@\t\r") !== false ? "'" . $texto : $texto;
    }
}
