<?php
include("config.php");
include("security.php");
require_once __DIR__ . "/integrations/OpenWeather.php";

verificarSesion();

/* ======================================================
   LISTA DE PACIENTES (para elegir a quién generar
   recomendaciones)
====================================================== */

$stmt = $conexion->prepare("
    SELECT
        u.id,
        u.nombre,
        e.imc,
        e.tipo_dieta,
        e.diagnostico,
        e.dieta_autorizada,
        en.nombre AS enfermedad_nombre,
        en.recomendaciones AS enfermedad_recomendaciones
    FROM usuarios u
    LEFT JOIN expedientes e ON u.id = e.usuario_id
    LEFT JOIN enfermedades en ON e.enfermedad_id = en.id
    WHERE u.rol = 'paciente'
    ORDER BY u.nombre ASC
");
$stmt->execute();
$pacientes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pacienteSeleccionado = null;
$idSeleccionado = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($idSeleccionado > 0) {
    foreach ($pacientes as $p) {
        if ((int)$p['id'] === $idSeleccionado) {
            $pacienteSeleccionado = $p;
            break;
        }
    }
}

/* ======================================================
   REGLAS DE RECOMENDACIÓN SEGÚN IMC
====================================================== */

function recomendacionPorIMC($imc)
{
    if (empty($imc) || $imc <= 0) {
        return ["Aún no hay datos biométricos suficientes (peso/estatura) para generar una recomendación de IMC.", "info"];
    }

    if ($imc < 18.5) {
        return ["Bajo peso: se recomienda evaluación nutricional para incrementar el aporte calórico de forma controlada.", "warning"];
    } elseif ($imc < 25) {
        return ["Peso saludable: mantener hábitos actuales de alimentación y actividad física.", "success"];
    } elseif ($imc < 30) {
        return ["Sobrepeso: se recomienda plan de actividad física moderada y ajuste en la dieta.", "warning"];
    } else {
        return ["Obesidad: se recomienda valoración integral (nutrición, actividad física y, de ser necesario, interconsulta especializada).", "danger"];
    }
}

$clima = obtenerClimaActual();
$recomendacionesClima = $clima ? recomendacionPorClima($clima) : [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recomendaciones | MediCore</title>
    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <?php include("sidebar.php"); ?>

    <div class="content">

        <section class="page-header">
            <h1><i class="fas fa-notes-medical"></i> Recomendaciones Clínicas</h1>
            <p>Recomendaciones automáticas basadas en el IMC del paciente, su diagnóstico y el clima actual de la clínica.</p>
        </section>

        <?php if ($clima): ?>
            <section class="card">
                <h2><i class="fas fa-cloud-sun"></i> Clima actual en <?= htmlspecialchars($clima['ciudad']) ?></h2>
                <p style="margin:10px 0;">
                    <strong><?= htmlspecialchars($clima['temperatura']) ?>°C</strong>
                    (sensación <?= htmlspecialchars($clima['sensacion']) ?>°C) —
                    <?= htmlspecialchars($clima['descripcion']) ?>, humedad <?= htmlspecialchars($clima['humedad']) ?>%
                </p>
                <?php foreach ($recomendacionesClima as $tip): ?>
                    <div class="alerta info" style="margin-top:10px;"><i class="fas fa-lightbulb"></i> <?= htmlspecialchars($tip) ?></div>
                <?php endforeach; ?>
            </section>
        <?php else: ?>
            <section class="card">
                <h2><i class="fas fa-cloud"></i> Clima actual</h2>
                <p>El módulo de clima no está activo todavía. Configura <code>OPENWEATHER_API_KEY</code> en tu archivo <code>.env</code> para habilitarlo (cuenta gratuita en openweathermap.org).</p>
            </section>
        <?php endif; ?>

        <section class="card">
            <h2><i class="fas fa-user-injured"></i> Selecciona un paciente</h2>
            <form method="GET" class="toolbar">
                <select name="id" onchange="this.form.submit()" style="padding:12px;border-radius:10px;border:2px solid var(--border);min-width:280px;">
                    <option value="">-- Selecciona un paciente --</option>
                    <?php foreach ($pacientes as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= $idSeleccionado === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </section>

        <?php if ($pacienteSeleccionado): ?>

            <?php [$textoImc, $tipoImc] = recomendacionPorIMC($pacienteSeleccionado['imc']); ?>

            <section class="card">
                <h2><i class="fas fa-heart-pulse"></i> Recomendación para <?= htmlspecialchars($pacienteSeleccionado['nombre']) ?></h2>

                <div class="alerta <?= $tipoImc ?>" style="margin-bottom:14px;">
                    <i class="fas fa-weight-scale"></i>
                    IMC actual: <?= $pacienteSeleccionado['imc'] ? number_format($pacienteSeleccionado['imc'], 2) : '--' ?>
                    — <?= htmlspecialchars($textoImc) ?>
                </div>

                <?php if (!empty($pacienteSeleccionado['enfermedad_nombre'])): ?>
                    <div class="alerta warning">
                        <i class="fas fa-book-medical"></i>
                        Enfermedad registrada: <strong><?= htmlspecialchars($pacienteSeleccionado['enfermedad_nombre']) ?></strong><br>
                        <?= htmlspecialchars($pacienteSeleccionado['enfermedad_recomendaciones'] ?? '') ?>
                    </div>
                <?php else: ?>
                    <div class="alerta info">
                        <i class="fas fa-circle-info"></i>
                        Este paciente no tiene un tipo de enfermedad vinculado del catálogo. Puedes asignarlo desde
                        <a href="expediente.php?id=<?= (int)$pacienteSeleccionado['id'] ?>">su expediente clínico</a>.
                    </div>
                <?php endif; ?>

                <a href="expediente.php?id=<?= (int)$pacienteSeleccionado['id'] ?>" class="btn-view" style="margin-top:10px;display:inline-block;">
                    <i class="fas fa-file-medical"></i> Abrir expediente completo
                </a>
            </section>

        <?php endif; ?>

    </div>

</body>
</html>
