<?php

/* =====================================================
   API: ASISTENTE DE MEDICAMENTOS
   POST con cabecera X-CSRF-Token y JSON { "pregunta": "...", "paciente_id": 15 }
   Solo médicos con sesión, máx. 15 consultas cada 5 min
===================================================== */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../config/esquema.php';
require_once __DIR__ . '/../integrations/ChatbotMedicamentos.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responderJson($codigo, array $datos)
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(405, ['ok' => false, 'mensaje' => 'Método no permitido.']);
}

if (empty($_SESSION['medico'])) {
    responderJson(401, ['ok' => false, 'mensaje' => 'Tu sesión terminó. Vuelve a iniciar sesión.']);
}

verificarSesion();

if (!tokenCSRFValido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    registrarLog("Asistente de medicamentos: token CSRF inválido.", "WARNING");
    responderJson(403, ['ok' => false, 'mensaje' => 'La página expiró. Recárgala e intenta de nuevo.']);
}

// Límite por sesión para no acabarnos la cuota de las APIs
$ahora = time();
$_SESSION['chatbot_uso'] = array_values(array_filter(
    $_SESSION['chatbot_uso'] ?? [],
    function ($momento) use ($ahora) {
        return $momento > $ahora - 300;
    }
));

if (count($_SESSION['chatbot_uso']) >= 15) {
    responderJson(429, ['ok' => false, 'mensaje' => 'Hiciste muchas consultas seguidas. Espera un par de minutos.']);
}

$_SESSION['chatbot_uso'][] = $ahora;

$entrada = json_decode(file_get_contents('php://input'), true);
$pregunta = trim(strip_tags((string) ($entrada['pregunta'] ?? '')));
$pacienteId = isset($entrada['paciente_id']) && $entrada['paciente_id'] !== '' ? intval($entrada['paciente_id']) : null;

if ($pregunta === '' || mb_strlen($pregunta) < 4) {
    responderJson(422, ['ok' => false, 'mensaje' => 'Escribe tu pregunta sobre un medicamento.']);
}

if (mb_strlen($pregunta) > 300) {
    responderJson(422, ['ok' => false, 'mensaje' => 'La pregunta es demasiado larga (máximo 300 caracteres).']);
}

if ($pacienteId !== null && $pacienteId <= 0) {
    $pacienteId = null;
}

set_time_limit(60);
prepararEsquemaInnovacion($conexion);

try {
    $medicoId = (int) ($_SESSION['medico_id'] ?? $_SESSION['medico']);
    $resultado = responderConsultaMedicamento($conexion, $medicoId, $pregunta, $pacienteId);

    registrarLog(
        "Asistente de medicamentos: consulta sobre " . ($resultado['medicamento'] ?? 'sin identificar') .
            ($pacienteId ? " (paciente ID $pacienteId)" : ""),
        "INFO"
    );

    responderJson(200, $resultado);
} catch (Exception $e) {
    registrarLog("Asistente de medicamentos: error " . $e->getMessage(), "CRITICAL");
    responderJson(500, ['ok' => false, 'mensaje' => 'No se pudo consultar la información en este momento.']);
}
