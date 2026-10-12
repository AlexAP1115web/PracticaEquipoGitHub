<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   INTEGRACIÓN: GOOGLE GEMINI (IA generativa)
   -----------------------------------------------------
   Se usa para:
   1) entender qué medicamento y qué tema pregunta el
      médico cuando la pregunta no es obvia,
   2) resumir en lenguaje claro el texto oficial de CIMA,
   3) leer el nombre del medicamento en una foto de la
      caja o el frasco (escáner), y
   4) ordenar en formato SOAP la nota de consulta que el
      médico dicta por voz.

   Llave gratuita: https://aistudio.google.com/apikey
   Variables: GEMINI_API_KEY y, opcional, GEMINI_MODEL.
   Si no hay llave, el asistente sigue funcionando y
   muestra directamente el texto oficial de CIMA; el
   escáner y el ordenado de notas se desactivan.
===================================================== */

require_once __DIR__ . '/../config/apis.php';

if (!function_exists('geminiDisponible')) {
    function geminiDisponible()
    {
        return apiHabilitada(['GEMINI_API_KEY']);
    }
}

if (!function_exists('geminiLlamar')) {
    /**
     * Petición a Gemini con una o varias partes (texto o imagen).
     *
     * @param array $partes Ej. [['text' => '...']] o [['inlineData' => [...]], ['text' => '...']]
     * @return string|null Texto generado o null si la IA no respondió.
     */
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
    /**
     * @return string|null Texto generado o null si la IA no respondió.
     */
    function geminiGenerar($instruccionSistema, $mensaje, $maxTokens = 700, $comoJson = false, $modelo = null)
    {
        return geminiLlamar($instruccionSistema, [['text' => $mensaje]], $maxTokens, $comoJson, $modelo);
    }
}

if (!function_exists('geminiAnalizarImagen')) {
    /**
     * Manda una foto (base64) junto con una instrucción. Los modelos Flash
     * de Gemini leen texto en imágenes (cajas, frascos, recetas).
     */
    function geminiAnalizarImagen($instruccionSistema, $mensaje, $tipoMime, $base64, $maxTokens = 500, $comoJson = true)
    {
        return geminiLlamar($instruccionSistema, [
            ['inlineData' => ['mimeType' => $tipoMime, 'data' => $base64]],
            ['text' => $mensaje]
        ], $maxTokens, $comoJson);
    }
}

if (!function_exists('geminiJson')) {
    /**
     * Decodifica la respuesta JSON de Gemini (a veces viene entre ```json ... ```).
     */
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
