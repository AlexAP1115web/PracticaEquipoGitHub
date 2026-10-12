<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   DETECCIÓN DE CONFLICTOS ENTRE ALERGIAS Y MEDICAMENTOS
   -----------------------------------------------------
   Compara las alergias registradas del paciente contra un
   texto (la receta del expediente o el medicamento que se
   consulta en el asistente) y avisa si aparece:

   - el mismo fármaco al que es alérgico, o
   - otro fármaco del mismo grupo (ej. alérgico a la
     penicilina y le recetan amoxicilina), o
   - un grupo con reactividad cruzada conocida, como
     precaución (ej. penicilinas -> cefalosporinas).

   Es una ayuda para el médico, no sustituye su criterio.
===================================================== */

if (!function_exists('normalizarTextoClinico')) {
    function normalizarTextoClinico($texto)
    {
        $texto = html_entity_decode((string) $texto, ENT_QUOTES, 'UTF-8');
        $texto = mb_strtolower($texto, 'UTF-8');
        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i',
            'ò' => 'o', 'ù' => 'u'
        ]);
        $texto = preg_replace('/[^a-z0-9]+/', ' ', $texto);

        return trim(preg_replace('/\s+/', ' ', $texto));
    }
}

if (!function_exists('gruposDeAlergia')) {
    /**
     * Cada grupo tiene los nombres con los que el médico suele
     * escribir la alergia (alias) y los fármacos que pertenecen a él.
     * "cruzada" son grupos con posible reactividad cruzada.
     */
    function gruposDeAlergia()
    {
        return [
            'penicilinas' => [
                'nombre'  => 'penicilinas (betalactámicos)',
                'alias'   => ['penicilina', 'penicilinas', 'betalactamicos', 'betalactamico'],
                'farmacos' => ['penicilina', 'bencilpenicilina', 'amoxicilina', 'ampicilina', 'dicloxacilina', 'cloxacilina', 'piperacilina', 'fenoximetilpenicilina'],
                'cruzada' => ['cefalosporinas']
            ],
            'cefalosporinas' => [
                'nombre'  => 'cefalosporinas',
                'alias'   => ['cefalosporina', 'cefalosporinas'],
                'farmacos' => ['cefalexina', 'cefadroxilo', 'cefuroxima', 'cefaclor', 'ceftriaxona', 'cefotaxima', 'cefixima', 'cefazolina', 'cefepima', 'ceftazidima'],
                'cruzada' => ['penicilinas']
            ],
            'aines' => [
                'nombre'  => 'AINEs (antiinflamatorios no esteroideos)',
                'alias'   => ['aine', 'aines', 'antiinflamatorio', 'antiinflamatorios', 'aspirina', 'asa'],
                'farmacos' => ['ibuprofeno', 'naproxeno', 'diclofenaco', 'ketorolaco', 'ketoprofeno', 'dexketoprofeno', 'acido acetilsalicilico', 'aspirina', 'indometacina', 'meloxicam', 'piroxicam', 'nimesulida', 'aceclofenaco', 'celecoxib', 'etoricoxib'],
                'cruzada' => []
            ],
            'sulfonamidas' => [
                'nombre'  => 'sulfonamidas',
                'alias'   => ['sulfa', 'sulfas', 'sulfonamida', 'sulfonamidas'],
                'farmacos' => ['sulfametoxazol', 'trimetoprima sulfametoxazol', 'cotrimoxazol', 'sulfadiazina', 'sulfasalazina'],
                'cruzada' => []
            ],
            'macrolidos' => [
                'nombre'  => 'macrólidos',
                'alias'   => ['macrolido', 'macrolidos'],
                'farmacos' => ['azitromicina', 'claritromicina', 'eritromicina', 'roxitromicina'],
                'cruzada' => []
            ],
            'quinolonas' => [
                'nombre'  => 'quinolonas',
                'alias'   => ['quinolona', 'quinolonas', 'fluoroquinolona', 'fluoroquinolonas'],
                'farmacos' => ['ciprofloxacino', 'levofloxacino', 'moxifloxacino', 'norfloxacino', 'ofloxacino'],
                'cruzada' => []
            ],
            'opioides' => [
                'nombre'  => 'opioides',
                'alias'   => ['opioide', 'opioides', 'opiaceos', 'codeina', 'morfina'],
                'farmacos' => ['codeina', 'morfina', 'tramadol', 'oxicodona', 'tapentadol', 'buprenorfina', 'fentanilo'],
                'cruzada' => []
            ],
            'metamizol' => [
                'nombre'  => 'pirazolonas (metamizol)',
                'alias'   => ['metamizol', 'dipirona', 'pirazolona', 'pirazolonas'],
                'farmacos' => ['metamizol', 'dipirona', 'propifenazona'],
                'cruzada' => []
            ],
        ];
    }
}

if (!function_exists('separarAlergias')) {
    function separarAlergias($alergias)
    {
        $partes = preg_split('/[,;\/\n]+|\s+y\s+/u', (string) $alergias);
        $lista = [];

        foreach ($partes as $parte) {
            $parte = normalizarTextoClinico($parte);
            // "alergia a la penicilina" -> "penicilina"
            $parte = trim(preg_replace('/^(alergia|alergias|alergico|alergica|alergicos)( a| al| de)?( la| el| los| las)?\s*/', '', $parte));

            if ($parte !== '' && !in_array($parte, ['ninguna', 'ninguno', 'no', 'niega', 'negadas', 'sin alergias', 'n a'], true)) {
                $lista[] = $parte;
            }
        }

        return array_values(array_unique($lista));
    }
}

if (!function_exists('textoContieneTermino')) {
    function textoContieneTermino($textoNormalizado, $termino)
    {
        $termino = normalizarTextoClinico($termino);

        if ($termino === '') {
            return false;
        }

        return (bool) preg_match('/\b' . preg_quote($termino, '/') . '\b/', $textoNormalizado);
    }
}

if (!function_exists('detectarConflictosAlergia')) {
    /**
     * @param string $texto    Receta o nombre/principio activo del medicamento.
     * @param string $alergias Alergias del paciente separadas por coma.
     * @return array Lista de conflictos: alergia, medicamento, nivel (alto|precaucion) y motivo.
     */
    function detectarConflictosAlergia($texto, $alergias)
    {
        $textoNorm = normalizarTextoClinico($texto);
        $listaAlergias = separarAlergias($alergias);
        $grupos = gruposDeAlergia();
        $conflictos = [];

        if ($textoNorm === '' || empty($listaAlergias)) {
            return [];
        }

        foreach ($listaAlergias as $alergia) {
            $candidatos = [];

            // 1) El mismo fármaco escrito tal cual
            $candidatos[$alergia] = ['nivel' => 'alto', 'motivo' => 'El paciente tiene registrada alergia a este medicamento.'];

            // 2) Grupos a los que pertenece la alergia
            foreach ($grupos as $clave => $grupo) {
                $pertenece = false;

                foreach (array_merge($grupo['alias'], $grupo['farmacos']) as $nombre) {
                    if ($alergia === $nombre || textoContieneTermino($alergia, $nombre)) {
                        $pertenece = true;
                        break;
                    }
                }

                if (!$pertenece) {
                    continue;
                }

                foreach ($grupo['farmacos'] as $farmaco) {
                    if (!isset($candidatos[$farmaco])) {
                        $candidatos[$farmaco] = [
                            'nivel'  => 'alto',
                            'motivo' => 'Pertenece al grupo de ' . $grupo['nombre'] . ', al que el paciente es alérgico.'
                        ];
                    }
                }

                foreach ($grupo['cruzada'] as $claveCruzada) {
                    foreach ($grupos[$claveCruzada]['farmacos'] as $farmaco) {
                        if (!isset($candidatos[$farmaco])) {
                            $candidatos[$farmaco] = [
                                'nivel'  => 'precaucion',
                                'motivo' => 'Posible reactividad cruzada entre ' . $grupo['nombre'] . ' y ' . $grupos[$claveCruzada]['nombre'] . '.'
                            ];
                        }
                    }
                }
            }

            foreach ($candidatos as $farmaco => $info) {
                if (textoContieneTermino($textoNorm, $farmaco)) {
                    $conflictos[$alergia . '|' . $farmaco] = [
                        'alergia'     => $alergia,
                        'medicamento' => $farmaco,
                        'nivel'       => $info['nivel'],
                        'motivo'      => $info['motivo']
                    ];
                }
            }
        }

        // Si "sulfametoxazol" y "trimetoprima sulfametoxazol" salen juntos, se deja solo el más largo
        foreach ($conflictos as $clave => $c) {
            foreach ($conflictos as $otraClave => $otro) {
                if (
                    $clave !== $otraClave &&
                    $c['alergia'] === $otro['alergia'] &&
                    strlen($otro['medicamento']) > strlen($c['medicamento']) &&
                    strpos($otro['medicamento'], $c['medicamento']) !== false
                ) {
                    unset($conflictos[$clave]);
                    break;
                }
            }
        }

        // Primero los de nivel alto
        usort($conflictos, function ($a, $b) {
            return ($a['nivel'] === 'alto' ? 0 : 1) <=> ($b['nivel'] === 'alto' ? 0 : 1);
        });

        return array_values($conflictos);
    }
}
