<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   ASISTENTE DE MEDICAMENTOS (chatbot)
   Saca tema y medicamento de la pregunta, busca la sección en la ficha de CIMA,
   revisa alergias del paciente y si hay llave de Gemini resume el texto
===================================================== */

require_once __DIR__ . '/Alergias.php';
require_once __DIR__ . '/CimaAPI.php';
require_once __DIR__ . '/GeminiAI.php';

if (!function_exists('temasChatbot')) {
    function temasChatbot()
    {
        return [
            'alergias' => [
                'titulo'    => 'Alergias e hipersensibilidad',
                'secciones' => ['4.3', '4.4'],
                'palabras'  => ['alergia', 'alergias', 'alergico', 'alergica', 'hipersensibilidad', 'anafilaxia', 'urticaria']
            ],
            'efectos' => [
                'titulo'    => 'Efectos secundarios (reacciones adversas)',
                'secciones' => ['4.8'],
                'palabras'  => ['efecto', 'efectos', 'secundario', 'secundarios', 'adverso', 'adversos', 'adversas', 'reaccion', 'reacciones', 'provoca', 'causa']
            ],
            'contraindicaciones' => [
                'titulo'    => 'Contraindicaciones',
                'secciones' => ['4.3'],
                'palabras'  => ['contraindicacion', 'contraindicaciones', 'contraindicado', 'contraindicada', 'prohibido']
            ],
            'interacciones' => [
                'titulo'    => 'Interacciones con otros medicamentos',
                'secciones' => ['4.5'],
                'palabras'  => ['interaccion', 'interacciones', 'mezclar', 'combinar', 'junto', 'juntos', 'alcohol', 'interactua']
            ],
            'embarazo' => [
                'titulo'    => 'Embarazo y lactancia',
                'secciones' => ['4.6'],
                'palabras'  => ['embarazo', 'embarazada', 'embarazadas', 'lactancia', 'amamantar', 'gestacion', 'fertilidad']
            ],
            'dosis' => [
                'titulo'    => 'Posología (dosis)',
                'secciones' => ['4.2'],
                'palabras'  => ['dosis', 'posologia', 'dosificacion', 'cada cuanto', 'cuanto debe', 'cuantos mg', 'cuanto se']
            ],
            'advertencias' => [
                'titulo'    => 'Advertencias y precauciones',
                'secciones' => ['4.4'],
                'palabras'  => ['precaucion', 'precauciones', 'advertencia', 'advertencias', 'cuidado', 'riesgo', 'riesgos']
            ],
            'indicaciones' => [
                'titulo'    => 'Indicaciones (para qué sirve)',
                'secciones' => ['4.1'],
                'palabras'  => ['sirve', 'indicacion', 'indicaciones', 'utiliza', 'usa', 'para que']
            ],
        ];
    }
}

if (!function_exists('detectarTemaChatbot')) {
    // $explicito queda en true si la pregunta sí trae el tema, si no regresa 'efectos'
    function detectarTemaChatbot($pregunta, &$explicito = null)
    {
        $texto = ' ' . normalizarTextoClinico($pregunta) . ' ';
        $explicito = true;

        foreach (temasChatbot() as $clave => $tema) {
            foreach ($tema['palabras'] as $palabra) {
                if (strpos($texto, ' ' . $palabra . ' ') !== false) {
                    return $clave;
                }
            }
        }

        $explicito = false;

        return 'efectos';
    }
}

if (!function_exists('alergiasMencionadas')) {
    // "mi paciente es alérgico a la penicilina" regresa ['penicilina']
    function alergiasMencionadas($pregunta)
    {
        $texto = normalizarTextoClinico($pregunta);
        preg_match_all('/alergic[oa]s? (?:a|al) (?:(?:la|el|los|las) )?(acido [a-z]{4,}|[a-z]{4,})/', $texto, $m);
        preg_match_all('/alergia (?:a|al) (?:(?:la|el|los|las) )?([a-z]{4,})/', $texto, $m2);

        return array_values(array_unique(array_merge($m[1] ?? [], $m2[1] ?? [])));
    }
}

if (!function_exists('candidatosMedicamento')) {
    // Posibles nombres del medicamento: la frase sin palabras comunes y luego cada palabra (la más larga primero)
    function candidatosMedicamento($pregunta)
    {
        $vacias = [
            'que', 'cual', 'cuales', 'como', 'cuando', 'donde', 'quien', 'por', 'para', 'con', 'sin', 'del', 'de', 'la', 'el',
            'los', 'las', 'un', 'una', 'unos', 'unas', 'al', 'a', 'y', 'o', 'en', 'es', 'son', 'su', 'sus', 'se', 'me', 'mi',
            'le', 'les', 'lo', 'tiene', 'tienen', 'tener', 'puede', 'pueden', 'puedo', 'hay', 'debe', 'deben', 'si', 'no',
            'paciente', 'pacientes', 'medicamento', 'medicamentos', 'farmaco', 'farmacos', 'medicina', 'pastilla', 'pastillas',
            'dame', 'dime', 'informacion', 'sobre', 'acerca', 'quiero', 'saber', 'necesito', 'favor', 'hola', 'gracias',
            'mas', 'muy', 'algun', 'alguna', 'otros', 'otras', 'otro', 'otra', 'este', 'esta', 'ese', 'esa', 'tiene',
            'ser', 'esta', 'estan', 'producir', 'produce', 'dar', 'da', 'tomar', 'toma', 'cada', 'cuanto', 'cuantos',
            'mg', 'ml', 'comprimidos', 'tabletas', 'capsulas', 'jarabe', 'mi', 'tu', 'puedes', 'explica', 'explicame'
        ];

        foreach (temasChatbot() as $tema) {
            foreach ($tema['palabras'] as $palabra) {
                $vacias[] = $palabra;
            }
        }

        // Lo que viene después de "alérgico a" es la alergia, no el medicamento consultado
        foreach (alergiasMencionadas($pregunta) as $mencion) {
            foreach (explode(' ', $mencion) as $palabraAlergia) {
                $vacias[] = $palabraAlergia;
            }
        }

        $palabras = array_filter(explode(' ', normalizarTextoClinico($pregunta)), function ($p) use ($vacias) {
            return mb_strlen($p) >= 4 && !in_array($p, $vacias, true) && !ctype_digit($p);
        });

        $palabras = array_values($palabras);
        $candidatos = [];

        if (count($palabras) > 1) {
            $candidatos[] = implode(' ', $palabras);
        }

        usort($palabras, function ($a, $b) {
            return mb_strlen($b) <=> mb_strlen($a);
        });

        foreach ($palabras as $p) {
            $candidatos[] = $p;
        }

        return array_slice(array_values(array_unique($candidatos)), 0, 4);
    }
}

if (!function_exists('identificarConGemini')) {
    function identificarConGemini($pregunta)
    {
        $json = geminiGenerar(
            'Extraes datos de preguntas sobre medicamentos. Responde solo JSON con las claves ' .
                '"medicamento" (principio activo en español, en minúsculas, o "" si no hay) y ' .
                '"tema" (uno de: alergias, efectos, contraindicaciones, interacciones, dosis, embarazo, advertencias, indicaciones). ' .
                'Si la pregunta usa una marca comercial (también marcas que se venden en México), devuelve su principio activo; si son varios, sepáralos con coma.',
            $pregunta,
            120,
            true
        );

        $datos = $json ? json_decode($json, true) : null;

        if (!is_array($datos)) {
            return null;
        }

        return [
            'medicamento' => trim((string) ($datos['medicamento'] ?? '')),
            'tema'        => array_key_exists($datos['tema'] ?? '', temasChatbot()) ? $datos['tema'] : null
        ];
    }
}

if (!function_exists('responderConsultaMedicamento')) {
    function responderConsultaMedicamento($conexion, $medicoId, $pregunta, $pacienteId = null)
    {
        $temas = temasChatbot();
        $tema = detectarTemaChatbot($pregunta, $temaExplicito);
        $medicamento = null;
        $usoIA = false;
        $candidatos = candidatosMedicamento($pregunta);

        foreach ($candidatos as $candidato) {
            $medicamento = cimaBuscarMedicamento($candidato, false);
            if ($medicamento) {
                break;
            }
        }

        // Marca comercial o pregunta poco clara: se le pide ayuda a Gemini
        if (!$medicamento && geminiDisponible()) {
            $extraido = identificarConGemini($pregunta);

            if ($extraido && $extraido['medicamento'] !== '') {
                foreach (preg_split('/\s*(?:,|\+|\/|\by\b)\s*/u', $extraido['medicamento']) as $principio) {
                    $medicamento = cimaBuscarMedicamento($principio);
                    if ($medicamento) {
                        break;
                    }
                }
                $usoIA = true;

                if ($extraido['tema']) {
                    $tema = $extraido['tema'];
                }
            }
        }

        // Sin Gemini o sin resultado: se intenta cada palabra como principio activo (marcas de México)
        if (!$medicamento) {
            foreach ($candidatos as $candidato) {
                $medicamento = cimaBuscarPorPrincipio($candidato);
                if ($medicamento) {
                    break;
                }
            }
        }

        // Seguimiento tipo "¿y sus interacciones?", se usa el último medicamento consultado
        if (!$medicamento && $temaExplicito && empty($candidatos) && !empty($_SESSION['chatbot_ultimo_medicamento'])) {
            $medicamento = $_SESSION['chatbot_ultimo_medicamento'];
        }

        if (!$medicamento) {
            return [
                'ok'        => true,
                'encontrado' => false,
                'respuesta' => "No pude identificar el medicamento en tu pregunta. Escribe el nombre del principio activo, por ejemplo: \"efectos secundarios del ibuprofeno\" o \"contraindicaciones de la amoxicilina\".",
                'alertas'   => [],
                'fuente'    => 'CIMA (AEMPS)',
                'cofepris'  => COFEPRIS_VISOR
            ];
        }

        $_SESSION['chatbot_ultimo_medicamento'] = $medicamento;

        // Texto oficial de la ficha técnica
        $textoOficial = '';

        foreach ($temas[$tema]['secciones'] as $seccion) {
            $textoOficial .= cimaSeccionFichaTecnica($medicamento['nregistro'], $seccion) . "\n\n";
        }

        $textoOficial = trim($textoOficial);

        // Alergias del paciente seleccionado
        $paciente = null;
        $alertas = [];

        $mencionadas = alergiasMencionadas($pregunta);

        if (!empty($mencionadas)) {
            $alertas = detectarConflictosAlergia(
                $medicamento['nombre'] . ' ' . $medicamento['principio'],
                implode(', ', $mencionadas)
            );
        }

        if ($pacienteId) {
            $stmt = $conexion->prepare("SELECT id, nombre, alergias FROM usuarios WHERE id = ? AND rol = 'paciente' LIMIT 1");
            $stmt->bind_param("i", $pacienteId);
            $stmt->execute();
            $paciente = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($paciente) {
                $alertas = detectarConflictosAlergia(
                    $medicamento['nombre'] . ' ' . $medicamento['principio'],
                    trim(($paciente['alergias'] ?? '') . ', ' . implode(', ', $mencionadas), ', ')
                );
            }
        }

        // Respuesta: resumen con IA o texto oficial recortado
        $respuesta = null;

        if ($textoOficial !== '' && geminiDisponible()) {
            $contextoAlergias = $paciente
                ? 'Paciente: ' . $paciente['nombre'] . '. Alergias registradas: ' . ($paciente['alergias'] ?: 'ninguna') . '.'
                : 'No se seleccionó paciente.' . (!empty($mencionadas) ? ' El médico menciona alergia a: ' . implode(', ', $mencionadas) . '.' : '');

            if (!empty($alertas)) {
                $contextoAlergias .= ' CONFLICTO DETECTADO por el sistema: ' . implode(' ', array_map(function ($a) {
                    return $a['medicamento'] . ' (' . $a['motivo'] . ')';
                }, $alertas));
            }

            $respuesta = geminiGenerar(
                'Eres el asistente de medicamentos de MediCore, un sistema clínico usado por médicos en México. ' .
                    'Responde en español, claro y breve (máximo 170 palabras), usando viñetas que empiecen con "- ". ' .
                    'Usa ÚNICAMENTE la información oficial de la ficha técnica que se te da (CIMA, AEMPS); no inventes datos, dosis ni porcentajes. ' .
                    'Si el texto oficial no responde la pregunta, dilo. Si la pregunta no trata de medicamentos, responde que solo ayudas con medicamentos. ' .
                    'Si hay un conflicto de alergia, la primera línea debe empezar con "ALERTA:". ' .
                    'Puedes resaltar términos clave con **negritas**. No agregues saludos.',
                "Pregunta del médico: $pregunta\n" .
                    "Medicamento identificado: {$medicamento['nombre']} (principio activo: {$medicamento['principio']}).\n" .
                    (!empty($medicamento['por_principio']) ? "Es la ficha de otro medicamento con el mismo principio activo (la marca de la pregunta no está en CIMA); responde sobre el principio activo.\n" : '') .
                    "Tema: {$temas[$tema]['titulo']}.\n" .
                    "$contextoAlergias\n\n" .
                    "Texto oficial de la ficha técnica:\n<<<\n" . mb_substr($textoOficial, 0, 9000) . "\n>>>",
                700
            );

            if ($respuesta) {
                $usoIA = true;
            }
        }

        if (!$respuesta) {
            $respuesta = $textoOficial !== ''
                ? mb_substr($textoOficial, 0, 1400) . (mb_strlen($textoOficial) > 1400 ? "\n…\n(Texto recortado. Consulta la ficha técnica completa en el enlace.)" : '')
                : 'CIMA no tiene esta sección de la ficha técnica para este medicamento. Revisa la ficha completa en el enlace.';
        }

        $fuente = $usoIA ? 'CIMA (AEMPS) + resumen con Gemini' : 'CIMA (AEMPS)';

        if (!empty($medicamento['por_principio'])) {
            $fuente .= ', ficha de referencia por principio activo';
        }

        // Historial
        try {
            $stmtLog = $conexion->prepare("
                INSERT INTO chatbot_consultas (medico_id, paciente_id, pregunta, medicamento, tema, fuente)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $pacienteLog = $paciente ? (int) $paciente['id'] : null;
            $preguntaLog = mb_substr($pregunta, 0, 300);
            $medLog = mb_substr($medicamento['nombre'], 0, 255);
            $temaLog = $temas[$tema]['titulo'];
            $stmtLog->bind_param("iissss", $medicoId, $pacienteLog, $preguntaLog, $medLog, $temaLog, $fuente);
            $stmtLog->execute();
            $stmtLog->close();
        } catch (Exception $e) {
            registrarLog("No se pudo guardar la consulta del asistente: " . $e->getMessage(), "WARNING");
        }

        return [
            'ok'          => true,
            'encontrado'  => true,
            'medicamento' => $medicamento['nombre'],
            'principio'   => $medicamento['principio'],
            'receta'      => $medicamento['receta'],
            'tema'        => $temas[$tema]['titulo'],
            'respuesta'   => $respuesta,
            'alertas'     => $alertas,
            'paciente'    => $paciente ? $paciente['nombre'] : null,
            'fuente'      => $fuente,
            'enlace'      => $medicamento['enlace'],
            'por_principio' => !empty($medicamento['por_principio']),
            'cofepris'    => COFEPRIS_VISOR
        ];
    }
}
