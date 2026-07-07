<?php
include("config.php");
require_once "security.php";
require_once __DIR__ . "/integrations/TwilioOTP.php";

/* ======================================================
   LIMPIAR SESIÓN SI VIENE DE EXPIRACIÓN / SEGURIDAD
====================================================== */
if (
    $_SERVER['REQUEST_METHOD'] === 'GET' &&
    isset($_GET['error']) &&
    in_array($_GET['error'], ['expirada', 'denegado', 'csrf', 'seguridad', 'hijacking'])
) {
    unset($_SESSION['medico']);
    unset($_SESSION['medico_id']);
    unset($_SESSION['nombre_medico']);
    unset($_SESSION['ultimo_acceso']);
    unset($_SESSION['user_agent']);
}

/* ======================================================
   VALIDACIÓN DE SESIÓN
====================================================== */
if (isset($_SESSION['medico']) && !empty($_SESSION['medico'])) {
    redirigir("index.php");
}

$error = "";
$exito = "";
$correo_value = "";

/* ======================================================
   MENSAJES DEL SISTEMA
====================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['error'])) {

    switch ($_GET['error']) {

        case 'expirada':
            $error = "Tu sesión expiró por inactividad. Inicia sesión nuevamente.";
            break;

        case 'denegado':
            $error = "Debes iniciar sesión para acceder.";
            break;

        case 'seguridad':
        case 'hijacking':
            $error = "La sesión fue cerrada por seguridad. Inicia sesión nuevamente.";
            break;

        case 'csrf':
            $error = "Solicitud inválida detectada.";
            break;

        default:
            $error = "Ocurrió un error inesperado.";
            break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['status']) && $_GET['status'] == 'logout_success') {
    $exito = "Sesión cerrada correctamente.";
}

/* ======================================================
   RATE LIMITING
====================================================== */
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
}

if (!isset($_SESSION['lockout_time'])) {
    $_SESSION['lockout_time'] = 0;
}

if ($_SESSION['login_attempts'] >= 3) {

    $tiempo_bloqueo = time() - $_SESSION['lockout_time'];

    if ($tiempo_bloqueo < 60) {

        $restante = 60 - $tiempo_bloqueo;
        $error = "Demasiados intentos fallidos. Espera {$restante} segundos.";
    } else {

        $_SESSION['login_attempts'] = 0;
        $_SESSION['lockout_time'] = 0;
    }
}

/* ======================================================
   LOGIN
====================================================== */
if (isset($_POST['login']) && empty($error)) {

    validarTokenCSRF($_POST['csrf_token'] ?? '');

    $correo = limpiar($_POST['correo'] ?? '');
    $password = $_POST['password'] ?? '';
    $correo_value = $correo;

    if (empty($correo) || empty($password)) {

        $error = "Completa todos los campos.";
    } else {

        $stmt = $conexion->prepare("
            SELECT *
            FROM medicos
            WHERE correo = ?
            LIMIT 1
        ");

        $stmt->bind_param("s", $correo);

        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows === 1) {

            $medico = $result->fetch_assoc();

            if (password_verify($password, $medico['password'])) {

                $_SESSION['login_attempts'] = 0;
                $_SESSION['lockout_time'] = 0;

                /* ==========================================
                   SEGUNDO FACTOR (OTP vía Twilio) - OPCIONAL
                   Solo se activa si OTP_ENABLED=true en .env Y
                   el médico tiene teléfono registrado. Si no,
                   el login continúa exactamente como antes.
                ========================================== */

                if (otpHabilitado() && !empty($medico['telefono'])) {

                    $codigo = generarCodigoOTP();
                    $expiraOtp = date("Y-m-d H:i:s", time() + 300); // 5 minutos

                    $stmtOtp = $conexion->prepare("
                        INSERT INTO otp_codes (medico_id, codigo, expira)
                        VALUES (?, ?, ?)
                    ");
                    $stmtOtp->bind_param("iss", $medico['id'], $codigo, $expiraOtp);
                    $stmtOtp->execute();
                    $stmtOtp->close();

                    enviarOTPTwilio($medico['telefono'], $codigo);

                    // Sesión "pre-autenticada": aún NO se marca $_SESSION['medico'],
                    // por lo que verificarSesion() seguirá bloqueando el acceso
                    // hasta que se valide el código en otp_verificar.php.
                    $_SESSION['otp_medico_pendiente'] = $medico['id'];
                    $_SESSION['otp_nombre_pendiente'] = $medico['nombre'];

                    registrarLog("OTP enviado para segundo factor: " . $correo, "INFO");

                    redirigir("otp_verificar.php");
                }

                session_regenerate_id(true);

                $_SESSION['medico'] = $medico['id'];
                $_SESSION['medico_id'] = $medico['id'];
                $_SESSION['nombre_medico'] = $medico['nombre'];
                $_SESSION['ultimo_acceso'] = time();
                $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                if (function_exists('registrarLog')) {

                    registrarLog(
                        "Inicio de sesión correcto: " . $correo,
                        "INFO"
                    );
                }

                redirigir("index.php");
            } else {

                $_SESSION['login_attempts']++;
                $_SESSION['lockout_time'] = time();

                $error = "Contraseña incorrecta.";

                if (function_exists('registrarLog')) {

                    registrarLog(
                        "Contraseña incorrecta: " . $correo,
                        "WARNING"
                    );
                }
            }
        } else {

            $_SESSION['login_attempts']++;
            $_SESSION['lockout_time'] = time();

            $error = "El correo no existe en MediCore.";
        }

        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>
        MediCore | Acceso Médico
    </title>

    <!-- ======================================================
         FUENTES
    ====================================================== -->

    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {

            --primary: #0ea5e9;
            --primary-dark: #0369a1;

            --navy: #0f172a;
            --navy-dark: #020617;

            --white: #ffffff;

            --border: #cbd5e1;

            --danger: #dc2626;
            --success: #059669;

            --gray: #64748b;
            --light: #f8fafc;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {

            min-height: 100vh;

            background:
                linear-gradient(rgba(2, 6, 23, .92),
                    rgba(15, 23, 42, .95)),
                url('https://images.unsplash.com/photo-1586773860418-d37222d8fce3?q=80&w=2070&auto=format&fit=crop');

            background-size: cover;
            background-position: center;

            display: flex;
            justify-content: center;
            align-items: center;

            overflow: hidden;

            position: relative;
        }

        /* ======================================================
           EFECTOS
        ====================================================== */

        .circle {

            position: absolute;

            border-radius: 50%;

            filter: blur(90px);

            opacity: .25;

            animation: float 8s infinite alternate;
        }

        .circle.one {

            width: 320px;
            height: 320px;

            background: #0ea5e9;

            top: -120px;
            left: -120px;
        }

        .circle.two {

            width: 260px;
            height: 260px;

            background: #38bdf8;

            bottom: -100px;
            right: -80px;
        }

        @keyframes float {

            from {
                transform: translateY(0px);
            }

            to {
                transform: translateY(20px);
            }
        }

        /* ======================================================
           LOGIN BOX
        ====================================================== */

        .login-container {

            width: 100%;
            max-width: 460px;

            background:
                rgba(255, 255, 255, .97);

            backdrop-filter: blur(14px);

            border-radius: 28px;

            padding: 45px;

            position: relative;

            z-index: 10;

            box-shadow:
                0 25px 50px rgba(0, 0, 0, .45);

            border: 1px solid rgba(255, 255, 255, .5);
        }

        .logo {

            width: 95px;

            display: block;

            margin: 0 auto 20px;

            border-radius: 24px;

            border: 4px solid white;

            box-shadow:
                0 10px 25px rgba(2, 132, 199, .25);
        }

        .title {

            text-align: center;

            color: var(--navy);

            font-size: 32px;

            font-weight: 800;

            margin-bottom: 6px;
        }

        .subtitle {

            text-align: center;

            color: var(--gray);

            margin-bottom: 35px;

            font-size: 15px;
        }

        /* ======================================================
           ALERTAS
        ====================================================== */

        .alert {

            padding: 15px 18px;

            border-radius: 14px;

            margin-bottom: 25px;

            font-size: 14px;

            font-weight: 600;

            display: flex;

            align-items: center;

            gap: 12px;
        }

        .alert-error {

            background: #fef2f2;

            color: var(--danger);

            border: 1px solid #fecaca;
        }

        .alert-success {

            background: #ecfdf5;

            color: var(--success);

            border: 1px solid #a7f3d0;
        }

        /* ======================================================
           FORM
        ====================================================== */

        .form-group {

            margin-bottom: 22px;
        }

        .form-group label {

            display: block;

            margin-bottom: 8px;

            color: var(--navy);

            font-size: 14px;

            font-weight: 700;
        }

        .input-box {

            position: relative;
        }

        .input-box i {

            position: absolute;

            left: 18px;
            top: 50%;

            transform: translateY(-50%);

            color: #94a3b8;

            font-size: 17px;
        }

        .input-box input {

            width: 100%;

            padding: 16px 18px 16px 50px;

            border-radius: 14px;

            border: 2px solid var(--border);

            background: var(--light);

            font-size: 15px;

            transition: .3s;
        }

        .input-box input:focus {

            outline: none;

            border-color: var(--primary);

            background: white;

            box-shadow:
                0 0 0 4px rgba(14, 165, 233, .15);
        }

        .toggle-password {

            position: absolute !important;

            right: 18px !important;
            left: auto !important;

            cursor: pointer;
        }

        /* ======================================================
           BUTTON
        ====================================================== */

        .btn-login {

            width: 100%;

            border: none;

            background:
                linear-gradient(135deg,
                    var(--primary),
                    var(--primary-dark));

            color: white;

            padding: 17px;

            border-radius: 14px;

            font-size: 16px;

            font-weight: 700;

            cursor: pointer;

            transition: .3s;

            display: flex;

            justify-content: center;

            align-items: center;

            gap: 10px;

            box-shadow:
                0 15px 25px rgba(2, 132, 199, .25);
        }

        .btn-login:hover {

            transform: translateY(-3px);

            box-shadow:
                0 18px 35px rgba(2, 132, 199, .35);
        }

        /* ======================================================
           LINKS
        ====================================================== */

        .extra {

            margin-top: 20px;

            text-align: right;
        }

        .extra a {

            color: var(--primary);

            text-decoration: none;

            font-size: 14px;

            font-weight: 600;
        }

        .extra a:hover {

            text-decoration: underline;
        }

        /* ======================================================
           GOOGLE OAUTH2
        ====================================================== */

        .oauth-divider {
            text-align: center;
            color: var(--gray);
            font-size: 13px;
            margin: 22px 0 16px;
            position: relative;
        }

        .oauth-divider::before,
        .oauth-divider::after {
            content: "";
            position: absolute;
            top: 50%;
            width: 38%;
            height: 1px;
            background: var(--border);
        }

        .oauth-divider::before { left: 0; }
        .oauth-divider::after { right: 0; }

        .btn-google {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px;
            border-radius: 14px;
            border: 2px solid var(--border);
            background: white;
            color: var(--navy);
            font-weight: 700;
            font-size: 14px;
            text-decoration: none;
            transition: .3s;
        }

        .btn-google:hover {
            border-color: var(--primary);
            background: var(--light);
        }

        .btn-google i {
            color: #ea4335;
        }

        /* ======================================================
           FOOTER
        ====================================================== */

        .footer {

            margin-top: 35px;

            text-align: center;

            color: #94a3b8;

            font-size: 13px;
        }

        /* ======================================================
           RESPONSIVE
        ====================================================== */

        @media(max-width:550px) {

            body {
                padding: 20px;
            }

            .login-container {
                padding: 35px 25px;
            }

            .title {
                font-size: 28px;
            }
        }
    </style>

</head>

<body>

    <div class="circle one"></div>

    <div class="circle two"></div>

    <!-- ======================================================
         LOGIN
    ====================================================== -->

    <div class="login-container">

        <img
            src="assets/Medicore.png"
            alt="Logo MediCore"
            class="logo">

        <h1 class="title">
            MediCore
        </h1>

        <p class="subtitle">
            Plataforma Profesional de Gestión Clínica
        </p>

        <!-- ======================================================
             ALERTAS
        ====================================================== -->

        <?php if ($error): ?>

            <div class="alert alert-error">

                <i class="fas fa-exclamation-triangle"></i>

                <?= htmlspecialchars($error) ?>

            </div>

        <?php endif; ?>

        <?php if ($exito): ?>

            <div class="alert alert-success">

                <i class="fas fa-check-circle"></i>

                <?= htmlspecialchars($exito) ?>

            </div>

        <?php endif; ?>

        <!-- ======================================================
             FORMULARIO
        ====================================================== -->

        <form
            method="POST"
            action="login.php"
            id="loginForm">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= generarTokenCSRF() ?>">

            <div class="form-group">

                <label>
                    Correo Institucional
                </label>

                <div class="input-box">

                    <i class="fas fa-envelope"></i>

                    <input
                        type="email"
                        name="correo"
                        placeholder="doctor@medicore.com"
                        value="<?= htmlspecialchars($correo_value) ?>"
                        required>

                </div>

            </div>

            <div class="form-group">

                <label>
                    Contraseña
                </label>

                <div class="input-box">

                    <i class="fas fa-lock"></i>

                    <input
                        type="password"
                        name="password"
                        id="password"
                        placeholder="••••••••"
                        required>

                    <i
                        class="fas fa-eye toggle-password"
                        onclick="mostrarPassword()">
                    </i>

                </div>

            </div>

            <button
                type="submit"
                name="login"
                class="btn-login"
                id="btnLogin">

                <i class="fas fa-sign-in-alt"></i>

                Ingresar al Sistema

            </button>

            <div class="extra">

                <a href="recuperar_password.php">
                    ¿Olvidaste tu contraseña?
                </a>

            </div>

        </form>

        <?php if (apiHabilitada(['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'])): ?>
            <div class="oauth-divider">o continúa con</div>
            <a href="oauth_google.php" class="btn-google">
                <i class="fab fa-google"></i>
                Iniciar sesión con Google
            </a>
        <?php endif; ?>

        <div class="footer">

            © <?= date("Y") ?> MediCore Professional System

        </div>

    </div>

    <!-- ======================================================
         SCRIPT
    ====================================================== -->

    <script>
        function mostrarPassword() {

            const input =
                document.getElementById("password");

            const icon =
                document.querySelector(".toggle-password");

            if (input.type === "password") {

                input.type = "text";

                icon.classList.remove("fa-eye");

                icon.classList.add("fa-eye-slash");

            } else {

                input.type = "password";

                icon.classList.remove("fa-eye-slash");

                icon.classList.add("fa-eye");
            }
        }
    </script>

</body>

</html>