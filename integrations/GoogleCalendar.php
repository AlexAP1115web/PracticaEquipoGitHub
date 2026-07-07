<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   INTEGRACIÓN: GOOGLE CALENDAR API (sincronizar citas)
   -----------------------------------------------------
   Requiere que el médico haya iniciado sesión con Google
   (oauth_google.php) habiendo autorizado el scope de
   Calendar, y que GOOGLE_CALENDAR_ENABLED=true en .env.
   Si cualquiera de estas condiciones falta, la función
   regresa false de forma silenciosa (no bloquea el guardado
   del expediente/cita).
===================================================== */

require_once __DIR__ . '/../config/apis.php';

if (!function_exists('googleCalendarDisponible')) {
    function googleCalendarDisponible()
    {
        return apiConfig('GOOGLE_CALENDAR_ENABLED', 'false') === 'true'
            && apiHabilitada(['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET']);
    }
}

if (!function_exists('refrescarAccessTokenGoogle')) {
    function refrescarAccessTokenGoogle($refreshToken)
    {
        $ch = curl_init('https://oauth2.googleapis.com/token');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id'     => apiConfig('GOOGLE_CLIENT_ID'),
                'client_secret' => apiConfig('GOOGLE_CLIENT_SECRET'),
                'refresh_token' => $refreshToken,
                'grant_type'    => 'refresh_token'
            ]),
            CURLOPT_TIMEOUT => 10
        ]);

        $respuesta = curl_exec($ch);
        curl_close($ch);

        $datos = json_decode($respuesta, true) ?: [];

        return $datos['access_token'] ?? null;
    }
}

if (!function_exists('sincronizarCitaConGoogleCalendar')) {
    /**
     * Crea un evento en el Google Calendar del médico autenticado
     * en sesión, a partir de los datos de una cita del expediente.
     */
    function sincronizarCitaConGoogleCalendar($nombrePaciente, $fecha, $hora, $descripcion = '')
    {
        global $conexion;

        if (!googleCalendarDisponible()) {
            return false;
        }

        $medicoId = $_SESSION['medico_id'] ?? null;

        if (empty($medicoId) || empty($fecha)) {
            return false;
        }

        $stmt = $conexion->prepare("SELECT google_refresh_token FROM medicos WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $medicoId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (empty($fila['google_refresh_token'])) {
            if (function_exists('registrarLog')) {
                registrarLog("El médico ID $medicoId no ha vinculado Google Calendar. Se omite la sincronización.", "INFO");
            }
            return false;
        }

        $accessToken = refrescarAccessTokenGoogle($fila['google_refresh_token']);

        if (empty($accessToken)) {
            registrarLog("No se pudo refrescar el access token de Google Calendar para el médico ID $medicoId.", "WARNING");
            return false;
        }

        $horaInicio = !empty($hora) ? $hora : '09:00:00';
        $timestampInicio = strtotime("$fecha $horaInicio");
        $timestampFin = $timestampInicio + (30 * 60); // duración de 30 minutos

        $evento = [
            'summary'     => 'Cita médica MediCore - ' . $nombrePaciente,
            'description' => 'Diagnóstico/observaciones: ' . $descripcion,
            'start' => [
                'dateTime' => date('c', $timestampInicio),
                'timeZone' => 'America/Mexico_City'
            ],
            'end' => [
                'dateTime' => date('c', $timestampFin),
                'timeZone' => 'America/Mexico_City'
            ]
        ];

        $calendarId = apiConfig('GOOGLE_CALENDAR_ID', 'primary');

        $ch = curl_init("https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calendarId) . "/events");

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($evento),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 10
        ]);

        curl_exec($ch);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $exito = ($codigoHttp >= 200 && $codigoHttp < 300);

        registrarLog(
            "Sincronización con Google Calendar para médico ID $medicoId | HTTP $codigoHttp",
            $exito ? "INFO" : "WARNING"
        );

        return $exito;
    }
}
