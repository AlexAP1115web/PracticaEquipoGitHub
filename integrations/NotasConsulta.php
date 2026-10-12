<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   NOTAS DE CONSULTA (dictado por voz + formato SOAP)
   -----------------------------------------------------
   El médico dicta lo que pasó en la consulta (el
   navegador convierte la voz en texto con la Web Speech
   API) o lo escribe. Gemini acomoda ese texto en el
   formato clínico SOAP:
     S - Subjetivo: lo que cuenta el paciente.
     O - Objetivo: exploración, signos vitales, estudios.
     A - Análisis: diagnóstico o impresión diagnóstica.
     P - Plan: tratamiento, medicamentos e indicaciones.
   Después se revisa el plan contra las alergias del
   paciente. El médico corrige lo que haga falta y guarda.
===================================================== */

require_once __DIR__ . '/GeminiAI.php';
require_once __DIR__ . '/Alergias.php';

if (!function_exists('camposNotaSOAP')) {
    function camposNotaSOAP()
    {
        return ['subjetivo', 'objetivo', 'analisis', 'plan'];
    }
}

if (!function_exists('estructurarNotaSOAP')) {
    /**
     * @return array|null ['subjetivo','objetivo','analisis','plan','medicamentos'] o null si Gemini no respondió.
     */
    function estructurarNotaSOAP($texto, $nombrePaciente = '')
    {
        $json = geminiGenerar(
            'Eres el asistente de documentación clínica de MediCore, usado por médicos en México. ' .
                'Recibes la transcripción de lo que un médico dictó durante una consulta y la ordenas en formato SOAP. ' .
                'Responde SOLO un objeto JSON con las claves "subjetivo", "objetivo", "analisis", "plan" (texto en español) ' .
                'y "medicamentos" (lista con los nombres de los medicamentos indicados en el plan, en minúsculas). ' .
                'Reglas: usa únicamente lo que dice la transcripción; no inventes signos, dosis, diagnósticos ni estudios. ' .
                'Si algo no se mencionó, deja esa clave como cadena vacía. Corrige la ortografía y los términos médicos mal transcritos ' .
                '(ej. "para ce ta mol" es "paracetamol"). Redacta en tercera persona, claro y breve; ' .
                'en "plan" pon cada indicación en su propio renglón empezando con "- ".',
            ($nombrePaciente !== '' ? "Paciente: $nombrePaciente\n" : '') .
                "Transcripción de la consulta:\n<<<\n" . mb_substr($texto, 0, 6000) . "\n>>>",
            900,
            true
        );

        $datos = geminiJson($json);

        if ($datos === null) {
            return null;
        }

        $nota = [];

        foreach (camposNotaSOAP() as $campo) {
            $valor = $datos[$campo] ?? '';
            if (is_array($valor)) {
                $valor = implode("\n", array_map('strval', $valor));
            }
            $nota[$campo] = mb_substr(trim(strip_tags((string) $valor)), 0, 5000);
        }

        $nota['medicamentos'] = array_values(array_filter(array_map(function ($m) {
            return mb_substr(mb_strtolower(trim(strip_tags((string) $m)), 'UTF-8'), 0, 80);
        }, (array) ($datos['medicamentos'] ?? []))));

        return $nota;
    }
}

if (!function_exists('alertasDeNota')) {
    /**
     * Revisa el plan (y los medicamentos detectados) contra las alergias.
     */
    function alertasDeNota(array $nota, $alergias)
    {
        if (trim((string) $alergias) === '') {
            return [];
        }

        $texto = ($nota['plan'] ?? '') . ' ' . implode(' ', $nota['medicamentos'] ?? []);

        return trim($texto) === '' ? [] : detectarConflictosAlergia($texto, $alergias);
    }
}

if (!function_exists('pacienteDeNota')) {
    function pacienteDeNota($conexion, $pacienteId)
    {
        $stmt = $conexion->prepare("SELECT id, nombre, alergias FROM usuarios WHERE id = ? AND rol = 'paciente' LIMIT 1");
        $stmt->bind_param("i", $pacienteId);
        $stmt->execute();
        $paciente = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $paciente ?: null;
    }
}

if (!function_exists('guardarNotaConsulta')) {
    /**
     * Guarda la nota. Si llega sin ordenar (por ejemplo, porque se
     * escribió sin internet) y Gemini está disponible, se ordena aquí.
     *
     * @return array ['ok' => bool, 'mensaje' => string, 'nota' => array|null, 'alertas' => array]
     */
    function guardarNotaConsulta($conexion, $medicoId, array $entrada)
    {
        $idCliente = strtolower(trim((string) ($entrada['id_cliente'] ?? '')));

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $idCliente)) {
            return ['ok' => false, 'mensaje' => 'Identificador de nota inválido.', 'nota' => null, 'alertas' => []];
        }

        $pacienteId = intval($entrada['paciente_id'] ?? 0);
        $paciente = $pacienteId > 0 ? pacienteDeNota($conexion, $pacienteId) : null;

        if (!$paciente) {
            return ['ok' => false, 'mensaje' => 'El paciente de la nota no existe.', 'nota' => null, 'alertas' => []];
        }

        $transcripcion = mb_substr(trim(strip_tags((string) ($entrada['transcripcion'] ?? ''))), 0, 8000);
        $nota = [];

        foreach (camposNotaSOAP() as $campo) {
            $nota[$campo] = mb_substr(trim(strip_tags((string) ($entrada[$campo] ?? ''))), 0, 5000);
        }

        $sinOrdenar = implode('', $nota) === '';

        if ($sinOrdenar && $transcripcion === '') {
            return ['ok' => false, 'mensaje' => 'La nota está vacía.', 'nota' => null, 'alertas' => []];
        }

        $nota['medicamentos'] = [];

        if ($sinOrdenar && geminiDisponible()) {
            $ordenada = estructurarNotaSOAP($transcripcion, $paciente['nombre']);
            if ($ordenada) {
                $nota = $ordenada;
            }
        }

        $origen = in_array($entrada['origen'] ?? '', ['voz', 'texto', 'sin_conexion'], true) ? $entrada['origen'] : 'texto';

        // Hora en que se escribió (las notas sin conexión llegan después).
        // Se acepta solo si es de los últimos 7 días y no está en el futuro.
        $creadoEn = date('Y-m-d H:i:s');
        $escrita = isset($entrada['escrita_en']) ? strtotime((string) $entrada['escrita_en']) : false;

        if ($escrita && $escrita <= time() + 120 && $escrita >= time() - 7 * 86400) {
            $creadoEn = date('Y-m-d H:i:s', $escrita);
        }

        $stmt = $conexion->prepare("
            INSERT IGNORE INTO notas_consulta
                (id_cliente, paciente_id, medico_id, transcripcion, subjetivo, objetivo, analisis, plan, origen, creado_en)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "siisssssss",
            $idCliente,
            $pacienteId,
            $medicoId,
            $transcripcion,
            $nota['subjetivo'],
            $nota['objetivo'],
            $nota['analisis'],
            $nota['plan'],
            $origen,
            $creadoEn
        );
        $stmt->execute();
        $stmt->close();

        // Se devuelve lo que quedó guardado (también si ya existía)
        $stmt = $conexion->prepare("
            SELECT id_cliente, transcripcion, subjetivo, objetivo, analisis, plan, origen, creado_en
            FROM notas_consulta
            WHERE id_cliente = ? AND paciente_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("si", $idCliente, $pacienteId);
        $stmt->execute();
        $guardada = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$guardada) {
            return ['ok' => false, 'mensaje' => 'No se pudo guardar la nota.', 'nota' => null, 'alertas' => []];
        }

        $guardada['creado_en_texto'] = date('d/m/Y H:i', strtotime($guardada['creado_en']));

        return [
            'ok'      => true,
            'mensaje' => 'Nota guardada en el expediente.',
            'nota'    => $guardada,
            'alertas' => alertasDeNota($nota, $paciente['alergias'] ?? '')
        ];
    }
}

if (!function_exists('notasDePaciente')) {
    function notasDePaciente($conexion, $pacienteId, $limite = 10)
    {
        $stmt = $conexion->prepare("
            SELECT id_cliente, transcripcion, subjetivo, objetivo, analisis, plan, origen, creado_en
            FROM notas_consulta
            WHERE paciente_id = ?
            ORDER BY creado_en DESC, id DESC
            LIMIT ?
        ");
        $stmt->bind_param("ii", $pacienteId, $limite);
        $stmt->execute();
        $notas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $notas;
    }
}
