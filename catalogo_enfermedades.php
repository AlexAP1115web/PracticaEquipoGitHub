<?php
include("config.php");
include("security.php");

verificarSesion();

$mensaje_exito = "";
$mensaje_error = "";

/* ======================================================
   AGREGAR NUEVA ENFERMEDAD AL CATÁLOGO
====================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    validarTokenCSRF($_POST['csrf_token'] ?? '');

    $nombre = limpiar(trim($_POST['nombre'] ?? ''));
    $categoria = limpiar(trim($_POST['categoria'] ?? ''));
    $descripcion = limpiar(trim($_POST['descripcion'] ?? ''));
    $recomendaciones = limpiar(trim($_POST['recomendaciones'] ?? ''));

    if (empty($nombre)) {
        $mensaje_error = "El nombre de la enfermedad es obligatorio.";
    } else {

        $stmt = $conexion->prepare("
            INSERT INTO enfermedades (nombre, categoria, descripcion, recomendaciones)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("ssss", $nombre, $categoria, $descripcion, $recomendaciones);

        if ($stmt->execute()) {
            $mensaje_exito = "Enfermedad agregada al catálogo correctamente.";
            registrarLog("Nueva enfermedad agregada al catálogo: $nombre", "INFO");
        } else {
            $mensaje_error = "No se pudo guardar la enfermedad.";
        }

        $stmt->close();
    }
}

/* ======================================================
   LISTADO DEL CATÁLOGO
====================================================== */

$resultado = $conexion->query("SELECT * FROM enfermedades ORDER BY categoria ASC, nombre ASC");
$enfermedades = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo de Enfermedades | MediCore</title>
    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <?php include("sidebar.php"); ?>

    <div class="content">

        <section class="page-header">
            <h1><i class="fas fa-book-medical"></i> Catálogo de Tipos de Enfermedades</h1>
            <p>Administra el catálogo institucional de enfermedades. Puedes vincular cada expediente clínico a una de estas entradas para generar recomendaciones automáticas.</p>
        </section>

        <?php if ($mensaje_exito): ?>
            <div class="alerta exito"><i class="fas fa-circle-check"></i> <?= htmlspecialchars($mensaje_exito) ?></div>
        <?php endif; ?>

        <?php if ($mensaje_error): ?>
            <div class="alerta error"><i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($mensaje_error) ?></div>
        <?php endif; ?>

        <section class="card">
            <h2><i class="fas fa-plus"></i> Agregar enfermedad al catálogo</h2>

            <form method="POST" class="form-grid" style="margin-top:14px;">
                <input type="hidden" name="csrf_token" value="<?= generarTokenCSRF() ?>">

                <div>
                    <label>Nombre</label>
                    <input type="text" name="nombre" required placeholder="Ej. Diabetes tipo 2">
                </div>

                <div>
                    <label>Categoría</label>
                    <input type="text" name="categoria" placeholder="Ej. Metabólica, Respiratoria...">
                </div>

                <div style="grid-column: 1 / -1;">
                    <label>Descripción</label>
                    <textarea name="descripcion" placeholder="Descripción breve de la enfermedad"></textarea>
                </div>

                <div style="grid-column: 1 / -1;">
                    <label>Recomendaciones generales</label>
                    <textarea name="recomendaciones" placeholder="Recomendaciones que se mostrarán automáticamente en el módulo de Recomendaciones"></textarea>
                </div>

                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Agregar al catálogo</button>
                </div>
            </form>
        </section>

        <section class="table-card">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> Enfermedades registradas (<?= count($enfermedades) ?>)</h3>
            </div>

            <div class="table-container">
                <?php if (count($enfermedades) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Categoría</th>
                                <th>Descripción</th>
                                <th>Recomendaciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($enfermedades as $enf): ?>
                                <tr>
                                    <td class="patient-name"><?= htmlspecialchars($enf['nombre']) ?></td>
                                    <td><?= htmlspecialchars($enf['categoria'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($enf['descripcion'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($enf['recomendaciones'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-book-medical"></i>
                        <h3>Catálogo vacío</h3>
                        <p>Agrega tu primera enfermedad usando el formulario de arriba.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

    </div>

</body>
</html>
