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

/* Citas de hoy, junto a "Agenda" y en el ícono de la app (pwa.js) */
$citas_hoy_sidebar = 0;

if (isset($conexion) && $conexion instanceof mysqli) {
    $resultado_citas = $conexion->query("SELECT COUNT(*) AS total FROM expedientes WHERE fecha_cita = CURDATE()");

    if ($resultado_citas) {
        $citas_hoy_sidebar = (int) ($resultado_citas->fetch_assoc()['total'] ?? 0);
    }

    // Recordatorios push de citas (semana, mañana y hoy) sin necesidad de cron
    require_once __DIR__ . '/config/esquema.php';
    require_once __DIR__ . '/integrations/WebPush.php';
    prepararEsquemaInnovacion($conexion);
    revisarRecordatoriosEnSegundoPlano($conexion);
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
        'extras'  => ['nuevo_paciente.php', 'expediente.php', 'mapa_corporal.php']
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
        'archivo' => 'recomendaciones.php',
        'icono'   => 'fas fa-notes-medical',
        'titulo'  => 'Recomendaciones'
    ],
    [
        'archivo' => 'chatbot.php',
        'icono'   => 'fas fa-robot',
        'titulo'  => 'Asistente IA'
    ],
    [
        'archivo' => 'catalogo_enfermedades.php',
        'icono'   => 'fas fa-disease',
        'titulo'  => 'Enfermedades'
    ],
    [
        'archivo' => 'ubicacion.php',
        'icono'   => 'fas fa-map-location-dot',
        'titulo'  => 'Ubicación'
    ],
    [
        'archivo' => 'infografia.php',
        'icono'   => 'fas fa-book-medical',
        'titulo'  => 'Material Educativo'
    ],
    [
        'archivo' => 'cache.php',
        'icono'   => 'fas fa-hard-drive',
        'titulo'  => 'Sin conexión'
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

        <div>
            <div class="doctor-name">
                <?= htmlspecialchars($nombre_medico) ?>
            </div>

            <div class="doctor-role">
                Médico tratante
            </div>
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

                <?php if ($item['archivo'] === 'agenda.php' && $citas_hoy_sidebar > 0): ?>
                    <b class="contador" title="Citas de hoy"><?= $citas_hoy_sidebar ?><span class="sr-only"> citas hoy</span></b>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-spacer"></div>

    <a href="logout.php" class="sidebar-link logout-btn">
        <i class="fas fa-right-from-bracket"></i>
        <span>Cerrar Sesión</span>
    </a>

    <!-- Lo lee pwa.js (citas en el ícono y notas pendientes) -->
    <div
        id="datos-pwa"
        hidden
        data-citas-hoy="<?= $citas_hoy_sidebar ?>"
        data-csrf="<?= function_exists('generarTokenCSRF') ? htmlspecialchars(generarTokenCSRF()) : '' ?>"></div>

</aside>