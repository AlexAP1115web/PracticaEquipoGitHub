<?php

/* =====================================================
   MEDICORE - ETAPA 3 DEL PIPELINE: PRUEBAS DE HUMO
   Levanta el sistema con el servidor de PHP y revisa que cada módulo responda bien:
   páginas públicas, protección de sesión, inicio de sesión, módulos del médico, APIs,
   tablas creadas por la app y cierre de sesión.
   Uso: php pruebas/humo.php http://127.0.0.1:8080
   Variables: DB_*, CI_CORREO, CI_PASSWORD
===================================================== */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/') . '/';
$galletas = [];   // cookies de la sesión, como las guardaría el navegador
$resultados = [];

// Cualquiera de estos textos en una página es un error de PHP o de base de datos
const ERRORES_PHP = '/(Fatal error|Parse error|Warning<\/b>:|Notice<\/b>:|Deprecated<\/b>:|Uncaught|Unknown column|doesn\'t exist|Error de infraestructura)/i';

function pedir($ruta, $opciones = [])
{
    global $base, $galletas;

    $cabeceras = $opciones['cabeceras'] ?? [];
    $cabeceras[] = 'User-Agent: MediCore-Pruebas/1.0';

    if ($galletas) {
        $cabeceras[] = 'Cookie: ' . implode('; ', array_map(function ($n, $v) {
            return "$n=$v";
        }, array_keys($galletas), $galletas));
    }

    $ch = curl_init($base . ltrim($ruta, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $cabeceras,
        CURLOPT_HEADERFUNCTION => function ($ch, $linea) use (&$galletas) {
            if (preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $linea, $c)) {
                if ($c[2] === '' || $c[2] === 'deleted') {
                    unset($galletas[$c[1]]);
                } else {
                    $galletas[$c[1]] = $c[2];
                }
            }
            return strlen($linea);
        }
    ]);

    if (isset($opciones['post'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opciones['post']);
    }

    $cuerpo = (string) curl_exec($ch);
    $respuesta = [
        'codigo'  => curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'destino' => (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL),
        'ms'      => round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000),
        'cuerpo'  => $cuerpo
    ];
    curl_close($ch);

    return $respuesta;
}

function revisar($grupo, $nombre, $ok, $r = null, $detalle = '')
{
    global $resultados;

    $resultados[] = [
        'grupo'   => $grupo,
        'nombre'  => $nombre,
        'ok'      => (bool) $ok,
        'codigo'  => $r['codigo'] ?? '',
        'ms'      => $r['ms'] ?? '',
        'detalle' => $detalle
    ];

    printf("%s  %-14s %-46s %s %s\n", $ok ? 'OK   ' : 'FALLA', $grupo, $nombre, $r ? "{$r['codigo']} {$r['ms']} ms" : '', $detalle);
}

function sinErrores($r)
{
    if (preg_match(ERRORES_PHP, $r['cuerpo'], $m)) {
        return 'contiene "' . $m[1] . '"';
    }
    return '';
}

function paginaBien($grupo, $ruta, $codigoEsperado = 200)
{
    $r = pedir($ruta);
    $error = sinErrores($r);
    revisar($grupo, $ruta, $r['codigo'] === $codigoEsperado && $error === '', $r, $error);
    return $r;
}

/* 1. Páginas públicas */
foreach (['home.php', 'login.php', 'aviso_privacidad.php', 'offline.html', 'manifest.json'] as $ruta) {
    paginaBien('Públicas', $ruta);
}

$salud = pedir('salud.php');
$json = json_decode($salud['cuerpo'], true);
revisar('Públicas', 'salud.php (estado y base de datos)', $salud['codigo'] === 200 && ($json['estado'] ?? '') === 'ok' && ($json['base_de_datos'] ?? '') === 'ok', $salud);

/* 2. Sin sesión, los módulos regresan al login */
foreach (['index.php', 'pacientes.php', 'expediente.php?id=1', 'reportes.php'] as $ruta) {
    $r = pedir($ruta);
    revisar('Sin sesión', $ruta . ' manda al login', in_array($r['codigo'], [301, 302, 303], true) && strpos($r['destino'], 'login.php') !== false, $r);
}

$r = pedir('api/mapa_corporal.php?paciente_id=1');
revisar('Sin sesión', 'api/mapa_corporal.php responde 401', $r['codigo'] === 401, $r);

/* 3. Inicio de sesión */
$login = pedir('login.php');
preg_match('/name="csrf_token" value="([^"]+)"/', $login['cuerpo'], $token);

$r = pedir('login.php', ['post' => http_build_query([
    'csrf_token' => $token[1] ?? '',
    'correo'     => getenv('CI_CORREO') ?: 'ci@medicore.test',
    'password'   => getenv('CI_PASSWORD') ?: 'PruebaCI-2026',
    'login'      => '1'
])]);
revisar('Sesión', 'Inicio de sesión del médico de prueba', in_array($r['codigo'], [302, 303], true) && strpos($r['destino'], 'index.php') !== false, $r);

/* 4. Módulos con sesión (la primera visita también crea las tablas nuevas) */
$modulos = [
    'index.php', 'pacientes.php', 'nuevo_paciente.php', 'expediente.php?id=1', 'agenda.php', 'reportes.php',
    'recomendaciones.php', 'chatbot.php', 'catalogo_enfermedades.php', 'ubicacion.php', 'infografia.php',
    'cache.php', 'configuracion.php', 'mapa_corporal.php?id=1', 'mapa_corporal.php?id=1&modo=paciente'
];

foreach ($modulos as $ruta) {
    paginaBien('Módulos', $ruta);
}

/* Reporte en PDF (mPDF por Composer): en la auditoría del Producto 1 no se generaba por la dependencia faltante */
$pdf = pedir('reporte_pdf.php');
revisar('Módulos', 'reporte_pdf.php genera un PDF', $pdf['codigo'] === 200 && strncmp($pdf['cuerpo'], '%PDF', 4) === 0, $pdf, strncmp($pdf['cuerpo'], '%PDF', 4) === 0 ? '' : 'no regresó un PDF');

/* 5. Tablas que crea config/esquema.php */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$bd = new mysqli(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASS'), getenv('DB_NAME'), (int) getenv('DB_PORT'));

foreach (['chatbot_consultas', 'push_suscripciones', 'push_mensajes', 'push_recordatorios', 'notas_consulta', 'mapa_registros', 'mapa_molestias'] as $tabla) {
    $existe = $bd->query("SHOW TABLES LIKE '$tabla'")->num_rows === 1;
    revisar('Esquema', "Tabla $tabla", $existe);
}

$columna = $bd->query("SHOW COLUMNS FROM usuarios LIKE 'alergias'")->num_rows === 1;
revisar('Esquema', 'Columna usuarios.alergias', $columna);

/* 6. APIs con sesión y token CSRF */
$mapa = pedir('mapa_corporal.php?id=1');
preg_match('/<script type="application\/json" id="mapaDatos">(.*?)<\/script>/s', $mapa['cuerpo'], $m);
$datosMapa = json_decode($m[1] ?? '', true);
$csrf = $datosMapa['csrf'] ?? '';

$registro = json_encode([
    'id_cliente'  => sprintf('%08x-%04x-4%03x-a%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff)),
    'paciente_id' => 1,
    'molestias'   => [['zona' => 'rodilla_der', 'sensacion' => 'dolor', 'intensidad' => 6, 'desde' => date('Y-m-d', strtotime('-3 days'))]]
]);

$r = pedir('api/mapa_corporal.php?accion=guardar', ['post' => $registro, 'cabeceras' => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]]);
$res = json_decode($r['cuerpo'], true);
revisar('APIs', 'Guardar registro del mapa corporal', $r['codigo'] === 200 && !empty($res['ok']), $r);

$r = pedir('api/mapa_corporal.php?accion=guardar', ['post' => $registro, 'cabeceras' => ['Content-Type: application/json']]);
revisar('APIs', 'Rechaza peticiones sin token CSRF', $r['codigo'] === 403, $r);

$r = pedir('api/mapa_corporal.php?accion=guardar', ['post' => json_encode(['id_cliente' => 'x', 'paciente_id' => 1]), 'cabeceras' => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]]);
revisar('APIs', 'Rechaza datos inválidos', $r['codigo'] === 422, $r);

$r = pedir('api/notas.php?accion=guardar');
revisar('APIs', 'api/notas.php solo acepta POST', $r['codigo'] === 405, $r);

/* 7. Cierre de sesión */
pedir('logout.php');
$r = pedir('index.php');
revisar('Sesión', 'Después de cerrar sesión regresa al login', in_array($r['codigo'], [301, 302, 303], true), $r);

/* Resumen */
$fallas = array_filter($resultados, function ($x) {
    return !$x['ok'];
});
$total = count($resultados);
$tiempos = array_filter(array_column($resultados, 'ms'), 'is_numeric');
$promedio = $tiempos ? round(array_sum($tiempos) / count($tiempos)) : 0;

printf("\nPruebas: %d. Correctas: %d. Fallidas: %d. Tiempo promedio de respuesta: %d ms.\n", $total, $total - count($fallas), count($fallas), $promedio);

if ($resumen = getenv('GITHUB_STEP_SUMMARY')) {
    $md = "### Etapa 3: pruebas de humo\n\n";
    $md .= "**$total pruebas, " . ($total - count($fallas)) . " correctas, " . count($fallas) . " fallidas.** Tiempo promedio de respuesta: $promedio ms.\n\n";
    $md .= "| Grupo | Prueba | Código | Tiempo | Resultado |\n|---|---|---|---|---|\n";
    foreach ($resultados as $x) {
        $md .= "| {$x['grupo']} | " . str_replace('|', '\\|', $x['nombre']) . " | {$x['codigo']} | " . ($x['ms'] !== '' ? "{$x['ms']} ms" : '') . " | " . ($x['ok'] ? 'Correcta' : 'Falló ' . $x['detalle']) . " |\n";
    }
    file_put_contents($resumen, $md, FILE_APPEND);
}

exit($fallas ? 1 : 0);
