<?php

/* =====================================================
   API: MAPA CORPORAL DE MOLESTIAS
   GET  ?paciente_id=N           registros del paciente con sus molestias
   POST ?accion=guardar (X-CSRF-Token)
        { id_cliente, paciente_id, comentario, sin_molestias, molestias: [{ zona, sensacion, intensidad, desde, detalle }] }
===================================================== */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../config/esquema.php';
require_once __DIR__ . '/../integrations/MapaCorporal.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responderMapa($codigo, array $datos)
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit();
}

if (empty($_SESSION['medico'])) {
    responderMapa(401, ['ok' => false, 'mensaje' => 'La sesión terminó. Pide al médico que vuelva a iniciar sesión.']);
}

verificarSesion();
prepararEsquemaInnovacion($conexion);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $pacienteId = intval($_GET['paciente_id'] ?? 0);

        if (!mapaPaciente($conexion, $pacienteId)) {
            responderMapa(404, ['ok' => false, 'mensaje' => 'El paciente no existe.']);
        }

        responderMapa(200, ['ok' => true, 'registros' => mapaRegistros($conexion, $pacienteId)]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        responderMapa(405, ['ok' => false, 'mensaje' => 'Método no permitido.']);
    }

    if (!tokenCSRFValido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        registrarLog("Mapa corporal: token CSRF inválido.", "WARNING");
        responderMapa(403, ['ok' => false, 'mensaje' => 'La página expiró. Recárgala e intenta de nuevo.']);
    }

    if (($_GET['accion'] ?? '') !== 'guardar') {
        responderMapa(400, ['ok' => false, 'mensaje' => 'Acción no válida.']);
    }

    $entrada = json_decode(file_get_contents('php://input'), true);

    if (!is_array($entrada)) {
        responderMapa(422, ['ok' => false, 'mensaje' => 'Datos incompletos.']);
    }

    $medicoId = (int) ($_SESSION['medico_id'] ?? $_SESSION['medico']);
    $resultado = guardarRegistroMapa($conexion, $medicoId, $entrada);

    if ($resultado['ok'] && empty($resultado['repetido'])) {
        registrarLog(
            "Mapa corporal: registro guardado (paciente ID " . intval($entrada['paciente_id'] ?? 0) .
                ", " . count((array) ($entrada['molestias'] ?? [])) . " zonas).",
            "INFO"
        );
    }

    responderMapa($resultado['ok'] ? 200 : 422, $resultado);
} catch (Exception $e) {
    registrarLog("Mapa corporal: error " . $e->getMessage(), "CRITICAL");
    responderMapa(500, ['ok' => false, 'mensaje' => 'No se pudo guardar en este momento. Intenta de nuevo.']);
}
