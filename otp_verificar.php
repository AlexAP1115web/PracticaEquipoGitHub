<?php
include("config.php");
require_once "security.php";
require_once __DIR__ . "/integrations/TwilioOTP.php";

/* ======================================================
   VERIFICACIÓN DEL SEGUNDO FACTOR (OTP POR SMS)
   Solo accesible si el usuario ya pasó correo+contraseña
   y quedó en estado "pre-autenticado" (login.php).
====================================================== */

if (empty($_SESSION['otp_medico_pendiente'])) {
    redirigir("login.php?error=denegado");
}

$medicoId = $_SESSION['otp_medico_pendiente'];
$nombre = $_SESSION['otp_nombre_pendiente'] ?? 'Especialista';
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    validarTokenCSRF($_POST['csrf_token'] ?? '');

    $codigoIngresado = limpiar(trim($_POST['codigo'] ?? ''));

    $stmt = $conexion->prepare("
        SELECT id, codigo, expira, usado
        FROM otp_codes
        WHERE medico_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $medicoId);
    $stmt->execute();
    $otp = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (
        $otp &&
        (int)$otp['usado'] === 0 &&
        strtotime($otp['expira']) > time() &&
        hash_equals($otp['codigo'], $codigoIngresado)
    ) {

        $marcar = $conexion->prepare("UPDATE otp_codes SET usado = 1 WHERE id = ?");
        $marcar->bind_param("i", $otp['id']);
        $marcar->execute();
        $marcar->close();

        $stmtMedico = $conexion->prepare("SELECT * FROM medicos WHERE id = ? LIMIT 1");
        $stmtMedico->bind_param("i", $medicoId);
        $stmtMedico->execute();
        $medico = $stmtMedico->get_result()->fetch_assoc();
        $stmtMedico->close();

        unset($_SESSION['otp_medico_pendiente'], $_SESSION['otp_nombre_pendiente']);

        session_regenerate_id(true);

        $_SESSION['medico'] = $medico['id'];
        $_SESSION['medico_id'] = $medico['id'];
        $_SESSION['nombre_medico'] = $medico['nombre'];
        $_SESSION['ultimo_acceso'] = time();
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        registrarLog("Segundo factor (OTP) verificado correctamente para médico ID $medicoId.", "INFO");

        redirigir("index.php");
    } else {

        $error = "Código incorrecto o expirado.";
        registrarLog("Código OTP incorrecto o expirado para médico ID $medicoId.", "WARNING");
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación en dos pasos | MediCore</title>
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
            width:100%; max-width:420px; background:rgba(255,255,255,.97); border-radius:26px;
            padding:42px; box-shadow:0 25px 50px rgba(0,0,0,.4); text-align:center;
        }
        .box h1 { color:#00843d; font-size:24px; margin-bottom:10px; }
        .box p { color:#64748b; font-size:14px; margin-bottom:26px; }
        input[type=text] {
            width:100%; padding:16px; border-radius:12px; border:2px solid #cbd5e1;
            margin-bottom:20px; font-size:22px; text-align:center; letter-spacing:8px;
        }
        input[type=text]:focus { outline:none; border-color:#00843d; }
        button {
            width:100%; padding:15px; border:none; border-radius:12px; background:#00843d;
            color:#fff; font-weight:700; font-size:15px; cursor:pointer;
        }
        button:hover { background:#006c32; }
        .alerta { padding:14px 16px; border-radius:12px; margin-bottom:20px; font-size:14px; background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
        .volver { display:block; text-align:center; margin-top:20px; color:#00843d; font-weight:600; text-decoration:none; font-size:14px; }
    </style>
</head>
<body>
    <div class="box">
        <h1><i class="fas fa-mobile-screen-button"></i> Verificación en dos pasos</h1>
        <p>Hola Dr(a). <?= htmlspecialchars($nombre) ?>, enviamos un código de 6 dígitos por SMS. Ingrésalo para continuar.</p>

        <?php if ($error): ?>
            <div class="alerta"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= generarTokenCSRF() ?>">
            <input type="text" name="codigo" maxlength="6" inputmode="numeric" placeholder="000000" required autofocus>
            <button type="submit"><i class="fas fa-check"></i> Verificar código</button>
        </form>

        <a href="login.php" class="volver"><i class="fas fa-arrow-left"></i> Cancelar e iniciar de nuevo</a>
    </div>
</body>
</html>
