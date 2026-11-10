<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   TRASLADO A LA CITA PRESENCIAL
   El consultorio lleva y trae al paciente (adultos mayores, movilidad reducida, sin transporte).
   Lo pide el médico desde el expediente o el paciente desde su pre-consulta por QR.
   La hora de recogida sale de la cita del expediente; la ruta se abre en Google Maps.
   Al confirmarlo se asigna chofer y vehículo: los datos del viaje le llegan al chofer por
   WhatsApp y al paciente por correo (al confirmar y cuando el chofer sale por él).
   Estados: solicitado -> confirmado -> en_camino -> completado (o cancelado); cada uno guarda su hora
===================================================== */

if (!function_exists('trasladoTipos')) {
    function trasladoTipos()
    {
        return [
            'ida'        => 'Solo ida',
            'vuelta'     => 'Solo regreso',
            'ida_vuelta' => 'Ida y vuelta'
        ];
    }
}

if (!function_exists('trasladoEstados')) {
    function trasladoEstados()
    {
        return [
            'solicitado' => 'Solicitado',
            'confirmado' => 'Confirmado',
            'en_camino'  => 'En camino',
            'completado' => 'Completado',
            'cancelado'  => 'Cancelado'
        ];
    }
}

if (!function_exists('trasladoSiguienteEstado')) {
    // Botón principal de la tarjeta: a qué estado pasa y cómo se llama la acción
    function trasladoSiguienteEstado($estado)
    {
        $siguiente = [
            'solicitado' => ['confirmado', 'Asignar chofer y confirmar', 'fa-circle-check'],
            'confirmado' => ['en_camino', 'El chofer ya salió', 'fa-van-shuttle'],
            'en_camino'  => ['completado', 'Marcar completado', 'fa-flag-checkered']
        ];

        return $siguiente[$estado] ?? null;
    }
}

if (!function_exists('trasladoMinutosOpciones')) {
    function trasladoMinutosOpciones()
    {
        return [30, 45, 60, 90];
    }
}

if (!function_exists('trasladoLimpiar')) {
    function trasladoLimpiar($texto, $maximo)
    {
        $texto = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $texto)));

        return mb_substr($texto, 0, $maximo);
    }
}

if (!function_exists('trasladoValidar')) {
    // Regresa [datos limpios, mensaje de error o null]
    function trasladoValidar(array $entrada)
    {
        $datos = [
            'tipo'          => (string) ($entrada['tipo'] ?? ''),
            'direccion'     => trasladoLimpiar($entrada['direccion'] ?? '', 255),
            'notas'         => trasladoLimpiar($entrada['notas'] ?? '', 200),
            'sucursal_id'   => !empty($entrada['sucursal_id']) ? (int) $entrada['sucursal_id'] : null,
            'minutos_antes' => (int) ($entrada['minutos_antes'] ?? 45)
        ];

        if (!isset(trasladoTipos()[$datos['tipo']])) {
            return [null, 'Elige si el traslado es de ida, de regreso o los dos.'];
        }

        if (mb_strlen($datos['direccion']) < 8) {
            return [null, 'Escribe la dirección donde se recoge al paciente (calle, número y colonia).'];
        }

        if (!in_array($datos['minutos_antes'], trasladoMinutosOpciones(), true)) {
            $datos['minutos_antes'] = 45;
        }

        return [$datos, null];
    }
}

if (!function_exists('trasladoTelefonoWhatsapp')) {
    // "222 418 7730" -> "522224187730" (México); null si no parece un teléfono
    function trasladoTelefonoWhatsapp($telefono)
    {
        $digitos = preg_replace('/\D+/', '', (string) $telefono);

        if (strlen($digitos) === 10) {
            return '52' . $digitos;
        }

        return strlen($digitos) >= 11 && strlen($digitos) <= 13 ? $digitos : null;
    }
}

if (!function_exists('trasladoValidarChofer')) {
    // Datos del chofer al confirmar; regresa [datos, error]
    function trasladoValidarChofer(array $entrada)
    {
        $datos = [
            'chofer_nombre'   => trasladoLimpiar($entrada['chofer_nombre'] ?? '', 100),
            'chofer_telefono' => trasladoLimpiar($entrada['chofer_telefono'] ?? '', 20),
            'vehiculo'        => trasladoLimpiar($entrada['vehiculo'] ?? '', 80)
        ];

        if (mb_strlen($datos['chofer_nombre']) < 3) {
            return [null, 'Escribe el nombre del chofer para confirmar el traslado.'];
        }

        if (!trasladoTelefonoWhatsapp($datos['chofer_telefono'])) {
            return [null, 'El teléfono del chofer debe tener 10 dígitos.'];
        }

        return [$datos, null];
    }
}

if (!function_exists('trasladoActivo')) {
    // El traslado que sigue pendiente para la próxima cita del paciente (o null)
    function trasladoActivo($conexion, $pacienteId)
    {
        $stmt = $conexion->prepare("
            SELECT *
            FROM traslados
            WHERE paciente_id = ? AND estado IN ('solicitado', 'confirmado', 'en_camino')
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param("i", $pacienteId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $fila ?: null;
    }
}

if (!function_exists('trasladoUltimoCompletado')) {
    function trasladoUltimoCompletado($conexion, $pacienteId)
    {
        $stmt = $conexion->prepare("
            SELECT *
            FROM traslados
            WHERE paciente_id = ? AND estado = 'completado'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param("i", $pacienteId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $fila ?: null;
    }
}

if (!function_exists('trasladoCrear')) {
    // Si ya había uno pendiente se cancela y queda el nuevo (el último que se pidió es el que vale)
    function trasladoCrear($conexion, $pacienteId, $medicoId, array $datos, $origen = 'medico')
    {
        $ahora = date('Y-m-d H:i:s');
        $origen = $origen === 'paciente' ? 'paciente' : 'medico';

        $stmt = $conexion->prepare("
            UPDATE traslados
            SET estado = 'cancelado', actualizado_en = ?
            WHERE paciente_id = ? AND estado IN ('solicitado', 'confirmado', 'en_camino')
        ");
        $stmt->bind_param("si", $ahora, $pacienteId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conexion->prepare("
            INSERT INTO traslados
                (paciente_id, medico_id, sucursal_id, tipo, direccion, notas, minutos_antes, estado, origen, creado_en, actualizado_en)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'solicitado', ?, ?, ?)
        ");
        $stmt->bind_param(
            "iiisssisss",
            $pacienteId,
            $medicoId,
            $datos['sucursal_id'],
            $datos['tipo'],
            $datos['direccion'],
            $datos['notas'],
            $datos['minutos_antes'],
            $origen,
            $ahora,
            $ahora
        );
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();

        return $id;
    }
}

if (!function_exists('trasladoCambiarEstado')) {
    // Solo avanza un paso a la vez o se cancela; regresa el traslado actualizado o null.
    // $chofer (nombre, teléfono y vehículo) se guarda al confirmar
    function trasladoCambiarEstado($conexion, $trasladoId, $pacienteId, $nuevoEstado, array $chofer = [])
    {
        $stmt = $conexion->prepare("SELECT * FROM traslados WHERE id = ? AND paciente_id = ? LIMIT 1");
        $stmt->bind_param("ii", $trasladoId, $pacienteId);
        $stmt->execute();
        $traslado = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$traslado) {
            return null;
        }

        $siguiente = trasladoSiguienteEstado($traslado['estado']);
        $permitido = ($siguiente && $siguiente[0] === $nuevoEstado) ||
            ($nuevoEstado === 'cancelado' && in_array($traslado['estado'], ['solicitado', 'confirmado', 'en_camino'], true));

        if (!$permitido) {
            return null;
        }

        $ahora = date('Y-m-d H:i:s');
        $columnaHora = [
            'confirmado' => 'confirmado_en',
            'en_camino'  => 'en_camino_en',
            'completado' => 'completado_en',
            'cancelado'  => 'cancelado_en'
        ][$nuevoEstado];

        if ($nuevoEstado === 'confirmado') {
            $stmt = $conexion->prepare("
                UPDATE traslados
                SET estado = ?, actualizado_en = ?, confirmado_en = ?, chofer_nombre = ?, chofer_telefono = ?, vehiculo = ?
                WHERE id = ?
            ");
            $stmt->bind_param("ssssssi", $nuevoEstado, $ahora, $ahora, $chofer['chofer_nombre'], $chofer['chofer_telefono'], $chofer['vehiculo'], $trasladoId);
            $traslado = $chofer + $traslado;
        } else {
            // El nombre de la columna sale de la lista de arriba, no del usuario
            $stmt = $conexion->prepare("UPDATE traslados SET estado = ?, actualizado_en = ?, $columnaHora = ? WHERE id = ?");
            $stmt->bind_param("sssi", $nuevoEstado, $ahora, $ahora, $trasladoId);
        }

        $stmt->execute();
        $stmt->close();

        $traslado['estado'] = $nuevoEstado;
        $traslado['actualizado_en'] = $ahora;
        $traslado[$columnaHora] = $ahora;

        return $traslado;
    }
}

if (!function_exists('trasladoSucursales')) {
    function trasladoSucursales($conexion)
    {
        $resultado = $conexion->query("SELECT id, nombre, direccion, lat, lng, telefono FROM sucursales ORDER BY id ASC");

        return $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('trasladoDetalle')) {
    // Todo lo que muestra la tarjeta: horario, destino, ruta en Maps y mensaje para el chofer
    function trasladoDetalle(array $traslado, array $paciente, $fechaCita, $horaCita, array $sucursales)
    {
        $tipos = trasladoTipos();
        $destino = null;

        foreach ($sucursales as $s) {
            if ($traslado['sucursal_id'] && (int) $s['id'] === (int) $traslado['sucursal_id']) {
                $destino = $s;
            }
        }

        if (!$destino && $sucursales) {
            $destino = $sucursales[0];
        }

        $recogida = null;
        $regreso = null;
        $textoCita = null;

        if (!empty($fechaCita) && !empty($horaCita)) {
            $cita = strtotime($fechaCita . ' ' . $horaCita);
            $textoCita = (function_exists('fechaCitaEnTexto') ? fechaCitaEnTexto($fechaCita) : date('d/m/Y', $cita)) . ' a las ' . date('H:i', $cita);

            if ($traslado['tipo'] !== 'vuelta') {
                $recogida = date('H:i', $cita - (int) $traslado['minutos_antes'] * 60);
            }

            if ($traslado['tipo'] !== 'ida') {
                // La consulta dura alrededor de una hora; el chofer confirma por teléfono
                $regreso = date('H:i', $cita + 60 * 60);
            }
        }

        $destinoTexto = $destino ? $destino['nombre'] . ' (' . $destino['direccion'] . ')' : 'Consultorio de MediCore';
        $destinoMaps = $destino
            ? (!empty($destino['lat']) && !empty($destino['lng']) ? $destino['lat'] . ',' . $destino['lng'] : $destino['direccion'])
            : '';

        $maps = 'https://www.google.com/maps/dir/?api=1&travelmode=driving&origin=' . rawurlencode($traslado['direccion']) .
            ($destinoMaps !== '' ? '&destination=' . rawurlencode($destinoMaps) : '');

        $lineas = [
            'Traslado MediCore (' . mb_strtolower($tipos[$traslado['tipo']] ?? $traslado['tipo']) . ')',
            !empty($traslado['chofer_nombre']) ? 'Chofer: ' . $traslado['chofer_nombre'] . (!empty($traslado['vehiculo']) ? ', ' . $traslado['vehiculo'] : '') : null,
            'Paciente: ' . $paciente['nombre'],
            !empty($paciente['telefono']) ? 'Teléfono: ' . $paciente['telefono'] : null,
            'Recoger en: ' . $traslado['direccion'],
            $recogida ? 'Hora de recogida: ' . $recogida . ' (' . $textoCita . ')' : ($textoCita ? 'Cita: ' . $textoCita : 'Cita: por confirmar'),
            $regreso ? 'Regreso: al terminar la consulta, aprox. ' . $regreso : null,
            'Destino: ' . $destinoTexto,
            $traslado['notas'] !== '' && $traslado['notas'] !== null ? 'Notas: ' . $traslado['notas'] : null,
            'Ruta: ' . $maps
        ];

        // Al chofer le llega directo a su número; si aún no hay chofer se elige el contacto
        $numeroChofer = trasladoTelefonoWhatsapp($traslado['chofer_telefono'] ?? '');
        $numeroPaciente = trasladoTelefonoWhatsapp($paciente['telefono'] ?? '');
        $primerNombre = explode(' ', trim($paciente['nombre']))[0];

        $aviso = ['Hola ' . $primerNombre . ', te escribimos de MediCore sobre tu traslado a la consulta.'];

        if ($recogida) {
            $aviso[] = 'Pasamos por ti a las ' . $recogida . ' en ' . $traslado['direccion'] . '.';
        }

        if (!empty($traslado['chofer_nombre'])) {
            $aviso[] = 'Tu chofer es ' . $traslado['chofer_nombre'] . (!empty($traslado['vehiculo']) ? ' (' . $traslado['vehiculo'] . ')' : '') .
                (!empty($traslado['chofer_telefono']) ? ', teléfono ' . $traslado['chofer_telefono'] : '') . '.';
        }

        $aviso[] = 'Tu cita: ' . ($textoCita ?: 'por confirmar') . ' en ' . $destinoTexto . '.';

        return [
            'tipo'       => $tipos[$traslado['tipo']] ?? $traslado['tipo'],
            'recogida'   => $recogida,
            'regreso'    => $regreso,
            'cita'       => $textoCita,
            'destino'    => $destinoTexto,
            'maps'       => $maps,
            'whatsapp'   => 'https://wa.me/' . ($numeroChofer ?: '') . '?text=' . rawurlencode(implode("\n", array_filter($lineas))),
            'whatsapp_paciente' => $numeroPaciente ? 'https://wa.me/' . $numeroPaciente . '?text=' . rawurlencode(implode(' ', $aviso)) : null,
            'sin_cita'   => $textoCita === null
        ];
    }
}

if (!function_exists('trasladosActivosPorPaciente')) {
    // Para la agenda: paciente_id => estado del traslado pendiente
    function trasladosActivosPorPaciente($conexion)
    {
        $resultado = $conexion->query("
            SELECT paciente_id, estado
            FROM traslados
            WHERE estado IN ('solicitado', 'confirmado', 'en_camino')
            ORDER BY id ASC
        ");
        $mapa = [];

        if ($resultado) {
            while ($fila = $resultado->fetch_assoc()) {
                $mapa[(int) $fila['paciente_id']] = $fila['estado'];
            }
        }

        return $mapa;
    }
}

if (!function_exists('trasladoCorreoPaciente')) {
    // Correo al paciente al confirmar el traslado y cuando el chofer sale por él (si hay SendGrid y correo)
    function trasladoCorreoPaciente(array $paciente, array $traslado, array $detalle, $momento)
    {
        if (empty($paciente['email']) || !function_exists('enviarCorreoSendGrid')) {
            return false;
        }

        $chofer = !empty($traslado['chofer_nombre'])
            ? $traslado['chofer_nombre'] . (!empty($traslado['chofer_telefono']) ? ' (tel. ' . $traslado['chofer_telefono'] . ')' : '')
            : null;
        $filas = [
            'Viaje'          => $detalle['tipo'],
            'Pasamos por ti' => $detalle['recogida'] ? 'a las ' . $detalle['recogida'] . ' en ' . $traslado['direccion'] : $traslado['direccion'],
            'Regreso'        => $detalle['regreso'] ? 'al terminar tu consulta (aprox. ' . $detalle['regreso'] . ')' : null,
            'Chofer'         => $chofer,
            'Vehículo'       => $traslado['vehiculo'] ?? null,
            'Tu cita'        => $detalle['cita'],
            'Destino'        => $detalle['destino']
        ];
        $html = '';

        foreach ($filas as $etiqueta => $valor) {
            if ($valor) {
                $html .= '<p style="margin:4px 0;"><strong>' . $etiqueta . ':</strong> ' . htmlspecialchars($valor) . '</p>';
            }
        }

        $nombre = htmlspecialchars($paciente['nombre']);
        $enCamino = $momento === 'en_camino';
        $intro = $enCamino
            ? 'Tu chofer <strong>ya va en camino</strong> por ti. Ten a la mano tu identificación y, si tienes, tus estudios.'
            : 'Tu traslado a la consulta quedó <strong>confirmado</strong>.';

        $cuerpo = "
            <div style='font-family:Arial,sans-serif;max-width:520px;margin:auto;'>
                <h2 style='color:#00843d;'>MediCore Professional System</h2>
                <p>Hola $nombre,</p>
                <p>$intro</p>
                $html
                <p style='color:#64748b;font-size:13px;'>Si cambia tu dirección o tu horario, avísale a tu médico. Este es un correo automático de MediCore.</p>
            </div>
        ";

        $asunto = $enCamino ? 'Tu chofer va en camino - MediCore' : 'Tu traslado está confirmado - MediCore';

        return enviarCorreoSendGrid($paciente['email'], $paciente['nombre'], $asunto, $cuerpo);
    }
}
