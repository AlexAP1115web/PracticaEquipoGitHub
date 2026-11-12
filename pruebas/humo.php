<?php

/* =====================================================
   MEDICORE - ETAPA 3 DEL PIPELINE: PRUEBAS DE HUMO
   Levanta el sistema con el servidor de PHP y revisa que cada módulo responda bien:
   páginas públicas, protección de sesión, inicio de sesión, módulos del médico, APIs,
   tablas creadas por la app, zona horaria de las citas y cierre de sesión.
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

// Pre-consulta: un enlace que no existe muestra un aviso amable (404), sin errores de PHP
paginaBien('Públicas', 'preconsulta.php?t=' . str_repeat('0', 48), 404);

$salud = pedir('salud.php');
$json = json_decode($salud['cuerpo'], true);
revisar('Públicas', 'salud.php (estado y base de datos)', $salud['codigo'] === 200 && ($json['estado'] ?? '') === 'ok' && ($json['base_de_datos'] ?? '') === 'ok', $salud);

// Incidente del 26/10: MySQL en UTC y PHP en hora de México. En GitHub Actions MySQL también está
// en UTC, así que si alguien quita la corrección esta prueba falla a cualquier hora del día
$desfase = $json['desfase_hora_bd_s'] ?? null;
revisar('Públicas', 'La base de datos usa la hora de México', is_int($desfase) && abs($desfase) <= 120, $salud, is_int($desfase) ? "desfase de $desfase s" : 'sin dato de hora');

/* 2. Sin sesión, los módulos regresan al login */
foreach (['index.php', 'pacientes.php', 'expediente.php?id=1', 'reportes.php'] as $ruta) {
    $r = pedir($ruta);
    revisar('Sin sesión', $ruta . ' manda al login', in_array($r['codigo'], [301, 302, 303], true) && strpos($r['destino'], 'login.php') !== false, $r);
}

$r = pedir('api/mapa_corporal.php?paciente_id=1');
revisar('Sin sesión', 'api/mapa_corporal.php responde 401', $r['codigo'] === 401, $r);

$r = pedir('api/preconsulta.php?accion=crear', ['post' => '{"paciente_id":1}', 'cabeceras' => ['Content-Type: application/json']]);
revisar('Sin sesión', 'Generar pre-consulta responde 401', $r['codigo'] === 401, $r);

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

foreach (['chatbot_consultas', 'push_suscripciones', 'push_mensajes', 'push_recordatorios', 'notas_consulta', 'mapa_registros', 'mapa_molestias', 'preconsultas', 'traslados'] as $tabla) {
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

/* Pre-consulta por QR: el médico genera el enlace y el paciente lo contesta sin cuenta */
$r = pedir('api/preconsulta.php?accion=crear', ['post' => '{"paciente_id":1}', 'cabeceras' => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]]);
$pre = json_decode($r['cuerpo'], true);
revisar('APIs', 'Generar enlace de pre-consulta', $r['codigo'] === 200 && !empty($pre['ok']) && preg_match('/^preconsulta\.php\?t=[a-f0-9]{48}$/', $pre['ruta'] ?? ''), $r);

$ruta = $pre['ruta'] ?? 'preconsulta.php?t=x';
$pagina = pedir($ruta);
revisar('APIs', 'Página de pre-consulta del paciente', $pagina['codigo'] === 200 && strpos($pagina['cuerpo'], 'id="preForm"') !== false && sinErrores($pagina) === '', $pagina, sinErrores($pagina));

preg_match('/<script type="application\/json" id="mapaDatos">(.*?)<\/script>/s', $pagina['cuerpo'], $m);
$datosPre = json_decode($m[1] ?? '', true);
$respuesta = json_encode([
    'token'      => $datosPre['token'] ?? '',
    'id_cliente' => sprintf('%08x-%04x-4%03x-a%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff)),
    'respuestas' => [
        'motivo' => 'Dolor de garganta y fiebre', 'inicio' => 'dias', 'medicamentos' => 'Amoxicilina 500 mg', 'alergias' => 'Penicilina', 'pregunta' => '',
        'traslado' => ['tipo' => 'ida_vuelta', 'direccion' => 'Av. Reforma 123, Centro, Puebla', 'notas' => 'Usa bastón']
    ],
    'molestias'  => [['zona' => 'cuello', 'sensacion' => 'ardor', 'intensidad' => 5, 'desde' => date('Y-m-d', strtotime('-2 days'))]]
]);

$r = pedir('api/preconsulta.php?accion=responder', ['post' => $respuesta, 'cabeceras' => ['Content-Type: application/json', 'X-CSRF-Token: ' . ($datosPre['csrf'] ?? '')]]);
$res = json_decode($r['cuerpo'], true);
revisar('APIs', 'El paciente envía su pre-consulta', $r['codigo'] === 200 && !empty($res['ok']), $r);

$r = pedir('api/preconsulta.php?accion=responder', ['post' => $respuesta, 'cabeceras' => ['Content-Type: application/json', 'X-CSRF-Token: ' . ($datosPre['csrf'] ?? '')]]);
revisar('APIs', 'El enlace de pre-consulta solo sirve una vez', $r['codigo'] === 410, $r);

$r = paginaBien('APIs', $ruta, 410);

$exp = pedir('expediente.php?id=1');
revisar('APIs', 'Resumen y alerta de alergia en el expediente', strpos($exp['cuerpo'], 'pre-resumen-ia') !== false && strpos($exp['cuerpo'], 'pre-alerta') !== false, $exp);

/* Traslado a la cita: el que pidió el paciente en su pre-consulta y el que programa el médico */
revisar('APIs', 'El traslado que pidió el paciente aparece en el expediente', strpos($exp['cuerpo'], 'id="traslado"') !== false && strpos($exp['cuerpo'], 'Lo pidió el paciente') !== false && strpos($exp['cuerpo'], 'Av. Reforma 123') !== false, $exp);

preg_match('/name="traslado_id" value="(\d+)"/', $exp['cuerpo'], $m);
$trasladoId = $m[1] ?? '0';
$r = pedir('traslado.php', ['post' => http_build_query(['csrf_token' => $csrf, 'paciente_id' => 1, 'traslado_id' => $trasladoId, 'accion' => 'estado', 'estado' => 'confirmado'])]);
$exp = pedir('expediente.php?id=1');
revisar('APIs', 'No se confirma un traslado sin chofer', strpos($exp['cuerpo'], 'Escribe el nombre del chofer') !== false && strpos($exp['cuerpo'], 'Asignar chofer y confirmar') !== false, $r);

$r = pedir('traslado.php', ['post' => http_build_query([
    'csrf_token' => $csrf, 'paciente_id' => 1, 'traslado_id' => $trasladoId, 'accion' => 'estado', 'estado' => 'confirmado',
    'chofer_nombre' => 'Ramón Hernández', 'chofer_telefono' => '222 418 7730', 'vehiculo' => 'Nissan Urvan blanca, placas TXA-482-B'
])]);
$exp = pedir('expediente.php?id=1');
revisar('APIs', 'Confirmar el traslado con chofer', in_array($r['codigo'], [302, 303], true) && strpos($exp['cuerpo'], 'El chofer ya salió') !== false && strpos($exp['cuerpo'], 'wa.me/522224187730') !== false, $r);

$r = pedir('traslado.php', ['post' => http_build_query(['csrf_token' => $csrf, 'paciente_id' => 1, 'traslado_id' => $trasladoId, 'accion' => 'estado', 'estado' => 'completado'])]);
$exp = pedir('expediente.php?id=1');
revisar('APIs', 'El traslado no se salta pasos', strpos($exp['cuerpo'], 'Ese traslado ya cambió') !== false && strpos($exp['cuerpo'], 'El chofer ya salió') !== false, $r);

$agenda = pedir('agenda.php');
revisar('APIs', 'La agenda marca la cita con traslado', strpos($agenda['cuerpo'], 'tras-chip') !== false && strpos($agenda['cuerpo'], 'Traslado confirmado') !== false && sinErrores($agenda) === '', $agenda, sinErrores($agenda));

$r = pedir('traslado.php', ['post' => http_build_query(['csrf_token' => $csrf, 'paciente_id' => 2, 'accion' => 'crear', 'tipo' => 'ida', 'direccion' => 'Calle 5', 'minutos_antes' => 60])]);
$exp = pedir('expediente.php?id=2');
revisar('APIs', 'Rechaza un traslado sin dirección completa', strpos($exp['cuerpo'], 'Escribe la dirección') !== false && strpos($exp['cuerpo'], 'Programar traslado') !== false, $r);

// Correo de prueba desde Configuración: sin llave en el servidor explica el motivo en lugar de fallar en silencio
$r = pedir('configuracion.php', ['post' => http_build_query(['csrf_token' => $csrf, 'accion' => 'probar_correo', 'correo_prueba' => 'prueba@ejemplo.com'])]);
revisar('APIs', 'El correo de prueba explica por qué no salió', $r['codigo'] === 200 && strpos($r['cuerpo'], 'Correos de MediCore') !== false && strpos($r['cuerpo'], 'No se pudo enviar el correo de prueba: No hay servicio de correo configurado') !== false && sinErrores($r) === '', $r, sinErrores($r));

$r = pedir('traslado.php', ['post' => http_build_query(['csrf_token' => $csrf, 'paciente_id' => 2, 'accion' => 'crear', 'tipo' => 'ida', 'direccion' => 'Calle 5 Sur 1203, Col. Centro, Puebla', 'minutos_antes' => 60, 'notas' => ''])]);
$exp = pedir('expediente.php?id=2');
revisar('APIs', 'El médico programa un traslado', in_array($r['codigo'], [302, 303], true) && strpos($exp['cuerpo'], 'Asignar chofer y confirmar') !== false && sinErrores($exp) === '', $exp, sinErrores($exp));

$r = pedir('traslado.php', ['post' => http_build_query(['paciente_id' => 2, 'accion' => 'cancelar', 'traslado_id' => 1])]);
revisar('APIs', 'traslado.php rechaza peticiones sin token CSRF', $r['codigo'] === 403, $r);

$r = pedir('api/notas.php?accion=guardar');
revisar('APIs', 'api/notas.php solo acepta POST', $r['codigo'] === 405, $r);

/* 7. Zona horaria (incidente del 26/10/2026): el servidor de MySQL trabaja en UTC y en la noche
   CURDATE() ya era el día siguiente, así que las citas de hoy se contaban como de mañana.
   config.php iguala la zona de MySQL con la de PHP; aquí se comprueba con una cita de hoy. */
$hoy = date('Y-m-d');   // fecha de México, la misma que usa todo el sistema
$bd->query("UPDATE expedientes SET fecha_cita = '$hoy' WHERE usuario_id = 1");
$inicio = pedir('index.php');
preg_match('/data-citas-hoy="(\d+)"/', $inicio['cuerpo'], $m);
revisar('Zona horaria', 'Las citas de hoy se cuentan con la fecha de México', ($m[1] ?? '') === '1', $inicio, 'citas hoy: ' . ($m[1] ?? 'sin dato') . ', fecha ' . $hoy);

/* 8. Cierre de sesión */
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
