<?php
include("config.php");
include("security.php");

verificarSesion();

$id_medico = $_SESSION['medico_id'] ?? $_SESSION['medico'] ?? $_SESSION['id'] ?? 0;
$nombreMedico = $_SESSION['nombre_medico'] ?? "Especialista";

if ($id_medico > 0) {
    $stmtMedico = $conexion->prepare("SELECT nombre FROM medicos WHERE id = ? LIMIT 1");
    $stmtMedico->bind_param("i", $id_medico);
    $stmtMedico->execute();
    $medicoData = $stmtMedico->get_result()->fetch_assoc();
    $nombreMedico = $medicoData['nombre'] ?? $nombreMedico;
    $stmtMedico->close();
}

$total_pacientes = contar('usuarios');
$autorizadas = contarPorEstado('autorizada');
$pendientes = contarPorEstado('pendiente');
$denegadas = contarPorEstado('denegada');

$total_citas_hoy = 0;
$stmtHoy = $conexion->prepare("SELECT COUNT(*) AS total FROM expedientes WHERE fecha_cita = CURDATE()");
$stmtHoy->execute();
$resHoy = $stmtHoy->get_result()->fetch_assoc();
$total_citas_hoy = $resHoy['total'] ?? 0;
$stmtHoy->close();

$busqueda = "";
$pacientes = [];

if (!empty($_GET['buscar'])) {
    $busqueda = limpiar($_GET['buscar']);
    $like = "%" . $busqueda . "%";

    $stmt = $conexion->prepare("
        SELECT u.id, u.nombre, u.email, u.telefono, e.imc, e.dieta_autorizada, e.fecha_cita
        FROM usuarios u
        LEFT JOIN expedientes e ON u.id = e.usuario_id
        WHERE (u.nombre LIKE ? OR u.email LIKE ? OR u.telefono LIKE ?)
        AND u.rol = 'paciente'
        ORDER BY u.nombre ASC
    ");

    $stmt->bind_param("sss", $like, $like, $like);
} else {
    $stmt = $conexion->prepare("
        SELECT u.id, u.nombre, u.email, u.telefono, e.imc, e.dieta_autorizada, e.fecha_cita
        FROM usuarios u
        LEFT JOIN expedientes e ON u.id = e.usuario_id
        WHERE u.rol = 'paciente'
        ORDER BY u.nombre ASC
    ");
}

$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $row['dieta_autorizada'] = $row['dieta_autorizada'] ?? 'pendiente';
    $pacientes[] = $row;
}

$stmt->close();
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MediCore | Dashboard Médico</title>

    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>

    <?php include("sidebar.php"); ?>

    <div class="content">

        <section class="dashboard-header">
            <div>
                <h1>
                    <i class="fas fa-user-doctor"></i>
                    Bienvenido, <?= htmlspecialchars($nombreMedico) ?>
                </h1>

                <p>
                    Panel central de administración clínica MediCore. Gestiona pacientes,
                    expedientes médicos, citas programadas y seguimientos nutricionales.
                </p>
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
                    <h2><?= $autorizadas ?></h2>
                    <p>Tratamientos Activos</p>
                </div>
                <div class="stat-icon icon-success">
                    <i class="fas fa-check-circle"></i>
                </div>
            </article>

            <article class="stat-card">
                <div>
                    <h2><?= $pendientes ?></h2>
                    <p>Casos Pendientes</p>
                </div>
                <div class="stat-icon icon-warning">
                    <i class="fas fa-clock"></i>
                </div>
            </article>

            <article class="stat-card">
                <div>
                    <h2><?= $total_citas_hoy ?></h2>
                    <p>Citas Hoy</p>
                </div>
                <div class="stat-icon red">
                    <i class="fas fa-calendar-check"></i>
                </div>
            </article>

        </section>

        <section class="card">

            <div class="toolbar">
                <div>
                    <h2>Buscar Expediente Clínico</h2>
                    <p>Localiza pacientes por nombre, correo o teléfono.</p>
                </div>
            </div>

            <form method="GET" class="toolbar">

                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input
                        type="text"
                        name="buscar"
                        placeholder="Buscar paciente..."
                        value="<?= htmlspecialchars($busqueda) ?>">
                </div>

                <button type="submit" class="btn-primary">
                    <i class="fas fa-search"></i>
                    Buscar
                </button>

                <?php if (!empty($busqueda)): ?>
                    <a href="index.php" class="btn-refresh">
                        <i class="fas fa-times"></i>
                        Limpiar
                    </a>
                <?php endif; ?>

                <a href="nuevo_paciente.php" class="btn-success">
                    <i class="fas fa-user-plus"></i>
                    Nuevo Paciente
                </a>

            </form>

        </section>

        <section class="table-card">

            <div class="table-header">
                <h3>
                    <i class="fas fa-hospital-user"></i>
                    Pacientes Registrados
                </h3>
            </div>

            <div class="table-container">

                <?php if (count($pacientes) > 0): ?>

                    <table>
                        <thead>
                            <tr>
                                <th>Paciente</th>
                                <th>Correo</th>
                                <th>Teléfono</th>
                                <th>Estado</th>
                                <th>IMC</th>
                                <th>Próxima Cita</th>
                                <th>Acción</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($pacientes as $row): ?>
                                <tr>
                                    <td class="patient-name">
                                        <?= htmlspecialchars($row['nombre']) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($row['email']) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($row['telefono'] ?? 'No registrado') ?>
                                    </td>

                                    <td>
                                        <span class="badge <?= htmlspecialchars($row['dieta_autorizada']) ?>">
                                            <?= ucfirst(htmlspecialchars($row['dieta_autorizada'])) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= $row['imc'] ? number_format($row['imc'], 2) : '--' ?>
                                    </td>

                                    <td>
                                        <?= $row['fecha_cita']
                                            ? date("d/m/Y", strtotime($row['fecha_cita']))
                                            : "Sin cita" ?>
                                    </td>

                                    <td>
                                        <a href="expediente.php?id=<?= intval($row['id']) ?>" class="btn-view">
                                            <i class="fas fa-file-medical"></i>
                                            Abrir
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                <?php else: ?>

                    <div class="empty-state">
                        <i class="fas fa-user-slash"></i>
                        <h3>No se encontraron pacientes</h3>
                        <p>No existen registros relacionados con tu búsqueda.</p>
                    </div>

                <?php endif; ?>

            </div>

        </section>

    </div>

</body>

</html>