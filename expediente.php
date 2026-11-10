<?php
include("config.php");
include("security.php");
require_once __DIR__ . "/integrations/SendGridMailer.php";
require_once __DIR__ . "/integrations/GoogleCalendar.php";
require_once __DIR__ . "/config/esquema.php";
require_once __DIR__ . "/integrations/Alergias.php";
require_once __DIR__ . "/integrations/NotasConsulta.php";
require_once __DIR__ . "/integrations/MapaCorporal.php";
require_once __DIR__ . "/integrations/WebPush.php";
require_once __DIR__ . "/integrations/Preconsulta.php";
require_once __DIR__ . "/integrations/Traslados.php";

verificarSesion();
prepararEsquemaInnovacion($conexion);

/* ======================================================
   VALIDAR PACIENTE
====================================================== */

if (!isset($_GET['id']) || empty($_GET['id'])) {
    registrarLog("Intento de acceso a expediente sin ID", "WARNING");
    redirigir("pacientes.php");
}

$paciente_id = intval($_GET['id']);

if ($paciente_id <= 0) {
    registrarLog("ID de paciente inválido", "WARNING");
    redirigir("pacientes.php");
}

$mensaje_exito = "";
$mensaje_error = "";

/* ======================================================
   OBTENER PACIENTE
====================================================== */

$stmt_paciente = $conexion->prepare("
    SELECT *
    FROM usuarios
    WHERE id = ?
    AND rol = 'paciente'
    LIMIT 1
");

$stmt_paciente->bind_param("i", $paciente_id);
$stmt_paciente->execute();

$paciente = $stmt_paciente->get_result()->fetch_assoc();
$stmt_paciente->close();

if (!$paciente) {
    registrarLog("Paciente inexistente ID: $paciente_id", "CRITICAL");
    redirigir("pacientes.php");
}

/* ======================================================
   OBTENER EXPEDIENTE
====================================================== */

$stmt_exp = $conexion->prepare("
    SELECT *
    FROM expedientes
    WHERE usuario_id = ?
    LIMIT 1
");

$stmt_exp->bind_param("i", $paciente_id);
$stmt_exp->execute();

$expediente = $stmt_exp->get_result()->fetch_assoc();
$stmt_exp->close();

/* ======================================================
   PROCESAR FORMULARIO
====================================================== */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    validarTokenCSRF($_POST['csrf_token'] ?? '');

    $peso = floatval($_POST['peso'] ?? 0);
    $estatura = floatval($_POST['estatura'] ?? 0);

    $presion_arterial = limpiar($_POST['presion_arterial'] ?? '');
    $diagnostico = limpiar($_POST['diagnostico'] ?? '');
    $receta = limpiar($_POST['receta'] ?? '');
    $tipo_dieta = limpiar($_POST['tipo_dieta'] ?? '');
    $dieta_autorizada = limpiar($_POST['dieta_autorizada'] ?? 'pendiente');

    $fecha_cita = !empty($_POST['fecha_cita'])
        ? $_POST['fecha_cita']
        : null;

    $hora_cita = !empty($_POST['hora_cita'])
        ? $_POST['hora_cita']
        : null;

    $plan_entrenamiento = limpiar($_POST['plan_entrenamiento'] ?? '');

    // Las alergias van en la tabla usuarios, no en expedientes
    $alergias = mb_substr(trim(strip_tags($_POST['alergias'] ?? '')), 0, 255);

    // Enfermedad del catálogo, es opcional (hay expedientes viejos sin ninguna)
    $enfermedad_id = !empty($_POST['enfermedad_id']) ? intval($_POST['enfermedad_id']) : null;

    // Liga de Jitsi Meet (gratis, sin API key). Sale del paciente + fecha y hora,
    // así que solo cambia si se mueve la cita
    $video_link = null;

    if (!empty($fecha_cita)) {
        $hashCita = substr(sha1($paciente_id . $fecha_cita . $hora_cita . 'medicore_salt'), 0, 10);
        $video_link = "https://meet.jit.si/MediCore-{$paciente_id}-{$hashCita}";
    }

    if ($peso <= 0 || $peso > 500) {
        $mensaje_error = "El peso ingresado no es válido.";
    } elseif ($estatura <= 0 || $estatura > 3) {
        $mensaje_error = "La estatura ingresada no es válida.";
    } elseif (
        empty($presion_arterial) ||
        empty($diagnostico) ||
        empty($receta) ||
        empty($tipo_dieta) ||
        empty($plan_entrenamiento)
    ) {
        $mensaje_error = "Todos los campos médicos obligatorios deben completarse.";
    }

    $imc = 0;

    if ($peso > 0 && $estatura > 0) {
        $imc = $peso / ($estatura * $estatura);
    }

    if (empty($mensaje_error)) {

        try {

            if ($expediente) {

                $update = $conexion->prepare("
                    UPDATE expedientes
                    SET
                        peso = ?,
                        estatura = ?,
                        imc = ?,
                        presion_arterial = ?,
                        diagnostico = ?,
                        receta = ?,
                        tipo_dieta = ?,
                        dieta_autorizada = ?,
                        fecha_cita = ?,
                        hora_cita = ?,
                        plan_entrenamiento = ?,
                        enfermedad_id = ?,
                        video_link = ?
                    WHERE usuario_id = ?
                ");

                $update->bind_param(
                    "dddssssssssisi",
                    $peso,
                    $estatura,
                    $imc,
                    $presion_arterial,
                    $diagnostico,
                    $receta,
                    $tipo_dieta,
                    $dieta_autorizada,
                    $fecha_cita,
                    $hora_cita,
                    $plan_entrenamiento,
                    $enfermedad_id,
                    $video_link,
                    $paciente_id
                );

                $update->execute();
                $update->close();

                $mensaje_exito = "Expediente actualizado correctamente.";

                registrarLog("Expediente actualizado ID paciente: $paciente_id", "INFO");
            } else {

                $insert = $conexion->prepare("
                    INSERT INTO expedientes (
                        usuario_id,
                        peso,
                        estatura,
                        imc,
                        presion_arterial,
                        diagnostico,
                        receta,
                        tipo_dieta,
                        dieta_autorizada,
                        fecha_cita,
                        hora_cita,
                        plan_entrenamiento,
                        enfermedad_id,
                        video_link
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $insert->bind_param(
                    "idddssssssssis",
                    $paciente_id,
                    $peso,
                    $estatura,
                    $imc,
                    $presion_arterial,
                    $diagnostico,
                    $receta,
                    $tipo_dieta,
                    $dieta_autorizada,
                    $fecha_cita,
                    $hora_cita,
                    $plan_entrenamiento,
                    $enfermedad_id,
                    $video_link
                );

                $insert->execute();
                $insert->close();

                $mensaje_exito = "Expediente creado correctamente.";

                registrarLog("Nuevo expediente creado ID paciente: $paciente_id", "INFO");
            }

            $stmt_alergias = $conexion->prepare("UPDATE usuarios SET alergias = ? WHERE id = ?");
            $stmt_alergias->bind_param("si", $alergias, $paciente_id);
            $stmt_alergias->execute();
            $stmt_alergias->close();
            $paciente['alergias'] = $alergias;

            $stmt_refresh = $conexion->prepare("
                SELECT *
                FROM expedientes
                WHERE usuario_id = ?
                LIMIT 1
            ");

            $stmt_refresh->bind_param("i", $paciente_id);
            $stmt_refresh->execute();

            $expediente = $stmt_refresh->get_result()->fetch_assoc();

            $stmt_refresh->close();

            /* ==================================================
               NOTIFICACIONES (correo, Google Calendar y push)
               si fallan o no están configurados no se cae el guardado
            ================================================== */

            // Aviso push a los dispositivos del médico si la cita es en los próximos 7 días
            if (!empty($fecha_cita)) {
                revisarRecordatoriosEnSegundoPlano($conexion, true);
            }

            if (!empty($fecha_cita) && !empty($paciente['email'])) {

                try {
                    $htmlCita = "
                        <div style='font-family:Arial,sans-serif;max-width:520px;margin:auto;'>
                            <h2 style='color:#00843d;'>MediCore Professional System</h2>
                            <p>Hola " . htmlspecialchars($paciente['nombre']) . ",</p>
                            <p>Tu cita médica ha sido confirmada para el <strong>" .
                                htmlspecialchars(date("d/m/Y", strtotime($fecha_cita))) .
                                "</strong>" . (!empty($hora_cita) ? " a las <strong>" . htmlspecialchars($hora_cita) . "</strong>" : "") . ".</p>
                            <p style='color:#64748b;font-size:13px;'>Este es un correo automático de MediCore.</p>
                        </div>
                    ";

                    enviarCorreoSendGrid($paciente['email'], $paciente['nombre'], "Confirmación de cita - MediCore", $htmlCita);
                } catch (Exception $eCorreo) {
                    registrarLog("No se pudo enviar confirmación de cita por correo: " . $eCorreo->getMessage(), "WARNING");
                }

                try {
                    sincronizarCitaConGoogleCalendar($paciente['nombre'], $fecha_cita, $hora_cita, $diagnostico);
                } catch (Exception $eCal) {
                    registrarLog("No se pudo sincronizar la cita con Google Calendar: " . $eCal->getMessage(), "WARNING");
                }
            }
        } catch (Exception $e) {

            registrarLog("Error expediente: " . $e->getMessage(), "CRITICAL");
            $mensaje_error = "Ocurrió un error al guardar el expediente.";
        }
    }
}

$dietas = [
    "Dieta Hipocalórica",
    "Dieta Hipercalórica",
    "Dieta Normocalórica",
    "Dieta Cetogénica",
    "Dieta Blanda",
    "Dieta para Diabéticos",
    "Otra"
];

/* ======================================================
   CATÁLOGO DE ENFERMEDADES (para el selector opcional)
====================================================== */

$resultadoEnfermedades = $conexion->query("SELECT id, nombre FROM enfermedades ORDER BY nombre ASC");

/* ======================================================
   ALERTAS DE ALERGIA (receta guardada vs. alergias)
====================================================== */

$alertasAlergia = detectarConflictosAlergia($expediente['receta'] ?? '', $paciente['alergias'] ?? '');
$enfermedadesCatalogo = $resultadoEnfermedades ? $resultadoEnfermedades->fetch_all(MYSQLI_ASSOC) : [];

/* ======================================================
   MAPA CORPORAL (resumen del último registro)
====================================================== */

$resumenMapa = resumenMapa(mapaRegistros($conexion, $paciente_id));

/* ======================================================
   PRE-CONSULTA POR QR (la más reciente del paciente)
====================================================== */

$preconsulta = preconsultaUltima($conexion, $paciente_id, $paciente['alergias'] ?? '');
$preEstado = $preconsulta['estado_actual'] ?? null;

/* ======================================================
   TRASLADO A LA CITA PRESENCIAL (el que sigue pendiente)
====================================================== */

$traslado = trasladoActivo($conexion, $paciente_id);
$trasladoHecho = $traslado ? null : trasladoUltimoCompletado($conexion, $paciente_id);
$sucursales = trasladoSucursales($conexion);
$trasladoInfo = $traslado
    ? trasladoDetalle($traslado, $paciente, $expediente['fecha_cita'] ?? null, $expediente['hora_cita'] ?? null, $sucursales)
    : null;
$avisoTraslado = $_SESSION['aviso_traslado'] ?? null;
unset($_SESSION['aviso_traslado']);

/* ======================================================
   NOTAS DE CONSULTA (dictadas o escritas, formato SOAP)
====================================================== */

$notasConsulta = notasDePaciente($conexion, $paciente_id, 10);
$iaNotas = geminiDisponible();
$origenesNota = [
    'voz'          => 'Dictada',
    'texto'        => 'Escrita',
    'sin_conexion' => 'Guardada sin conexión'
];
$titulosSOAP = [
    'subjetivo' => 'Subjetivo',
    'objetivo'  => 'Objetivo',
    'analisis'  => 'Análisis',
    'plan'      => 'Plan'
];
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expediente Clínico | <?= htmlspecialchars($paciente['nombre']) ?></title>

    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<?php include __DIR__ . "/pwa_head.php"; ?>
</head>

<body>

    <?php include("sidebar.php"); ?>

    <div class="content">

        <section class="top-actions">

            <a href="pacientes.php" class="btn-refresh">
                <i class="fas fa-arrow-left"></i>
                Regresar a Pacientes
            </a>

            <button type="button" onclick="descargarExpediente()" class="btn-pdf">
                <i class="fas fa-file-pdf"></i>
                Exportar PDF
            </button>

            <button type="button" class="btn-eliminar" id="btnEliminarPaciente">
                <i class="fas fa-trash-can"></i>
                Eliminar paciente
            </button>

        </section>

        <?php if ($mensaje_exito): ?>
            <div class="alerta exito">
                <i class="fas fa-circle-check"></i>
                <?= htmlspecialchars($mensaje_exito) ?>
            </div>
        <?php endif; ?>

        <?php if ($mensaje_error): ?>
            <div class="alerta error">
                <i class="fas fa-triangle-exclamation"></i>
                <?= htmlspecialchars($mensaje_error) ?>
            </div>
        <?php endif; ?>

        <?php foreach ($alertasAlergia as $alerta): ?>
            <div class="alerta <?= $alerta['nivel'] === 'precaucion' ? 'precaucion' : 'error' ?>">
                <i class="fas fa-triangle-exclamation"></i>
                <?= $alerta['nivel'] === 'precaucion' ? 'Precaución' : 'Alerta de alergia' ?>:
                la receta incluye <strong><?= htmlspecialchars($alerta['medicamento']) ?></strong>
                y el paciente tiene registrada alergia a <strong><?= htmlspecialchars($alerta['alergia']) ?></strong>.
                <?= htmlspecialchars($alerta['motivo']) ?>
            </div>
        <?php endforeach; ?>

        <main id="zona-expediente-pdf">

            <section class="header-paciente">

                <h2>
                    <i class="fas fa-file-medical"></i>
                    Expediente Clínico Profesional
                </h2>

                <p>
                    Información médica, nutricional y seguimiento clínico del paciente.
                </p>

                <div class="exp-grid">

                    <article class="exp-item">
                        <span>Paciente</span>
                        <strong><?= htmlspecialchars($paciente['nombre']) ?></strong>
                    </article>

                    <article class="exp-item">
                        <span>Correo</span>
                        <strong><?= htmlspecialchars($paciente['email']) ?></strong>
                    </article>

                    <article class="exp-item">
                        <span>Edad</span>
                        <strong>
                            <?= htmlspecialchars($paciente['edad'] ?? 'N/D') ?> años
                        </strong>
                    </article>

                    <article class="exp-item">
                        <span>Teléfono</span>
                        <strong>
                            <?= htmlspecialchars($paciente['telefono'] ?? 'N/D') ?>
                        </strong>
                    </article>

                    <article class="exp-item <?= !empty($paciente['alergias']) ? 'alergias' : '' ?>">
                        <span>Alergias</span>
                        <strong>
                            <?php if (!empty($paciente['alergias'])): ?>
                                <i class="fas fa-triangle-exclamation"></i>
                                <?= htmlspecialchars($paciente['alergias']) ?>
                            <?php else: ?>
                                Sin alergias registradas
                            <?php endif; ?>
                        </strong>
                    </article>

                    <article class="exp-item">
                        <span>Dirección</span>
                        <strong>
                            <?= htmlspecialchars($paciente['direccion'] ?? 'N/D') ?>
                        </strong>
                    </article>

                </div>

            </section>

            <!-- ==================================================
                 NOTA DE CONSULTA: dictado por voz + formato SOAP
            ================================================== -->
            <section
                class="card nota-consulta"
                id="notaConsulta"
                data-paciente="<?= (int) $paciente_id ?>"
                data-csrf="<?= htmlspecialchars(generarTokenCSRF()) ?>"
                data-ia="<?= $iaNotas ? '1' : '0' ?>"
                data-html2canvas-ignore="true">

                <div class="nota-cab">
                    <div>
                        <h3 class="section-title">
                            <i class="fas fa-microphone-lines"></i>
                            Nota de consulta
                        </h3>
                        <p class="nota-ayuda">
                            Dicta o escribe lo que pasó en la consulta.
                            <?= $iaNotas ? 'MediCore la ordena en formato SOAP y revisa las alergias del paciente.' : 'Se guarda en el historial del paciente.' ?>
                        </p>
                    </div>
                    <p class="nota-red" id="notaRed" hidden>
                        <i class="fas fa-wifi" aria-hidden="true"></i>
                        Sin conexión: las notas se guardan en este dispositivo
                    </p>
                </div>

                <div class="dictado">
                    <button type="button" class="btn-dictar" id="btnDictar" aria-pressed="false">
                        <i class="fas fa-microphone" aria-hidden="true"></i>
                        <span>Dictar</span>
                    </button>
                    <p class="dictado-estado" id="dictadoEstado" aria-live="polite">
                        Presiona Dictar y habla con normalidad.
                    </p>
                </div>

                <label for="notaTexto" class="sr-only">Lo que pasó en la consulta</label>
                <textarea
                    id="notaTexto"
                    class="nota-texto"
                    rows="5"
                    maxlength="8000"
                    placeholder="Ej. Refiere dolor de garganta desde hace tres días, sin fiebre. Faringe hiperémica, temperatura 37.2. Faringitis aguda. Paracetamol 500 mg cada 8 horas por 3 días y abundantes líquidos."></textarea>
                <p class="dictado-parcial" id="dictadoParcial" aria-hidden="true"></p>

                <div class="soap" id="notaSoap" hidden>
                    <div class="soap-grid">
                        <label>
                            <span>Subjetivo <small>lo que refiere el paciente</small></span>
                            <textarea data-campo="subjetivo" rows="3"></textarea>
                        </label>
                        <label>
                            <span>Objetivo <small>exploración y signos</small></span>
                            <textarea data-campo="objetivo" rows="3"></textarea>
                        </label>
                        <label>
                            <span>Análisis <small>diagnóstico</small></span>
                            <textarea data-campo="analisis" rows="3"></textarea>
                        </label>
                        <label>
                            <span>Plan <small>tratamiento e indicaciones</small></span>
                            <textarea data-campo="plan" rows="3"></textarea>
                        </label>
                    </div>
                </div>

                <div class="nota-alertas" id="notaAlertas"></div>

                <div class="nota-acciones">
                    <?php if ($iaNotas): ?>
                        <button type="button" class="btn-success" id="btnOrdenar">
                            <i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i>
                            Ordenar con IA
                        </button>
                    <?php endif; ?>
                    <button type="button" class="btn-success" id="btnPasarExpediente" hidden>
                        <i class="fas fa-arrow-down" aria-hidden="true"></i>
                        Pasar al diagnóstico y receta
                    </button>
                    <button type="button" class="btn-primary" id="btnGuardarNota">
                        <i class="fas fa-floppy-disk" aria-hidden="true"></i>
                        Guardar nota
                    </button>
                </div>

                <p class="nota-mensaje" id="notaMensaje" role="status"></p>

                <h4 class="notas-titulo">Notas anteriores</h4>

                <p class="notas-vacio" id="notasVacio" <?= empty($notasConsulta) ? '' : 'hidden' ?>>
                    Todavía no hay notas de consulta para este paciente.
                </p>

                <ol class="notas-lista" id="notasLista">
                    <?php foreach ($notasConsulta as $nota): ?>
                        <?php
                        $resumenNota = trim(strtok((string) ($nota['analisis'] ?: $nota['subjetivo'] ?: $nota['transcripcion']), "\n"));
                        $tieneSOAP = trim($nota['subjetivo'] . $nota['objetivo'] . $nota['analisis'] . $nota['plan']) !== '';
                        ?>
                        <li class="nota" data-id="<?= htmlspecialchars($nota['id_cliente']) ?>">
                            <details>
                                <summary>
                                    <span class="nota-fecha"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($nota['creado_en']))) ?></span>
                                    <span class="nota-origen <?= htmlspecialchars($nota['origen']) ?>"><?= htmlspecialchars($origenesNota[$nota['origen']] ?? 'Escrita') ?></span>
                                    <span class="nota-resumen"><?= htmlspecialchars(mb_strimwidth($resumenNota, 0, 90, '…')) ?></span>
                                </summary>

                                <?php if ($tieneSOAP): ?>
                                    <dl class="nota-soap">
                                        <?php foreach ($titulosSOAP as $campo => $titulo): ?>
                                            <?php if (trim((string) $nota[$campo]) !== ''): ?>
                                                <dt><?= $titulo ?></dt>
                                                <dd><?= htmlspecialchars($nota[$campo]) ?></dd>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </dl>
                                <?php else: ?>
                                    <p class="nota-libre"><?= htmlspecialchars($nota['transcripcion']) ?></p>
                                <?php endif; ?>
                            </details>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>

            <!-- ==================================================
                 PRE-CONSULTA POR QR: el paciente contesta desde su celular antes de la cita
            ================================================== -->
            <section class="card mapa-tarjeta" id="preconsulta" data-html2canvas-ignore="true">
                <h3 class="section-title">
                    <i class="fas fa-qrcode" aria-hidden="true"></i>
                    Pre-consulta por QR
                </h3>

                <div class="pre-resumen">
                    <div class="pre-resumen-texto">
                        <?php if ($preEstado === 'respondida'): ?>
                            <span class="pre-etiqueta">
                                <i class="fas fa-circle-check" aria-hidden="true"></i>
                                Respondida el <?= date('d/m/Y \a \l\a\s H:i', strtotime($preconsulta['respondida_en'])) ?>
                            </span>

                            <?php foreach (array_slice($preconsulta['alertas'], 0, 2) as $alerta): ?>
                                <p class="pre-alerta">
                                    <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                                    <span>
                                        Dice tomar <strong><?= e($alerta['medicamento']) ?></strong> y tiene alergia a
                                        <strong><?= e($alerta['alergia']) ?></strong>. <?= e($alerta['motivo']) ?>
                                    </span>
                                </p>
                            <?php endforeach; ?>

                            <p class="pre-resumen-ia"><?= e($preconsulta['resumen']) ?></p>
                            <p>
                                <?= $preconsulta['resumen_fuente'] === 'ia'
                                    ? 'Resumen hecho con IA a partir de sus respuestas. Confírmalo con el paciente.'
                                    : 'Resumen armado con sus respuestas.' ?>
                            </p>

                            <?php if ($preconsulta['respuestas']): ?>
                                <?php $r = $preconsulta['respuestas']; $inicios = preconsultaOpcionesInicio(); ?>
                                <details class="pre-detalle">
                                    <summary>Ver sus respuestas completas</summary>
                                    <dl class="pre-respuestas">
                                        <dt>Motivo</dt>
                                        <dd><?= e($r['motivo']) ?></dd>
                                        <dt>Desde cuándo</dt>
                                        <dd><?= e($inicios[$r['inicio']] ?? $r['inicio']) ?></dd>
                                        <dt>Medicamentos</dt>
                                        <dd><?= $r['medicamentos'] !== '' ? e($r['medicamentos']) : 'No mencionó' ?></dd>
                                        <dt>Alergias</dt>
                                        <dd><?= $r['alergias'] !== '' ? e($r['alergias']) : 'No mencionó' ?></dd>
                                        <?php if ($r['pregunta'] !== ''): ?>
                                            <dt>Su pregunta</dt>
                                            <dd><?= e($r['pregunta']) ?></dd>
                                        <?php endif; ?>
                                        <?php if (!empty($r['traslado'])): ?>
                                            <dt>Traslado</dt>
                                            <dd><?= e(trasladoTipos()[$r['traslado']['tipo']] ?? '') ?> desde <?= e($r['traslado']['direccion']) ?></dd>
                                        <?php endif; ?>
                                    </dl>
                                </details>
                            <?php endif; ?>

                        <?php elseif ($preEstado === 'valida'): ?>
                            <span class="pre-etiqueta pendiente">
                                <i class="fas fa-hourglass-half" aria-hidden="true"></i>
                                Esperando al paciente
                            </span>
                            <p>
                                El enlace vence el <?= e(fechaCitaEnTexto($preconsulta['expira_en'])) ?> a las <?= date('H:i', strtotime($preconsulta['expira_en'])) ?>.
                                Cuando lo conteste verás aquí el resumen y te llegará una notificación.
                            </p>
                        <?php else: ?>
                            <p>
                                El paciente escanea un QR con su celular y, sin crear cuenta, contesta por qué viene,
                                desde cuándo, qué medicamentos toma y marca en SomaMap dónde siente algo.
                                Antes de la cita te llega aquí un resumen.
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="pre-resumen-acciones">
                        <button type="button" class="btn-primary" data-preconsulta="generar" data-paciente="<?= (int) $paciente_id ?>" data-estado="<?= e($preEstado ?? 'ninguna') ?>" data-csrf="<?= e(generarTokenCSRF()) ?>">
                            <i class="fas fa-qrcode" aria-hidden="true"></i>
                            <?= $preEstado === 'valida' ? 'Mostrar QR' : ($preEstado === 'respondida' ? 'Nueva pre-consulta' : 'Generar QR') ?>
                        </button>
                        <?php if ($preEstado === 'respondida' && !empty($preconsulta['registro_mapa_id'])): ?>
                            <a href="mapa_corporal.php?id=<?= (int) $paciente_id ?>" class="btn-refresh">
                                <i class="fas fa-person" aria-hidden="true"></i>
                                Ver en SomaMap
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <dialog class="pre-dialogo" id="preDialogo" aria-labelledby="preDialogoTitulo" data-html2canvas-ignore="true">
                <div class="pre-dialogo-cuerpo">
                    <div class="pre-dialogo-cab">
                        <div>
                            <h3 id="preDialogoTitulo">Pre-consulta de <?= e(explode(' ', trim($paciente['nombre']))[0]) ?></h3>
                            <p>Pídele que lo escanee con la cámara de su celular, o mándale el enlace.</p>
                        </div>
                        <button type="button" class="mapa-hoja-cerrar" data-preconsulta="cerrar" aria-label="Cerrar">
                            <i class="fas fa-xmark" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div class="pre-qr" id="preQR" role="img" aria-label="Código QR de la pre-consulta">
                        <span class="pre-qr-cargando">Generando…</span>
                    </div>
                    <code class="pre-enlace" id="preEnlace"></code>
                    <p class="pre-vence" id="preVence"></p>
                    <p class="mapa-error" id="preDialogoError" role="alert" hidden></p>

                    <div class="pre-dialogo-acciones">
                        <button type="button" class="btn-refresh" data-preconsulta="copiar">
                            <i class="fas fa-copy" aria-hidden="true"></i>
                            <span>Copiar enlace</span>
                        </button>
                        <a class="btn-primary" id="preWhatsapp" href="#" target="_blank" rel="noopener">
                            <i class="fab fa-whatsapp" aria-hidden="true"></i>
                            WhatsApp
                        </a>
                    </div>
                </div>
            </dialog>

            <!-- ==================================================
                 SOMAMAP: mapa interactivo de síntomas; el paciente marca dónde siente algo
            ================================================== -->
            <section class="card mapa-tarjeta" data-html2canvas-ignore="true">
                <h3 class="section-title">
                    <i class="fas fa-person" aria-hidden="true"></i>
                    SomaMap
                    <span class="mapa-marca-sub">Mapa interactivo de síntomas</span>
                </h3>

                <div class="mapa-resumen">
                    <div class="mapa-resumen-texto">
                        <?php if ($resumenMapa): ?>
                            <p>
                                Último registro: <strong><?= date('d/m/Y H:i', strtotime($resumenMapa['fecha'])) ?></strong>.
                                <?= $resumenMapa['zonas'] === 0 ? 'Sin síntomas.' : ($resumenMapa['zonas'] === 1 ? '1 zona marcada.' : $resumenMapa['zonas'] . ' zonas marcadas.') ?>
                                <?= $resumenMapa['total'] === 1 ? '1 registro en total.' : $resumenMapa['total'] . ' registros en total.' ?>
                            </p>
                            <?php if ($resumenMapa['principales']): ?>
                                <ul class="mapa-lista">
                                    <?php foreach ($resumenMapa['principales'] as $m): ?>
                                        <li class="mapa-item">
                                            <span class="mapa-intensidad-chip" data-nivel="<?= (int) ceil($m['intensidad'] / 2) ?>"><?= (int) $m['intensidad'] ?></span>
                                            <span class="mapa-item-texto">
                                                <strong><?= htmlspecialchars($m['zona']) ?></strong>
                                                <span><?= htmlspecialchars($m['sensacion']) ?></span>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        <?php else: ?>
                            <p>
                                El paciente toca en una silueta dónde siente algún síntoma y dice qué siente, desde cuándo y qué tan fuerte.
                                Todavía no hay registros.
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="mapa-resumen-acciones">
                        <a href="mapa_corporal.php?id=<?= (int) $paciente_id ?>&amp;modo=paciente" class="btn-primary">
                            <i class="fas fa-hand-pointer" aria-hidden="true"></i>
                            Entregar al paciente
                        </a>
                        <?php if ($resumenMapa): ?>
                            <a href="mapa_corporal.php?id=<?= (int) $paciente_id ?>" class="btn-refresh">
                                <i class="fas fa-chart-line" aria-hidden="true"></i>
                                Ver evolución
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="card">

                <form method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= generarTokenCSRF() ?>">

                    <h3 class="section-title">
                        <i class="fas fa-heart-pulse"></i>
                        Evaluación Biométrica
                    </h3>

                    <div class="form-grid">

                        <div>
                            <label>Presión Arterial</label>
                            <input
                                type="text"
                                name="presion_arterial"
                                required
                                placeholder="Ej. 120/80"
                                value="<?= htmlspecialchars($expediente['presion_arterial'] ?? '') ?>">
                        </div>

                        <div>
                            <label>Peso corporal (kg)</label>
                            <input
                                type="number"
                                step="0.01"
                                name="peso"
                                id="peso"
                                required
                                oninput="calcularIMC()"
                                value="<?= htmlspecialchars($expediente['peso'] ?? '') ?>">
                        </div>

                        <div>
                            <label>Estatura (m)</label>
                            <input
                                type="number"
                                step="0.01"
                                name="estatura"
                                id="estatura"
                                required
                                oninput="calcularIMC()"
                                value="<?= htmlspecialchars($expediente['estatura'] ?? '') ?>">
                        </div>

                    </div>

                    <section class="imc-box">

                        <p class="imc-label">
                            Índice de Masa Corporal Calculado
                        </p>

                        <div id="imc-display" class="imc-number">
                            <?= isset($expediente['imc'])
                                ? number_format($expediente['imc'], 2)
                                : "0.00" ?>
                        </div>

                        <div id="imc-texto" class="imc-text"></div>

                    </section>

                    <section class="medical-note">

                        <strong>
                            <i class="fas fa-circle-info"></i>
                            Parámetros OMS
                        </strong>

                        <ul>
                            <li>Bajo peso: menor a 18.5</li>
                            <li>Normal: 18.5 - 24.9</li>
                            <li>Sobrepeso: 25 - 29.9</li>
                            <li>Obesidad: superior a 30</li>
                        </ul>

                    </section>

                    <h3 class="section-title">
                        <i class="fas fa-stethoscope"></i>
                        Diagnóstico y Tratamiento
                    </h3>

                    <label>Diagnóstico Médico</label>

                    <textarea
                        name="diagnostico"
                        required
                        placeholder="Describe el estado clínico general del paciente..."><?= htmlspecialchars($expediente['diagnostico'] ?? '') ?></textarea>

                    <label>Tipo de Dieta</label>

                    <select name="tipo_dieta" required>
                        <option value="">Selecciona una opción</option>

                        <?php foreach ($dietas as $dieta): ?>
                            <option
                                value="<?= htmlspecialchars($dieta) ?>"
                                <?= (
                                    isset($expediente['tipo_dieta']) &&
                                    $expediente['tipo_dieta'] === $dieta
                                ) ? 'selected' : '' ?>>

                                <?= htmlspecialchars($dieta) ?>

                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label>Tipo de Enfermedad (catálogo, opcional)</label>

                    <select name="enfermedad_id">
                        <option value="">-- Ninguna / no aplica --</option>

                        <?php foreach ($enfermedadesCatalogo as $enf): ?>
                            <option
                                value="<?= (int)$enf['id'] ?>"
                                <?= (isset($expediente['enfermedad_id']) && (int)$expediente['enfermedad_id'] === (int)$enf['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($enf['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label>Alergias conocidas</label>

                    <input
                        type="text"
                        name="alergias"
                        maxlength="255"
                        placeholder="Separadas por coma. Ej. penicilina, ibuprofeno, mariscos (déjalo vacío si no tiene)"
                        value="<?= htmlspecialchars($paciente['alergias'] ?? '') ?>">

                    <p class="medical-note" data-html2canvas-ignore="true" style="margin:8px 0 18px;">
                        <i class="fas fa-shield-virus"></i>
                        Al guardar, MediCore revisa si la receta incluye algún medicamento al que el paciente es alérgico
                        (o de su mismo grupo, como penicilinas o AINEs).
                        <a href="chatbot.php?paciente=<?= (int) $paciente_id ?>" target="_blank" rel="noopener" style="color:var(--primary);font-weight:700;">
                            <i class="fas fa-robot"></i> Consultar un medicamento en el asistente
                        </a>
                    </p>

                    <label>Receta y Plan Alimenticio</label>

                    <textarea
                        name="receta"
                        required
                        placeholder="Medicamentos, horarios, alimentos permitidos y restricciones..."><?= htmlspecialchars($expediente['receta'] ?? '') ?></textarea>

                    <label>Plan de Entrenamiento</label>

                    <textarea
                        name="plan_entrenamiento"
                        required
                        placeholder="Ejercicios, cardio, recomendaciones físicas y seguimiento..."><?= htmlspecialchars($expediente['plan_entrenamiento'] ?? '') ?></textarea>

                    <div class="form-grid">

                        <div>
                            <label>Estatus del Plan</label>

                            <select name="dieta_autorizada" required>

                                <option
                                    value="pendiente"
                                    <?= (($expediente['dieta_autorizada'] ?? '') === 'pendiente') ? 'selected' : '' ?>>
                                    Pendiente
                                </option>

                                <option
                                    value="autorizada"
                                    <?= (($expediente['dieta_autorizada'] ?? '') === 'autorizada') ? 'selected' : '' ?>>
                                    Autorizada
                                </option>

                                <option
                                    value="denegada"
                                    <?= (($expediente['dieta_autorizada'] ?? '') === 'denegada') ? 'selected' : '' ?>>
                                    Denegada
                                </option>

                            </select>
                        </div>

                    </div>

                    <h3 class="section-title">
                        <i class="fas fa-calendar-check"></i>
                        Seguimiento Médico
                    </h3>

                    <div class="form-grid">

                        <div>
                            <label>Fecha de Cita</label>
                            <input
                                type="date"
                                name="fecha_cita"
                                value="<?= htmlspecialchars($expediente['fecha_cita'] ?? '') ?>">
                        </div>

                        <div>
                            <label>Hora de Cita</label>
                            <input
                                type="time"
                                name="hora_cita"
                                value="<?= htmlspecialchars($expediente['hora_cita'] ?? '') ?>">
                        </div>

                    </div>

                    <?php if (!empty($expediente['video_link'])): ?>
                        <div class="medical-note" data-html2canvas-ignore="true">
                            <strong>
                                <i class="fas fa-video"></i>
                                Consulta por videollamada
                            </strong>
                            <p style="margin:8px 0;">
                                Se generó una sala de videollamada para la cita programada. Compártela con el paciente
                                y usa este mismo botón para entrar tú también.
                            </p>
                            <a
                                href="<?= htmlspecialchars($expediente['video_link']) ?>"
                                target="_blank"
                                rel="noopener"
                                class="btn-success">
                                <i class="fas fa-video"></i>
                                Iniciar videollamada
                            </a>
                        </div>
                    <?php endif; ?>

                    <div class="form-actions" data-html2canvas-ignore="true">

                        <button type="submit" class="btn-primary">
                            <i class="fas fa-save"></i>
                            Guardar Expediente
                        </button>

                    </div>

                </form>

            </section>

            <!-- ==================================================
                 TRASLADO A LA CITA PRESENCIAL: el consultorio lleva y trae al paciente
            ================================================== -->
            <section class="card mapa-tarjeta" id="traslado" data-html2canvas-ignore="true">
                <h3 class="section-title">
                    <i class="fas fa-van-shuttle" aria-hidden="true"></i>
                    Traslado a la cita
                    <span class="mapa-marca-sub">Llevamos y traemos al paciente</span>
                </h3>

                <?php if ($avisoTraslado): ?>
                    <div class="alerta <?= $avisoTraslado['tipo'] === 'exito' ? 'exito' : 'error' ?>" role="status">
                        <i class="fas <?= $avisoTraslado['tipo'] === 'exito' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>" aria-hidden="true"></i>
                        <span><?= e($avisoTraslado['texto']) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($traslado): ?>
                    <?php
                    // Cada paso con la hora en que pasó
                    $pasosTraslado = [
                        'solicitado' => ['Solicitado', $traslado['creado_en']],
                        'confirmado' => ['Confirmado', $traslado['confirmado_en']],
                        'en_camino'  => ['En camino', $traslado['en_camino_en']],
                        'completado' => ['Completado', $traslado['completado_en']]
                    ];
                    $indiceActual = array_search($traslado['estado'], array_keys($pasosTraslado), true);
                    $siguienteTraslado = trasladoSiguienteEstado($traslado['estado']);
                    ?>
                    <ol class="tras-pasos" aria-label="Estado del traslado">
                        <?php foreach (array_values($pasosTraslado) as $i => $paso): ?>
                            <li class="<?= $i < $indiceActual ? 'hecho' : '' ?>" <?= $i === $indiceActual ? 'aria-current="step"' : '' ?>>
                                <span aria-hidden="true"><?= $i < $indiceActual ? '<i class="fas fa-check"></i>' : $i + 1 ?></span>
                                <div>
                                    <?= e($paso[0]) ?>
                                    <?php if ($i <= $indiceActual && !empty($paso[1])): ?>
                                        <small><?= date('d/m H:i', strtotime($paso[1])) ?></small>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>

                    <div class="pre-resumen">
                        <div class="pre-resumen-texto">
                            <?php if ($traslado['origen'] === 'paciente'): ?>
                                <span class="pre-etiqueta pendiente">
                                    <i class="fas fa-mobile-screen" aria-hidden="true"></i>
                                    Lo pidió el paciente en su pre-consulta
                                </span>
                            <?php endif; ?>

                            <dl class="pre-respuestas">
                                <dt>Viaje</dt>
                                <dd><?= e($trasladoInfo['tipo']) ?></dd>
                                <dt>Recoger en</dt>
                                <dd><?= e($traslado['direccion']) ?></dd>
                                <?php if ($traslado['tipo'] !== 'vuelta'): ?>
                                    <dt>Recogida</dt>
                                    <dd>
                                        <?php if ($trasladoInfo['recogida']): ?>
                                            <strong><?= e($trasladoInfo['recogida']) ?></strong>,
                                            <?= (int) $traslado['minutos_antes'] ?> min antes de la cita del <?= e($trasladoInfo['cita']) ?>
                                        <?php else: ?>
                                            Se calcula cuando agendes la fecha y hora de la cita.
                                        <?php endif; ?>
                                    </dd>
                                <?php endif; ?>
                                <?php if ($traslado['tipo'] !== 'ida'): ?>
                                    <dt>Regreso</dt>
                                    <dd><?= $trasladoInfo['regreso'] ? 'Al terminar la consulta, aprox. ' . e($trasladoInfo['regreso']) : 'Al terminar la consulta' ?></dd>
                                <?php endif; ?>
                                <dt>Destino</dt>
                                <dd><?= e($trasladoInfo['destino']) ?></dd>
                                <?php if (!empty($traslado['chofer_nombre'])): ?>
                                    <dt>Chofer</dt>
                                    <dd><?= e($traslado['chofer_nombre']) ?>, tel. <?= e($traslado['chofer_telefono']) ?></dd>
                                    <?php if (!empty($traslado['vehiculo'])): ?>
                                        <dt>Vehículo</dt>
                                        <dd><?= e($traslado['vehiculo']) ?></dd>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!empty($traslado['notas'])): ?>
                                    <dt>Notas</dt>
                                    <dd><?= e($traslado['notas']) ?></dd>
                                <?php endif; ?>
                            </dl>
                        </div>

                        <div class="pre-resumen-acciones">
                            <?php if ($siguienteTraslado && $traslado['estado'] !== 'solicitado'): ?>
                                <form method="POST" action="traslado.php">
                                    <input type="hidden" name="csrf_token" value="<?= e(generarTokenCSRF()) ?>">
                                    <input type="hidden" name="paciente_id" value="<?= (int) $paciente_id ?>">
                                    <input type="hidden" name="traslado_id" value="<?= (int) $traslado['id'] ?>">
                                    <input type="hidden" name="accion" value="estado">
                                    <input type="hidden" name="estado" value="<?= e($siguienteTraslado[0]) ?>">
                                    <button type="submit" class="btn-primary">
                                        <i class="fas <?= e($siguienteTraslado[2]) ?>" aria-hidden="true"></i>
                                        <?= e($siguienteTraslado[1]) ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <a href="<?= e($trasladoInfo['maps']) ?>" class="btn-refresh" target="_blank" rel="noopener">
                                <i class="fas fa-route" aria-hidden="true"></i>
                                Ver ruta
                            </a>
                            <?php if (!empty($traslado['chofer_nombre'])): ?>
                                <a href="<?= e($trasladoInfo['whatsapp']) ?>" class="btn-refresh" target="_blank" rel="noopener">
                                    <i class="fab fa-whatsapp" aria-hidden="true"></i>
                                    Mandar datos al chofer
                                </a>
                                <?php if ($trasladoInfo['whatsapp_paciente']): ?>
                                    <a href="<?= e($trasladoInfo['whatsapp_paciente']) ?>" class="btn-refresh" target="_blank" rel="noopener">
                                        <i class="fas fa-comment-medical" aria-hidden="true"></i>
                                        Avisar al paciente
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                            <form method="POST" action="traslado.php" data-confirmar="Toca otra vez para cancelar">
                                <input type="hidden" name="csrf_token" value="<?= e(generarTokenCSRF()) ?>">
                                <input type="hidden" name="paciente_id" value="<?= (int) $paciente_id ?>">
                                <input type="hidden" name="traslado_id" value="<?= (int) $traslado['id'] ?>">
                                <input type="hidden" name="accion" value="cancelar">
                                <button type="submit" class="tras-cancelar">Cancelar traslado</button>
                            </form>
                        </div>
                    </div>

                    <?php if ($traslado['estado'] === 'solicitado'): ?>
                        <!-- Para confirmar se asigna el chofer: sus datos le llegan al paciente -->
                        <form method="POST" action="traslado.php" class="tras-chofer">
                            <input type="hidden" name="csrf_token" value="<?= e(generarTokenCSRF()) ?>">
                            <input type="hidden" name="paciente_id" value="<?= (int) $paciente_id ?>">
                            <input type="hidden" name="traslado_id" value="<?= (int) $traslado['id'] ?>">
                            <input type="hidden" name="accion" value="estado">
                            <input type="hidden" name="estado" value="confirmado">

                            <h4>
                                <i class="fas fa-id-card" aria-hidden="true"></i>
                                Asignar chofer
                            </h4>
                            <p>Sus datos le llegan al paciente por correo y al chofer por WhatsApp.</p>

                            <div class="form-grid">
                                <div>
                                    <label for="trasChofer">Nombre del chofer</label>
                                    <input type="text" id="trasChofer" name="chofer_nombre" maxlength="100" required autocomplete="off" placeholder="Ej. Ramón Hernández">
                                </div>
                                <div>
                                    <label for="trasChoferTel">Teléfono (WhatsApp)</label>
                                    <input type="tel" id="trasChoferTel" name="chofer_telefono" maxlength="20" required inputmode="tel" autocomplete="off" placeholder="10 dígitos">
                                </div>
                                <div>
                                    <label for="trasVehiculo">Vehículo <small>(opcional)</small></label>
                                    <input type="text" id="trasVehiculo" name="vehiculo" maxlength="80" autocomplete="off" placeholder="Modelo, color y placas">
                                </div>
                            </div>

                            <button type="submit" class="btn-primary">
                                <i class="fas fa-circle-check" aria-hidden="true"></i>
                                Asignar chofer y confirmar
                            </button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="tras-intro">
                        Para pacientes que no pueden llegar solos a una cita presencial: el consultorio pasa por ellos y los regresa a casa.
                        También lo pueden pedir desde su pre-consulta por QR.
                        <?php if ($trasladoHecho): ?>
                            Último traslado completado el <?= date('d/m/Y', strtotime($trasladoHecho['actualizado_en'])) ?>.
                        <?php endif; ?>
                    </p>

                    <form method="POST" action="traslado.php" class="tras-form">
                        <input type="hidden" name="csrf_token" value="<?= e(generarTokenCSRF()) ?>">
                        <input type="hidden" name="paciente_id" value="<?= (int) $paciente_id ?>">
                        <input type="hidden" name="accion" value="crear">

                        <fieldset class="mapa-campo">
                            <legend>Viaje</legend>
                            <div class="mapa-chips">
                                <?php foreach (trasladoTipos() as $clave => $texto): ?>
                                    <label><input type="radio" name="tipo" value="<?= e($clave) ?>" <?= $clave === 'ida_vuelta' ? 'checked' : '' ?>><span><?= e($texto) ?></span></label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>

                        <div class="form-grid">
                            <div>
                                <label for="trasDireccion">Recoger en</label>
                                <input type="text" id="trasDireccion" name="direccion" maxlength="255" required
                                    value="<?= e($paciente['direccion'] ?? '') ?>" placeholder="Calle, número, colonia y referencias">
                            </div>
                            <?php if (count($sucursales) > 1): ?>
                                <div>
                                    <label for="trasSucursal">Llevar a</label>
                                    <select id="trasSucursal" name="sucursal_id">
                                        <?php foreach ($sucursales as $s): ?>
                                            <option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php elseif ($sucursales): ?>
                                <input type="hidden" name="sucursal_id" value="<?= (int) $sucursales[0]['id'] ?>">
                            <?php endif; ?>
                            <div>
                                <label for="trasMinutos">Hora de recogida</label>
                                <select id="trasMinutos" name="minutos_antes">
                                    <?php foreach (trasladoMinutosOpciones() as $min): ?>
                                        <option value="<?= $min ?>" <?= $min === 45 ? 'selected' : '' ?>><?= $min ?> min antes de la cita</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="trasNotas">Notas para el chofer <small>(opcional)</small></label>
                                <input type="text" id="trasNotas" name="notas" maxlength="200" placeholder="Ej. usa silla de ruedas, va con acompañante">
                            </div>
                        </div>

                        <button type="submit" class="btn-primary">
                            <i class="fas fa-van-shuttle" aria-hidden="true"></i>
                            Programar traslado
                        </button>
                    </form>
                <?php endif; ?>
            </section>

        </main>

        <dialog class="dialogo-eliminar" id="dialogoEliminar" aria-labelledby="tituloEliminar">
            <form method="POST" action="eliminar_paciente.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generarTokenCSRF()) ?>">
                <input type="hidden" name="paciente_id" value="<?= (int) $paciente_id ?>">

                <h3 id="tituloEliminar">¿Eliminar a <?= htmlspecialchars($paciente['nombre']) ?>?</h3>
                <p>
                    Se borran su cuenta, su expediente, sus citas y sus notas de consulta.
                    Esto no se puede deshacer.
                </p>

                <div class="dialogo-acciones">
                    <button type="button" class="btn-refresh" id="btnCancelarEliminar">Cancelar</button>
                    <button type="submit" class="btn-eliminar-confirmar">
                        <i class="fas fa-trash-can"></i>
                        Eliminar paciente
                    </button>
                </div>
            </form>
        </dialog>

    </div>

    <script src="assets/js/nota-voz.js?v=3" defer></script>

    <script>
        (function () {
            const dialogo = document.getElementById('dialogoEliminar');
            document.getElementById('btnEliminarPaciente').addEventListener('click', function () {
                dialogo.showModal();
            });
            document.getElementById('btnCancelarEliminar').addEventListener('click', function () {
                dialogo.close();
            });
            dialogo.querySelector('form').addEventListener('submit', function () {
                this.querySelector('[type=submit]').disabled = true;
            });
        })();
    </script>

    <script>
        // Cancelar el traslado pide un segundo toque para no hacerlo por accidente
        document.querySelectorAll('form[data-confirmar]').forEach(function (form) {
            const boton = form.querySelector('[type=submit]');
            const texto = boton.textContent;
            let temporizador = null;

            form.addEventListener('submit', function (e) {
                if (form.dataset.listo === '1') {
                    boton.disabled = true;
                    return;
                }
                e.preventDefault();
                form.dataset.listo = '1';
                boton.textContent = form.dataset.confirmar;
                clearTimeout(temporizador);
                temporizador = setTimeout(function () {
                    delete form.dataset.listo;
                    boton.textContent = texto;
                }, 4000);
            });
        });
    </script>

    <script>
        function calcularIMC() {
            const peso = parseFloat(document.getElementById('peso').value);
            const estatura = parseFloat(document.getElementById('estatura').value);

            const imcDisplay = document.getElementById('imc-display');
            const imcTexto = document.getElementById('imc-texto');

            if (peso > 0 && estatura > 0) {
                const imc = peso / (estatura * estatura);

                imcDisplay.innerText = imc.toFixed(2);

                if (imc < 18.5) {
                    imcDisplay.style.color = "var(--warning)";
                    imcTexto.style.color = "var(--warning)";
                    imcTexto.innerText = "Bajo peso";
                } else if (imc >= 18.5 && imc < 25) {
                    imcDisplay.style.color = "var(--success)";
                    imcTexto.style.color = "var(--success)";
                    imcTexto.innerText = "Peso saludable";
                } else if (imc >= 25 && imc < 30) {
                    imcDisplay.style.color = "#c2410c";
                    imcTexto.style.color = "#c2410c";
                    imcTexto.innerText = "Sobrepeso";
                } else {
                    imcDisplay.style.color = "var(--danger)";
                    imcTexto.style.color = "var(--danger)";
                    imcTexto.innerText = "Obesidad";
                }
            } else {
                imcDisplay.innerText = "0.00";
                imcTexto.innerText = "";
                imcDisplay.style.color = "var(--primary)";
            }
        }

        window.onload = calcularIMC;

        function descargarExpediente() {
            const elemento = document.getElementById('zona-expediente-pdf');

            const opciones = {
                margin: 0.45,
                filename: 'Expediente_MediCore_<?= str_replace(" ", "_", $paciente['nombre']) ?>.pdf',
                image: {
                    type: 'jpeg',
                    quality: 1
                },
                html2canvas: {
                    scale: 2,
                    useCORS: true
                },
                jsPDF: {
                    unit: 'in',
                    format: 'letter',
                    orientation: 'portrait'
                },
                pagebreak: {
                    mode: ['css', 'legacy']
                }
            };

            html2pdf()
                .set(opciones)
                .from(elemento)
                .save();
        }
    </script>

    <script src="assets/js/qrcode.js?v=1" defer></script>
    <script src="assets/js/preconsulta-qr.js?v=1" defer></script>

</body>

</html>