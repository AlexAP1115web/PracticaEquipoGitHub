<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   ESCÁNER DE MEDICAMENTOS (foto de la caja o el frasco)
   Gemini lee la foto, se busca en CIMA y se revisan las alergias del paciente.
   La foto no se guarda en el servidor
===================================================== */

require_once __DIR__ . '/GeminiAI.php';
require_once __DIR__ . '/CimaAPI.php';
require_once __DIR__ . '/Alergias.php';

if (!function_exists('leerFotoMedicamento')) {
    // null si Gemini no respondió
    function leerFotoMedicamento($tipoMime, $base64)
    {
        $texto = geminiAnalizarImagen(
            'Identificas medicamentos en fotos de cajas, frascos, blísteres, tubos o recetas impresas. ' .
                'Responde SOLO un objeto JSON con esta forma: ' .
                '{"es_medicamento": true, "medicamentos": [{"nombre_comercial": "", "principios_activos": [""], "concentracion": "", "forma": ""}]}. ' .
                'Reglas: "principios_activos" en español y en minúsculas, sin la concentración (ej. ["paracetamol"], ["amoxicilina", "ácido clavulánico"]). ' .
                'Si solo ves la marca, escribe el principio activo que corresponde a esa marca. ' .
                '"concentracion" como aparece (ej. "500 mg"); "forma" en español (tabletas, cápsulas, jarabe, suspensión, crema, gotas, solución inyectable). ' .
                'Máximo 3 medicamentos. Si no se lee un dato, déjalo vacío; no inventes. ' .
                'Si la foto no muestra ningún medicamento, responde {"es_medicamento": false, "medicamentos": []}.',
            '¿Qué medicamento o medicamentos aparecen en esta foto?',
            $tipoMime,
            $base64,
            400,
            true
        );

        $datos = geminiJson($texto);

        if ($datos === null) {
            return null;
        }

        $medicamentos = [];

        foreach (array_slice($datos['medicamentos'] ?? [], 0, 3) as $m) {
            if (!is_array($m)) {
                continue;
            }

            $principios = [];

            foreach ((array) ($m['principios_activos'] ?? []) as $p) {
                $p = mb_strtolower(trim(strip_tags((string) $p)), 'UTF-8');
                if ($p !== '' && mb_strlen($p) <= 80) {
                    $principios[] = $p;
                }
            }

            $nombre = mb_substr(trim(strip_tags((string) ($m['nombre_comercial'] ?? ''))), 0, 120);

            if ($nombre === '' && empty($principios)) {
                continue;
            }

            $medicamentos[] = [
                'nombre_detectado' => $nombre,
                'principios'       => array_slice($principios, 0, 4),
                'concentracion'    => mb_substr(trim(strip_tags((string) ($m['concentracion'] ?? ''))), 0, 40),
                'forma'            => mb_substr(trim(strip_tags((string) ($m['forma'] ?? ''))), 0, 40)
            ];
        }

        return [
            'es_medicamento' => !empty($datos['es_medicamento']) && !empty($medicamentos),
            'medicamentos'   => $medicamentos
        ];
    }
}

if (!function_exists('escanearMedicamento')) {
    function escanearMedicamento($conexion, $medicoId, $tipoMime, $base64, $pacienteId = null)
    {
        $lectura = leerFotoMedicamento($tipoMime, $base64);

        if ($lectura === null) {
            return [
                'ok'      => false,
                'mensaje' => 'No se pudo leer la foto en este momento. Intenta de nuevo en unos segundos.'
            ];
        }

        if (!$lectura['es_medicamento']) {
            return [
                'ok'             => true,
                'es_medicamento' => false,
                'medicamentos'   => [],
                'mensaje'        => 'No encontré un medicamento en la foto. Intenta con la caja de frente, con buena luz y el nombre completo a la vista.'
            ];
        }

        // Paciente elegido (para cruzar alergias)
        $paciente = null;

        if ($pacienteId) {
            $stmt = $conexion->prepare("SELECT id, nombre, alergias FROM usuarios WHERE id = ? AND rol = 'paciente' LIMIT 1");
            $stmt->bind_param("i", $pacienteId);
            $stmt->execute();
            $paciente = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        $alergias = $paciente['alergias'] ?? '';
        $resultado = [];
        $primeroEnCima = null;

        foreach ($lectura['medicamentos'] as $m) {
            $cima = null;

            foreach ($m['principios'] as $principio) {
                $cima = cimaBuscarMedicamento($principio);
                if ($cima) {
                    break;
                }
            }

            if (!$cima && $m['nombre_detectado'] !== '') {
                $cima = cimaBuscarMedicamento($m['nombre_detectado']);
            }

            // Alergias con todo lo leído aunque no esté en CIMA (marcas de México)
            $textoRevision = trim(
                $m['nombre_detectado'] . ' ' . implode(' ', $m['principios']) .
                    ($cima ? ' ' . $cima['nombre'] . ' ' . $cima['principio'] : '')
            );

            $alertas = $alergias !== '' ? detectarConflictosAlergia($textoRevision, $alergias) : [];

            if ($cima && $primeroEnCima === null) {
                $primeroEnCima = $cima;
            }

            $resultado[] = $m + [
                'encontrado'    => (bool) $cima,
                'nombre_cima'   => $cima['nombre'] ?? null,
                'principio_cima' => $cima['principio'] ?? null,
                'receta'        => $cima['receta'] ?? '',
                'enlace'        => $cima['enlace'] ?? null,
                'alertas'       => $alertas
            ];
        }

        // Resumen breve del primer medicamento que sí está en CIMA
        $resumen = null;
        $sugerencias = [];

        if ($primeroEnCima) {
            $_SESSION['chatbot_ultimo_medicamento'] = $primeroEnCima;

            $textoOficial = trim(
                cimaSeccionFichaTecnica($primeroEnCima['nregistro'], '4.1') . "\n\n" .
                    cimaSeccionFichaTecnica($primeroEnCima['nregistro'], '4.3')
            );

            if ($textoOficial !== '') {
                $resumen = geminiGenerar(
                    'Eres el asistente de medicamentos de MediCore. Con ÚNICAMENTE el texto oficial que se te da, escribe en español ' .
                        'máximo 4 viñetas que empiecen con "- ": primero para qué se usa y después cuándo no usarlo. ' .
                        'Máximo 80 palabras en total. No inventes datos ni dosis. Sin saludos.',
                    "Medicamento: {$primeroEnCima['nombre']} (principio activo: {$primeroEnCima['principio']}).\n\n" .
                        "Texto oficial (4.1 Indicaciones y 4.3 Contraindicaciones):\n<<<\n" . mb_substr($textoOficial, 0, 6000) . "\n>>>",
                    300
                );

                if (!$resumen) {
                    $resumen = mb_substr($textoOficial, 0, 500) . (mb_strlen($textoOficial) > 500 ? '…' : '');
                }
            }

            $principio = $primeroEnCima['principio'];
            $sugerencias = [
                ['texto' => 'Efectos secundarios', 'pregunta' => "Efectos secundarios de $principio"],
                ['texto' => 'Interacciones', 'pregunta' => "Interacciones de $principio"],
                ['texto' => 'Dosis', 'pregunta' => "Dosis de $principio"]
            ];
        }

        // Historial (se guarda qué se leyó, nunca la foto)
        try {
            $stmtLog = $conexion->prepare("
                INSERT INTO chatbot_consultas (medico_id, paciente_id, pregunta, medicamento, tema, fuente)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            foreach ($resultado as $r) {
                $pacienteLog = $paciente ? (int) $paciente['id'] : null;
                $preguntaLog = mb_substr('Foto: ' . ($r['nombre_detectado'] ?: implode(' + ', $r['principios'])), 0, 300);
                $medLog = mb_substr($r['nombre_cima'] ?: implode(' + ', $r['principios']), 0, 255);
                $temaLog = 'Escaneo con cámara';
                $fuenteLog = 'Gemini (foto) + CIMA (AEMPS)';
                $stmtLog->bind_param("iissss", $medicoId, $pacienteLog, $preguntaLog, $medLog, $temaLog, $fuenteLog);
                $stmtLog->execute();
            }

            $stmtLog->close();
        } catch (Exception $e) {
            registrarLog("No se pudo guardar el escaneo en el historial: " . $e->getMessage(), "WARNING");
        }

        return [
            'ok'             => true,
            'es_medicamento' => true,
            'medicamentos'   => $resultado,
            'resumen'        => $resumen,
            'sugerencias'    => $sugerencias,
            'paciente'       => $paciente ? $paciente['nombre'] : null
        ];
    }
}
