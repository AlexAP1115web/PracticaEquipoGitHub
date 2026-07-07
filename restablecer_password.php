<?php
include("config.php");
require_once "security.php";

/* ======================================================
   PÁGINA PÚBLICA: RESTABLECER CONTRASEÑA CON TOKEN
====================================================== */

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$mensaje = "";
$tipoMensaje = "";
$tokenValido = false;
$medicoId = null;

if (!empty($token)) {

    $stmt = $conexion->prepare("
        SELECT pr.id, pr.medico_id, pr.expira, pr.usado
        FROM password_resets pr
        WHERE pr.token = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $reset = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($reset && (int)$reset['usado'] === 0 && strtotime($reset['expira']) > time()) {
        $tokenValido = true;
        $medicoId = (int)$reset['medico_id'];
    } else {
        $mensaje = "Este enlace de recuperación ya expiró o ya fue utilizado. Solicita uno nuevo.";
        $tipoMensaje = "error";
    }
} else {
    $mensaje = "Enlace de recuperación inválido.";
    $tipoMensaje = "error";
}

if ($tokenValido && $_SERVER['REQUEST_METHOD'] === 'POST') {

    validarTokenCSRF($_POST['csrf_token'] ?? '');

    $passNueva = trim($_POST['pass_nueva'] ?? '');
    $passConfirm = trim($_POST['pass_confirm'] ?? '');

    if (empty($passNueva) || empty($passConfirm)) {
        $mensaje = "Completa ambos campos de contraseña.";
        $tipoMensaje = "error";
    } elseif ($passNueva !== $passConfirm) {
        $mensaje = "Las contraseñas no coinciden.";
        $tipoMensaje = "error";
    } elseif (strlen($passNueva) < 8 || !preg_match('/[A-Z]/', $passNueva) || !preg_match('/[0-9]/', $passNueva)) {
        $mensaje = "La contraseña debe tener mínimo 8 caracteres, una mayúscula y un número.";
        $tipoMensaje = "error";
    } else {

        $hash = password_hash($passNueva, PASSWORD_DEFAULT);

        $update = $conexion->prepare("UPDATE medicos SET password = ? WHERE id = ?");
        $update->bind_param("si", $hash, $medicoId);
        $update->execute();
        $update->close();

        $marcar = $conexion->prepare("UPDATE password_resets SET usado = 1 WHERE token = ?");
        $marcar->bind_param("s", $token);
        $marcar->execute();
        $marcar->close();

        registrarLog("Contraseña restablecida vía enlace de recuperación. Médico ID: $medicoId", "INFO");

        redirigir("login.php?status=logout_success");
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer Contraseña | MediCore</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Poppins',sans-serif; }
        body {
            min-height:100vh; display:flex; justify-content:center; align-items:center;
            background: linear-gradient(rgba(2,22,12,.92), rgba(6,53,27,.92)),
                url('https://images.unsplash.com/photo-1584982751601-97dcc096659c?q=80&w=2070&auto=format&fit=crop');
            background-size:cover; background-position:center;
        }
        .box {
            width:100%; max-width:440px; background:rgba(255,255,255,.97); border-radius:26px;
            padding:42px; box-shadow:0 25px 50px rgba(0,0,0,.4);
        }
        .box h1 { color:#00843d; font-size:26px; margin-bottom:22px; text-align:center; }
        label { display:block; margin-bottom:8px; font-weight:700; color:#0f172a; font-size:14px; }
        input[type=password] {
            width:100%; padding:14px 16px; border-radius:12px; border:2px solid #cbd5e1;
            margin-bottom:20px; font-size:15px;
        }
        input[type=password]:focus { outline:none; border-color:#00843d; }
        button {
            width:100%; padding:15px; border:none; border-radius:12px; background:#00843d;
            color:#fff; font-weight:700; font-size:15px; cursor:pointer;
        }
        button:hover { background:#006c32; }
        .alerta { padding:14px 16px; border-radius:12px; margin-bottom:20px; font-size:14px; }
        .alerta.error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
        .volver { display:block; text-align:center; margin-top:20px; color:#00843d; font-weight:600; text-decoration:none; font-size:14px; }
    </style>
</head>
<body>
    <div class="box">
        <h1><i class="fas fa-lock"></i> Nueva contraseña</h1>

        <?php if ($mensaje): ?>
            <div class="alerta <?= $tipoMensaje === 'error' ? 'error' : 'info' ?>">
                <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>

        <?php if ($tokenValido): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generarTokenCSRF() ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                <label>Nueva contraseña</label>
                <input type="password" name="pass_nueva" required placeholder="Mínimo 8 caracteres, 1 mayúscula, 1 número">

                <label>Confirmar contraseña</label>
                <input type="password" name="pass_confirm" required placeholder="Repite la contraseña">

                <button type="submit"><i class="fas fa-check"></i> Restablecer contraseña</button>
            </form>
        <?php else: ?>
            <a href="recuperar_password.php" class="volver"><i class="fas fa-arrow-left"></i> Solicitar un nuevo enlace</a>
        <?php endif; ?>

        <a href="login.php" class="volver"><i class="fas fa-arrow-left"></i> Volver al inicio de sesión</a>
    </div>
</body>
</html>
