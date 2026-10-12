<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   API: NOTAS DE CONSULTA
   -----------------------------------------------------
   POST api/notas.php?accion=estructurar
     { "paciente_id": 15, "texto": "..." }
     -> ordena el texto en formato SOAP con Gemini y
        revisa el plan contra las alergias del paciente.

   POST api/notas.php?accion=guardar
     { "id_cliente": "uuid", "paciente_id": 15, "transcripcion": "...",
       "subjetivo": "", "objetivo": "", "analisis": "", "plan": "",
       "origen": "voz|texto|sin_conexion", "escrita_en": "ISO 8601" }
     -> guarda la nota. La usa la página y también el service
        worker cuando manda las notas que se escribieron sin
        conexión (Background Sync).

   Seguridad: sesión de médico, token CSRF en la cabecera
   X-CSRF-Token y límite de uso para el ordenado con IA.
===================================================== */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../config/esquema.php';
require_once __DIR__ . '/../integrations/NotasConsulta.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responderNotas($codigo, array $datos)
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderNotas(405, ['ok' => false, 'mensaje' => 'Método no permitido.']);
}

if (empty($_SESSION['medico'])) {
    responderNotas(401, ['ok' => false, 'mensaje' => 'Tu sesión terminó. Vuelve a iniciar sesión.']);
}

verificarSesion();

if (!tokenCSRFValido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    registrarLog("Notas de consulta: token CSRF inválido.", "WARNING");
    responderNotas(403, ['ok' => false, 'mensaje' => 'La página expiró. Recárgala e intenta de nuevo.']);
}

prepararEsquemaInnovacion($conexion);

$accion = $_GET['accion'] ?? '';
$entrada = json_decode(file_get_contents('php://input'), true);

if (!is_array($entrada)) {
    responderNotas(422, ['ok' => false, 'mensaje' => 'Datos incompletos.']);
}

$medicoId = (int) ($_SESSION['medico_id'] ?? $_SESSION['medico']);

try {
    if ($accion === 'estructurar') {
        if (!geminiDisponible()) {
            responderNotas(503, ['ok' => false, 'mensaje' => 'Para ordenar la nota hace falta configurar GEMINI_API_KEY. Puedes guardarla tal como está.']);
        }

        // Límite de uso de la IA por sesión
        $ahora = time();
        $_SESSION['notas_ia_uso'] = array_values(array_filter(
            $_SESSION['notas_ia_uso'] ?? [],
            function ($momento) use ($ahora) {
                return $momento > $ahora - 300;
            }
        ));

        if (count($_SESSION['notas_ia_uso']) >= 10) {
            responderNotas(429, ['ok' => false, 'mensaje' => 'Ordenaste muchas notas seguidas. Espera un par de minutos.']);
        }

        $_SESSION['notas_ia_uso'][] = $ahora;

        $texto = trim(strip_tags((string) ($entrada['texto'] ?? '')));
        $paciente = pacienteDeNota($conexion, intval($entrada['paciente_id'] ?? 0));

        if (!$paciente) {
            responderNotas(422, ['ok' => false, 'mensaje' => 'El paciente no existe.']);
        }

        if (mb_strlen($texto) < 15) {
            responderNotas(422, ['ok' => false, 'mensaje' => 'Dicta o escribe un poco más antes de ordenar la nota.']);
        }

        set_time_limit(60);
        $nota = estructurarNotaSOAP($texto, $paciente['nombre']);

        if (!$nota) {
            responderNotas(502, ['ok' => false, 'mensaje' => 'No se pudo ordenar la nota en este momento. Puedes guardarla tal como está.']);
        }

        registrarLog("Notas de consulta: nota ordenada con IA (paciente ID {$paciente['id']}).", "INFO");

        responderNotas(200, [
            'ok'      => true,
            'nota'    => $nota,
            'alertas' => alertasDeNota($nota, $paciente['alergias'] ?? '')
        ]);
    }

    if ($accion === 'guardar') {
        set_time_limit(60);
        $resultado = guardarNotaConsulta($conexion, $medicoId, $entrada);

        if ($resultado['ok']) {
            registrarLog(
                "Notas de consulta: nota guardada (paciente ID " . intval($entrada['paciente_id'] ?? 0) .
                    ", origen " . ($resultado['nota']['origen'] ?? 'texto') . ").",
                "INFO"
            );
        }

        responderNotas($resultado['ok'] ? 200 : 422, $resultado);
    }

    responderNotas(400, ['ok' => false, 'mensaje' => 'Acción no válida.']);
} catch (Exception $e) {
    registrarLog("Notas de consulta: error " . $e->getMessage(), "CRITICAL");
    responderNotas(500, ['ok' => false, 'mensaje' => 'No se pudo procesar la nota en este momento.']);
}
