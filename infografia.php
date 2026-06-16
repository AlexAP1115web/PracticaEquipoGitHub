<?php
require "config.php";
require "security.php";

verificarSesion();

/* ======================================================
   CONFIGURACIÓN DE MATERIAL EDUCATIVO
====================================================== */

$archivo_infografia = "IMG/infografia.png";
$nombre_descarga = "Prevencion_Enfermedades_MediCore.png";

$archivo_existe = file_exists($archivo_infografia);

registrarLog("Acceso al módulo de Biblioteca Educativa Clínica.", "INFO");
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Biblioteca Educativa | MediCore</title>

    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>

    <?php include("sidebar.php"); ?>

    <div class="content">

        <section class="page-header">
            <h1>
                <i class="fas fa-book-medical"></i>
                Biblioteca Educativa
            </h1>

            <p>
                Material visual clínico para reforzar la educación preventiva,
                hábitos saludables y seguimiento médico de los pacientes.
            </p>
        </section>

        <section class="edu-grid">

            <article class="edu-card main-edu-card">

                <?php if ($archivo_existe): ?>

                    <div class="edu-card-header">
                        <h2>
                            <i class="fas fa-file-image"></i>
                            Infografía Clínica
                        </h2>

                        <p>
                            Documento visual de apoyo enfocado en la prevención
                            de enfermedades crónicas y promoción de hábitos saludables.
                        </p>

                        <span class="badge success">
                            <i class="fas fa-circle-check"></i>
                            Material disponible
                        </span>
                    </div>

                    <div class="edu-image-wrapper">
                        <img
                            src="<?= htmlspecialchars($archivo_infografia) ?>"
                            alt="Infografía clínica MediCore"
                            class="edu-image">
                    </div>

                    <div class="download-zone">
                        <a
                            href="<?= htmlspecialchars($archivo_infografia) ?>"
                            download="<?= htmlspecialchars($nombre_descarga) ?>"
                            class="btn-primary">

                            <i class="fas fa-download"></i>
                            Descargar Material Clínico

                        </a>
                    </div>

                <?php else: ?>

                    <div class="empty-state">
                        <i class="fas fa-triangle-exclamation"></i>

                        <h3>
                            Archivo no encontrado
                        </h3>

                        <p>
                            El recurso educativo solicitado no existe en el directorio configurado.
                        </p>
                    </div>

                <?php endif; ?>

            </article>

            <aside class="edu-card side-edu-card">

                <div class="mini-box">
                    <div class="mini-icon">
                        <i class="fas fa-brain"></i>
                    </div>

                    <h3>Objetivo Educativo</h3>

                    <p>
                        Fortalecer el aprendizaje preventivo mediante contenido gráfico
                        de fácil comprensión para pacientes.
                    </p>
                </div>

                <div class="mini-box">
                    <div class="mini-icon">
                        <i class="fas fa-heart-pulse"></i>
                    </div>

                    <h3>Prevención Clínica</h3>

                    <p>
                        Las enfermedades crónicas pueden prevenirse con hábitos saludables,
                        actividad física constante y monitoreo médico oportuno.
                    </p>
                </div>

                <div class="mini-box">
                    <div class="mini-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>

                    <h3>Recomendaciones</h3>

                    <ul class="tips-list">
                        <li>Controlar presión arterial periódicamente.</li>
                        <li>Mantener una alimentación balanceada.</li>
                        <li>Reducir consumo de azúcares procesados.</li>
                        <li>Realizar ejercicio físico diariamente.</li>
                        <li>Asistir a revisiones médicas frecuentes.</li>
                    </ul>
                </div>

            </aside>

        </section>

    </div>

</body>

</html>