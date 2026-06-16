<?php
include("config.php");
include("security.php");

verificarSesion();

/* ======================================================
   OBTENER PACIENTES
   Nota: no se usa u.sexo porque tu tabla usuarios no tiene esa columna.
====================================================== */

$stmt = $conexion->prepare("
    SELECT
        u.id,
        u.nombre,
        u.email,
        u.telefono,
        u.edad,
        u.fecha_registro,
        e.imc,
        e.fecha_cita,
        e.dieta_autorizada
    FROM usuarios u
    LEFT JOIN expedientes e ON u.id = e.usuario_id
    WHERE u.rol = 'paciente'
    ORDER BY u.nombre ASC
");

$stmt->execute();
$result = $stmt->get_result();
$pacientes = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total_pacientes = count($pacientes);
$con_cita = 0;
$sin_cita = 0;
$activos = 0;

foreach ($pacientes as $p) {
    if (!empty($p['fecha_cita'])) {
        $con_cita++;
    } else {
        $sin_cita++;
    }

    if (($p['dieta_autorizada'] ?? '') === 'autorizada') {
        $activos++;
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pacientes | MediCore</title>

    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>

    <?php include("sidebar.php"); ?>

    <div class="content">

        <section class="page-header">
            <h1>
                <i class="fas fa-hospital-user"></i>
                Administración de Pacientes
            </h1>

            <p>
                Consulta expedientes clínicos, citas programadas y seguimiento médico
                desde el panel profesional MediCore.
            </p>
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
                    <h2><?= $con_cita ?></h2>
                    <p>Citas Programadas</p>
                </div>

                <div class="stat-icon icon-success">
                    <i class="fas fa-calendar-check"></i>
                </div>
            </article>

            <article class="stat-card">
                <div>
                    <h2><?= $sin_cita ?></h2>
                    <p>Sin Consulta</p>
                </div>

                <div class="stat-icon icon-warning">
                    <i class="fas fa-clock"></i>
                </div>
            </article>

            <article class="stat-card">
                <div>
                    <h2><?= $activos ?></h2>
                    <p>Tratamientos Activos</p>
                </div>

                <div class="stat-icon icon-success">
                    <i class="fas fa-circle-check"></i>
                </div>
            </article>

        </section>

        <section class="card">

            <div class="toolbar">
                <div class="search-box">
                    <i class="fas fa-search"></i>

                    <input
                        type="text"
                        id="searchInput"
                        placeholder="Buscar paciente por nombre, correo o teléfono..."
                        onkeyup="filtrarTabla()">
                </div>

                <a href="nuevo_paciente.php" class="btn-add">
                    <i class="fas fa-user-plus"></i>
                    Nuevo Paciente
                </a>
            </div>

        </section>

        <section class="table-card">

            <div class="table-header">
                <h3>
                    <i class="fas fa-list"></i>
                    Directorio de Pacientes
                </h3>
            </div>

            <div class="table-container">

                <?php if ($total_pacientes > 0): ?>

                    <table id="tablaPacientes">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Paciente</th>
                                <th>Correo</th>
                                <th>Teléfono</th>
                                <th>Edad</th>
                                <th>Registro</th>
                                <th>Próxima Cita</th>
                                <th>Estatus</th>
                                <th>IMC</th>
                                <th>Acción</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($pacientes as $p): ?>
                                <?php $estado = $p['dieta_autorizada'] ?? 'pendiente'; ?>

                                <tr class="fila">
                                    <td class="patient-id">
                                        #<?= str_pad($p['id'], 4, "0", STR_PAD_LEFT) ?>
                                    </td>

                                    <td class="patient-name nombre">
                                        <?= htmlspecialchars($p['nombre']) ?>
                                    </td>

                                    <td class="correo">
                                        <?= htmlspecialchars($p['email']) ?>
                                    </td>

                                    <td class="telefono">
                                        <?= htmlspecialchars($p['telefono'] ?? 'No registrado') ?>
                                    </td>

                                    <td>
                                        <?= !empty($p['edad']) ? intval($p['edad']) . ' años' : '—' ?>
                                    </td>

                                    <td>
                                        <?= !empty($p['fecha_registro'])
                                            ? date("d/m/Y", strtotime($p['fecha_registro']))
                                            : '—' ?>
                                    </td>

                                    <td>
                                        <?php if (!empty($p['fecha_cita'])): ?>
                                            <span class="badge success">
                                                <i class="fas fa-calendar-check"></i>
                                                <?= date("d/m/Y", strtotime($p['fecha_cita'])) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge warning">
                                                <i class="fas fa-clock"></i>
                                                Pendiente
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if ($estado === 'autorizada'): ?>
                                            <span class="badge success">
                                                <i class="fas fa-circle-check"></i>
                                                Activo
                                            </span>
                                        <?php elseif ($estado === 'denegada'): ?>
                                            <span class="badge danger">
                                                <i class="fas fa-ban"></i>
                                                Suspendido
                                            </span>
                                        <?php else: ?>
                                            <span class="badge warning">
                                                <i class="fas fa-hourglass-half"></i>
                                                En revisión
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= !empty($p['imc']) ? number_format($p['imc'], 1) : '—' ?>
                                    </td>

                                    <td>
                                        <a href="expediente.php?id=<?= intval($p['id']) ?>" class="btn-view">
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
                        <h3>No existen pacientes registrados</h3>
                        <p>Comienza registrando el primer paciente en el sistema MediCore.</p>
                    </div>

                <?php endif; ?>

            </div>

        </section>

    </div>

    <script>
        function filtrarTabla() {
            const input = document.getElementById('searchInput');
            const filtro = input.value.toLowerCase();
            const filas = document.querySelectorAll('.fila');

            filas.forEach(fila => {
                const nombre = fila.querySelector('.nombre').textContent.toLowerCase();
                const correo = fila.querySelector('.correo').textContent.toLowerCase();
                const telefono = fila.querySelector('.telefono').textContent.toLowerCase();

                fila.style.display =
                    nombre.includes(filtro) ||
                    correo.includes(filtro) ||
                    telefono.includes(filtro) ?
                    '' :
                    'none';
            });
        }
    </script>

</body>

</html>