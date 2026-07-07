<?php
include("config.php");
require_once "security.php";
require_once __DIR__ . "/integrations/SendGridMailer.php";

/* ======================================================
   PÁGINA PÚBLICA: SOLICITAR RECUPERACIÓN DE CONTRASEÑA
   No requiere sesión iniciada (es para quien no puede entrar).
====================================================== */

$mensaje = "";
$tipoMensaje = ""; // exito | error | info

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    validarTokenCSRF($_POST['csrf_token'] ?? '');

    $correo = limpiar(trim($_POST['correo'] ?? ''));

    if (empty($correo) || !validarEmail($correo)) {

        $mensaje = "Ingresa un correo electrónico válido.";
        $tipoMensaje = "error";
    } else {

        $stmt = $conexion->prepare("SELECT id, nombre, correo FROM medicos WHERE correo = ? LIMIT 1");
        $stmt->bind_param("s", $correo);
        $stmt->execute();
        $medico = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Mensaje genérico siempre igual, exista o no el correo,
        // para no revelar qué cuentas existen en el sistema (OWASP).
        $mensaje = "Si el correo está registrado en MediCore, recibirás un enlace de recuperación en unos minutos.";
        $tipoMensaje = "info";

        if ($medico) {

            $token = tokenAleatorio(32);
            $expira = date("Y-m-d H:i:s", time() + 3600); // 1 hora

            $stmtInsert = $conexion->prepare("
                INSERT INTO password_resets (medico_id, token, expira)
                VALUES (?, ?, ?)
            ");
            $stmtInsert->bind_param("iss", $medico['id'], $token, $expira);

            if ($stmtInsert->execute()) {

                $urlBase = rtrim(apiConfig('APP_URL', ''), '/');
                $urlReset = ($urlBase ?: '') . "/restablecer_password.php?token=" . urlencode($token);

                $html = "
                    <div style='font-family:Arial,sans-serif;max-width:520px;margin:auto;'>
                        <h2 style='color:#00843d;'>MediCore Professional System</h2>
                        <p>Hola Dr(a). " . htmlspecialchars($medico['nombre']) . ",</p>
                        <p>Recibimos una solicitud para restablecer tu contraseña. Este enlace es válido por 1 hora:</p>
                        <p><a href='" . htmlspecialchars($urlReset) . "' style='background:#00843d;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;'>Restablecer contraseña</a></p>
                        <p style='color:#64748b;font-size:13px;'>Si tú no solicitaste este cambio, ignora este correo. Tu contraseña actual seguirá funcionando.</p>
                    </div>
                ";

                enviarCorreoSendGrid($medico['correo'], $medico['nombre'], "Recuperación de contraseña - MediCore", $html);

                registrarLog("Solicitud de recuperación de contraseña para: " . $correo, "INFO");
            }

            $stmtInsert->close();
        } else {
            registrarLog("Intento de recuperación con correo no registrado: " . $correo, "WARNING");
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Contraseña | MediCore</title>
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
        .box h1 { color:#00843d; font-size:26px; margin-bottom:8px; text-align:center; }
        .box p.subtitle { color:#64748b; text-align:center; margin-bottom:26px; font-size:14px; }
        label { display:block; margin-bottom:8px; font-weight:700; color:#0f172a; font-size:14px; }
        input[type=email] {
            width:100%; padding:14px 16px; border-radius:12px; border:2px solid #cbd5e1;
            margin-bottom:20px; font-size:15px;
        }
        input[type=email]:focus { outline:none; border-color:#00843d; }
        button {
            width:100%; padding:15px; border:none; border-radius:12px; background:#00843d;
            color:#fff; font-weight:700; font-size:15px; cursor:pointer;
        }
        button:hover { background:#006c32; }
        .alerta { padding:14px 16px; border-radius:12px; margin-bottom:20px; font-size:14px; }
        .alerta.error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
        .alerta.info { background:#dff5e8; color:#00602b; border:1px solid #b7e9cd; }
        .volver { display:block; text-align:center; margin-top:20px; color:#00843d; font-weight:600; text-decoration:none; font-size:14px; }
    </style>
</head>
<body>
    <div class="box">
        <h1><i class="fas fa-key"></i> Recuperar contraseña</h1>
        <p class="subtitle">Te enviaremos un enlace para restablecer tu acceso a MediCore.</p>

        <?php if ($mensaje): ?>
            <div class="alerta <?= $tipoMensaje === 'error' ? 'error' : 'info' ?>">
                <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= generarTokenCSRF() ?>">
            <label>Correo institucional</label>
            <input type="email" name="correo" placeholder="doctor@medicore.com" required>
            <button type="submit"><i class="fas fa-paper-plane"></i> Enviar enlace</button>
        </form>

        <a href="login.php" class="volver"><i class="fas fa-arrow-left"></i> Volver al inicio de sesión</a>
    </div>
</body>
</html>
