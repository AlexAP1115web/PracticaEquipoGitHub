<?php
include("config.php");
include("security.php");

verificarSesion();

/* ======================================================
   OBTENER CITAS PROGRAMADAS
====================================================== */

$stmt = $conexion->prepare("
    SELECT
        u.id,
        u.nombre,
        u.email,
        e.fecha_cita,
        e.hora_cita,
        e.dieta_autorizada,
        e.video_link
    FROM usuarios u
    INNER JOIN expedientes e
        ON u.id = e.usuario_id
    WHERE e.fecha_cita >= CURDATE()
    ORDER BY e.fecha_cita ASC, e.hora_cita ASC
");

$stmt->execute();

$result = $stmt->get_result();

$citas = $result->fetch_all(MYSQLI_ASSOC);

$stmt->close();

$total_citas = count($citas);

/* ======================================================
   MÉTRICAS
====================================================== */

$hoy = date("Y-m-d");

$citas_hoy = 0;
$citas_pendientes = 0;
$citas_autorizadas = 0;

foreach ($citas as $cita) {

    if ($cita['fecha_cita'] == $hoy) {
        $citas_hoy++;
    }

    if (($cita['dieta_autorizada'] ?? 'pendiente') === 'pendiente') {
        $citas_pendientes++;
    }

    if (($cita['dieta_autorizada'] ?? '') === 'autorizada') {
        $citas_autorizadas++;
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>
        Agenda Médica | MediCore
    </title>

    <link
        rel="stylesheet"
        href="assets/style.css?v=<?= time() ?>">

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

</head>

<body>

    <?php include("sidebar.php"); ?>

    <div class="content">

        <section class="agenda-header">

            <h1>
                <i class="fas fa-calendar-check"></i>
                Agenda Clínica Profesional
            </h1>

            <p>
                Administra consultas médicas, seguimientos nutricionales y citas
                programadas dentro del ecosistema profesional MediCore.
            </p>

            <div class="agenda-actions">

                <a
                    href="pacientes.php"
                    class="btn-primary">

                    <i class="fas fa-users"></i>
                    Pacientes

                </a>

                <a
                    href="index.php"
                    class="btn-success">

                    <i class="fas fa-chart-line"></i>
                    Dashboard

                </a>

            </div>

        </section>

        <section class="stats-grid">

            <article class="stat-box">

                <div class="stat-top">

                    <div>

                        <div class="stat-number">
                            <?= $total_citas ?>
                        </div>

                        <div class="stat-label">
                            Citas Programadas
                        </div>

                    </div>

                    <div class="stat-icon icon-primary">
                        <i class="fas fa-calendar-day"></i>
                    </div>

                </div>

            </article>

            <article class="stat-box">

                <div class="stat-top">

                    <div>

                        <div class="stat-number">
                            <?= $citas_hoy ?>
                        </div>

                        <div class="stat-label">
                            Consultas Hoy
                        </div>

                    </div>

                    <div class="stat-icon icon-success">
                        <i class="fas fa-user-doctor"></i>
                    </div>

                </div>

            </article>

            <article class="stat-box">

                <div class="stat-top">

                    <div>

                        <div class="stat-number">
                            <?= $citas_pendientes ?>
                        </div>

                        <div class="stat-label">
                            Pendientes
                        </div>

                    </div>

                    <div class="stat-icon icon-warning">
                        <i class="fas fa-clock"></i>
                    </div>

                </div>

            </article>

            <article class="stat-box">

                <div class="stat-top">

                    <div>

                        <div class="stat-number">
                            <?= $citas_autorizadas ?>
                        </div>

                        <div class="stat-label">
                            Autorizadas
                        </div>

                    </div>

                    <div class="stat-icon icon-success">
                        <i class="fas fa-circle-check"></i>
                    </div>

                </div>

            </article>

        </section>

        <section class="card">

            <div class="toolbar">

                <div>

                    <h2>
                        Próximas Consultas
                    </h2>

                    <p>
                        Seguimiento médico y control de citas activas.
                    </p>

                </div>

                <div class="search-box">

                    <i class="fas fa-search"></i>

                    <input
                        type="text"
                        id="buscador"
                        placeholder="Buscar paciente..."
                        onkeyup="filtrarTabla()">

                </div>

            </div>

            <?php if ($total_citas > 0): ?>

                <div class="agenda-table">

                    <table id="tablaAgenda">

                        <thead>

                            <tr>
                                <th>Fecha</th>
                                <th>Hora</th>
                                <th>Paciente</th>
                                <th>Estado</th>
                                <th>Consulta</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($citas as $cita): ?>

                                <?php
                                $estado = $cita['dieta_autorizada'] ?? 'pendiente';
                                ?>

                                <tr class="fila-cita">

                                    <td>

                                        <div class="fecha-box">
                                            <?= date("d / m / Y", strtotime($cita['fecha_cita'])) ?>
                                        </div>

                                    </td>

                                    <td>

                                        <div class="hora-box">

                                            <i class="fas fa-clock"></i>

                                            <?= !empty($cita['hora_cita'])
                                                ? date("H:i", strtotime($cita['hora_cita']))
                                                : "Sin horario" ?>

                                        </div>

                                    </td>

                                    <td>

                                        <div class="paciente-info">

                                            <strong class="nombre-paciente">
                                                <?= htmlspecialchars($cita['nombre']) ?>
                                            </strong>

                                            <span>
                                                <?= htmlspecialchars($cita['email']) ?>
                                            </span>

                                        </div>

                                    </td>

                                    <td>

                                        <span class="badge <?= htmlspecialchars($estado) ?>">
                                            <?= ucfirst(htmlspecialchars($estado)) ?>
                                        </span>

                                    </td>

                                    <td>

                                        <a
                                            href="expediente.php?id=<?= intval($cita['id']) ?>"
                                            class="btn-consulta">

                                            <i class="fas fa-stethoscope"></i>
                                            Abrir Consulta

                                        </a>

                                        <?php if (!empty($cita['video_link'])): ?>
                                            <a
                                                href="<?= htmlspecialchars($cita['video_link']) ?>"
                                                target="_blank"
                                                rel="noopener"
                                                class="btn-consulta"
                                                style="margin-top:6px;">

                                                <i class="fas fa-video"></i>
                                                Videollamada

                                            </a>
                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="empty-state">

                    <i class="fas fa-calendar-xmark"></i>

                    <h3>
                        No hay citas programadas
                    </h3>

                    <p>
                        El sistema no detectó consultas próximas registradas en la agenda médica de MediCore.
                    </p>

                </div>

            <?php endif; ?>

        </section>

    </div>

    <script>
        function filtrarTabla() {

            const input = document.getElementById("buscador");

            if (!input) return;

            const filtro = input.value.toLowerCase();

            const filas = document.querySelectorAll(".fila-cita");

            filas.forEach(fila => {

                const nombre = fila
                    .querySelector(".nombre-paciente")
                    .textContent
                    .toLowerCase();

                fila.style.display = nombre.includes(filtro) ? "" : "none";
            });
        }
    </script>

</body>

</html>