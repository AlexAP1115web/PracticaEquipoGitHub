<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   MAPA CORPORAL DE MOLESTIAS
   El paciente toca una zona de la silueta y dice qué siente, desde cuándo y con qué
   intensidad. Cada vez que lo hace se guarda un registro con sus molestias, así el médico
   puede ver cómo cambiaron de una consulta a otra.
   Las zonas y sensaciones válidas están aquí; la silueta se dibuja en assets/js/mapa-corporal.js
===================================================== */

if (!function_exists('mapaZonas')) {
    // "derecho" e "izquierdo" son los del paciente
    function mapaZonas()
    {
        return [
            // Frente
            'cabeza'          => ['nombre' => 'Cabeza y cara', 'vista' => 'frente'],
            'cuello'          => ['nombre' => 'Cuello', 'vista' => 'frente'],
            'hombro_der'      => ['nombre' => 'Hombro derecho', 'vista' => 'frente'],
            'hombro_izq'      => ['nombre' => 'Hombro izquierdo', 'vista' => 'frente'],
            'pecho'           => ['nombre' => 'Pecho', 'vista' => 'frente'],
            'abdomen_sup'     => ['nombre' => 'Abdomen superior', 'vista' => 'frente'],
            'abdomen_inf'     => ['nombre' => 'Abdomen inferior', 'vista' => 'frente'],
            'pelvis'          => ['nombre' => 'Cadera e ingle', 'vista' => 'frente'],
            'brazo_der'       => ['nombre' => 'Brazo derecho', 'vista' => 'frente'],
            'brazo_izq'       => ['nombre' => 'Brazo izquierdo', 'vista' => 'frente'],
            'antebrazo_der'   => ['nombre' => 'Antebrazo derecho', 'vista' => 'frente'],
            'antebrazo_izq'   => ['nombre' => 'Antebrazo izquierdo', 'vista' => 'frente'],
            'mano_der'        => ['nombre' => 'Mano derecha', 'vista' => 'frente'],
            'mano_izq'        => ['nombre' => 'Mano izquierda', 'vista' => 'frente'],
            'muslo_der'       => ['nombre' => 'Muslo derecho', 'vista' => 'frente'],
            'muslo_izq'       => ['nombre' => 'Muslo izquierdo', 'vista' => 'frente'],
            'rodilla_der'     => ['nombre' => 'Rodilla derecha', 'vista' => 'frente'],
            'rodilla_izq'     => ['nombre' => 'Rodilla izquierda', 'vista' => 'frente'],
            'pierna_der'      => ['nombre' => 'Pierna derecha', 'vista' => 'frente'],
            'pierna_izq'      => ['nombre' => 'Pierna izquierda', 'vista' => 'frente'],
            'pie_der'         => ['nombre' => 'Pie derecho', 'vista' => 'frente'],
            'pie_izq'         => ['nombre' => 'Pie izquierdo', 'vista' => 'frente'],

            // Espalda
            'cabeza_post'     => ['nombre' => 'Cabeza (parte de atrás)', 'vista' => 'espalda'],
            'nuca'            => ['nombre' => 'Nuca', 'vista' => 'espalda'],
            'hombro_post_der' => ['nombre' => 'Hombro derecho (por detrás)', 'vista' => 'espalda'],
            'hombro_post_izq' => ['nombre' => 'Hombro izquierdo (por detrás)', 'vista' => 'espalda'],
            'espalda_alta'    => ['nombre' => 'Espalda alta', 'vista' => 'espalda'],
            'espalda_media'   => ['nombre' => 'Espalda media', 'vista' => 'espalda'],
            'espalda_baja'    => ['nombre' => 'Espalda baja (cintura)', 'vista' => 'espalda'],
            'gluteos'         => ['nombre' => 'Glúteos', 'vista' => 'espalda'],
            'brazo_post_der'  => ['nombre' => 'Brazo derecho (por detrás)', 'vista' => 'espalda'],
            'brazo_post_izq'  => ['nombre' => 'Brazo izquierdo (por detrás)', 'vista' => 'espalda'],
            'codo_der'        => ['nombre' => 'Codo y antebrazo derecho', 'vista' => 'espalda'],
            'codo_izq'        => ['nombre' => 'Codo y antebrazo izquierdo', 'vista' => 'espalda'],
            'mano_dorso_der'  => ['nombre' => 'Dorso de la mano derecha', 'vista' => 'espalda'],
            'mano_dorso_izq'  => ['nombre' => 'Dorso de la mano izquierda', 'vista' => 'espalda'],
            'muslo_post_der'  => ['nombre' => 'Muslo derecho (por detrás)', 'vista' => 'espalda'],
            'muslo_post_izq'  => ['nombre' => 'Muslo izquierdo (por detrás)', 'vista' => 'espalda'],
            'corva_der'       => ['nombre' => 'Detrás de la rodilla derecha', 'vista' => 'espalda'],
            'corva_izq'       => ['nombre' => 'Detrás de la rodilla izquierda', 'vista' => 'espalda'],
            'pantorrilla_der' => ['nombre' => 'Pantorrilla derecha', 'vista' => 'espalda'],
            'pantorrilla_izq' => ['nombre' => 'Pantorrilla izquierda', 'vista' => 'espalda'],
            'talon_der'       => ['nombre' => 'Talón derecho', 'vista' => 'espalda'],
            'talon_izq'       => ['nombre' => 'Talón izquierdo', 'vista' => 'espalda']
        ];
    }
}

if (!function_exists('mapaSensaciones')) {
    function mapaSensaciones()
    {
        return [
            'dolor'          => 'Dolor',
            'punzadas'       => 'Punzadas',
            'ardor'          => 'Ardor',
            'presion'        => 'Presión',
            'calambres'      => 'Calambres',
            'hormigueo'      => 'Hormigueo',
            'adormecimiento' => 'Adormecimiento',
            'comezon'        => 'Comezón',
            'rigidez'        => 'Rigidez',
            'inflamacion'    => 'Inflamación',
            'otra'           => 'Otra'
        ];
    }
}

if (!function_exists('mapaPaciente')) {
    function mapaPaciente($conexion, $pacienteId)
    {
        $stmt = $conexion->prepare("SELECT id, nombre, alergias FROM usuarios WHERE id = ? AND rol = 'paciente' LIMIT 1");
        $stmt->bind_param("i", $pacienteId);
        $stmt->execute();
        $paciente = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $paciente ?: null;
    }
}

if (!function_exists('mapaRegistros')) {
    // Registros del paciente del más viejo al más nuevo, cada uno con sus molestias
    function mapaRegistros($conexion, $pacienteId)
    {
        $stmt = $conexion->prepare("
            SELECT r.id, r.comentario, r.creado_en,
                   m.zona, m.sensacion, m.intensidad, m.desde, m.detalle
            FROM mapa_registros r
            LEFT JOIN mapa_molestias m ON m.registro_id = r.id
            WHERE r.paciente_id = ?
            ORDER BY r.creado_en ASC, r.id ASC, m.intensidad DESC, m.id ASC
        ");
        $stmt->bind_param("i", $pacienteId);
        $stmt->execute();
        $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $zonas = mapaZonas();
        $registros = [];

        foreach ($filas as $fila) {
            $id = (int) $fila['id'];

            if (!isset($registros[$id])) {
                $registros[$id] = [
                    'id'         => $id,
                    'fecha'      => $fila['creado_en'],
                    'comentario' => $fila['comentario'] ?? '',
                    'molestias'  => []
                ];
            }

            // Una zona que ya no exista en la lista no se muestra
            if ($fila['zona'] !== null && isset($zonas[$fila['zona']])) {
                $registros[$id]['molestias'][] = [
                    'zona'       => $fila['zona'],
                    'sensacion'  => $fila['sensacion'],
                    'intensidad' => (int) $fila['intensidad'],
                    'desde'      => $fila['desde'],
                    'detalle'    => $fila['detalle'] ?? ''
                ];
            }
        }

        return array_values($registros);
    }
}

if (!function_exists('mapaFechaValida')) {
    // 'AAAA-MM-DD' que no sea futura ni de hace más de 30 años; si no, null
    function mapaFechaValida($texto)
    {
        $texto = trim((string) $texto);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto)) {
            return null;
        }

        $fecha = DateTime::createFromFormat('!Y-m-d', $texto);

        if (!$fecha || $fecha->format('Y-m-d') !== $texto) {
            return null;
        }

        $hoy = new DateTime('today');

        if ($fecha > $hoy || $fecha < (clone $hoy)->modify('-30 years')) {
            return null;
        }

        return $texto;
    }
}

if (!function_exists('guardarRegistroMapa')) {
    function guardarRegistroMapa($conexion, $medicoId, array $entrada)
    {
        $idCliente = strtolower(trim((string) ($entrada['id_cliente'] ?? '')));

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $idCliente)) {
            return ['ok' => false, 'mensaje' => 'Identificador de registro inválido.'];
        }

        $pacienteId = intval($entrada['paciente_id'] ?? 0);

        if ($pacienteId <= 0 || !mapaPaciente($conexion, $pacienteId)) {
            return ['ok' => false, 'mensaje' => 'El paciente no existe.'];
        }

        // Si el mismo registro llega dos veces (doble clic o reintento) no se duplica
        $stmt = $conexion->prepare("SELECT id FROM mapa_registros WHERE id_cliente = ? LIMIT 1");
        $stmt->bind_param("s", $idCliente);
        $stmt->execute();
        $existente = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existente) {
            return ['ok' => true, 'repetido' => true, 'registros' => mapaRegistros($conexion, $pacienteId)];
        }

        $zonas = mapaZonas();
        $sensaciones = mapaSensaciones();
        $molestias = [];

        foreach (array_slice((array) ($entrada['molestias'] ?? []), 0, count($zonas)) as $m) {
            if (!is_array($m)) {
                continue;
            }

            $zona = (string) ($m['zona'] ?? '');
            $sensacion = (string) ($m['sensacion'] ?? '');
            $intensidad = intval($m['intensidad'] ?? 0);

            if (!isset($zonas[$zona]) || !isset($sensaciones[$sensacion]) || $intensidad < 1 || $intensidad > 10) {
                return ['ok' => false, 'mensaje' => 'Una de las molestias tiene datos incompletos. Revísala e intenta de nuevo.'];
            }

            // Una zona por registro; si viene repetida se queda la última
            $molestias[$zona] = [
                'zona'       => $zona,
                'sensacion'  => $sensacion,
                'intensidad' => $intensidad,
                'desde'      => mapaFechaValida($m['desde'] ?? ''),
                'detalle'    => mb_substr(trim(strip_tags((string) ($m['detalle'] ?? ''))), 0, 200)
            ];
        }

        $sinMolestias = !empty($entrada['sin_molestias']);

        if (!$molestias && !$sinMolestias) {
            return ['ok' => false, 'mensaje' => 'Marca al menos una zona o indica que no tienes molestias.'];
        }

        $comentario = mb_substr(trim(strip_tags((string) ($entrada['comentario'] ?? ''))), 0, 300);
        $ahora = date('Y-m-d H:i:s');

        $conexion->begin_transaction();

        try {
            $stmt = $conexion->prepare("
                INSERT INTO mapa_registros (id_cliente, paciente_id, medico_id, comentario, creado_en)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("siiss", $idCliente, $pacienteId, $medicoId, $comentario, $ahora);
            $stmt->execute();
            $registroId = $stmt->insert_id;
            $stmt->close();

            if ($molestias) {
                $stmt = $conexion->prepare("
                    INSERT INTO mapa_molestias (registro_id, zona, sensacion, intensidad, desde, detalle)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                foreach ($molestias as $m) {
                    $stmt->bind_param("ississ", $registroId, $m['zona'], $m['sensacion'], $m['intensidad'], $m['desde'], $m['detalle']);
                    $stmt->execute();
                }

                $stmt->close();
            }

            $conexion->commit();
        } catch (Exception $e) {
            $conexion->rollback();
            throw $e;
        }

        return ['ok' => true, 'registros' => mapaRegistros($conexion, $pacienteId)];
    }
}

if (!function_exists('resumenMapa')) {
    // Para la tarjeta del expediente: el registro más reciente y cuántos hay
    function resumenMapa(array $registros)
    {
        if (!$registros) {
            return null;
        }

        $ultimo = end($registros);
        $zonas = mapaZonas();
        $sensaciones = mapaSensaciones();
        $principales = [];

        foreach (array_slice($ultimo['molestias'], 0, 3) as $m) {
            $principales[] = [
                'zona'       => $zonas[$m['zona']]['nombre'],
                'sensacion'  => $sensaciones[$m['sensacion']] ?? $m['sensacion'],
                'intensidad' => $m['intensidad']
            ];
        }

        return [
            'total'       => count($registros),
            'fecha'       => $ultimo['fecha'],
            'zonas'       => count($ultimo['molestias']),
            'principales' => $principales
        ];
    }
}
