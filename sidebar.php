<?php

/**
 * ======================================================
 * MEDICORE PROFESSIONAL SYSTEM
 * SIDEBAR GLOBAL
 * Estilos únicamente en assets/style.css
 * ======================================================
 */

$pagina_actual = basename($_SERVER['PHP_SELF']);

$medico_id = $_SESSION['medico_id']
    ?? $_SESSION['medico']
    ?? $_SESSION['usuario_id']
    ?? null;

$nombre_medico = $_SESSION['nombre_medico']
    ?? $_SESSION['nombre']
    ?? 'Especialista';

$foto_perfil_sidebar = '';

if (
    isset($conexion)
    && $conexion instanceof mysqli
    && !empty($medico_id)
) {
    $stmt_foto = $conexion->prepare("
        SELECT foto_perfil
        FROM medicos
        WHERE id = ?
        LIMIT 1
    ");

    if ($stmt_foto) {
        $stmt_foto->bind_param("i", $medico_id);
        $stmt_foto->execute();

        $resultado_foto = $stmt_foto->get_result();

        if ($resultado_foto && $resultado_foto->num_rows > 0) {
            $data_foto = $resultado_foto->fetch_assoc();

            if (
                !empty($data_foto['foto_perfil'])
                && file_exists($data_foto['foto_perfil'])
            ) {
                $foto_perfil_sidebar = $data_foto['foto_perfil'];
            }
        }

        $stmt_foto->close();
    }
}

$menu_items = [
    [
        'archivo' => 'index.php',
        'icono'   => 'fas fa-chart-pie',
        'titulo'  => 'Dashboard'
    ],
    [
        'archivo' => 'pacientes.php',
        'icono'   => 'fas fa-user-injured',
        'titulo'  => 'Pacientes',
        'extras'  => ['nuevo_paciente.php', 'expediente.php']
    ],
    [
        'archivo' => 'agenda.php',
        'icono'   => 'fas fa-calendar-check',
        'titulo'  => 'Agenda'
    ],
    [
        'archivo' => 'reportes.php',
        'icono'   => 'fas fa-chart-line',
        'titulo'  => 'Reportes'
    ],
    [
        'archivo' => 'infografia.php',
        'icono'   => 'fas fa-book-medical',
        'titulo'  => 'Material Educativo'
    ],
    [
        'archivo' => 'configuracion.php',
        'icono'   => 'fas fa-gear',
        'titulo'  => 'Configuración'
    ]
];
?>

<aside class="sidebar">

    <div class="sidebar-logo">
        <?php if (file_exists("assets/Medicore.png")): ?>
            <img src="assets/Medicore.png" alt="MediCore Logo">
        <?php elseif (file_exists("assets/logo.png")): ?>
            <img src="assets/logo.png" alt="MediCore Logo">
        <?php else: ?>
            <div class="logo-fallback">MediCore</div>
        <?php endif; ?>
    </div>

    <div class="profile-box">
        <?php if (!empty($foto_perfil_sidebar)): ?>
            <img
                src="<?= htmlspecialchars($foto_perfil_sidebar) ?>"
                alt="Foto Médico"
                class="profile-image">
        <?php else: ?>
            <div class="profile-avatar">
                <i class="fas fa-user-doctor"></i>
            </div>
        <?php endif; ?>

        <div class="doctor-name">
            Dr. <?= htmlspecialchars($nombre_medico) ?>
        </div>

        <div class="doctor-role">
            Panel Médico Profesional
        </div>
    </div>

    <nav class="sidebar-menu">
        <?php foreach ($menu_items as $item): ?>
            <?php
            $activo = $pagina_actual === $item['archivo'];

            if (
                isset($item['extras'])
                && in_array($pagina_actual, $item['extras'])
            ) {
                $activo = true;
            }
            ?>

            <a
                href="<?= htmlspecialchars($item['archivo']) ?>"
                class="sidebar-link <?= $activo ? 'active' : '' ?>">

                <i class="<?= htmlspecialchars($item['icono']) ?>"></i>
                <span><?= htmlspecialchars($item['titulo']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-spacer"></div>

    <a href="logout.php" class="sidebar-link logout-btn">
        <i class="fas fa-right-from-bracket"></i>
        <span>Cerrar Sesión</span>
    </a>

</aside>