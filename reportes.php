<?php
include("config.php");
include("security.php");

verificarSesion();

/* ======================================================
   MÉTRICAS GLOBALES
====================================================== */

$pendientes  = intval(contarPorEstado('pendiente'));
$autorizadas = intval(contarPorEstado('autorizada'));
$denegadas   = intval(contarPorEstado('denegada'));
$total_planes = $pendientes + $autorizadas + $denegadas;

/* ======================================================
   MÉTRICAS IMC
====================================================== */

$imc_normal = 0;
$imc_sobrepeso = 0;
$imc_obesidad = 0;

$stmt = $conexion->prepare("SELECT imc FROM expedientes WHERE imc > 0");
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $imc = floatval($row['imc']);

    if ($imc < 25) {
        $imc_normal++;
    } elseif ($imc >= 25 && $imc < 30) {
        $imc_sobrepeso++;
    } else {
        $imc_obesidad++;
    }
}

$stmt->close();

/* ======================================================
   TOTALES
====================================================== */

$total_pacientes = 0;
$queryPacientes = $conexion->query("
    SELECT COUNT(*) AS total
    FROM usuarios
    WHERE rol = 'paciente'
");

if ($queryPacientes && $rowPacientes = $queryPacientes->fetch_assoc()) {
    $total_pacientes = intval($rowPacientes['total']);
}

$total_expedientes = 0;
$queryExpedientes = $conexion->query("
    SELECT COUNT(*) AS total
    FROM expedientes
");

if ($queryExpedientes && $rowExp = $queryExpedientes->fetch_assoc()) {
    $total_expedientes = intval($rowExp['total']);
}

$promedio_imc = "0.0";
$queryPromedio = $conexion->query("
    SELECT AVG(imc) AS promedio
    FROM expedientes
    WHERE imc > 0
");

if ($queryPromedio && $rowPromedio = $queryPromedio->fetch_assoc()) {
    $promedio_imc = !is_null($rowPromedio['promedio'])
        ? number_format($rowPromedio['promedio'], 1)
        : "0.0";
}

/* ======================================================
   ÚLTIMOS PACIENTES
====================================================== */

$ultimos = [];

$stmtUltimos = $conexion->prepare("
    SELECT nombre, email, fecha_registro
    FROM usuarios
    WHERE rol = 'paciente'
    ORDER BY fecha_registro DESC
    LIMIT 5
");

$stmtUltimos->execute();
$resUltimos = $stmtUltimos->get_result();

while ($row = $resUltimos->fetch_assoc()) {
    $ultimos[] = $row;
}

$stmtUltimos->close();
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes | MediCore</title>

    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</head>

<body>

    <?php include("sidebar.php"); ?>

    <div class="content">

        <section class="report-header">
            <h1>
                <i class="fas fa-chart-line"></i>
                Centro de Analítica Clínica
            </h1>

            <p>
                Panel ejecutivo para monitoreo institucional, métricas nutricionales,
                distribución epidemiológica y control estadístico de pacientes.
            </p>

            <div class="header-actions">
                <button type="button" onclick="generarPDF()" class="btn-action btn-pdf">
                    <i class="fas fa-file-pdf"></i>
                    Exportar PDF
                </button>

                <button type="button" onclick="location.reload()" class="btn-action btn-refresh">
                    <i class="fas fa-rotate"></i>
                    Actualizar Datos
                </button>
            </div>
        </section>

        <section class="stats-grid">

            <article class="stat-card">
                <div>
                    <h2><?= $total_pacientes ?></h2>
                    <p>Pacientes Registrados</p>
                </div>

                <div class="stat-icon icon-primary">
                    <i class="fas fa-users"></i>
                </div>
            </article>

            <article class="stat-card">
                <div>
                    <h2><?= $total_expedientes ?></h2>
                    <p>Expedientes Clínicos</p>
                </div>

                <div class="stat-icon icon-success">
                    <i class="fas fa-folder-open"></i>
                </div>
            </article>

            <article class="stat-card">
                <div>
                    <h2><?= $promedio_imc ?></h2>
                    <p>Promedio IMC</p>
                </div>

                <div class="stat-icon icon-warning">
                    <i class="fas fa-heart-pulse"></i>
                </div>
            </article>

            <article class="stat-card">
                <div>
                    <h2><?= $total_planes ?></h2>
                    <p>Planes Nutricionales</p>
                </div>

                <div class="stat-icon red">
                    <i class="fas fa-chart-pie"></i>
                </div>
            </article>

        </section>

        <main id="pdf-zone">

            <section class="charts-grid">

                <article class="chart-card">
                    <h3>Estado de Planes Dietéticos</h3>

                    <div class="chart-area">
                        <canvas id="graficaDietas"></canvas>
                    </div>
                </article>

                <article class="chart-card">
                    <h3>Distribución por IMC</h3>

                    <div class="chart-area">
                        <canvas id="graficaIMC"></canvas>
                    </div>
                </article>

            </section>

            <section class="table-card">

                <div class="table-header">
                    <h3>
                        <i class="fas fa-clock"></i>
                        Últimos Pacientes Registrados
                    </h3>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Paciente</th>
                                <th>Correo</th>
                                <th>Fecha Registro</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (count($ultimos) > 0): ?>
                                <?php foreach ($ultimos as $u): ?>
                                    <tr>
                                        <td class="patient-name">
                                            <?= htmlspecialchars($u['nombre']) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($u['email']) ?>
                                        </td>

                                        <td>
                                            <?= !empty($u['fecha_registro'])
                                                ? date("d/m/Y H:i", strtotime($u['fecha_registro']))
                                                : "Sin fecha" ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3">
                                        No hay pacientes registrados recientemente.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </section>

        </main>

    </div>

    <script>
        const ctxDietas = document.getElementById('graficaDietas');

        new Chart(ctxDietas, {
            type: 'doughnut',
            data: {
                labels: ['Pendientes', 'Autorizadas', 'Denegadas'],
                datasets: [{
                    data: [
                        <?= $pendientes ?>,
                        <?= $autorizadas ?>,
                        <?= $denegadas ?>
                    ],
                    backgroundColor: [
                        '#d97706',
                        '#059669',
                        '#dc2626'
                    ],
                    borderWidth: 0,
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: {
                                family: 'Inter',
                                size: 12,
                                weight: '600'
                            }
                        }
                    }
                }
            }
        });

        const ctxIMC = document.getElementById('graficaIMC');

        new Chart(ctxIMC, {
            type: 'bar',
            data: {
                labels: ['Normal', 'Sobrepeso', 'Obesidad'],
                datasets: [{
                    label: 'Pacientes',
                    data: [
                        <?= $imc_normal ?>,
                        <?= $imc_sobrepeso ?>,
                        <?= $imc_obesidad ?>
                    ],
                    backgroundColor: [
                        '#00843d',
                        '#d97706',
                        '#dc2626'
                    ],
                    borderRadius: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });

        function generarPDF() {
            const element = document.getElementById('pdf-zone');

            const options = {
                margin: 0.4,
                filename: 'Reporte_MediCore_' + new Date().toISOString().slice(0, 10) + '.pdf',
                image: {
                    type: 'jpeg',
                    quality: 1
                },
                html2canvas: {
                    scale: 2,
                    useCORS: true,
                    scrollX: 0,
                    scrollY: 0
                },
                jsPDF: {
                    unit: 'in',
                    format: 'letter',
                    orientation: 'landscape'
                }
            };

            html2pdf().set(options).from(element).save();
        }
    </script>

</body>

</html>