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

    // A diferencia del resto de formularios, aquí no se corta la página
    // con un 403: si el token ya no sirve (la pestaña llevaba horas
    // abierta, o se volvió con el botón "atrás" después de cerrar
    // sesión) se muestra el aviso dentro del propio formulario y el
    // médico simplemente vuelve a intentarlo.
    if (!tokenCSRFValido($_POST['csrf_token'] ?? '')) {

        registrarLog("Formulario de acceso vencido; se pide reintentar.", "WARNING");

        $error = "El formulario de acceso expiró. Vuelve a escribir tus datos.";
        $correo_value = limpiar($_POST['correo'] ?? '');
    }
}

if (isset($_POST['login']) && empty($error)) {

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

                    $otpEnviado = enviarOTPTwilio($medico['telefono'], $codigo);

                    // Si Twilio no pudo enviar el SMS (límite diario, número
                    // no verificado, cuenta caída, etc.) NO dejamos al médico
                    // atorado esperando un código que nunca va a llegar: se
                    // deja continuar con un solo factor (correo + contraseña),
                    // igual que si OTP no estuviera habilitado.
                    if ($otpEnviado) {

                        // Sesión "pre-autenticada": aún NO se marca $_SESSION['medico'],
                        // por lo que verificarSesion() seguirá bloqueando el acceso
                        // hasta que se valide el código en otp_verificar.php.
                        $_SESSION['otp_medico_pendiente'] = $medico['id'];
                        $_SESSION['otp_nombre_pendiente'] = $medico['nombre'];

                        registrarLog("OTP enviado para segundo factor: " . $correo, "INFO");

                        redirigir("otp_verificar.php");
                    }

                    registrarLog("No se pudo enviar OTP (Twilio falló); se permite acceso con un solo factor: " . $correo, "WARNING");
                }

                session_regenerate_id(true);

                $_SESSION['medico'] = $medico['id'];
                $_SESSION['medico_id'] = $medico['id'];
                $_SESSION['nombre_medico'] = $medico['nombre'];
                $_SESSION['ultimo_acceso'] = time();
                $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

                rotarTokenCSRF();

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión | MediCore</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --pino: #0E3B2C;
            --pino-2: #134A38;
            --verde: #00843D;
            --verde-osc: #006B31;
            --menta: #E4F1E8;
            --papel: #F4F7F5;
            --linea: #D9E2DC;
            --tinta: #15241D;
            --gris: #5E6E66;
            --alerta: #B42318;
            --alerta-bg: #FEF3F2;
            --ok: #067647;
            --ok-bg: #ECFDF3;
            --precaucion: #A15C07;
            --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Onest', system-ui, -apple-system, 'Segoe UI', sans-serif;
            color: var(--tinta);
            background: #fff;
            -webkit-font-smoothing: antialiased;
        }

        .acceso {
            display: grid;
            grid-template-columns: minmax(0, 46fr) minmax(0, 54fr);
            min-height: 100vh;
            min-height: 100dvh;
        }

        /* ---------- Panel de contexto ---------- */

        .contexto {
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 40px;
            padding: 40px 48px;
            background: var(--pino);
            color: #fff;
            overflow: hidden;
            isolation: isolate;
        }

        .marca {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #fff;
            text-decoration: none;
            font-size: 18px;
            font-weight: 600;
            letter-spacing: -0.01em;
        }

        .marca span.logo {
            display: grid;
            place-items: center;
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: #fff;
        }

        .marca img {
            width: 36px;
            height: 36px;
            object-fit: contain;
        }

        .contexto-texto {
            max-width: 30rem;
        }

        .contexto h2 {
            font-size: clamp(28px, 2.6vw, 38px);
            line-height: 1.15;
            font-weight: 600;
            letter-spacing: -0.02em;
            text-wrap: balance;
        }

        .contexto-texto p {
            margin-top: 16px;
            font-size: 16px;
            line-height: 1.6;
            color: rgba(228, 241, 232, .78);
            max-width: 28rem;
        }

        /* Línea de electrocardiograma: se dibuja una sola vez al cargar */
        .ecg {
            position: absolute;
            left: 0;
            right: 0;
            top: 46%;
            width: 100%;
            height: 120px;
            z-index: -1;
            pointer-events: none;
        }

        .ecg path {
            fill: none;
            stroke: #2FA86A;
            stroke-width: 2;
            stroke-linejoin: round;
            stroke-linecap: round;
            opacity: .45;
            stroke-dasharray: 1;
            stroke-dashoffset: 0;
            animation: trazo 1.6s var(--ease-out) .2s both;
        }

        @keyframes trazo {
            from { stroke-dashoffset: 1; }
            to { stroke-dashoffset: 0; }
        }

        /* Ejemplo del asistente de medicamentos */
        .muestra {
            max-width: 26rem;
            background: #fff;
            color: var(--tinta);
            border-radius: 14px;
            padding: 18px;
            border: 1px solid rgba(255, 255, 255, .2);
        }

        .muestra-cab {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 600;
            color: var(--gris);
        }

        .muestra-cab i {
            display: grid;
            place-items: center;
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: var(--menta);
            color: var(--verde);
            font-size: 13px;
        }

        .muestra-pregunta {
            margin: 14px 0 10px auto;
            width: fit-content;
            max-width: 90%;
            padding: 10px 14px;
            border-radius: 12px 12px 4px 12px;
            background: var(--pino);
            color: #fff;
            font-size: 14px;
            line-height: 1.45;
        }

        .muestra-respuesta {
            display: flex;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 10px;
            background: var(--alerta-bg);
            border: 1px solid #FECDCA;
            font-size: 14px;
            line-height: 1.5;
        }

        .muestra-respuesta i {
            color: var(--alerta);
            margin-top: 3px;
        }

        .muestra-respuesta strong {
            color: var(--alerta);
            font-weight: 600;
        }

        .muestra-fuente {
            margin-top: 10px;
            font-size: 12px;
            color: var(--gris);
        }

        .contexto-pie {
            font-size: 13px;
            color: rgba(228, 241, 232, .6);
        }

        /* ---------- Formulario ---------- */

        .formulario {
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 48px 32px;
        }

        .formulario-caja {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
        }

        .formulario h1 {
            font-size: 30px;
            line-height: 1.2;
            font-weight: 600;
            letter-spacing: -0.02em;
        }

        .formulario-intro {
            margin-top: 8px;
            color: var(--gris);
            font-size: 15px;
            line-height: 1.55;
        }

        .aviso {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            margin-top: 24px;
            padding: 12px 14px;
            border-radius: 9px;
            font-size: 14px;
            line-height: 1.5;
        }

        .aviso i {
            margin-top: 3px;
        }

        .aviso-error {
            background: var(--alerta-bg);
            border: 1px solid #FECDCA;
            color: var(--alerta);
        }

        .aviso-exito {
            background: var(--ok-bg);
            border: 1px solid #ABEFC6;
            color: var(--ok);
        }

        form {
            margin-top: 28px;
        }

        .campo + .campo {
            margin-top: 18px;
        }

        .campo-cab {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 12px;
            margin-bottom: 8px;
        }

        .campo label {
            display: block;
            font-size: 14px;
            font-weight: 500;
        }

        .campo-cab a {
            font-size: 14px;
            color: var(--verde-osc);
            font-weight: 500;
            text-decoration: none;
            border-radius: 4px;
        }

        .entrada {
            position: relative;
        }

        .entrada > i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gris);
            font-size: 15px;
            pointer-events: none;
        }

        .entrada input {
            width: 100%;
            height: 48px;
            padding: 0 14px 0 42px;
            border: 1px solid var(--linea);
            border-radius: 9px;
            background: #fff;
            font: inherit;
            font-size: 16px;
            color: var(--tinta);
            transition: border-color 150ms ease, box-shadow 150ms ease;
        }

        .entrada input::placeholder {
            color: #95A39C;
        }

        .entrada input:focus {
            outline: none;
            border-color: var(--verde);
            box-shadow: 0 0 0 3px rgba(0, 132, 61, .18);
        }

        .entrada input[type="password"],
        .entrada input.con-boton {
            padding-right: 52px;
        }

        .ver-clave {
            position: absolute;
            right: 2px;
            top: 2px;
            width: 44px;
            height: 44px;
            display: grid;
            place-items: center;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: var(--gris);
            cursor: pointer;
            font-size: 15px;
        }

        .bloq-mayus {
            display: none;
            margin-top: 8px;
            font-size: 13px;
            color: var(--precaucion);
        }

        .bloq-mayus.visible {
            display: block;
        }

        .btn-entrar {
            width: 100%;
            height: 48px;
            margin-top: 26px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            border: 0;
            border-radius: 9px;
            background: var(--verde);
            color: #fff;
            font: inherit;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 150ms ease, transform 120ms var(--ease-out);
        }

        .btn-entrar:active {
            transform: scale(.97);
        }

        .btn-entrar[aria-busy="true"] {
            background: var(--verde-osc);
            cursor: progress;
        }

        .separador {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 22px 0;
            font-size: 13px;
            color: var(--gris);
        }

        .separador::before,
        .separador::after {
            content: "";
            flex: 1;
            height: 1px;
            background: var(--linea);
        }

        .btn-google {
            width: 100%;
            height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            border: 1px solid var(--linea);
            border-radius: 9px;
            background: #fff;
            color: var(--tinta);
            font-size: 15px;
            font-weight: 500;
            text-decoration: none;
            transition: background-color 150ms ease, transform 120ms var(--ease-out);
        }

        .btn-google:active {
            transform: scale(.97);
        }

        .volver {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 28px;
            font-size: 14px;
            color: var(--gris);
            text-decoration: none;
            border-radius: 4px;
        }

        a:focus-visible,
        button:focus-visible {
            outline: 2px solid var(--verde);
            outline-offset: 2px;
        }

        @media (hover: hover) and (pointer: fine) {
            .btn-entrar:hover { background: var(--verde-osc); }
            .btn-google:hover { background: var(--papel); }
            .ver-clave:hover { color: var(--tinta); }
            .campo-cab a:hover,
            .volver:hover { text-decoration: underline; text-underline-offset: 3px; }
            .volver:hover { color: var(--tinta); }
        }

        /* ---------- Pantallas medianas y móviles ---------- */

        @media (max-width: 1100px) {
            .contexto { padding: 36px; }
        }

        @media (max-width: 900px) {
            .acceso {
                grid-template-columns: 1fr;
                grid-template-rows: auto 1fr;
            }

            .contexto {
                padding: 18px 24px 34px;
                gap: 0;
            }

            .contexto-texto,
            .muestra,
            .contexto-pie {
                display: none;
            }

            .ecg {
                top: auto;
                bottom: 0;
                height: 44px;
            }

            .formulario {
                justify-content: flex-start;
                padding: 36px 24px 48px;
            }

            .formulario h1 {
                font-size: 26px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .ecg path { animation: none; }
            .btn-entrar,
            .btn-google,
            .entrada input { transition: none; }
            .btn-entrar:active,
            .btn-google:active { transform: none; }
        }
    </style>

    <?php include __DIR__ . "/pwa_head.php"; ?>
</head>

<body>

    <main class="acceso">

        <!-- Panel de contexto -->
        <aside class="contexto" aria-label="Acerca de MediCore">

            <a class="marca" href="home.php">
                <span class="logo"><img src="assets/Medicore.png" alt=""></span>
                MediCore
            </a>

            <svg class="ecg" viewBox="0 0 600 120" preserveAspectRatio="none" aria-hidden="true">
                <path pathLength="1" d="M0 70 H190 L204 70 L214 52 L224 70 L238 70 L250 14 L264 108 L276 70 L292 70 L302 60 L314 70 H600" />
            </svg>

            <div class="contexto-texto">
                <h2>Tu consulta, ordenada antes de que entre el paciente.</h2>
                <p>Expedientes, citas, reportes y un asistente que revisa alergias y efectos secundarios con la información oficial de CIMA.</p>
            </div>

            <div class="muestra" aria-hidden="true">
                <div class="muestra-cab">
                    <i class="fas fa-robot"></i>
                    Asistente de medicamentos
                </div>
                <p class="muestra-pregunta">¿Puedo recetarle amoxicilina a Ana Lucía?</p>
                <div class="muestra-respuesta">
                    <i class="fas fa-triangle-exclamation"></i>
                    <p><strong>Alergia registrada: penicilina.</strong> La amoxicilina pertenece a ese grupo; elige otra opción.</p>
                </div>
                <p class="muestra-fuente">Fuente: ficha técnica en CIMA (AEMPS)</p>
            </div>

            <p class="contexto-pie">© <?= date("Y") ?> MediCore Professional System</p>
        </aside>

        <!-- Formulario de acceso -->
        <section class="formulario">
            <div class="formulario-caja">

                <h1>Iniciar sesión</h1>
                <p class="formulario-intro">Usa el correo con el que te registraron en MediCore.</p>

                <?php if ($error): ?>
                    <div class="aviso aviso-error" role="alert">
                        <i class="fas fa-circle-exclamation"></i>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($exito): ?>
                    <div class="aviso aviso-exito" role="status">
                        <i class="fas fa-circle-check"></i>
                        <span><?= htmlspecialchars($exito) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="login.php" id="loginForm" novalidate>

                    <input type="hidden" name="csrf_token" value="<?= generarTokenCSRF() ?>">
                    <!-- Asegura que el formulario se procese aunque el botón quede deshabilitado al enviar -->
                    <input type="hidden" name="login" value="1">

                    <div class="campo">
                        <div class="campo-cab">
                            <label for="correo">Correo institucional</label>
                        </div>
                        <div class="entrada">
                            <i class="fas fa-envelope" aria-hidden="true"></i>
                            <input
                                type="email"
                                id="correo"
                                name="correo"
                                placeholder="doctor@medicore.com"
                                autocomplete="username"
                                value="<?= htmlspecialchars($correo_value) ?>"
                                required
                                <?= $correo_value === '' ? 'autofocus' : '' ?>>
                        </div>
                    </div>

                    <div class="campo">
                        <div class="campo-cab">
                            <label for="password">Contraseña</label>
                            <a href="recuperar_password.php">¿La olvidaste?</a>
                        </div>
                        <div class="entrada">
                            <i class="fas fa-lock" aria-hidden="true"></i>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                placeholder="Tu contraseña"
                                autocomplete="current-password"
                                class="con-boton"
                                required
                                <?= $correo_value !== '' ? 'autofocus' : '' ?>>
                            <button
                                type="button"
                                class="ver-clave"
                                onclick="mostrarPassword()"
                                aria-label="Mostrar contraseña"
                                aria-pressed="false">
                                <i class="fas fa-eye toggle-password" aria-hidden="true"></i>
                            </button>
                        </div>
                        <p class="bloq-mayus" id="bloqMayus" aria-live="polite">Bloq Mayús está activado.</p>
                    </div>

                    <button type="submit" name="login" class="btn-entrar" id="btnLogin">
                        Ingresar
                    </button>
                </form>

                <?php if (apiHabilitada(['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'])): ?>
                    <div class="separador">o</div>
                    <a href="oauth_google.php" class="btn-google">
                        <i class="fab fa-google" aria-hidden="true"></i>
                        Continuar con Google
                    </a>
                <?php endif; ?>

                <a class="volver" href="home.php">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                    Volver al portal
                </a>
            </div>
        </section>
    </main>

    <script>
        function mostrarPassword() {
            const input = document.getElementById("password");
            const boton = document.querySelector(".ver-clave");
            const icon = document.querySelector(".toggle-password");
            const visible = input.type === "password";

            input.type = visible ? "text" : "password";
            icon.classList.toggle("fa-eye", !visible);
            icon.classList.toggle("fa-eye-slash", visible);
            boton.setAttribute("aria-pressed", visible ? "true" : "false");
            boton.setAttribute("aria-label", visible ? "Ocultar contraseña" : "Mostrar contraseña");
            input.focus();
        }

        (function () {
            const form = document.getElementById("loginForm");
            const boton = document.getElementById("btnLogin");
            const clave = document.getElementById("password");
            const aviso = document.getElementById("bloqMayus");

            // Aviso de Bloq Mayús mientras se escribe la contraseña
            ["keydown", "keyup"].forEach(function (evento) {
                clave.addEventListener(evento, function (e) {
                    if (typeof e.getModifierState === "function") {
                        aviso.classList.toggle("visible", e.getModifierState("CapsLock"));
                    }
                });
            });
            clave.addEventListener("blur", function () {
                aviso.classList.remove("visible");
            });

            form.addEventListener("submit", function (e) {
                if (!form.checkValidity()) {
                    e.preventDefault();
                    form.reportValidity();
                    return;
                }
                // Evita dobles envíos mientras el servidor responde
                boton.disabled = true;
                boton.setAttribute("aria-busy", "true");
                boton.textContent = "Verificando…";
            });
        })();
    </script>
</body>

</html>
