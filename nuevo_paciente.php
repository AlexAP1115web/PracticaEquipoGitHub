<?php
include("config.php");
include("security.php");

verificarSesion();

$mensaje_exito = "";
$mensaje_error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    validarTokenCSRF($_POST['csrf_token'] ?? '');

    $nombre = limpiar($_POST['nombre'] ?? '');
    $email_raw = limpiar($_POST['email'] ?? '');
    $email = filter_var($email_raw, FILTER_VALIDATE_EMAIL);
    $password_plana = $_POST['password'] ?? '';
    $telefono = limpiar($_POST['telefono'] ?? '');
    $direccion = limpiar($_POST['direccion'] ?? '');
    $edad = intval($_POST['edad'] ?? 0);
    $rol = "paciente";

    if (
        empty($nombre) ||
        !$email ||
        empty($password_plana) ||
        empty($telefono) ||
        empty($direccion) ||
        $edad <= 0
    ) {
        $mensaje_error = "Completa correctamente todos los campos obligatorios.";
    } elseif (strlen($password_plana) < 8) {
        $mensaje_error = "La contraseña debe contener mínimo 8 caracteres.";
    } else {

        $check = $conexion->prepare("
            SELECT id
            FROM usuarios
            WHERE email = ?
            LIMIT 1
        ");

        $check->bind_param("s", $email);
        $check->execute();

        $resultado = $check->get_result();

        if ($resultado->num_rows > 0) {

            $mensaje_error = "El correo ya se encuentra registrado.";
        } else {

            $password_encriptada = password_hash($password_plana, PASSWORD_BCRYPT);

            $insert = $conexion->prepare("
                INSERT INTO usuarios
                (
                    nombre,
                    email,
                    password,
                    rol,
                    telefono,
                    direccion,
                    edad
                )
                VALUES
                (?, ?, ?, ?, ?, ?, ?)
            ");

            $insert->bind_param(
                "ssssssi",
                $nombre,
                $email,
                $password_encriptada,
                $rol,
                $telefono,
                $direccion,
                $edad
            );

            if ($insert->execute()) {

                $nuevo_id = $insert->insert_id;

                $mensaje_exito = "Paciente registrado correctamente en MediCore.";

                registrarLog(
                    "Nuevo paciente registrado ID: $nuevo_id por Médico ID: " . ($_SESSION['medico'] ?? 'N/D'),
                    "INFO"
                );

                header("refresh:2;url=expediente.php?id=" . $nuevo_id);
            } else {
                $mensaje_error = "No se pudo registrar el paciente.";
            }

            $insert->close();
        }

        $check->close();
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuevo Paciente | MediCore</title>

    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>

    <?php include("sidebar.php"); ?>

    <div class="content">

        <section class="top-actions">
            <a href="pacientes.php" class="btn-refresh">
                <i class="fas fa-arrow-left"></i>
                Volver a Pacientes
            </a>
        </section>

        <section class="page-header">
            <h1>
                <i class="fas fa-user-plus"></i>
                Registro Clínico de Paciente
            </h1>

            <p>
                Crea un nuevo expediente digital dentro del ecosistema médico de MediCore.
            </p>
        </section>

        <?php if ($mensaje_exito): ?>
            <div class="alerta exito">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($mensaje_exito) ?> Redirigiendo al expediente...
            </div>
        <?php endif; ?>

        <?php if ($mensaje_error): ?>
            <div class="alerta error">
                <i class="fas fa-triangle-exclamation"></i>
                <?= htmlspecialchars($mensaje_error) ?>
            </div>
        <?php endif; ?>

        <section class="card">

            <div class="medical-note">
                <strong>
                    <i class="fas fa-shield-heart"></i>
                    Seguridad Institucional
                </strong>

                <p>
                    Toda la información será protegida mediante protocolos de seguridad clínica
                    y cifrado institucional MediCore.
                </p>
            </div>

            <form method="POST" id="formPaciente">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= generarTokenCSRF() ?>">

                <h3 class="section-title">
                    <i class="fas fa-id-card"></i>
                    Datos Generales
                </h3>

                <label>Nombre Completo</label>
                <input
                    type="text"
                    name="nombre"
                    placeholder="Ej. Juan Pérez Hernández"
                    required>

                <div class="form-grid">

                    <div>
                        <label>Teléfono</label>
                        <input
                            type="tel"
                            name="telefono"
                            placeholder="2221234567"
                            required>
                    </div>

                    <div>
                        <label>Edad</label>
                        <input
                            type="number"
                            name="edad"
                            min="1"
                            max="120"
                            placeholder="Ej. 30"
                            required>
                    </div>

                </div>

                <label>Dirección</label>
                <textarea
                    name="direccion"
                    placeholder="Dirección completa del paciente..."
                    required></textarea>

                <h3 class="section-title">
                    <i class="fas fa-user-lock"></i>
                    Datos de Acceso
                </h3>

                <label>Correo Electrónico</label>
                <input
                    type="email"
                    name="email"
                    placeholder="paciente@email.com"
                    required>

                <label>Contraseña Provisional</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Mínimo 8 caracteres"
                    required>

                <div class="form-actions">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i>
                        Guardar Paciente y Crear Expediente
                    </button>
                </div>

            </form>

        </section>

    </div>

    <script>
        document.getElementById('formPaciente').addEventListener('submit', function(e) {
            const pass = document.getElementById('password').value;

            if (pass.length < 8) {
                e.preventDefault();
                alert('La contraseña debe contener mínimo 8 caracteres.');
            }
        });
    </script>

</body>

</html>