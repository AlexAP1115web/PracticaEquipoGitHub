<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   PRE-CONSULTA POR QR
   El médico genera un enlace de un solo uso (QR) desde el expediente. El paciente lo abre en
   su celular sin cuenta, contesta unas preguntas y marca en SomaMap dónde siente algo.
   Al médico le llega un resumen (con IA si hay GEMINI_API_KEY, si no uno automático)
   y una notificación push.
   Las fechas se comparan en PHP (hora de México); MySQL en Railway está en UTC.
===================================================== */

require_once __DIR__ . '/MapaCorporal.php';
require_once __DIR__ . '/Alergias.php';
require_once __DIR__ . '/GeminiAI.php';

if (!defined('PRECONSULTA_HORAS')) {
    // Cuánto dura el enlace antes de vencer
    define('PRECONSULTA_HORAS', 72);
}

if (!function_exists('preconsultaOpcionesInicio')) {
    function preconsultaOpcionesInicio()
    {
        return [
            'hoy'     => 'Desde hoy',
            'dias'    => 'Hace unos días',
            'semana'  => 'Hace una semana o más',
            'mes'     => 'Hace un mes o más',
            'control' => 'Es una revisión de control'
        ];
    }
}

if (!function_exists('preconsultaTokenValido')) {
    function preconsultaTokenValido($token)
    {
        return is_string($token) && preg_match('/^[a-f0-9]{48}$/', $token) === 1;
    }
}

if (!function_exists('preconsultaCrear')) {
    // Si el paciente ya tiene una pendiente y vigente se reutiliza (mismo QR)
    function preconsultaCrear($conexion, $pacienteId, $medicoId)
    {
        $ahora = date('Y-m-d H:i:s');

        $stmt = $conexion->prepare("
            SELECT token, expira_en
            FROM preconsultas
            WHERE paciente_id = ? AND estado = 'pendiente' AND expira_en > ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param("is", $pacienteId, $ahora);
        $stmt->execute();
        $vigente = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($vigente) {
            return ['token' => $vigente['token'], 'expira_en' => $vigente['expira_en'], 'nueva' => false];
        }

        $token = bin2hex(random_bytes(24));
        $expira = date('Y-m-d H:i:s', strtotime('+' . PRECONSULTA_HORAS . ' hours'));

        $stmt = $conexion->prepare("
            INSERT INTO preconsultas (token, paciente_id, medico_id, estado, creado_en, expira_en)
            VALUES (?, ?, ?, 'pendiente', ?, ?)
        ");
        $stmt->bind_param("siiss", $token, $pacienteId, $medicoId, $ahora, $expira);
        $stmt->execute();
        $stmt->close();

        return ['token' => $token, 'expira_en' => $expira, 'nueva' => true];
    }
}

if (!function_exists('preconsultaPorToken')) {
    // Pre-consulta con el nombre del paciente y del médico; null si el token no existe
    function preconsultaPorToken($conexion, $token)
    {
        if (!preconsultaTokenValido($token)) {
            return null;
        }

        $stmt = $conexion->prepare("
            SELECT p.*, u.nombre AS paciente, u.alergias, m.nombre AS medico,
                   e.fecha_cita, e.hora_cita
            FROM preconsultas p
            JOIN usuarios u ON u.id = p.paciente_id
            LEFT JOIN medicos m ON m.id = p.medico_id
            LEFT JOIN expedientes e ON e.usuario_id = p.paciente_id
            WHERE p.token = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $fila ?: null;
    }
}

if (!function_exists('preconsultaEstado')) {
    // valida, respondida o vencida
    function preconsultaEstado(array $pre)
    {
        if ($pre['estado'] === 'respondida') {
            return 'respondida';
        }

        return $pre['expira_en'] > date('Y-m-d H:i:s') ? 'valida' : 'vencida';
    }
}

if (!function_exists('preconsultaLimpiarTexto')) {
    function preconsultaLimpiarTexto($texto, $maximo)
    {
        $texto = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $texto)));

        return mb_substr($texto, 0, $maximo);
    }
}

if (!function_exists('preconsultaValidarRespuestas')) {
    // Regresa [respuestas limpias, mensaje de error o null]
    function preconsultaValidarRespuestas($entrada)
    {
        $entrada = is_array($entrada) ? $entrada : [];
        $opciones = preconsultaOpcionesInicio();

        $respuestas = [
            'motivo'       => preconsultaLimpiarTexto($entrada['motivo'] ?? '', 300),
            'inicio'       => (string) ($entrada['inicio'] ?? ''),
            'medicamentos' => preconsultaLimpiarTexto($entrada['medicamentos'] ?? '', 200),
            'alergias'     => preconsultaLimpiarTexto($entrada['alergias'] ?? '', 150),
            'pregunta'     => preconsultaLimpiarTexto($entrada['pregunta'] ?? '', 300)
        ];

        if (mb_strlen($respuestas['motivo']) < 3) {
            return [null, 'Cuéntanos en pocas palabras por qué vienes a consulta.'];
        }

        if (!isset($opciones[$respuestas['inicio']])) {
            return [null, 'Elige desde cuándo te sientes así.'];
        }

        return [$respuestas, null];
    }
}

if (!function_exists('preconsultaAlertas')) {
    // Medicamentos que dice tomar vs. alergias del expediente y las que mencionó
    function preconsultaAlertas(array $respuestas, $alergiasExpediente)
    {
        $alergias = trim(implode(', ', array_filter([(string) $alergiasExpediente, $respuestas['alergias'] ?? ''])));

        if (($respuestas['medicamentos'] ?? '') === '' || $alergias === '') {
            return [];
        }

        return detectarConflictosAlergia($respuestas['medicamentos'], $alergias);
    }
}

if (!function_exists('preconsultaTextoSintomas')) {
    // "Rodilla derecha: dolor 6/10, desde hace 3 días"
    function preconsultaTextoSintomas(array $molestias)
    {
        $zonas = mapaZonas();
        $sensaciones = mapaSensaciones();
        $hoy = new DateTime('today');
        $partes = [];

        usort($molestias, function ($a, $b) {
            return $b['intensidad'] <=> $a['intensidad'];
        });

        foreach ($molestias as $m) {
            $texto = ($zonas[$m['zona']]['nombre'] ?? $m['zona']) . ': ' .
                mb_strtolower($sensaciones[$m['sensacion']] ?? $m['sensacion']) . ' ' . (int) $m['intensidad'] . '/10';

            if (!empty($m['desde'])) {
                $dias = (int) $hoy->diff(new DateTime($m['desde']))->days;
                $texto .= $dias === 0 ? ', desde hoy' : ($dias === 1 ? ', desde ayer' : ", desde hace $dias días");
            }

            if (!empty($m['detalle'])) {
                $texto .= ' ("' . $m['detalle'] . '")';
            }

            $partes[] = $texto;
        }

        return $partes;
    }
}

if (!function_exists('preconsultaResumenAutomatico')) {
    // Resumen sin IA: siempre funciona, también en las pruebas
    function preconsultaResumenAutomatico(array $respuestas, array $molestias, array $alertas)
    {
        $opciones = preconsultaOpcionesInicio();
        $lineas = [];

        if ($alertas) {
            $a = $alertas[0];
            $lineas[] = 'Atención: dice tomar ' . $a['medicamento'] . ' y tiene alergia a ' . $a['alergia'] . '.';
        }

        $lineas[] = 'Motivo: ' . rtrim($respuestas['motivo'], '.') . ' (' . mb_strtolower($opciones[$respuestas['inicio']]) . ').';

        $sintomas = preconsultaTextoSintomas($molestias);
        $lineas[] = $sintomas
            ? 'En SomaMap marcó ' . implode('; ', array_slice($sintomas, 0, 4)) . (count($sintomas) > 4 ? ' y ' . (count($sintomas) - 4) . ' zona(s) más' : '') . '.'
            : 'En SomaMap indicó que no siente nada en el cuerpo.';

        $lineas[] = $respuestas['medicamentos'] !== '' ? 'Toma: ' . $respuestas['medicamentos'] . '.' : 'No mencionó medicamentos.';

        if ($respuestas['alergias'] !== '') {
            $lineas[] = 'Alergias que mencionó: ' . $respuestas['alergias'] . '.';
        }

        if ($respuestas['pregunta'] !== '') {
            $lineas[] = 'Quiere preguntar: ' . $respuestas['pregunta'];
        }

        return implode(' ', $lineas);
    }
}

if (!function_exists('preconsultaResumenIA')) {
    // Resumen breve para el médico; null si Gemini no está disponible o no responde
    function preconsultaResumenIA(array $pre, array $respuestas, array $molestias, array $alertas)
    {
        if (!geminiDisponible()) {
            return null;
        }

        $opciones = preconsultaOpcionesInicio();
        $datos = [
            'motivo'                => $respuestas['motivo'],
            'desde_cuando'          => $opciones[$respuestas['inicio']],
            'sintomas_somamap'      => preconsultaTextoSintomas($molestias),
            'medicamentos_actuales' => $respuestas['medicamentos'],
            'alergias_expediente'   => (string) ($pre['alergias'] ?? ''),
            'alergias_mencionadas'  => $respuestas['alergias'],
            'pregunta_del_paciente' => $respuestas['pregunta'],
            'conflictos_detectados' => array_map(function ($a) {
                return $a['medicamento'] . ' vs alergia a ' . $a['alergia'];
            }, $alertas)
        ];

        $instruccion = 'Eres el asistente clínico de MediCore. Resume para el médico la pre-consulta que llenó su paciente ' .
            'en español de México, en 3 a 5 frases cortas y en tercera persona. Empieza por lo más importante. ' .
            'Si hay conflictos detectados entre medicamentos y alergias, menciónalos primero. ' .
            'No des diagnósticos, no sugieras tratamientos y no inventes datos que no vengan en la información. ' .
            'Texto plano, sin viñetas, sin markdown y sin saludos.';

        $texto = geminiGenerar($instruccion, json_encode($datos, JSON_UNESCAPED_UNICODE), 320);

        if (!$texto) {
            return null;
        }

        $texto = trim(preg_replace(['/[*_#`]+/', '/\s+/u'], ['', ' '], $texto));

        return $texto !== '' ? mb_substr($texto, 0, 900) : null;
    }
}

if (!function_exists('preconsultaGuardar')) {
    // Guarda el registro de SomaMap y las respuestas. El resumen con IA va después (preconsultaCompletar)
    function preconsultaGuardar($conexion, array $pre, array $entrada)
    {
        if (preconsultaEstado($pre) !== 'valida') {
            return ['ok' => false, 'codigo' => 410, 'mensaje' => 'Esta pre-consulta ya se respondió o venció. Pide a tu médico un código nuevo.'];
        }

        list($respuestas, $error) = preconsultaValidarRespuestas($entrada['respuestas'] ?? []);

        if ($error) {
            return ['ok' => false, 'codigo' => 422, 'mensaje' => $error];
        }

        $mapa = guardarRegistroMapa($conexion, (int) $pre['medico_id'], [
            'id_cliente'    => $entrada['id_cliente'] ?? '',
            'paciente_id'   => (int) $pre['paciente_id'],
            'comentario'    => $entrada['comentario'] ?? '',
            'sin_molestias' => !empty($entrada['sin_molestias']),
            'molestias'     => $entrada['molestias'] ?? []
        ]);

        if (!$mapa['ok']) {
            return ['ok' => false, 'codigo' => 422, 'mensaje' => $mapa['mensaje']];
        }

        $registro = null;

        foreach ($mapa['registros'] as $r) {
            if ((int) $r['id'] === (int) $mapa['registro_id']) {
                $registro = $r;
            }
        }

        $molestias = $registro['molestias'] ?? [];
        $alertas = preconsultaAlertas($respuestas, $pre['alergias'] ?? '');
        $resumen = preconsultaResumenAutomatico($respuestas, $molestias, $alertas);
        $json = json_encode($respuestas, JSON_UNESCAPED_UNICODE);
        $ahora = date('Y-m-d H:i:s');
        $registroId = (int) $mapa['registro_id'];
        $id = (int) $pre['id'];

        // estado = 'pendiente' en el WHERE: si llegan dos envíos a la vez solo cuenta uno
        $stmt = $conexion->prepare("
            UPDATE preconsultas
            SET estado = 'respondida', respuestas = ?, registro_mapa_id = ?, resumen = ?,
                resumen_fuente = 'auto', respondida_en = ?
            WHERE id = ? AND estado = 'pendiente'
        ");
        $stmt->bind_param("sissi", $json, $registroId, $resumen, $ahora, $id);
        $stmt->execute();
        $cambio = $stmt->affected_rows === 1;
        $stmt->close();

        if (!$cambio) {
            return ['ok' => false, 'codigo' => 410, 'mensaje' => 'Esta pre-consulta ya se había enviado.'];
        }

        return [
            'ok'         => true,
            'respuestas' => $respuestas,
            'molestias'  => $molestias,
            'alertas'    => $alertas
        ];
    }
}

if (!function_exists('preconsultaCompletar')) {
    // Después de contestarle al paciente: resumen con IA (si hay) y aviso push al médico
    function preconsultaCompletar($conexion, array $pre, array $guardado)
    {
        $resumenIA = preconsultaResumenIA($pre, $guardado['respuestas'], $guardado['molestias'], $guardado['alertas']);

        if ($resumenIA) {
            $id = (int) $pre['id'];
            $stmt = $conexion->prepare("UPDATE preconsultas SET resumen = ?, resumen_fuente = 'ia' WHERE id = ?");
            $stmt->bind_param("si", $resumenIA, $id);
            $stmt->execute();
            $stmt->close();
        }

        if (function_exists('notificarMedico') && function_exists('pushDisponible') && pushDisponible()) {
            $primerNombre = explode(' ', trim($pre['paciente']))[0];
            $cuerpo = $guardado['alertas']
                ? "$primerNombre respondió su pre-consulta. Revisa: posible conflicto con sus alergias."
                : "$primerNombre respondió su pre-consulta. Toca para ver el resumen.";

            try {
                notificarMedico($conexion, (int) $pre['medico_id'], 'Pre-consulta lista', $cuerpo, 'expediente.php?id=' . (int) $pre['paciente_id'] . '#preconsulta');
            } catch (Exception $e) {
                if (function_exists('registrarLog')) {
                    registrarLog('Pre-consulta: no se pudo enviar el aviso push: ' . $e->getMessage(), 'WARNING');
                }
            }
        }
    }
}

if (!function_exists('preconsultaUltima')) {
    // Para la tarjeta del expediente: la más reciente, con su estado y alertas
    function preconsultaUltima($conexion, $pacienteId, $alergiasExpediente)
    {
        $stmt = $conexion->prepare("SELECT * FROM preconsultas WHERE paciente_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param("i", $pacienteId);
        $stmt->execute();
        $pre = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$pre) {
            return null;
        }

        $pre['estado_actual'] = preconsultaEstado($pre);
        $pre['respuestas'] = json_decode((string) $pre['respuestas'], true) ?: null;
        $pre['alertas'] = $pre['respuestas'] ? preconsultaAlertas($pre['respuestas'], $alergiasExpediente) : [];

        return $pre;
    }
}
