<?php
include("config.php");
include("security.php");

verificarSesion();

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
                        plan_entrenamiento = ?
                    WHERE usuario_id = ?
                ");

                $update->bind_param(
                    "dddssssssssi",
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
                        plan_entrenamiento
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $insert->bind_param(
                    "idddssssssss",
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
                    $plan_entrenamiento
                );

                $insert->execute();
                $insert->close();

                $mensaje_exito = "Expediente creado correctamente.";

                registrarLog("Nuevo expediente creado ID paciente: $paciente_id", "INFO");
            }

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

                    <article class="exp-item">
                        <span>Dirección</span>
                        <strong>
                            <?= htmlspecialchars($paciente['direccion'] ?? 'N/D') ?>
                        </strong>
                    </article>

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

                    <div class="form-actions" data-html2canvas-ignore="true">

                        <button type="submit" class="btn-primary">
                            <i class="fas fa-save"></i>
                            Guardar Expediente
                        </button>

                    </div>

                </form>

            </section>

        </main>

    </div>

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

</body>

</html>