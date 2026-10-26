<?php

/* =====================================================
   API: NOTIFICACIONES PUSH (api/push.php?accion=...)
   Con sesión + X-CSRF-Token: suscribir, cancelar, prueba, recordatorios
   Sin sesión: pendientes (la llama sw.js) y cron (GET ?token=..., necesita PUSH_CRON_TOKEN)
===================================================== */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/../config/esquema.php';
require_once __DIR__ . '/../integrations/WebPush.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responderPush($codigo, array $datos)
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

prepararEsquemaInnovacion($conexion);

$accion = $_GET['accion'] ?? '';
$entrada = json_decode(file_get_contents('php://input'), true) ?: [];

/* ======================================================
   ACCIONES PÚBLICAS
====================================================== */

if ($accion === 'pendientes') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        responderPush(405, ['ok' => false]);
    }

    $endpoint = (string) ($entrada['endpoint'] ?? '');

    if (!preg_match('#^https://#', $endpoint) || strlen($endpoint) > 1000) {
        responderPush(200, ['ok' => true, 'mensajes' => []]);
    }

    $hash = hash('sha256', $endpoint);

    $stmt = $conexion->prepare("
        SELECT m.id, m.titulo, m.cuerpo, m.url
        FROM push_mensajes m
        INNER JOIN push_suscripciones s ON s.id = m.suscripcion_id
        WHERE s.endpoint_hash = ? AND m.entregado = 0
        ORDER BY m.id ASC
        LIMIT 5
    ");
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    $mensajes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!empty($mensajes)) {
        $ids = implode(',', array_map('intval', array_column($mensajes, 'id')));
        $conexion->query("UPDATE push_mensajes SET entregado = 1 WHERE id IN ($ids)");
    }

    responderPush(200, ['ok' => true, 'mensajes' => $mensajes]);
}

if ($accion === 'cron') {
    $tokenEsperado = apiConfig('PUSH_CRON_TOKEN');

    if ($tokenEsperado === '' || !hash_equals($tokenEsperado, (string) ($_GET['token'] ?? ''))) {
        registrarLog("Push cron: token inválido.", "WARNING");
        responderPush(403, ['ok' => false]);
    }

    $resumen = enviarRecordatoriosDeCitas($conexion);
    registrarLog("Push cron: " . json_encode($resumen), "INFO");
    responderPush(200, ['ok' => true] + $resumen);
}

/* ======================================================
   ACCIONES DEL MÉDICO (sesión + CSRF)
====================================================== */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderPush(405, ['ok' => false, 'mensaje' => 'Método no permitido.']);
}

if (empty($_SESSION['medico'])) {
    responderPush(401, ['ok' => false, 'mensaje' => 'Tu sesión terminó. Vuelve a iniciar sesión.']);
}

verificarSesion();

if (!tokenCSRFValido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responderPush(403, ['ok' => false, 'mensaje' => 'La página expiró. Recárgala e intenta de nuevo.']);
}

if (!pushDisponible()) {
    responderPush(503, ['ok' => false, 'mensaje' => 'Las notificaciones no están configuradas en el servidor (faltan las llaves VAPID).']);
}

$medicoId = (int) ($_SESSION['medico_id'] ?? $_SESSION['medico']);

switch ($accion) {
    case 'suscribir':
        $endpoint = (string) ($entrada['endpoint'] ?? '');
        $p256dh = (string) ($entrada['keys']['p256dh'] ?? '');
        $auth = (string) ($entrada['keys']['auth'] ?? '');

        if (!preg_match('#^https://#', $endpoint) || strlen($endpoint) > 1000) {
            responderPush(422, ['ok' => false, 'mensaje' => 'Suscripción inválida.']);
        }

        $hash = hash('sha256', $endpoint);
        $navegador = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        $stmt = $conexion->prepare("
            INSERT INTO push_suscripciones (medico_id, endpoint, endpoint_hash, p256dh, auth, navegador)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE medico_id = VALUES(medico_id), p256dh = VALUES(p256dh),
                                    auth = VALUES(auth), navegador = VALUES(navegador)
        ");
        $stmt->bind_param("isssss", $medicoId, $endpoint, $hash, $p256dh, $auth, $navegador);
        $stmt->execute();
        $stmt->close();

        registrarLog("Push: dispositivo suscrito para el médico ID $medicoId.", "INFO");
        responderPush(200, ['ok' => true, 'mensaje' => 'Notificaciones activadas en este dispositivo.']);

    case 'cancelar':
        $hash = hash('sha256', (string) ($entrada['endpoint'] ?? ''));
        $stmt = $conexion->prepare("DELETE FROM push_suscripciones WHERE endpoint_hash = ? AND medico_id = ?");
        $stmt->bind_param("si", $hash, $medicoId);
        $stmt->execute();
        $stmt->close();

        responderPush(200, ['ok' => true, 'mensaje' => 'Notificaciones desactivadas en este dispositivo.']);

    case 'prueba':
        $r = notificarMedico(
            $conexion,
            $medicoId,
            'MediCore: notificación de prueba',
            'Las notificaciones push funcionan. Aquí recibirás los recordatorios de tus citas.',
            'index.php'
        );

        if ($r['dispositivos'] === 0) {
            responderPush(200, ['ok' => false, 'mensaje' => 'Primero activa las notificaciones en este dispositivo.']);
        }

        responderPush(200, ['ok' => true, 'mensaje' => "Notificación enviada a {$r['enviados']} de {$r['dispositivos']} dispositivo(s)."]);

    case 'recordatorios':
        $r = enviarRecordatoriosDeCitas($conexion);

        if ($r['dispositivos'] === 0) {
            $mensaje = 'Ningún dispositivo tiene las notificaciones activadas.';
        } elseif ($r['citas'] === 0) {
            $mensaje = 'No hay avisos pendientes: las citas de los próximos 7 días ya se avisaron.';
        } else {
            $mensaje = "Se avisó de {$r['citas']} cita(s) en {$r['enviados']} de {$r['dispositivos']} dispositivo(s).";
        }

        responderPush(200, ['ok' => true, 'mensaje' => $mensaje] + $r);

    default:
        responderPush(400, ['ok' => false, 'mensaje' => 'Acción no válida.']);
}
