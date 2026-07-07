<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   INTEGRACIÓN: OPENWEATHER (clima actual)
   -----------------------------------------------------
   Cuenta gratuita: https://openweathermap.org/api
   Implementado con cURL puro. Si OPENWEATHER_API_KEY no
   está configurada, regresa null y el módulo de
   Recomendaciones simplemente oculta la sección de clima.
===================================================== */

require_once __DIR__ . '/../config/apis.php';

if (!function_exists('openWeatherDisponible')) {
    function openWeatherDisponible()
    {
        return apiHabilitada(['OPENWEATHER_API_KEY']);
    }
}

if (!function_exists('obtenerClimaActual')) {
    function obtenerClimaActual($ciudad = null)
    {
        if (!openWeatherDisponible()) {
            return null;
        }

        $ciudad = $ciudad ?: apiConfig('OPENWEATHER_CIUDAD', 'Puebla,MX');
        $apiKey = apiConfig('OPENWEATHER_API_KEY');

        $url = "https://api.openweathermap.org/data/2.5/weather?" . http_build_query([
            'q'     => $ciudad,
            'appid' => $apiKey,
            'units' => 'metric',
            'lang'  => 'es'
        ]);

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8
        ]);

        $respuesta = curl_exec($ch);
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($codigoHttp !== 200) {
            return null;
        }

        $datos = json_decode($respuesta, true);

        if (empty($datos['main'])) {
            return null;
        }

        return [
            'ciudad'      => $datos['name'] ?? $ciudad,
            'temperatura' => round($datos['main']['temp'] ?? 0, 1),
            'sensacion'   => round($datos['main']['feels_like'] ?? 0, 1),
            'humedad'     => $datos['main']['humidity'] ?? null,
            'descripcion' => ucfirst($datos['weather'][0]['description'] ?? ''),
            'icono'       => $datos['weather'][0]['icon'] ?? '01d',
            'condicion'   => $datos['weather'][0]['main'] ?? ''
        ];
    }
}

if (!function_exists('recomendacionPorClima')) {
    function recomendacionPorClima(array $clima)
    {
        $temp = $clima['temperatura'];
        $condicion = strtolower($clima['condicion']);
        $recomendaciones = [];

        if ($temp >= 30) {
            $recomendaciones[] = "Temperatura elevada: recomienda a tus pacientes hidratarse frecuentemente y evitar exposición solar prolongada.";
        } elseif ($temp <= 12) {
            $recomendaciones[] = "Temperatura baja: refuerza precauciones contra enfermedades respiratorias (gripe, bronquitis) y recomienda abrigo adecuado.";
        } else {
            $recomendaciones[] = "Temperatura templada: condiciones favorables, mantén las recomendaciones habituales de actividad física.";
        }

        if (strpos($condicion, 'rain') !== false || strpos($condicion, 'drizzle') !== false || strpos($condicion, 'storm') !== false) {
            $recomendaciones[] = "Se esperan lluvias: advierte sobre riesgo de resbalones y recomienda evitar traslados innecesarios a pacientes de edad avanzada.";
        }

        if (strpos($condicion, 'clear') !== false && $temp >= 25) {
            $recomendaciones[] = "Cielo despejado y calor: recuerda el uso de protector solar, sobre todo en pacientes con tratamientos dermatológicos.";
        }

        return $recomendaciones;
    }
}
