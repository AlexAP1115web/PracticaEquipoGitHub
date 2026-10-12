<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   API: ESCÁNER DE MEDICAMENTOS
   -----------------------------------------------------
   POST api/escanear.php
   Cabecera X-CSRF-Token + cuerpo JSON:
     { "imagen": "<base64 sin encabezado>", "tipo": "image/jpeg", "paciente_id": 15 }

   Seguridad:
   - Solo médicos con sesión iniciada.
   - Token CSRF en la cabecera.
   - Máximo 6 fotos cada 5 minutos por sesión.
   - Solo JPEG, PNG o WebP de hasta 4 MB; se valida que
     realmente sea una imagen.
   - La foto no se guarda: solo se manda a Gemini.
===================================================== */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../config/esquema.php';
require_once __DIR__ . '/../integrations/EscanerMedicamentos.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responderEscaneo($codigo, array $datos)
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderEscaneo(405, ['ok' => false, 'mensaje' => 'Método no permitido.']);
}

if (empty($_SESSION['medico'])) {
    responderEscaneo(401, ['ok' => false, 'mensaje' => 'Tu sesión terminó. Vuelve a iniciar sesión.']);
}

verificarSesion();

if (!tokenCSRFValido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    registrarLog("Escáner de medicamentos: token CSRF inválido.", "WARNING");
    responderEscaneo(403, ['ok' => false, 'mensaje' => 'La página expiró. Recárgala e intenta de nuevo.']);
}

if (!geminiDisponible()) {
    responderEscaneo(503, ['ok' => false, 'mensaje' => 'Para leer fotos hace falta configurar GEMINI_API_KEY en el servidor.']);
}

// Límite de uso por sesión
$ahora = time();
$_SESSION['escaner_uso'] = array_values(array_filter(
    $_SESSION['escaner_uso'] ?? [],
    function ($momento) use ($ahora) {
        return $momento > $ahora - 300;
    }
));

if (count($_SESSION['escaner_uso']) >= 6) {
    responderEscaneo(429, ['ok' => false, 'mensaje' => 'Enviaste muchas fotos seguidas. Espera un par de minutos.']);
}

$entrada = json_decode(file_get_contents('php://input'), true);
$base64 = (string) ($entrada['imagen'] ?? '');
$pacienteId = isset($entrada['paciente_id']) && $entrada['paciente_id'] !== '' ? intval($entrada['paciente_id']) : null;

if ($pacienteId !== null && $pacienteId <= 0) {
    $pacienteId = null;
}

if ($base64 === '' || strlen($base64) > 5600000) {
    responderEscaneo(422, ['ok' => false, 'mensaje' => 'La foto está vacía o pesa más de 4 MB.']);
}

$binario = base64_decode($base64, true);
$info = $binario !== false ? @getimagesizefromstring($binario) : false;
$tiposPermitidos = ['image/jpeg', 'image/png', 'image/webp'];

if ($info === false || !in_array($info['mime'], $tiposPermitidos, true)) {
    registrarLog("Escáner de medicamentos: archivo rechazado (no es imagen válida).", "WARNING");
    responderEscaneo(422, ['ok' => false, 'mensaje' => 'El archivo no es una foto válida (usa JPG, PNG o WebP).']);
}

$_SESSION['escaner_uso'][] = $ahora;

set_time_limit(90);
prepararEsquemaInnovacion($conexion);

try {
    $medicoId = (int) ($_SESSION['medico_id'] ?? $_SESSION['medico']);
    $resultado = escanearMedicamento($conexion, $medicoId, $info['mime'], base64_encode($binario), $pacienteId);

    $leidos = array_map(function ($m) {
        return $m['nombre_detectado'] ?: implode(' + ', $m['principios']);
    }, $resultado['medicamentos'] ?? []);

    registrarLog(
        "Escáner de medicamentos: " . (empty($leidos) ? 'sin medicamento reconocido' : implode(', ', $leidos)) .
            ($pacienteId ? " (paciente ID $pacienteId)" : ""),
        "INFO"
    );

    responderEscaneo($resultado['ok'] ? 200 : 502, $resultado);
} catch (Exception $e) {
    registrarLog("Escáner de medicamentos: error " . $e->getMessage(), "CRITICAL");
    responderEscaneo(500, ['ok' => false, 'mensaje' => 'No se pudo analizar la foto en este momento.']);
}
