<?php
include("config.php");
include("security.php");

verificarSesion();

/* ======================================================
   SUCURSALES / CLÍNICAS REGISTRADAS
====================================================== */

$resultado = $conexion->query("SELECT * FROM sucursales ORDER BY nombre ASC");
$sucursales = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];

$mapsApiKey = apiConfig('GOOGLE_MAPS_API_KEY');
$mapaDisponible = !empty($mapsApiKey);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ubicación | MediCore</title>
    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <?php include("sidebar.php"); ?>

    <div class="content">

        <section class="page-header">
            <h1><i class="fas fa-map-location-dot"></i> Ubicación de Clínicas y Sucursales</h1>
            <p>Localiza las clínicas, hospitales o sucursales de MediCore más cercanas.</p>
        </section>

        <?php if (!$mapaDisponible): ?>
            <section class="card">
                <h2><i class="fas fa-map"></i> Mapa no disponible todavía</h2>
                <p>
                    Configura <code>GOOGLE_MAPS_API_KEY</code> en tu archivo <code>.env</code> para mostrar el mapa interactivo
                    (crea una API key gratuita en Google Cloud Console y habilita la "Maps Embed API").
                    Mientras tanto, aquí está el listado de sucursales registradas.
                </p>
            </section>
        <?php endif; ?>

        <section class="table-card">
            <div class="table-header">
                <h3><i class="fas fa-hospital"></i> Sucursales registradas (<?= count($sucursales) ?>)</h3>
            </div>

            <div class="table-container">
                <?php if (count($sucursales) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Dirección</th>
                                <th>Teléfono</th>
                                <th>Mapa</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sucursales as $s): ?>
                                <tr>
                                    <td class="patient-name"><?= htmlspecialchars($s['nombre']) ?></td>
                                    <td><?= htmlspecialchars($s['direccion']) ?></td>
                                    <td><?= htmlspecialchars($s['telefono'] ?? 'No registrado') ?></td>
                                    <td>
                                        <?php if ($mapaDisponible): ?>
                                            <iframe
                                                width="260"
                                                height="160"
                                                style="border:0;border-radius:12px;"
                                                loading="lazy"
                                                referrerpolicy="no-referrer-when-downgrade"
                                                src="https://www.google.com/maps/embed/v1/place?key=<?= urlencode($mapsApiKey) ?>&q=<?= urlencode($s['direccion']) ?>">
                                            </iframe>
                                        <?php else: ?>
                                            <a
                                                href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($s['direccion']) ?>"
                                                target="_blank"
                                                rel="noopener"
                                                class="btn-view">
                                                <i class="fas fa-location-dot"></i> Ver en Google Maps
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-map-location-dot"></i>
                        <h3>Sin sucursales registradas</h3>
                        <p>Agrega registros en la tabla <code>sucursales</code> desde phpMyAdmin (ver database/schema.sql).</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

    </div>

</body>
</html>
