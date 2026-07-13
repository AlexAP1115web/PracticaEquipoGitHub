<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   INTEGRACIÓN: TWILIO (código OTP como segundo factor)
   -----------------------------------------------------
   Implementado con cURL puro (sin SDK de Twilio/Composer).
   Se activa solo si OTP_ENABLED=true en .env Y el médico
   tiene un teléfono registrado. Si falta cualquiera de las
   dos condiciones, el login sigue funcionando exactamente
   igual que antes (un solo factor: correo + contraseña).
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
    /**
     * Envía un SMS con el código OTP mediante la API REST de Twilio.
     *
     * @return bool true si Twilio aceptó el mensaje (HTTP 2xx)
     */
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
