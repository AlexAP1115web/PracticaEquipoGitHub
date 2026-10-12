<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   INTEGRACIÓN: TWILIO (código OTP como segundo factor)
   Solo se usa con OTP_ENABLED=true y si el médico tiene teléfono,
   si no el login queda normal con correo y contraseña
===================================================== */

require_once __DIR__ . '/../config/apis.php';

if (!function_exists('otpHabilitado')) {
    function otpHabilitado()
    {
        return apiConfig('OTP_ENABLED', 'false') === 'true'
            && apiHabilitada(['TWILIO_ACCOUNT_SID', 'TWILIO_AUTH_TOKEN', 'TWILIO_FROM_NUMBER']);
    }
}

if (!function_exists('generarCodigoOTP')) {
    function generarCodigoOTP()
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('enviarOTPTwilio')) {
    // true si Twilio responde 2xx
    function enviarOTPTwilio($telefonoDestino, $codigo)
    {
        $sid = apiConfig('TWILIO_ACCOUNT_SID');
        $token = apiConfig('TWILIO_AUTH_TOKEN');
        $desde = apiConfig('TWILIO_FROM_NUMBER');

        if (empty($sid) || empty($token) || empty($desde)) {
            return false;
        }

        $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json");

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => "$sid:$token",
            CURLOPT_POSTFIELDS => http_build_query([
                'To'   => $telefonoDestino,
                'From' => $desde,
                'Body' => "MediCore: tu código de verificación es $codigo. Válido por 5 minutos."
            ]),
            CURLOPT_TIMEOUT => 10
        ]);

        $respuesta = curl_exec($ch);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorCurl = curl_error($ch);
        curl_close($ch);

        $exito = ($codigoHttp >= 200 && $codigoHttp < 300);

        if (function_exists('registrarLog')) {
            registrarLog(
                "Envío de OTP por Twilio a $telefonoDestino | HTTP $codigoHttp" .
                    ($errorCurl ? " | Error cURL: $errorCurl" : "") .
                    (!$exito ? " | Respuesta: " . substr((string)$respuesta, 0, 300) : ""),
                $exito ? "INFO" : "WARNING"
            );
        }

        return $exito;
    }
}
