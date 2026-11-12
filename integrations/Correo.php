<?php
/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   CORREOS: recuperación de contraseña, citas y traslados
   Se envían por la API HTTPS del proveedor porque Railway (plan Hobby) bloquea SMTP.
   - Brevo: plan gratis de 300 correos al día, a cualquier destinatario (BREVO_API_KEY).
   - SendGrid: solo con plan de pago, ya no tiene plan gratis (SENDGRID_API_KEY).
   El remitente debe estar verificado en el proveedor (MAIL_FROM_EMAIL).
===================================================== */

require_once __DIR__ . '/../config/apis.php';

if (!function_exists('proveedorCorreo')) {
    function proveedorCorreo()
    {
        if (apiHabilitada(['BREVO_API_KEY'])) {
            return 'brevo';
        }

        if (apiHabilitada(['SENDGRID_API_KEY'])) {
            return 'sendgrid';
        }

        return null;
    }
}

if (!function_exists('remitenteCorreo')) {
    function remitenteCorreo()
    {
        $email = apiConfig('MAIL_FROM_EMAIL', apiConfig('BREVO_FROM_EMAIL', apiConfig('SENDGRID_FROM_EMAIL', '')));
        $nombre = apiConfig('MAIL_FROM_NAME', apiConfig('SENDGRID_FROM_NAME', 'MediCore Professional System'));

        return ['email' => trim($email), 'nombre' => $nombre];
    }
}

if (!function_exists('urlBaseApp')) {
    // APP_URL si está configurada; si no, la dirección con la que se abrió la página (Railway pasa X-Forwarded-Proto)
    function urlBaseApp()
    {
        $url = rtrim(apiConfig('APP_URL', ''), '/');

        if ($url !== '') {
            return $url;
        }

        $https = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $carpeta = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

        return ($https ? 'https://' : 'http://') . $host . $carpeta;
    }
}

if (!function_exists('peticionCorreo')) {
    function peticionCorreo($url, array $cabeceras, array $cuerpo)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($cuerpo, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => array_merge($cabeceras, ['Content-Type: application/json', 'Accept: application/json']),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 12
        ]);

        $respuesta = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorCurl = curl_error($ch);
        curl_close($ch);

        $datos = json_decode((string) $respuesta, true);
        $error = '';

        if ($codigo < 200 || $codigo >= 300) {
            // Brevo: {"code": "...", "message": "..."}; SendGrid: {"errors": [{"message": "..."}]}
            $error = $errorCurl ?: ($datos['message'] ?? ($datos['errors'][0]['message'] ?? "HTTP $codigo"));
        }

        return ['codigo' => $codigo, 'error' => $error];
    }
}

if (!function_exists('enviarCorreo')) {
    // Regresa ['ok' => bool, 'proveedor' => 'brevo'|'sendgrid'|null, 'error' => texto para el médico]
    function enviarCorreo($destinatarioEmail, $destinatarioNombre, $asunto, $contenidoHtml)
    {
        $destinatarioEmail = trim((string) $destinatarioEmail);
        $proveedor = proveedorCorreo();
        $remitente = remitenteCorreo();
        $resultado = ['ok' => false, 'proveedor' => $proveedor, 'error' => ''];

        if (!filter_var($destinatarioEmail, FILTER_VALIDATE_EMAIL)) {
            $resultado['error'] = 'El correo del destinatario no es válido.';
        } elseif ($proveedor === null) {
            $resultado['error'] = 'No hay servicio de correo configurado (falta BREVO_API_KEY en el servidor).';
        } elseif (!filter_var($remitente['email'], FILTER_VALIDATE_EMAIL)) {
            $resultado['error'] = 'Falta MAIL_FROM_EMAIL: el correo remitente verificado en ' . ucfirst($proveedor) . '.';
        } elseif ($proveedor === 'brevo') {
            $r = peticionCorreo('https://api.brevo.com/v3/smtp/email', ['api-key: ' . apiConfig('BREVO_API_KEY')], [
                'sender'      => ['email' => $remitente['email'], 'name' => $remitente['nombre']],
                'to'          => [['email' => $destinatarioEmail, 'name' => $destinatarioNombre ?: $destinatarioEmail]],
                'subject'     => $asunto,
                'htmlContent' => $contenidoHtml
            ]);
            $resultado['ok'] = $r['error'] === '';
            $resultado['error'] = $r['error'];
        } else {
            $r = peticionCorreo('https://api.sendgrid.com/v3/mail/send', ['Authorization: Bearer ' . apiConfig('SENDGRID_API_KEY')], [
                'personalizations' => [['to' => [['email' => $destinatarioEmail, 'name' => $destinatarioNombre ?: $destinatarioEmail]]]],
                'from'             => ['email' => $remitente['email'], 'name' => $remitente['nombre']],
                'subject'          => $asunto,
                'content'          => [['type' => 'text/html', 'value' => $contenidoHtml]]
            ]);
            $resultado['ok'] = $r['error'] === '';
            $resultado['error'] = $r['error'];
        }

        if (function_exists('registrarLog')) {
            registrarLog(
                'Correo (' . ($proveedor ?: 'sin proveedor') . ") -> $destinatarioEmail | $asunto | " .
                    ($resultado['ok'] ? 'enviado' : 'no enviado: ' . $resultado['error']),
                $resultado['ok'] ? 'INFO' : 'WARNING'
            );
        }

        return $resultado;
    }
}
