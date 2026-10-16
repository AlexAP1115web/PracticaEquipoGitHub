<?php
include("config.php");
include("security.php");

verificarSesion();

/* ======================================================
   ALMACENAMIENTO SIN CONEXIÓN (Cache API)
   La página solo arma la vista; todo lo de la caché lo hace assets/js/cache.js
   desde el navegador con window.caches
====================================================== */

// Páginas de consulta que se pueden guardar. Ninguna trae datos de pacientes
$paginasConsulta = [
    [
        'url'       => 'catalogo_enfermedades.php',
        'titulo'    => 'Catálogo de enfermedades',
        'detalle'   => 'Síntomas, tratamiento y prevención de cada enfermedad.',
        'icono'     => 'fa-disease',
        'recursos'  => []
    ],
    [
        'url'       => 'infografia.php',
        'titulo'    => 'Material educativo',
        'detalle'   => 'Infografía de prevención para mostrar al paciente.',
        'icono'     => 'fa-book-medical',
        'recursos'  => ['IMG/infografia.png']
    ],
    [
        'url'       => 'aviso_privacidad.php',
        'titulo'    => 'Aviso de privacidad',
        'detalle'   => 'Para leerlo con el paciente aunque no haya señal.',
        'icono'     => 'fa-file-shield',
        'recursos'  => []
    ]
];

registrarLog("Acceso al módulo de almacenamiento sin conexión.", "INFO");
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sin conexión | MediCore</title>

    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<?php include __DIR__ . "/pwa_head.php"; ?>
</head>

<body>

    <?php include("sidebar.php"); ?>

    <div class="content" id="moduloCache">

        <section class="page-header">
            <h1>
                <i class="fas fa-hard-drive"></i>
                Almacenamiento sin conexión
            </h1>

            <p>
                Guarda páginas de consulta en este dispositivo para abrirlas aunque no haya internet
                y revisa lo que MediCore tiene en la caché del navegador.
            </p>
        </section>

        <div class="alerta error" id="cacheNoDisponible" hidden>
            <i class="fas fa-triangle-exclamation"></i>
            Este navegador no permite usar la caché (Cache API). Abre MediCore en Chrome, Edge o Firefox con https.
        </div>

        <section class="stats-grid">
            <article class="stat-card">
                <div>
                    <h2 id="totalCaches">0</h2>
                    <p>Cachés en el navegador</p>
                </div>
                <div class="stat-icon icon-primary"><i class="fas fa-layer-group"></i></div>
            </article>

            <article class="stat-card">
                <div>
                    <h2 id="totalArchivos">0</h2>
                    <p>Archivos guardados</p>
                </div>
                <div class="stat-icon icon-success"><i class="fas fa-file-lines"></i></div>
            </article>

            <article class="stat-card">
                <div>
                    <h2 id="espacioUsado">—</h2>
                    <p>Espacio usado por MediCore</p>
                </div>
                <div class="stat-icon icon-warning"><i class="fas fa-hard-drive"></i></div>
            </article>

            <article class="stat-card">
                <div>
                    <h2 id="estadoRed">En línea</h2>
                    <p>Conexión</p>
                </div>
                <div class="stat-icon icon-success" id="iconoRed"><i class="fas fa-wifi"></i></div>
            </article>
        </section>

        <section class="table-card">
            <div class="table-header">
                <h3>
                    <i class="fas fa-download"></i>
                    Páginas para consultar sin internet
                </h3>
            </div>

            <ul class="cache-paginas" id="paginasConsulta">
                <?php foreach ($paginasConsulta as $p): ?>
                    <li class="cache-pagina"
                        data-url="<?= htmlspecialchars($p['url']) ?>"
                        data-recursos="<?= htmlspecialchars(json_encode($p['recursos'])) ?>">
                        <label class="cache-pagina-check">
                            <input type="checkbox" checked>
                            <span class="sr-only">Seleccionar <?= htmlspecialchars($p['titulo']) ?></span>
                        </label>
                        <span class="cache-pagina-icono" aria-hidden="true"><i class="fas <?= $p['icono'] ?>"></i></span>
                        <div class="cache-pagina-texto">
                            <strong><?= htmlspecialchars($p['titulo']) ?></strong>
                            <span><?= htmlspecialchars($p['detalle']) ?></span>
                        </div>
                        <span class="badge warning cache-estado">No guardada</span>
                        <button type="button" class="btn-view cache-quitar" hidden>
                            <i class="fas fa-xmark"></i>
                            Quitar
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="cache-acciones">
                <button type="button" class="btn-primary" id="btnGuardarPaginas">
                    <i class="fas fa-download"></i>
                    Guardar seleccionadas
                </button>
                <button type="button" class="btn-refresh" id="btnActualizarPaginas">
                    <i class="fas fa-rotate"></i>
                    Actualizar guardadas
                </button>
                <p class="cache-mensaje" id="mensajePaginas" role="status"></p>
            </div>

            <p class="cache-nota">
                Las páginas de pacientes, agenda y expedientes nunca se guardan: siempre se piden al servidor
                para no dejar datos clínicos en el dispositivo. Al cerrar sesión se borran las páginas guardadas.
            </p>
        </section>

        <section class="table-card">
            <div class="table-header cache-cabecera">
                <h3>
                    <i class="fas fa-box-archive"></i>
                    Contenido de la caché
                </h3>

                <div class="cache-filtros">
                    <label for="selectCache" class="sr-only">Caché</label>
                    <select id="selectCache"></select>
                    <button type="button" class="btn-eliminar" id="btnVaciarCache" hidden>
                        <i class="fas fa-trash-can"></i>
                        Vaciar
                    </button>
                </div>
            </div>

            <p class="cache-nota" id="notaCache"></p>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Archivo</th>
                            <th>Tipo</th>
                            <th>Tamaño</th>
                            <th>Guardado</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody id="tablaCache">
                        <tr>
                            <td colspan="5" class="cache-vacio">Cargando…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="table-card">
            <div class="table-header">
                <h3>
                    <i class="fas fa-route"></i>
                    Cómo usa MediCore la caché
                </h3>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Qué se pide</th>
                            <th>Estrategia</th>
                            <th>Métodos de la Cache API</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Archivos base (offline.html, estilos, íconos)</td>
                            <td>Se guardan al instalar el service worker</td>
                            <td><code>caches.open()</code>, <code>cache.addAll()</code></td>
                        </tr>
                        <tr>
                            <td>Estilos, scripts, íconos y fuentes</td>
                            <td>Stale-while-revalidate: responde con la copia y la actualiza en segundo plano</td>
                            <td><code>cache.match()</code>, <code>cache.put()</code></td>
                        </tr>
                        <tr>
                            <td>Páginas de consulta guardadas</td>
                            <td>Network first: con internet trae la versión nueva; sin internet usa la copia</td>
                            <td><code>caches.match()</code>, <code>cache.put()</code></td>
                        </tr>
                        <tr>
                            <td>Pacientes, agenda, expedientes y formularios</td>
                            <td>Solo red; sin internet se muestra la página sin conexión</td>
                            <td><code>caches.match()</code> (offline.html)</td>
                        </tr>
                        <tr>
                            <td>Versiones anteriores de la app</td>
                            <td>Se borran al activar la versión nueva</td>
                            <td><code>caches.keys()</code>, <code>caches.delete()</code></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

    </div>

    <script src="assets/js/cache.js?v=1" defer></script>

</body>

</html>
