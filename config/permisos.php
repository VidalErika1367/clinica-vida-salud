<?php
declare(strict_types=1);

// Operaciones propias de cada rol, según el catálogo CU del documento.
$cases = require __DIR__ . '/casos.php';
return [
    'administrador' => [
        'disponibles' => array_keys($cases),
        'obligatorios' => ['CU02', 'CU03', 'CU04', 'CU05'],
    ],
    'recepcionista' => [
        'disponibles' => ['CU02','CU03','CU06','CU11','CU12','CU13','CU14','CU15','CU16','CU17','CU18','CU19','CU20','CU22','CU23','CU24','CU25','CU26','CU28','CU29'],
        'obligatorios' => ['CU02', 'CU03'],
    ],
    'medico' => [
        'disponibles' => ['CU02','CU03','CU16','CU18'],
        'obligatorios' => ['CU02', 'CU03'],
    ],
    'paciente' => [
        'disponibles' => ['CU01','CU02','CU03','CU07','CU11','CU12','CU13','CU14','CU15','CU19','CU21','CU24','CU27','CU28','CU29','CU30'],
        'obligatorios' => ['CU02', 'CU03'],
    ],
];
