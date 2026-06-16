<?php
include("config.php");
include("security.php");

verificarSesion();

$mensaje_exito = "";
$mensaje_error = "";

$id_medico = $_SESSION['medico']
    ?? $_SESSION['medico_id']
    ?? $_SESSION['id']
    ?? 0;

/* ======================================================
   OBTENER DATOS DEL MÉDICO
====================================================== */

$stmt_info = $conexion->prepare("
    SELECT *
    FROM medicos
    WHERE id = ?
    LIMIT 1
");

$stmt_info->bind_param("i", $id_medico);
$stmt_info->execute();

$resultado_info = $stmt_info->get_result();
$medico_info = $resultado_info->fetch_assoc();

$stmt_info->close();

if (!$medico_info) {
    session_destroy();
    header("Location: login.php?error=sesion_invalida");
    exit();
}

/* ======================================================
   PROCESAR FORMULARIOS
====================================================== */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    validarTokenCSRF($_POST['csrf_token'] ?? '');

    /* ======================================================
       ACTUALIZAR PERFIL
    ====================================================== */

    if (isset($_POST['accion']) && $_POST['accion'] === 'actualizar_perfil') {

        $nombre = limpiar(trim($_POST['nombre'] ?? ''));
        $correo = limpiar(trim($_POST['correo'] ?? ''));

        $foto_path = $medico_info['foto_perfil'] ?? '';

        if (empty($nombre) || empty($correo)) {
            $mensaje_error = "Todos los campos del perfil son obligatorios.";
        } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $mensaje_error = "El correo electrónico no tiene un formato válido.";
        } else {

            $stmt_correo = $conexion->prepare("
                SELECT id
                FROM medicos
                WHERE correo = ?
                AND id != ?
                LIMIT 1
            ");

            $stmt_correo->bind_param("si", $correo, $id_medico);
            $stmt_correo->execute();

            $correo_existente = $stmt_correo->get_result();

            if ($correo_existente->num_rows > 0) {
                $mensaje_error = "El correo electrónico ya está registrado.";
                registrarLog("Intento de usar correo duplicado: $correo", "WARNING");
            }

            $stmt_correo->close();
        }

        if (empty($mensaje_error)) {

            if (
                isset($_FILES['foto_perfil']) &&
                $_FILES['foto_perfil']['error'] === 0
            ) {

                $max_size = 2 * 1024 * 1024;

                $nombre_original = $_FILES['foto_perfil']['name'];
                $tmp_name = $_FILES['foto_perfil']['tmp_name'];
                $tamano = $_FILES['foto_perfil']['size'];

                $extension = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));

                $formatos_permitidos = ['jpg', 'jpeg', 'png', 'webp'];

                if ($tamano > $max_size) {

                    $mensaje_error = "La imagen supera el tamaño máximo permitido de 2MB.";
                    registrarLog("Intento de subida mayor a 2MB ($tamano bytes)", "WARNING");
                } elseif (!in_array($extension, $formatos_permitidos)) {

                    $mensaje_error = "Formato no permitido. Solo JPG, PNG o WEBP.";
                    registrarLog("Formato de imagen no permitido: .$extension", "CRITICAL");
                } else {

                    $mime = mime_content_type($tmp_name);

                    $mimes_validos = [
                        'image/jpeg',
                        'image/png',
                        'image/webp'
                    ];

                    if (!in_array($mime, $mimes_validos)) {

                        $mensaje_error = "El archivo subido no es una imagen válida.";
                        registrarLog("Intento de subida sospechosa MIME: $mime", "CRITICAL");
                    } else {

                        $directorio = "uploads/perfiles/";

                        if (!file_exists($directorio)) {
                            mkdir($directorio, 0777, true);
                        }

                        $nuevo_nombre =
                            "MEDICORE_" .
                            $id_medico .
                            "_" .
                            time() .
                            "." .
                            $extension;

                        $ruta_final = $directorio . $nuevo_nombre;

                        if (move_uploaded_file($tmp_name, $ruta_final)) {

                            if (
                                !empty($medico_info['foto_perfil']) &&
                                file_exists($medico_info['foto_perfil'])
                            ) {
                                @unlink($medico_info['foto_perfil']);
                            }

                            $foto_path = $ruta_final;

                            registrarLog("Foto de perfil actualizada correctamente.", "INFO");
                        } else {
                            $mensaje_error = "Error interno al guardar la imagen.";
                        }
                    }
                }
            }
        }

        if (empty($mensaje_error)) {

            $update_perfil = $conexion->prepare("
                UPDATE medicos
                SET nombre = ?,
                    correo = ?,
                    foto_perfil = ?
                WHERE id = ?
            ");

            $update_perfil->bind_param(
                "sssi",
                $nombre,
                $correo,
                $foto_path,
                $id_medico
            );

            if ($update_perfil->execute()) {

                $mensaje_exito = "Perfil actualizado correctamente.";

                $medico_info['nombre'] = $nombre;
                $medico_info['correo'] = $correo;
                $medico_info['foto_perfil'] = $foto_path;

                $_SESSION['nombre_medico'] = $nombre;

                registrarLog("Actualización de perfil exitosa.", "INFO");
            } else {
                $mensaje_error = "No se pudo actualizar el perfil.";
            }

            $update_perfil->close();
        }
    }

    /* ======================================================
       CAMBIO DE CONTRASEÑA
    ====================================================== */

    if (isset($_POST['pass_actual'])) {

        $pass_actual  = trim($_POST['pass_actual'] ?? '');
        $pass_nueva   = trim($_POST['pass_nueva'] ?? '');
        $pass_confirm = trim($_POST['pass_confirm'] ?? '');

        if (
            empty($pass_actual) ||
            empty($pass_nueva) ||
            empty($pass_confirm)
        ) {

            $mensaje_error = "Todos los campos de contraseña son obligatorios.";
        } elseif ($pass_nueva !== $pass_confirm) {

            $mensaje_error = "Las nuevas contraseñas no coinciden.";
            registrarLog("Contraseña nueva no coincide.", "WARNING");
        } elseif (strlen($pass_nueva) < 8) {

            $mensaje_error = "La contraseña debe tener mínimo 8 caracteres.";
        } elseif (
            !preg_match('/[A-Z]/', $pass_nueva) ||
            !preg_match('/[0-9]/', $pass_nueva)
        ) {

            $mensaje_error = "La contraseña debe incluir al menos una mayúscula y un número.";
        } else {

            $stmt_password = $conexion->prepare("
                SELECT password
                FROM medicos
                WHERE id = ?
                LIMIT 1
            ");

            $stmt_password->bind_param("i", $id_medico);
            $stmt_password->execute();

            $resultado_password = $stmt_password->get_result()->fetch_assoc();

            $stmt_password->close();

            if (
                $resultado_password &&
                password_verify($pass_actual, $resultado_password['password'])
            ) {

                $nuevo_hash = password_hash($pass_nueva, PASSWORD_DEFAULT);

                $update_password = $conexion->prepare("
                    UPDATE medicos
                    SET password = ?
                    WHERE id = ?
                ");

                $update_password->bind_param("si", $nuevo_hash, $id_medico);

                if ($update_password->execute()) {

                    $mensaje_exito = "Contraseña actualizada exitosamente.";
                    registrarLog("Cambio de contraseña exitoso.", "INFO");
                } else {
                    $mensaje_error = "Error al actualizar la contraseña.";
                }

                $update_password->close();
            } else {

                $mensaje_error = "La contraseña actual es incorrecta.";
                registrarLog("Intento fallido de cambio de contraseña.", "CRITICAL");
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración | MediCore</title>

    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>

    <?php include("sidebar.php"); ?>

    <div class="content">

        <section class="config-header">
            <h2>
                <i class="fas fa-user-shield"></i>
                Configuración Profesional
            </h2>

            <p>
                Administra tu perfil médico, fotografía institucional y seguridad de acceso dentro de MediCore.
            </p>
        </section>

        <?php if ($mensaje_exito): ?>
            <div class="alerta exito">
                <i class="fas fa-circle-check"></i>
                <?= htmlspecialchars($mensaje_exito) ?>
            </div>
        <?php endif; ?>

        <?php if ($mensaje_error): ?>
            <div class="alerta error">
                <i class="fas fa-triangle-exclamation"></i>
                <?= htmlspecialchars($mensaje_error) ?>
            </div>
        <?php endif; ?>

        <section class="config-grid">

            <article class="config-card">

                <h3>
                    <i class="fas fa-user-doctor"></i>
                    Información del Perfil
                </h3>

                <form method="POST" enctype="multipart/form-data">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= generarTokenCSRF() ?>">

                    <input
                        type="hidden"
                        name="accion"
                        value="actualizar_perfil">

                    <div class="foto-container">

                        <?php if (!empty($medico_info['foto_perfil']) && file_exists($medico_info['foto_perfil'])): ?>

                            <img
                                src="<?= htmlspecialchars($medico_info['foto_perfil']) ?>"
                                class="foto-preview"
                                alt="Foto Perfil">

                        <?php else: ?>

                            <div class="foto-placeholder">
                                <i class="fas fa-user-md"></i>
                            </div>

                        <?php endif; ?>

                    </div>

                    <label>Fotografía Profesional</label>

                    <input
                        type="file"
                        name="foto_perfil"
                        accept="image/*"
                        class="input-file">

                    <label>Nombre Completo</label>

                    <input
                        type="text"
                        name="nombre"
                        required
                        value="<?= htmlspecialchars($medico_info['nombre'] ?? '') ?>"
                        placeholder="Ej. Dr. Alejandro Pérez">

                    <label>Correo Electrónico</label>

                    <input
                        type="email"
                        name="correo"
                        required
                        value="<?= htmlspecialchars($medico_info['correo'] ?? '') ?>"
                        placeholder="correo@medicore.com">

                    <button type="submit" class="btn-primary full-btn">
                        <i class="fas fa-save"></i>
                        Guardar Cambios
                    </button>

                </form>

            </article>

            <article class="config-card">

                <h3>
                    <i class="fas fa-lock"></i>
                    Seguridad y Contraseña
                </h3>

                <div class="security-box">

                    <strong>
                        Recomendaciones de seguridad:
                    </strong>

                    <ul>
                        <li>Utiliza mínimo 8 caracteres.</li>
                        <li>Incluye letras mayúsculas y números.</li>
                        <li>No compartas tus credenciales.</li>
                        <li>Actualiza tu contraseña regularmente.</li>
                    </ul>

                </div>

                <form method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= generarTokenCSRF() ?>">

                    <label>Contraseña Actual</label>

                    <input
                        type="password"
                        name="pass_actual"
                        required
                        placeholder="Ingresa tu contraseña actual">

                    <label>Nueva Contraseña</label>

                    <input
                        type="password"
                        name="pass_nueva"
                        required
                        placeholder="Nueva contraseña segura">

                    <label>Confirmar Nueva Contraseña</label>

                    <input
                        type="password"
                        name="pass_confirm"
                        required
                        placeholder="Repite la nueva contraseña">

                    <button type="submit" class="btn-primary full-btn">
                        <i class="fas fa-key"></i>
                        Actualizar Contraseña
                    </button>

                </form>

            </article>

        </section>

    </div>

</body>

</html>