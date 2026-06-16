<?php
include("config.php");
include("security.php");

verificarSesion();

/* ======================================================
   OBTENER ID DEL MÉDICO
====================================================== */

$id_medico =
    $_SESSION['medico_id']
    ?? $_SESSION['medico']
    ?? $_SESSION['id']
    ?? 1;

/* ======================================================
   OBTENER DATOS DEL MÉDICO
====================================================== */

$stmt_medico = $conexion->prepare("
    SELECT nombre
    FROM medicos
    WHERE id = ?
    OR correo = ?
");

$id_str = (string)$id_medico;

$stmt_medico->bind_param("ss", $id_str, $id_str);
$stmt_medico->execute();

$resultado = $stmt_medico->get_result();

$nombre_medico =
    ($resultado->num_rows > 0)
    ? $resultado->fetch_assoc()['nombre']
    : 'Doctor';

$stmt_medico->close();

/* ======================================================
   ESTADÍSTICAS
====================================================== */

$query_pacientes = $conexion->query("
    SELECT COUNT(*) as total
    FROM usuarios
");

$total_pacientes =
    $query_pacientes
    ? $query_pacientes->fetch_assoc()['total']
    : 0;

$query_expedientes = $conexion->query("
    SELECT COUNT(*) as total
    FROM expedientes
");

$total_expedientes =
    $query_expedientes
    ? $query_expedientes->fetch_assoc()['total']
    : 0;

$query_recientes = $conexion->query("
    SELECT
        id,
        nombre,
        email
    FROM usuarios
    ORDER BY id DESC
    LIMIT 5
");
?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>
        Dashboard Médico | MediCore
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

        <section class="dashboard-header">

            <div>

                <h1>
                    <i class="fas fa-user-doctor"></i>
                    Bienvenido Doctor,
                    <?= htmlspecialchars($nombre_medico) ?>
                </h1>

                <p>
                    Panel principal de MediCore Professional System.
                    Administra pacientes, expedientes clínicos, historial médico
                    y monitorea la actividad del sistema de salud en tiempo real.
                </p>

            </div>

        </section>

        <section class="stats-grid">

            <article class="stat-card">

                <div>

                    <h2>
                        <?= $total_pacientes ?>
                    </h2>

                    <p>
                        Pacientes Registrados
                    </p>

                </div>

                <div class="stat-icon icon-primary">

                    <i class="fas fa-users"></i>

                </div>

            </article>

            <article class="stat-card">

                <div>

                    <h2>
                        <?= $total_expedientes ?>
                    </h2>

                    <p>
                        Expedientes Clínicos
                    </p>

                </div>

                <div class="stat-icon icon-success">

                    <i class="fas fa-file-medical"></i>

                </div>

            </article>

            <article class="stat-card">

                <div>

                    <h2>
                        Activo
                    </h2>

                    <p>
                        Estado del Sistema
                    </p>

                </div>

                <div class="stat-icon icon-warning">

                    <i class="fas fa-heart-pulse"></i>

                </div>

            </article>

        </section>

        <section class="table-card">

            <div class="table-header">

                <h3>
                    <i class="fas fa-clock"></i>
                    Pacientes Recientes
                </h3>

            </div>

            <div class="table-container">

                <?php if (
                    isset($query_recientes)
                    && $query_recientes->num_rows > 0
                ): ?>

                    <table>

                        <thead>

                            <tr>
                                <th>ID</th>
                                <th>Paciente</th>
                                <th>Correo</th>
                                <th>Estado</th>
                                <th>Acción</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php while ($paciente = $query_recientes->fetch_assoc()): ?>

                                <tr>

                                    <td class="patient-id">
                                        #<?= htmlspecialchars($paciente['id']) ?>
                                    </td>

                                    <td class="patient-name">
                                        <?= htmlspecialchars($paciente['nombre']) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($paciente['email']) ?>
                                    </td>

                                    <td>
                                        <span class="badge success">
                                            Activo
                                        </span>
                                    </td>

                                    <td>
                                        <a
                                            href="pacientes.php"
                                            class="btn-view">

                                            <i class="fas fa-eye"></i>
                                            Ver Perfil

                                        </a>
                                    </td>

                                </tr>

                            <?php endwhile; ?>

                        </tbody>

                    </table>

                <?php else: ?>

                    <div class="empty-state">

                        <i class="fas fa-folder-open"></i>

                        <h3>
                            Sin pacientes registrados
                        </h3>

                        <p>
                            No existen pacientes registrados todavía.
                        </p>

                    </div>

                <?php endif; ?>

            </div>

        </section>

    </div>

</body>

</html>