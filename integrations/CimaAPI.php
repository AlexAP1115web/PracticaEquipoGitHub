<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   INTEGRACIÓN: CIMA - AEMPS (información oficial de
   medicamentos)
   -----------------------------------------------------
   CIMA es el Centro de Información de Medicamentos de la
   Agencia Española de Medicamentos y Productos Sanitarios.
   Su API REST es pública, gratuita, no pide llave y
   devuelve en español la ficha técnica de cada
   medicamento dividida por secciones:

     4.1 Indicaciones        4.5 Interacciones
     4.2 Posología           4.6 Embarazo y lactancia
     4.3 Contraindicaciones  4.8 Reacciones adversas
     4.4 Advertencias

   Documentación: https://cima.aemps.es/cima/rest/
===================================================== */

require_once __DIR__ . '/../config/apis.php';

if (!function_exists('cimaPeticion')) {
    function cimaPeticion($ruta, array $parametros = [])
    {
        $url = 'https://cima.aemps.es/cima/rest/' . $ruta;

        if (!empty($parametros)) {
            $url .= '?' . http_build_query($parametros);
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'MediCore-PWA/1.0'
        ]);

        $cuerpo = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorCurl = curl_error($ch);
        curl_close($ch);

        if ($codigo !== 200 || $cuerpo === false || $cuerpo === '') {
            if (function_exists('registrarLog')) {
                registrarLog("CIMA -> $ruta | HTTP $codigo" . ($errorCurl ? " | Error cURL: $errorCurl" : ""), "WARNING");
            }
            return null;
        }

        return $cuerpo;
    }
}

if (!function_exists('cimaBuscarMedicamento')) {
    /**
     * Busca un medicamento por nombre o principio activo y elige el
     * resultado más útil: comercializado, de un solo principio activo
     * que coincida con lo buscado y con ficha técnica por secciones.
     */
    function cimaBuscarMedicamento($termino)
    {
        $termino = trim((string) $termino);

        if (mb_strlen($termino) < 3) {
            return null;
        }

        $cuerpo = cimaPeticion('medicamentos', ['nombre' => $termino, 'comerc' => 1]);

        if ($cuerpo === null) {
            return null;
        }

        $datos = json_decode($cuerpo, true);
        $resultados = $datos['resultados'] ?? [];

        if (empty($resultados)) {
            return null;
        }

        $buscado = function_exists('normalizarTextoClinico')
            ? normalizarTextoClinico($termino)
            : mb_strtolower($termino);

        $mejor = null;
        $mejorPuntaje = -1;

        foreach ($resultados as $med) {
            $principio = $med['vtm']['nombre'] ?? '';
            $principioNorm = function_exists('normalizarTextoClinico') ? normalizarTextoClinico($principio) : mb_strtolower($principio);
            $nombreNorm = function_exists('normalizarTextoClinico') ? normalizarTextoClinico($med['nombre'] ?? '') : mb_strtolower($med['nombre'] ?? '');

            $fichaSeccionada = false;
            foreach ($med['docs'] ?? [] as $doc) {
                if ((int) ($doc['tipo'] ?? 0) === 1 && !empty($doc['secc'])) {
                    $fichaSeccionada = true;
                }
            }

            if (!$fichaSeccionada) {
                continue;
            }

            $puntaje = 0;
            if ($principioNorm === $buscado) $puntaje += 6;
            if (strpos($nombreNorm, $buscado) === 0) $puntaje += 3;
            if (!empty($med['comerc'])) $puntaje += 2;
            if (strpos($principio, '+') === false) $puntaje += 1;

            if ($puntaje > $mejorPuntaje) {
                $mejorPuntaje = $puntaje;
                $mejor = $med;
            }
        }

        if ($mejor === null) {
            return null;
        }

        return [
            'nregistro' => $mejor['nregistro'],
            'nombre'    => $mejor['nombre'] ?? $termino,
            'principio' => $mejor['vtm']['nombre'] ?? $termino,
            'receta'    => $mejor['cpresc'] ?? '',
            'enlace'    => 'https://cima.aemps.es/cima/publico/detalle.html?nregistro=' . rawurlencode($mejor['nregistro'])
        ];
    }
}

if (!function_exists('cimaHtmlATexto')) {
    function cimaHtmlATexto($html)
    {
        $html = preg_replace('/<\s*(br|\/p|\/li|\/tr|\/h[1-6]|\/div)\s*\/?>/i', "\n", (string) $html);
        $html = preg_replace('/<\s*li[^>]*>/i', "\n- ", $html);
        $texto = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $texto = preg_replace("/[ \t\x{00A0}]+/u", ' ', $texto);
        $texto = preg_replace("/\n\s*\n+/", "\n", $texto);

        return trim($texto);
    }
}

if (!function_exists('cimaSeccionFichaTecnica')) {
    /**
     * Devuelve el texto de una sección de la ficha técnica (tipo 1).
     */
    function cimaSeccionFichaTecnica($nregistro, $seccion)
    {
        $cuerpo = cimaPeticion('docSegmentado/contenido/1', [
            'nregistro' => $nregistro,
            'seccion'   => $seccion
        ]);

        if ($cuerpo === null) {
            return '';
        }

        // Según la cabecera Accept, CIMA responde JSON (con el HTML de la
        // sección en "contenido") o directamente el HTML.
        $json = json_decode($cuerpo, true);

        if (is_array($json)) {
            $partes = isset($json['contenido']) ? [$json] : $json;
            $html = '';

            foreach ($partes as $parte) {
                if (is_array($parte) && isset($parte['contenido'])) {
                    $html .= $parte['contenido'] . "\n";
                }
            }

            return cimaHtmlATexto($html);
        }

        return cimaHtmlATexto($cuerpo);
    }
}
