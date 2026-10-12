<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   NOTIFICACIONES PUSH (Web Push + VAPID)
   Se manda un push vacío firmado con VAPID y el service worker pide el mensaje
   a api/push.php, así no hay que cifrar el contenido (en PHP pide librerías).
   Variables: VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY (base64url, de npx web-push generate-vapid-keys) y VAPID_SUBJECT
===================================================== */

require_once __DIR__ . '/../config/apis.php';

if (!function_exists('pushDisponible')) {
    function pushDisponible()
    {
        return apiHabilitada(['VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY']);
    }
}

if (!function_exists('base64urlCodificar')) {
    function base64urlCodificar($datos)
    {
        return rtrim(strtr(base64_encode($datos), '+/', '-_'), '=');
    }
}

if (!function_exists('base64urlDecodificar')) {
    function base64urlDecodificar($texto)
    {
        $texto = strtr($texto, '-_', '+/');
        return base64_decode($texto . str_repeat('=', (4 - strlen($texto) % 4) % 4));
    }
}

if (!function_exists('vapidLlavePrivadaPem')) {
    // Llave EC P-256 en PEM armada con las llaves base64url del .env
    function vapidLlavePrivadaPem()
    {
        $privada = base64urlDecodificar(apiConfig('VAPID_PRIVATE_KEY'));
        $publica = base64urlDecodificar(apiConfig('VAPID_PUBLIC_KEY'));

        if (strlen($privada) !== 32 || strlen($publica) !== 65) {
            return null;
        }

        $der = "\x30\x77"                                   // SEQUENCE
            . "\x02\x01\x01"                                  // versión 1
            . "\x04\x20" . $privada                           // llave privada (32 bytes)
            . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07" // curva prime256v1
            . "\xa1\x44\x03\x42\x00" . $publica;              // llave pública (65 bytes)

        return "-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END EC PRIVATE KEY-----\n";
    }
}

if (!function_exists('firmaDerARaw')) {
    // openssl da la firma en DER y el JWT ES256 la necesita como r||s (32 bytes cada uno)
    function firmaDerARaw($der)
    {
        $pos = 2;

        if (ord($der[1]) & 0x80) {
            $pos += ord($der[1]) & 0x7f;
        }

        $valores = [];

        for ($i = 0; $i < 2; $i++) {
            $pos++;                       // 0x02 (INTEGER)
            $largo = ord($der[$pos]);
            $pos++;
            $valor = substr($der, $pos, $largo);
            $pos += $largo;
            $valor = ltrim($valor, "\x00");
            $valores[] = str_pad($valor, 32, "\x00", STR_PAD_LEFT);
        }

        return $valores[0] . $valores[1];
    }
}

if (!function_exists('vapidCabeceraAutorizacion')) {
    function vapidCabeceraAutorizacion($endpoint)
    {
        $pem = vapidLlavePrivadaPem();

        if ($pem === null) {
            return null;
        }

        $partes = parse_url($endpoint);
        $audiencia = $partes['scheme'] . '://' . $partes['host'];

        $cabecera = base64urlCodificar(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $carga = base64urlCodificar(json_encode([
            'aud' => $audiencia,
            'exp' => time() + 12 * 3600,
            'sub' => apiConfig('VAPID_SUBJECT', 'mailto:soporte@medicore.app')
        ], JSON_UNESCAPED_SLASHES));

        $datos = $cabecera . '.' . $carga;
        $firmaDer = '';

        if (!openssl_sign($datos, $firmaDer, $pem, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        $jwt = $datos . '.' . base64urlCodificar(firmaDerARaw($firmaDer));

        return 'vapid t=' . $jwt . ', k=' . apiConfig('VAPID_PUBLIC_KEY');
    }
}

if (!function_exists('enviarPushVacio')) {
    // Regresa el código HTTP: 201 entregado, 404/410 la suscripción ya no existe
    function enviarPushVacio($endpoint)
    {
        $autorizacion = vapidCabeceraAutorizacion($endpoint);

        if ($autorizacion === null) {
            return 0;
        }

        $ch = curl_init($endpoint);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $autorizacion,
                'TTL: 86400',
                'Urgency: normal',
                'Content-Length: 0'
            ]
        ]);

        $respuesta = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!in_array($codigo, [200, 201, 202], true) && function_exists('registrarLog')) {
            registrarLog("Push -> HTTP $codigo | " . substr((string) $respuesta, 0, 200), "WARNING");
        }

        return $codigo;
    }
}

if (!function_exists('notificarSuscripciones')) {
    // Guarda el mensaje de cada suscripción y despierta a su service worker
    function notificarSuscripciones($conexion, array $suscripciones, $titulo, $cuerpo, $url = 'index.php')
    {
        $enviados = 0;
        $eliminados = 0;

        $insertar = $conexion->prepare("
            INSERT INTO push_mensajes (suscripcion_id, titulo, cuerpo, url)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($suscripciones as $s) {
            $id = (int) $s['id'];
            $insertar->bind_param("isss", $id, $titulo, $cuerpo, $url);
            $insertar->execute();

            $codigo = enviarPushVacio($s['endpoint']);

            if (in_array($codigo, [200, 201, 202], true)) {
                $enviados++;
            } elseif (in_array($codigo, [404, 410], true)) {
                // El usuario desinstaló la app o quitó el permiso
                $borrar = $conexion->prepare("DELETE FROM push_suscripciones WHERE id = ?");
                $borrar->bind_param("i", $id);
                $borrar->execute();
                $borrar->close();
                $eliminados++;
            }
        }

        $insertar->close();

        return ['enviados' => $enviados, 'eliminados' => $eliminados];
    }
}

if (!function_exists('notificarMedico')) {
    function notificarMedico($conexion, $medicoId, $titulo, $cuerpo, $url = 'index.php')
    {
        $stmt = $conexion->prepare("SELECT id, endpoint FROM push_suscripciones WHERE medico_id = ?");
        $stmt->bind_param("i", $medicoId);
        $stmt->execute();
        $suscripciones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return notificarSuscripciones($conexion, $suscripciones, $titulo, $cuerpo, $url) + ['dispositivos' => count($suscripciones)];
    }
}

if (!function_exists('enviarRecordatoriosDeCitas')) {
    // Citas de hoy y mañana sin recordatorio. Va a todos los dispositivos
    // porque todos los médicos ven a todos los pacientes
    function enviarRecordatoriosDeCitas($conexion)
    {
        $citas = $conexion->query("
            SELECT e.id AS expediente_id, e.usuario_id, e.fecha_cita, e.hora_cita, u.nombre
            FROM expedientes e
            INNER JOIN usuarios u ON u.id = e.usuario_id
            LEFT JOIN push_recordatorios r
                ON r.expediente_id = e.id AND r.fecha_cita = e.fecha_cita
            WHERE e.fecha_cita BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 1 DAY)
              AND r.expediente_id IS NULL
            ORDER BY e.fecha_cita, e.hora_cita
        ")->fetch_all(MYSQLI_ASSOC);

        $suscripciones = $conexion->query("SELECT id, endpoint FROM push_suscripciones")->fetch_all(MYSQLI_ASSOC);
        $resumen = ['citas' => count($citas), 'enviados' => 0, 'eliminados' => 0];

        if (empty($citas) || empty($suscripciones)) {
            return $resumen;
        }

        $marcar = $conexion->prepare("INSERT IGNORE INTO push_recordatorios (expediente_id, fecha_cita) VALUES (?, ?)");

        foreach ($citas as $cita) {
            $esHoy = $cita['fecha_cita'] === date('Y-m-d');
            $hora = $cita['hora_cita'] ? ' a las ' . substr($cita['hora_cita'], 0, 5) : '';
            $titulo = ($esHoy ? 'Cita hoy: ' : 'Cita mañana: ') . $cita['nombre'];
            $cuerpo = 'Consulta programada' . $hora . '. Toca para abrir el expediente.';

            $r = notificarSuscripciones($conexion, $suscripciones, $titulo, $cuerpo, 'expediente.php?id=' . (int) $cita['usuario_id']);
            $resumen['enviados'] += $r['enviados'];
            $resumen['eliminados'] += $r['eliminados'];

            $idExp = (int) $cita['expediente_id'];
            $marcar->bind_param("is", $idExp, $cita['fecha_cita']);
            $marcar->execute();

            if ($r['eliminados'] > 0) {
                $suscripciones = $conexion->query("SELECT id, endpoint FROM push_suscripciones")->fetch_all(MYSQLI_ASSOC);
            }
        }

        $marcar->close();

        return $resumen;
    }
}
