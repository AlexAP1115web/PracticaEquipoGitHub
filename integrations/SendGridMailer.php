<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   INTEGRACIÓN: SENDGRID (correos transaccionales)
   -----------------------------------------------------
   Implementado con cURL puro (sin SDK/Composer) para que
   funcione de inmediato. Si SENDGRID_API_KEY no está
   configurada en .env, la función regresa false sin
   generar errores ni romper la página que la llama.
===================================================== */

require_once __DIR__ . '/../config/apis.php';

if (!function_exists('sendGridDisponible')) {
    function sendGridDisponible()
    {
        return apiHabilitada(['SENDGRID_API_KEY']);
    }
}

if (!function_exists('enviarCorreoSendGrid')) {
    /**
     * Envía un correo transaccional usando la API v3 de SendGrid.
     *
     * @param string $destinatarioEmail
     * @param string $destinatarioNombre
     * @param string $asunto
     * @param string $contenidoHtml
     * @return bool true si SendGrid aceptó el envío (HTTP 2xx)
     */
    function enviarCorreoSendGrid($destinatarioEmail, $destinatarioNombre, $asunto, $contenidoHtml)
    {
        if (!sendGridDisponible()) {

            if (function_exists('registrarLog')) {
                registrarLog(
                    "SendGrid no configurado. No se envió correo a $destinatarioEmail (asunto: $asunto).",
                    "WARNING"
                );
            }

            return false;
        }

        $apiKey    = apiConfig('SENDGRID_API_KEY');
        $fromEmail = apiConfig('SENDGRID_FROM_EMAIL', 'notificaciones@medicore.com');
        $fromName  = apiConfig('SENDGRID_FROM_NAME', 'MediCore Professional System');

        $payload = [
            'personalizations' => [[
                'to' => [[
                    'email' => $destinatarioEmail,
                    'name'  => $destinatarioNombre
                ]],
                'subject' => $asunto
            ]],
            'from' => [
                'email' => $fromEmail,
                'name'  => $fromName
            ],
            'content' => [[
                'type'  => 'text/html',
                'value' => $contenidoHtml
            ]]
        ];

        $ch = curl_init('https://api.sendgrid.com/v3/mail/send');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 10
        ]);

        curl_exec($ch);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorCurl  = curl_error($ch);
        curl_close($ch);

        $exito = ($codigoHttp >= 200 && $codigoHttp < 300);

        if (function_exists('registrarLog')) {
            registrarLog(
                "SendGrid -> $destinatarioEmail | Asunto: $asunto | HTTP $codigoHttp" .
                    ($errorCurl ? " | Error cURL: $errorCurl" : ""),
                $exito ? "INFO" : "WARNING"
            );
        }

        return $exito;
    }
}
