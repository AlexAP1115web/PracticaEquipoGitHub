<?php

/* =====================================================
   API: PRE-CONSULTA POR QR
   POST ?accion=crear     (médico con sesión, X-CSRF-Token)  { paciente_id }
        Regresa el token del enlace; el QR lo dibuja el navegador del médico.
   POST ?accion=responder (paciente sin cuenta, X-CSRF-Token de su página)
        { token, id_cliente, respuestas: { motivo, inicio, medicamentos, alergias, pregunta },
          comentario, sin_molestias, molestias: [{ zona, sensacion, intensidad, desde, detalle }] }
        El token de 48 caracteres es la llave: solo sirve una vez y vence en 72 horas.
===================================================== */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../config/esquema.php';
require_once __DIR__ . '/../integrations/Preconsulta.php';
require_once __DIR__ . '/../integrations/WebPush.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function responderPreconsulta($codigo, array $datos)
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit();
}

$accion = $_GET['accion'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderPreconsulta(405, ['ok' => false, 'mensaje' => 'Método no permitido.']);
}

/* ---------- El médico genera el enlace ---------- */

if ($accion === 'crear') {
    if (empty($_SESSION['medico'])) {
        responderPreconsulta(401, ['ok' => false, 'mensaje' => 'La sesión terminó. Vuelve a iniciar sesión.']);
    }

    verificarSesion();

    if (!tokenCSRFValido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        registrarLog("Pre-consulta: token CSRF inválido al crear.", "WARNING");
        responderPreconsulta(403, ['ok' => false, 'mensaje' => 'La página expiró. Recárgala e intenta de nuevo.']);
    }

    prepararEsquemaInnovacion($conexion);

    $entrada = json_decode(file_get_contents('php://input'), true);
    $pacienteId = intval($entrada['paciente_id'] ?? 0);
    $paciente = $pacienteId > 0 ? mapaPaciente($conexion, $pacienteId) : null;

    if (!$paciente) {
        responderPreconsulta(404, ['ok' => false, 'mensaje' => 'El paciente no existe.']);
    }

    try {
        $medicoId = (int) ($_SESSION['medico_id'] ?? $_SESSION['medico']);
        $pre = preconsultaCrear($conexion, $pacienteId, $medicoId);

        if ($pre['nueva']) {
            registrarLog("Pre-consulta: enlace generado (paciente ID $pacienteId).", "INFO");
        }

        responderPreconsulta(200, [
            'ok'          => true,
            'token'       => $pre['token'],
            'ruta'        => 'preconsulta.php?t=' . $pre['token'],
            'expira_en'   => $pre['expira_en'],
            'expira_texto' => fechaCitaEnTexto($pre['expira_en']) . ' a las ' . date('H:i', strtotime($pre['expira_en'])),
            'paciente'    => explode(' ', trim($paciente['nombre']))[0]
        ]);
    } catch (Exception $e) {
        registrarLog("Pre-consulta: error al crear " . $e->getMessage(), "CRITICAL");
        responderPreconsulta(500, ['ok' => false, 'mensaje' => 'No se pudo generar el código. Intenta de nuevo.']);
    }
}

/* ---------- El paciente envía sus respuestas ---------- */

if ($accion === 'responder') {
    // Límite por sesión para que nadie use la página para llenar la base
    if (!controlarIntentos('preconsulta', 8, 300)) {
        responderPreconsulta(429, ['ok' => false, 'mensaje' => 'Demasiados intentos. Espera unos minutos.']);
    }

    if (!tokenCSRFValido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        registrarIntentoFallido('preconsulta');
        responderPreconsulta(403, ['ok' => false, 'mensaje' => 'La página expiró. Recárgala e intenta de nuevo.']);
    }

    prepararEsquemaInnovacion($conexion);

    $entrada = json_decode(file_get_contents('php://input'), true);

    if (!is_array($entrada)) {
        responderPreconsulta(422, ['ok' => false, 'mensaje' => 'Datos incompletos.']);
    }

    $pre = preconsultaPorToken($conexion, (string) ($entrada['token'] ?? ''));

    if (!$pre) {
        registrarIntentoFallido('preconsulta');
        registrarLog("Pre-consulta: token inexistente.", "WARNING");
        responderPreconsulta(404, ['ok' => false, 'mensaje' => 'Este código no es válido. Pide a tu médico uno nuevo.']);
    }

    try {
        $guardado = preconsultaGuardar($conexion, $pre, $entrada);

        if (!$guardado['ok']) {
            registrarIntentoFallido('preconsulta');
            responderPreconsulta($guardado['codigo'], ['ok' => false, 'mensaje' => $guardado['mensaje']]);
        }

        registrarLog("Pre-consulta: respondida (paciente ID {$pre['paciente_id']}, " . count($guardado['molestias']) . " zonas).", "INFO");

        // Se le contesta al paciente de inmediato; el resumen con IA y el aviso push siguen después
        http_response_code(200);
        echo json_encode(['ok' => true, 'registros' => []], JSON_UNESCAPED_UNICODE);

        if (function_exists('fastcgi_finish_request')) {
            session_write_close();
            fastcgi_finish_request();
        }

        try {
            preconsultaCompletar($conexion, $pre, $guardado);
        } catch (Throwable $e) {
            registrarLog("Pre-consulta: no se pudo completar el resumen " . $e->getMessage(), "WARNING");
        }
        exit();
    } catch (Exception $e) {
        registrarLog("Pre-consulta: error al responder " . $e->getMessage(), "CRITICAL");
        responderPreconsulta(500, ['ok' => false, 'mensaje' => 'No se pudo enviar en este momento. Intenta de nuevo.']);
    }
}

responderPreconsulta(400, ['ok' => false, 'mensaje' => 'Acción no válida.']);
