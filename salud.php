<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   PUNTO DE SALUD (/salud.php)
   Lo usan el healthcheck de Railway al desplegar y el monitoreo programado de GitHub Actions.
   Responde 200 si PHP y MySQL contestan y 503 si la base no responde.
   No usa sesión ni muestra datos de pacientes.
===================================================== */

require_once __DIR__ . '/config/apis.php';

date_default_timezone_set('America/Mexico_City');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$inicio = microtime(true);
$commit = (string) getenv('RAILWAY_GIT_COMMIT_SHA');

$salud = [
    'estado'   => 'ok',
    'servicio' => 'MediCore Professional System',
    'version'  => $commit !== '' ? substr($commit, 0, 7) : 'local',
    'entorno'  => getenv('RAILWAY_ENVIRONMENT_NAME') ?: 'local',
    'php'      => PHP_VERSION,
    'hora'     => date('c')
];

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $inicioBd = microtime(true);
    $bd = mysqli_init();
    $bd->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    $bd->real_connect(
        apiConfig('DB_HOST', 'localhost'),
        apiConfig('DB_USER', 'root'),
        apiConfig('DB_PASS', ''),
        apiConfig('DB_NAME', 'MediCore_db'),
        (int) apiConfig('DB_PORT', '3306')
    );
    $bd->query('SELECT 1');
    $bd->close();

    $salud['base_de_datos'] = 'ok';
    $salud['bd_ms'] = round((microtime(true) - $inicioBd) * 1000, 1);
} catch (Throwable $e) {
    $salud['estado'] = 'error';
    $salud['base_de_datos'] = 'sin conexión';
    http_response_code(503);
}

$salud['respuesta_ms'] = round((microtime(true) - $inicio) * 1000, 1);

echo json_encode($salud, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
