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

if (!function_exists('notificarVarios')) {
    // Guarda los mensajes de cada suscripción y despierta UNA sola vez a su service worker,
    // que recoge todos los pendientes. Antes se mandaba un push por mensaje: el primero se
    // llevaba todos los mensajes y los demás llegaban como "Tienes avisos nuevos en MediCore".
    function notificarVarios($conexion, array $suscripciones, array $mensajes)
    {
        $enviados = 0;
        $eliminados = 0;

        if (empty($mensajes)) {
            return ['enviados' => 0, 'eliminados' => 0];
        }

        $insertar = $conexion->prepare("
            INSERT INTO push_mensajes (suscripcion_id, titulo, cuerpo, url)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($suscripciones as $s) {
            $id = (int) $s['id'];

            foreach ($mensajes as $m) {
                $titulo = mb_substr($m['titulo'], 0, 150);
                $cuerpo = mb_substr($m['cuerpo'], 0, 500);
                $url = $m['url'] ?? 'index.php';
                $insertar->bind_param("isss", $id, $titulo, $cuerpo, $url);
                $insertar->execute();
            }

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

if (!function_exists('notificarSuscripciones')) {
    function notificarSuscripciones($conexion, array $suscripciones, $titulo, $cuerpo, $url = 'index.php')
    {
        return notificarVarios($conexion, $suscripciones, [['titulo' => $titulo, 'cuerpo' => $cuerpo, 'url' => $url]]);
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

if (!function_exists('fechaCitaEnTexto')) {
    // '2026-11-02' -> 'lunes 2 de noviembre'
    function fechaCitaEnTexto($fecha)
    {
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $t = strtotime($fecha);

        return $dias[(int) date('w', $t)] . ' ' . date('j', $t) . ' de ' . $meses[(int) date('n', $t) - 1];
    }
}

if (!function_exists('enviarRecordatoriosDeCitas')) {
    // Avisos de cada cita: una semana antes (o al agendarla si faltan 2 a 7 días), un día antes
    // y el mismo día. Van a todos los dispositivos porque todos los médicos ven a todos los pacientes.
    // Las fechas salen de PHP (hora de México); en Railway MySQL está en UTC y en la noche
    // CURDATE() ya era el día siguiente.
    function enviarRecordatoriosDeCitas($conexion)
    {
        $hoy = date('Y-m-d');
        $limite = date('Y-m-d', strtotime($hoy . ' +7 days'));
        $horaActual = date('H:i');

        $stmt = $conexion->prepare("
            SELECT e.id AS expediente_id, e.usuario_id, e.fecha_cita, e.hora_cita, u.nombre
            FROM expedientes e
            INNER JOIN usuarios u ON u.id = e.usuario_id
            WHERE e.fecha_cita BETWEEN ? AND ?
            ORDER BY e.fecha_cita, e.hora_cita
        ");
        $stmt->bind_param("ss", $hoy, $limite);
        $stmt->execute();
        $citas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $resumen = ['citas' => 0, 'enviados' => 0, 'eliminados' => 0, 'dispositivos' => 0];

        // Qué aviso le toca a cada cita y si ya se mandó
        $yaEnviado = $conexion->prepare("SELECT 1 FROM push_recordatorios WHERE expediente_id = ? AND fecha_cita = ? AND aviso = ? LIMIT 1");
        $pendientes = [];

        foreach ($citas as $cita) {
            $faltan = (int) round((strtotime($cita['fecha_cita']) - strtotime($hoy)) / 86400);
            $hora = $cita['hora_cita'] ? substr($cita['hora_cita'], 0, 5) : '';

            if ($faltan === 0) {
                // Una cita de hoy que ya pasó no se avisa
                if ($hora !== '' && $hora < $horaActual) {
                    continue;
                }
                $aviso = 'hoy';
            } elseif ($faltan === 1) {
                $aviso = 'manana';
            } else {
                $aviso = 'semana';
            }

            $idExp = (int) $cita['expediente_id'];
            $yaEnviado->bind_param("iss", $idExp, $cita['fecha_cita'], $aviso);
            $yaEnviado->execute();

            if ($yaEnviado->get_result()->fetch_row()) {
                continue;
            }

            $cita['aviso'] = $aviso;
            $cita['faltan'] = $faltan;
            $cita['hora'] = $hora;
            $pendientes[] = $cita;
        }

        $yaEnviado->close();
        $resumen['citas'] = count($pendientes);

        $suscripciones = $conexion->query("SELECT id, endpoint FROM push_suscripciones")->fetch_all(MYSQLI_ASSOC);
        $resumen['dispositivos'] = count($suscripciones);

        // Sin dispositivos no se marca nada, así se avisa cuando alguien active las notificaciones
        if (empty($pendientes) || empty($suscripciones)) {
            return $resumen;
        }

        // Se marca antes de mandar: si dos páginas revisan al mismo tiempo, solo una manda el aviso
        $marcar = $conexion->prepare("INSERT IGNORE INTO push_recordatorios (expediente_id, fecha_cita, aviso) VALUES (?, ?, ?)");
        $mensajes = [];
        $conteo = ['hoy' => 0, 'manana' => 0, 'semana' => 0];

        foreach ($pendientes as $cita) {
            $idExp = (int) $cita['expediente_id'];
            $marcar->bind_param("iss", $idExp, $cita['fecha_cita'], $cita['aviso']);
            $marcar->execute();

            if ($marcar->affected_rows !== 1) {
                continue;
            }

            $conteo[$cita['aviso']]++;
            $aLas = $cita['hora'] !== '' ? ' a las ' . $cita['hora'] : '';
            $url = 'expediente.php?id=' . (int) $cita['usuario_id'];

            if ($cita['aviso'] === 'hoy') {
                $mensajes[] = ['titulo' => 'Cita hoy: ' . $cita['nombre'], 'cuerpo' => 'Consulta' . $aLas . '. Toca para abrir el expediente.', 'url' => $url];
            } elseif ($cita['aviso'] === 'manana') {
                $mensajes[] = ['titulo' => 'Cita mañana: ' . $cita['nombre'], 'cuerpo' => 'Consulta el ' . fechaCitaEnTexto($cita['fecha_cita']) . $aLas . '. Toca para abrir el expediente.', 'url' => $url];
            } else {
                $mensajes[] = ['titulo' => 'Próxima cita: ' . $cita['nombre'], 'cuerpo' => 'El ' . fechaCitaEnTexto($cita['fecha_cita']) . $aLas . ' (en ' . $cita['faltan'] . ' días). Toca para abrir el expediente.', 'url' => $url];
            }
        }

        $marcar->close();

        // Muchas citas de golpe: un solo aviso con el resumen en lugar de llenar la pantalla
        if (count($mensajes) > 4) {
            $partes = [];
            if ($conteo['hoy']) $partes[] = $conteo['hoy'] . ' hoy';
            if ($conteo['manana']) $partes[] = $conteo['manana'] . ' mañana';
            if ($conteo['semana']) $partes[] = $conteo['semana'] . ' en los próximos días';
            $mensajes = [[
                'titulo' => 'Tienes ' . count($mensajes) . ' citas próximas',
                'cuerpo' => ucfirst(implode(', ', $partes)) . '. Toca para ver la agenda.',
                'url'    => 'agenda.php'
            ]];
        }

        $r = notificarVarios($conexion, $suscripciones, $mensajes);
        $resumen['citas'] = array_sum($conteo);
        $resumen['enviados'] = $r['enviados'];
        $resumen['eliminados'] = $r['eliminados'];

        return $resumen;
    }
}

if (!function_exists('revisarRecordatoriosEnSegundoPlano')) {
    // Railway no tiene cron: cuando el médico abre una página (como máximo cada 10 minutos) o guarda
    // una cita, se revisan los avisos después de mandar la página, así no la hace más lenta.
    // $ahora = true no espera los 10 minutos (al guardar una cita)
    function revisarRecordatoriosEnSegundoPlano($conexion, $ahora = false)
    {
        static $programado = false;

        if ($programado || !pushDisponible()) {
            return;
        }

        if (!$ahora) {
            $marcas = [
                __DIR__ . '/../logs/.ultimos_recordatorios',
                __DIR__ . '/../sessions_data/.ultimos_recordatorios'
            ];
            $ultima = 0;

            foreach ($marcas as $marca) {
                if (file_exists($marca)) {
                    $ultima = max($ultima, (int) @filemtime($marca));
                }
            }

            if (time() - $ultima < 600) {
                return;
            }

            foreach ($marcas as $marca) {
                if (@touch($marca)) {
                    break;
                }
            }
        }

        $programado = true;

        register_shutdown_function(function () use ($conexion) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            try {
                $r = enviarRecordatoriosDeCitas($conexion);

                if ($r['citas'] > 0 && function_exists('registrarLog')) {
                    registrarLog("Push: recordatorios de " . $r['citas'] . " cita(s), entregados en " . $r['enviados'] . " dispositivo(s).", "INFO");
                }
            } catch (Throwable $e) {
                if (function_exists('registrarLog')) {
                    registrarLog("Push: no se pudieron revisar los recordatorios: " . $e->getMessage(), "WARNING");
                }
            }
        });
    }
}
