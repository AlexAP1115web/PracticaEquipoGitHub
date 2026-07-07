<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   INTEGRACIÓN: GOOGLE OAUTH 2.0 (login institucional)
   -----------------------------------------------------
   Implementado con cURL puro (sin SDK de Google/Composer).
   Si GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET no están
   configurados en .env, apiHabilitada() regresa false y
   el botón de login con Google simplemente no se muestra
   (ver login.php), sin afectar el login tradicional.
===================================================== */

require_once __DIR__ . '/../config/apis.php';

if (!function_exists('googleOAuthDisponible')) {
    function googleOAuthDisponible()
    {
        return apiHabilitada(['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET']);
    }
}

if (!function_exists('construirUrlAutorizacionGoogle')) {
    function construirUrlAutorizacionGoogle($incluirCalendar = false)
    {
        $scopes = ['openid', 'email', 'profile'];

        if ($incluirCalendar) {
            $scopes[] = 'https://www.googleapis.com/auth/calendar.events';
        }

        $parametros = [
            'client_id'     => apiConfig('GOOGLE_CLIENT_ID'),
            'redirect_uri'  => apiConfig('GOOGLE_REDIRECT_URI'),
            'response_type' => 'code',
            'scope'         => implode(' ', $scopes),
            'access_type'   => 'offline',
            'prompt'        => 'consent select_account',
            'state'         => $_SESSION['oauth_state'] ?? ''
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($parametros);
    }
}

if (!function_exists('intercambiarCodigoGooglePorToken')) {
    function intercambiarCodigoGooglePorToken($codigo)
    {
        $ch = curl_init('https://oauth2.googleapis.com/token');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'code'          => $codigo,
                'client_id'     => apiConfig('GOOGLE_CLIENT_ID'),
                'client_secret' => apiConfig('GOOGLE_CLIENT_SECRET'),
                'redirect_uri'  => apiConfig('GOOGLE_REDIRECT_URI'),
                'grant_type'    => 'authorization_code'
            ]),
            CURLOPT_TIMEOUT => 10
        ]);

        $respuesta = curl_exec($ch);
        curl_close($ch);

        return json_decode($respuesta, true) ?: [];
    }
}

if (!function_exists('obtenerPerfilGoogle')) {
    function obtenerPerfilGoogle($accessToken)
    {
        $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_TIMEOUT => 10
        ]);

        $respuesta = curl_exec($ch);
        curl_close($ch);

        return json_decode($respuesta, true) ?: [];
    }
}
