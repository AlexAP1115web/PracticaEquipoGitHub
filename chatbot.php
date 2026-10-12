<?php
include("config.php");
include("security.php");
require_once __DIR__ . "/config/esquema.php";
require_once __DIR__ . "/integrations/GeminiAI.php";

verificarSesion();
prepararEsquemaInnovacion($conexion);

$medicoId = (int) ($_SESSION['medico_id'] ?? $_SESSION['medico']);

/* ======================================================
   PACIENTES (para cruzar la consulta con sus alergias)
====================================================== */

$pacientes = $conexion->query("
    SELECT id, nombre, alergias
    FROM usuarios
    WHERE rol = 'paciente'
    ORDER BY nombre ASC
")->fetch_all(MYSQLI_ASSOC);

$pacienteInicial = isset($_GET['paciente']) ? intval($_GET['paciente']) : 0;

/* ======================================================
   ÚLTIMAS CONSULTAS DEL MÉDICO
====================================================== */

$stmtHist = $conexion->prepare("
    SELECT c.pregunta, c.medicamento, c.tema, c.fuente, c.creado_en, u.nombre AS paciente
    FROM chatbot_consultas c
    LEFT JOIN usuarios u ON u.id = c.paciente_id
    WHERE c.medico_id = ?
    ORDER BY c.id DESC
    LIMIT 8
");
$stmtHist->bind_param("i", $medicoId);
$stmtHist->execute();
$historial = $stmtHist->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtHist->close();

$iaActiva = geminiDisponible();
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(generarTokenCSRF()) ?>">
    <title>Asistente de medicamentos | MediCore</title>

    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0 0 0 0);
            white-space: nowrap;
        }

        .chat-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 320px;
            gap: 24px;
            align-items: start;
        }

        /* ---------- Panel de conversación ---------- */

        .chat-panel {
            display: flex;
            flex-direction: column;
            height: calc(100vh - 210px);
            min-height: 520px;
            background: var(--hoja);
            border: 1px solid var(--linea);
            border-radius: var(--radio-hoja);
            overflow: hidden;
        }

        .chat-top {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            justify-content: space-between;
            gap: 12px 16px;
            padding: 16px 20px;
            border-bottom: 1px solid var(--linea);
        }

        .chat-top label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 500;
            color: var(--gris);
        }

        .chat-top select {
            min-width: 260px;
            height: 42px;
        }

        .estado-ia {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 32px;
            padding: 0 12px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 500;
            white-space: nowrap;
        }

        .estado-ia.on {
            background: var(--ok-fondo);
            color: var(--ok);
        }

        .estado-ia.off {
            background: var(--precaucion-fondo);
            color: var(--precaucion);
        }

        /* Franja con las alergias del paciente elegido */
        .contexto-paciente {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 20px;
            font-size: 14px;
            border-bottom: 1px solid var(--linea);
        }

        .contexto-paciente[hidden] {
            display: none;
        }

        .contexto-paciente.riesgo {
            background: var(--alerta-fondo);
            color: var(--alerta);
        }

        .contexto-paciente.sin-alergias {
            background: var(--ok-fondo);
            color: var(--ok);
        }

        .contexto-paciente strong {
            font-weight: 600;
        }

        .chat-mensajes {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 14px;
            padding: 22px 20px;
            overflow-y: auto;
            overscroll-behavior: contain;
            background: var(--papel);
        }

        .msg {
            max-width: 82%;
            padding: 12px 15px;
            border-radius: 14px;
            font-size: 15px;
            line-height: 1.55;
            animation: msg-entra 200ms cubic-bezier(.23, 1, .32, 1);
        }

        @keyframes msg-entra {
            from {
                opacity: 0;
                transform: translateY(6px);
            }
            to {
                opacity: 1;
                transform: none;
            }
        }

        .msg.medico {
            align-self: flex-end;
            background: var(--pino);
            color: #fff;
            border-bottom-right-radius: 4px;
        }

        .msg.bot {
            align-self: flex-start;
            background: var(--hoja);
            border: 1px solid var(--linea);
            border-bottom-left-radius: 4px;
        }

        .msg.bot p + p,
        .msg.bot p + ul,
        .msg.bot ul + p {
            margin-top: 8px;
        }

        .msg.bot ul {
            padding-left: 20px;
        }

        .msg.bot li + li {
            margin-top: 4px;
        }

        .msg-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 10px;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 10px;
            border-radius: 999px;
            background: var(--menta);
            color: var(--verde-oscuro);
            font-size: 12px;
            font-weight: 600;
        }

        .msg-fuente {
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid var(--linea-suave);
            font-size: 13px;
            color: var(--gris);
        }

        .msg-fuente a {
            color: var(--verde-oscuro);
            font-weight: 600;
        }

        .alerta-alergia {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            padding: 10px 12px;
            border-radius: var(--radio-control);
            background: var(--alerta-fondo);
            color: var(--alerta);
            font-size: 14px;
            line-height: 1.5;
        }

        .alerta-alergia i {
            margin-top: 3px;
        }

        .alerta-alergia.precaucion {
            background: var(--precaucion-fondo);
            color: var(--precaucion);
        }

        .escribiendo {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--gris);
        }

        .sugerencias {
            display: flex;
            gap: 8px;
            padding: 12px 20px 0;
            overflow-x: auto;
            scrollbar-width: none;
        }

        .sugerencias::-webkit-scrollbar {
            display: none;
        }

        .sugerencias button {
            flex: none;
            height: 34px;
            padding: 0 14px;
            border: 1px solid var(--linea);
            border-radius: 999px;
            background: var(--hoja);
            color: var(--tinta);
            font: inherit;
            font-size: 13px;
            cursor: pointer;
            transition: border-color 150ms ease, color 150ms ease, transform 120ms cubic-bezier(.23, 1, .32, 1);
        }

        .sugerencias button:active {
            transform: scale(.97);
        }

        .chat-form {
            display: flex;
            gap: 10px;
            padding: 12px 20px 18px;
        }

        .chat-form input {
            flex: 1;
            height: 46px;
            margin-bottom: 0;
            font-size: 16px;
        }

        .chat-form .btn-primary {
            height: 46px;
            white-space: nowrap;
        }

        .btn-foto {
            flex: none;
            width: 46px;
            height: 46px;
            display: grid;
            place-items: center;
            border: 1px solid var(--linea);
            border-radius: var(--radio-control);
            background: var(--hoja);
            color: var(--pino);
            font-size: 17px;
            cursor: pointer;
            transition: border-color 150ms ease, background-color 150ms ease, transform 120ms cubic-bezier(.23, 1, .32, 1);
        }

        .btn-foto:active {
            transform: scale(.97);
        }

        .btn-foto:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        .btn-foto.resaltado {
            border-color: var(--verde);
            background: var(--menta);
            box-shadow: 0 0 0 3px rgba(0, 132, 61, .2);
        }

        /* Foto enviada por el médico */
        .msg.medico.foto {
            display: grid;
            gap: 6px;
            padding: 6px 6px 8px;
            font-size: 13px;
        }

        .msg.medico.foto img {
            display: block;
            width: 200px;
            max-width: 100%;
            border-radius: 10px;
        }

        .msg.medico.foto span {
            padding: 0 6px;
            color: rgba(255, 255, 255, .8);
        }

        /* Resultado del escaneo */
        .escaneo {
            padding: 12px 0;
            border-top: 1px solid var(--linea-suave);
        }

        .escaneo:first-of-type {
            border-top: 0;
            padding-top: 2px;
        }

        .escaneo-nombre {
            font-size: 16px;
        }

        .escaneo-nombre strong {
            font-weight: 600;
        }

        .escaneo-nombre span,
        .escaneo-principio {
            color: var(--gris);
            font-size: 14px;
        }

        .escaneo .alerta-alergia {
            margin: 10px 0 0;
        }

        .escaneo-cima,
        .escaneo-ok {
            margin-top: 8px;
            font-size: 14px;
        }

        .escaneo-cima i,
        .escaneo-ok i {
            color: var(--ok);
            margin-right: 4px;
        }

        .escaneo-cima.sin-cima i {
            color: var(--info);
        }

        .escaneo-cima a {
            color: var(--verde-oscuro);
            font-weight: 600;
            margin-left: 4px;
        }

        .escaneo-resumen {
            margin-top: 10px;
            padding: 12px 14px;
            border-radius: var(--radio-control);
            background: var(--papel);
            font-size: 14px;
        }

        .escaneo-acciones {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }

        .escaneo-acciones button {
            height: 34px;
            padding: 0 12px;
            border: 1px solid var(--linea);
            border-radius: 999px;
            background: var(--hoja);
            color: var(--tinta);
            font: inherit;
            font-size: 13px;
            cursor: pointer;
        }

        /* ---------- Columna lateral ---------- */

        .side-card {
            background: var(--hoja);
            border: 1px solid var(--linea);
            border-radius: var(--radio-hoja);
            padding: 20px;
        }

        .side-card + .side-card {
            margin-top: 20px;
        }

        .side-card h2 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 14px;
        }

        .pasos-asistente {
            list-style: none;
            counter-reset: paso;
        }

        .pasos-asistente li {
            position: relative;
            padding-left: 34px;
            font-size: 14px;
            line-height: 1.5;
            color: var(--gris);
        }

        .pasos-asistente li + li {
            margin-top: 12px;
        }

        .pasos-asistente li::before {
            counter-increment: paso;
            content: counter(paso);
            position: absolute;
            left: 0;
            top: 0;
            display: grid;
            place-items: center;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--menta);
            color: var(--verde-oscuro);
            font-size: 12px;
            font-weight: 600;
        }

        .pasos-asistente strong {
            color: var(--tinta);
            font-weight: 600;
        }

        .nota-apoyo {
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid var(--linea-suave);
            font-size: 13px;
            line-height: 1.5;
            color: var(--gris);
        }

        .historial {
            list-style: none;
        }

        .historial li {
            padding: 12px 0;
            border-top: 1px solid var(--linea-suave);
        }

        .historial li:first-child {
            border-top: 0;
            padding-top: 0;
        }

        .historial p {
            font-size: 14px;
            line-height: 1.45;
            color: var(--tinta);
        }

        .historial small {
            display: block;
            margin-top: 4px;
            font-size: 12px;
            line-height: 1.45;
            color: var(--gris);
        }

        .historial-vacio {
            font-size: 14px;
            color: var(--gris);
        }

        @media (hover: hover) and (pointer: fine) {
            .btn-foto:hover:not(:disabled) {
                border-color: var(--verde);
                background: var(--menta);
            }

            .escaneo-acciones button:hover {
                border-color: var(--verde);
                color: var(--verde-oscuro);
            }

            .sugerencias button:hover {
                border-color: var(--verde);
                color: var(--verde-oscuro);
            }
        }

        @media (max-width: 1100px) {
            .chat-layout {
                grid-template-columns: 1fr;
            }

            .chat-panel {
                height: 72vh;
                min-height: 480px;
            }
        }

        @media (max-width: 640px) {
            .chat-top select {
                min-width: 0;
                width: 100%;
            }

            .chat-top > div {
                width: 100%;
            }

            .msg {
                max-width: 92%;
            }

            .chat-form .btn-text {
                display: none;
            }

            .chat-form .btn-primary {
                width: 46px;
                padding: 0;
            }

            .chat-mensajes,
            .chat-top,
            .contexto-paciente,
            .chat-form {
                padding-left: 14px;
                padding-right: 14px;
            }

            .sugerencias {
                padding-left: 14px;
                padding-right: 14px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .msg { animation: none; }
            .btn-foto { transition: none; }
            .btn-foto:active { transform: none; }
            .sugerencias button { transition: none; }
            .sugerencias button:active { transform: none; }
        }
    </style>

    <?php include __DIR__ . "/pwa_head.php"; ?>
</head>

<body>

    <?php include("sidebar.php"); ?>

    <div class="content">

        <section class="page-header">
            <h1><i class="fas fa-robot"></i> Asistente de medicamentos</h1>
            <p>
                Pregunta por efectos secundarios, contraindicaciones, interacciones o dosis.
                Responde con la ficha técnica oficial de CIMA y la cruza con las alergias del paciente.
            </p>
        </section>

        <div class="chat-layout">

            <section class="chat-panel" aria-label="Conversación con el asistente">

                <div class="chat-top">
                    <div>
                        <label for="paciente">Paciente</label>
                        <select id="paciente">
                            <option value="">Consulta general, sin paciente</option>
                            <?php foreach ($pacientes as $p): ?>
                                <option
                                    value="<?= (int) $p['id'] ?>"
                                    data-alergias="<?= htmlspecialchars($p['alergias'] ?? '') ?>"
                                    data-nombre="<?= htmlspecialchars($p['nombre']) ?>"
                                    <?= $pacienteInicial === (int) $p['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['nombre']) ?><?= !empty($p['alergias']) ? ' (con alergias)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <span class="estado-ia <?= $iaActiva ? 'on' : 'off' ?>">
                        <i class="fas <?= $iaActiva ? 'fa-wand-magic-sparkles' : 'fa-book-medical' ?>" aria-hidden="true"></i>
                        <?= $iaActiva ? 'Resúmenes con Gemini' : 'Solo ficha técnica' ?>
                    </span>
                </div>

                <p id="aviso-alergias" class="contexto-paciente" role="status" hidden></p>

                <div class="chat-mensajes" id="mensajes" aria-live="polite">
                    <div class="msg bot">
                        <p>Escribe el nombre del medicamento y lo que necesitas saber.</p>
                        <p>Por ejemplo: <strong>efectos secundarios del ibuprofeno</strong> o <strong>¿puede tomar amoxicilina un paciente alérgico a la penicilina?</strong></p>
                        <?php if ($iaActiva): ?>
                            <p>También puedes tomarle foto a la caja o al frasco con el botón <i class="fas fa-camera" aria-label="cámara"></i> y te digo qué medicamento es y si el paciente es alérgico.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="sugerencias" aria-label="Preguntas de ejemplo">
                    <button type="button" data-pregunta="Efectos secundarios del ibuprofeno">Efectos del ibuprofeno</button>
                    <button type="button" data-pregunta="Contraindicaciones de la amoxicilina">Contraindicaciones de amoxicilina</button>
                    <button type="button" data-pregunta="Interacciones del omeprazol">Interacciones del omeprazol</button>
                    <button type="button" data-pregunta="¿Se puede usar paracetamol en el embarazo?">Paracetamol en el embarazo</button>
                    <button type="button" data-pregunta="Dosis de metformina">Dosis de metformina</button>
                </div>

                <form class="chat-form" id="formChat" autocomplete="off">
                    <?php if ($iaActiva): ?>
                        <button type="button" class="btn-foto" id="btnFoto" aria-label="Escanear un medicamento con la cámara" title="Escanear medicamento">
                            <i class="fas fa-camera" aria-hidden="true"></i>
                        </button>
                        <input type="file" id="entradaFoto" accept="image/*" capture="environment" hidden>
                    <?php endif; ?>
                    <label for="pregunta" class="sr-only">Pregunta sobre un medicamento</label>
                    <input
                        type="text"
                        id="pregunta"
                        maxlength="300"
                        placeholder="Escribe tu pregunta sobre un medicamento"
                        required>
                    <button type="submit" class="btn-primary" id="btnEnviar" aria-label="Enviar pregunta">
                        <i class="fas fa-paper-plane" aria-hidden="true"></i>
                        <span class="btn-text">Enviar</span>
                    </button>
                </form>
            </section>

            <aside>
                <div class="side-card">
                    <h2>Cómo responde</h2>
                    <ol class="pasos-asistente">
                        <li>Busca el medicamento en <strong>CIMA</strong>, la base oficial de la Agencia Española de Medicamentos.</li>
                        <li>Lee la sección de la ficha técnica que corresponde a tu pregunta.</li>
                        <li><?= $iaActiva ? 'Gemini resume ese texto en lenguaje claro, sin agregar datos.' : 'Muestra el texto oficial. Con Gemini activo también lo resume.' ?></li>
                        <li>Si elegiste un paciente, compara el medicamento con sus <strong>alergias</strong> y te avisa.</li>
                    </ol>
                    <p class="nota-apoyo">
                        Es una herramienta de apoyo: no sustituye el criterio clínico ni la ficha técnica completa.
                    </p>
                </div>

                <div class="side-card">
                    <h2>Tus últimas consultas</h2>
                    <p class="historial-vacio" id="historialVacio" <?= empty($historial) ? '' : 'hidden' ?>>Aquí aparecerán tus preguntas recientes.</p>
                    <ul class="historial" id="historial">
                        <?php foreach ($historial as $h): ?>
                            <?php
                            $medicamentoHist = $h['medicamento']
                                ? mb_strtoupper(mb_substr($h['medicamento'], 0, 1)) . mb_strtolower(mb_substr($h['medicamento'], 1))
                                : 'Medicamento sin identificar';
                            ?>
                            <li>
                                <p><?= htmlspecialchars($h['pregunta']) ?></p>
                                <small>
                                    <?= htmlspecialchars($medicamentoHist) ?><br>
                                    <?= htmlspecialchars(date('d/m/Y H:i', strtotime($h['creado_en']))) ?><?= $h['paciente'] ? ', ' . htmlspecialchars($h['paciente']) : '' ?>
                                </small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </aside>
        </div>
    </div>

    <script src="assets/js/chatbot.js?v=3"></script>
</body>

</html>
