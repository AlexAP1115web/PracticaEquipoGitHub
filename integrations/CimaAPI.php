<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   INTEGRACIÓN: CIMA - AEMPS (información oficial de medicamentos)
   API pública sin llave, regresa la ficha técnica por secciones (4.1 a 4.8)
   Docs: https://cima.aemps.es/cima/rest/
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

// Visor oficial de registros sanitarios de México (no tiene API, solo se enlaza)
if (!defined('COFEPRIS_VISOR')) {
    define('COFEPRIS_VISOR', 'https://registros.cofepris.gob.mx/BRSDM/');
}

if (!function_exists('cimaNucleoPrincipio')) {
    // Deja solo el nombre del fármaco ("clorhidrato de metformina" queda "metformina").
    // En CIMA "ácido ascórbico" está como "ASCORBICO ACIDO", por eso se quita "ácido" y la sal
    function cimaNucleoPrincipio($texto)
    {
        $texto = function_exists('normalizarTextoClinico') ? normalizarTextoClinico($texto) : mb_strtolower(trim((string) $texto));
        $sobran = [
            'acido', 'de', 'sodico', 'sodica', 'sodio', 'potasico', 'potasica', 'potasio', 'calcico', 'calcica', 'calcio',
            'magnesico', 'clorhidrato', 'hidrocloruro', 'bromhidrato', 'hidrobromuro', 'bromuro', 'maleato', 'sulfato',
            'fosfato', 'citrato', 'tartrato', 'succinato', 'besilato', 'mesilato', 'monohidrato', 'dihidrato', 'trihidrato'
        ];

        $palabras = array_filter(explode(' ', $texto), function ($p) use ($sobran) {
            return $p !== '' && !in_array($p, $sobran, true);
        });

        return implode(' ', $palabras);
    }
}

if (!function_exists('cimaMejorResultado')) {
    // Se queda con el que tenga ficha por secciones y mejor puntaje (principio igual, comercializado, uno solo).
    // $mismoPrincipio = true descarta los que no tengan exactamente ese principio activo
    function cimaMejorResultado(array $parametros, $termino, $mismoPrincipio = false)
    {
        $cuerpo = cimaPeticion('medicamentos', $parametros + ['comerc' => 1]);

        if ($cuerpo === null) {
            return null;
        }

        $datos = json_decode($cuerpo, true);
        $resultados = $datos['resultados'] ?? [];

        if (empty($resultados)) {
            return null;
        }

        $buscado = function_exists('normalizarTextoClinico') ? normalizarTextoClinico($termino) : mb_strtolower($termino);
        $nucleo = cimaNucleoPrincipio($termino);

        $mejor = null;
        $mejorPuntaje = -1;

        foreach ($resultados as $med) {
            $principio = $med['vtm']['nombre'] ?? '';
            $principioNorm = function_exists('normalizarTextoClinico') ? normalizarTextoClinico($principio) : mb_strtolower($principio);
            $nombreNorm = function_exists('normalizarTextoClinico') ? normalizarTextoClinico($med['nombre'] ?? '') : mb_strtolower($med['nombre'] ?? '');
            $igual = $principioNorm === $buscado || ($nucleo !== '' && cimaNucleoPrincipio($principio) === $nucleo);

            if ($mismoPrincipio && !$igual) {
                continue;
            }

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
            if ($igual) $puntaje += 6;
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
            'nregistro'     => $mejor['nregistro'],
            'nombre'        => $mejor['nombre'] ?? $termino,
            'principio'     => trim($mejor['vtm']['nombre'] ?? $termino),
            'receta'        => $mejor['cpresc'] ?? '',
            'enlace'        => 'https://cima.aemps.es/cima/publico/detalle.html?nregistro=' . rawurlencode($mejor['nregistro']),
            'por_principio' => false
        ];
    }
}

if (!function_exists('cimaBuscarPorPrincipio')) {
    // Para marcas de México: se busca otro medicamento con el mismo principio activo y se usa su ficha como referencia
    function cimaBuscarPorPrincipio($termino)
    {
        $nucleo = cimaNucleoPrincipio($termino);

        if (mb_strlen($nucleo) < 4) {
            return null;
        }

        $variantes = [$nucleo];
        $palabras = explode(' ', $nucleo);

        if (count($palabras) > 1) {
            usort($palabras, function ($a, $b) {
                return mb_strlen($b) <=> mb_strlen($a);
            });
            $variantes[] = $palabras[0];
        }

        foreach ($variantes as $variante) {
            $med = cimaMejorResultado(['practiv1' => $variante], $termino, true);

            if ($med) {
                $med['por_principio'] = true;
                return $med;
            }
        }

        return null;
    }
}

if (!function_exists('cimaBuscarMedicamento')) {
    // Primero por nombre; si no sale (marca mexicana o "ácido ..."), por principio activo
    function cimaBuscarMedicamento($termino, $tambienPorPrincipio = true)
    {
        $termino = trim((string) $termino);

        if (mb_strlen($termino) < 3) {
            return null;
        }

        $med = cimaMejorResultado(['nombre' => $termino], $termino);

        if ($med || !$tambienPorPrincipio) {
            return $med;
        }

        return cimaBuscarPorPrincipio($termino);
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
    // doc tipo 1 = ficha técnica
    function cimaSeccionFichaTecnica($nregistro, $seccion)
    {
        $cuerpo = cimaPeticion('docSegmentado/contenido/1', [
            'nregistro' => $nregistro,
            'seccion'   => $seccion
        ]);

        if ($cuerpo === null) {
            return '';
        }

        // A veces regresa JSON con el HTML en "contenido" y a veces el HTML directo
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
