<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   INTEGRACIÓN: GOOGLE GEMINI (IA generativa)
   La usan el chatbot, el escáner y las notas SOAP. Sin GEMINI_API_KEY el escáner y las notas se apagan
   GEMINI_MODEL es opcional. Llave: https://aistudio.google.com/apikey
===================================================== */

require_once __DIR__ . '/../config/apis.php';

if (!function_exists('geminiDisponible')) {
    function geminiDisponible()
    {
        return apiHabilitada(['GEMINI_API_KEY']);
    }
}

if (!function_exists('geminiLlamar')) {
    // $partes son de texto o imagen (inlineData). Regresa null si no responde
    function geminiLlamar($instruccionSistema, array $partes, $maxTokens = 700, $comoJson = false, $modelo = null)
    {
        if (!geminiDisponible()) {
            return null;
        }

        $modelo = $modelo ?: apiConfig('GEMINI_MODEL', 'gemini-3.5-flash-lite');
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($modelo) . ':generateContent';

        $configuracion = [
            'temperature'     => 0.2,
            'maxOutputTokens' => $maxTokens
        ];

        if ($comoJson) {
            $configuracion['responseMimeType'] = 'application/json';
        }

        $cuerpo = [
            'systemInstruction' => ['parts' => [['text' => $instruccionSistema]]],
            'contents'          => [['role' => 'user', 'parts' => $partes]],
            'generationConfig'  => $configuracion
        ];

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                // La llave va en cabecera, no en la URL, para que no quede en logs
                'x-goog-api-key: ' . apiConfig('GEMINI_API_KEY')
            ],
            CURLOPT_POSTFIELDS     => json_encode($cuerpo, JSON_UNESCAPED_UNICODE)
        ]);

        $respuesta = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorCurl = curl_error($ch);
        curl_close($ch);

        // Si el modelo configurado ya no existe, se intenta una vez con otro
        $respaldo = 'gemini-3.8-flash';

        if ($codigo === 404 && $modelo !== $respaldo) {
            if (function_exists('registrarLog')) {
                registrarLog("Gemini: el modelo $modelo no está disponible, se usa $respaldo.", "WARNING");
            }
            return geminiLlamar($instruccionSistema, $partes, $maxTokens, $comoJson, $respaldo);
        }

        if ($codigo !== 200) {
            if (function_exists('registrarLog')) {
                registrarLog(
                    "Gemini ($modelo) -> HTTP $codigo" .
                        ($errorCurl ? " | Error cURL: $errorCurl" : "") .
                        " | " . substr((string) $respuesta, 0, 200),
                    "WARNING"
                );
            }
            return null;
        }

        $datos = json_decode($respuesta, true);
        $texto = '';

        foreach ($datos['candidates'][0]['content']['parts'] ?? [] as $parte) {
            if (isset($parte['text'])) {
                $texto .= $parte['text'];
            }
        }

        $texto = trim($texto);

        return $texto !== '' ? $texto : null;
    }
}

if (!function_exists('geminiGenerar')) {
    function geminiGenerar($instruccionSistema, $mensaje, $maxTokens = 700, $comoJson = false, $modelo = null)
    {
        return geminiLlamar($instruccionSistema, [['text' => $mensaje]], $maxTokens, $comoJson, $modelo);
    }
}

if (!function_exists('geminiAnalizarImagen')) {
    // Foto en base64 + instrucción (los modelos Flash sí leen texto de cajas y recetas)
    function geminiAnalizarImagen($instruccionSistema, $mensaje, $tipoMime, $base64, $maxTokens = 500, $comoJson = true)
    {
        return geminiLlamar($instruccionSistema, [
            ['inlineData' => ['mimeType' => $tipoMime, 'data' => $base64]],
            ['text' => $mensaje]
        ], $maxTokens, $comoJson);
    }
}

if (!function_exists('geminiJson')) {
    // A veces el JSON viene entre ```json ```
    function geminiJson($texto)
    {
        if (!$texto) {
            return null;
        }

        $texto = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($texto)));
        $datos = json_decode($texto, true);

        return is_array($datos) ? $datos : null;
    }
}
