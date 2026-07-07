<?php
/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   CONFIGURACIÓN GLOBAL ENTERPRISE 2026
===================================================== */

date_default_timezone_set("America/Mexico_City");

/* =====================================================
   VARIABLES DE ENTORNO Y CONFIGURACIÓN DE APIs EXTERNAS
   (Google OAuth2/Calendar/Maps, Twilio, SendGrid, OpenWeather)
===================================================== */

require_once __DIR__ . '/config/apis.php';

/* =====================================================
   LOGS
===================================================== */

if (!function_exists('registrarLog')) {
    function registrarLog($evento, $nivel = "INFO")
    {
        $directorio_logs = __DIR__ . '/logs';
        $archivo_log = $directorio_logs . '/seguridad.log';

        if (!file_exists($directorio_logs)) {
            mkdir($directorio_logs, 0755, true);
            file_put_contents($directorio_logs . '/.htaccess', "Deny from all");
        }

        $fecha = date("Y-m-d H:i:s");
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'IP_DESCONOCIDA';
        $usuario = $_SESSION['medico'] ?? 'NO_AUTENTICADO';
        $navegador = $_SERVER['HTTP_USER_AGENT'] ?? 'DESCONOCIDO';

        $mensaje = "[$fecha] [$nivel] [Usuario:$usuario] [IP:$ip] [Browser:$navegador] => $evento" . PHP_EOL;

        error_log($mensaje, 3, $archivo_log);
    }
}

/* =====================================================
   SESIÓN
===================================================== */

ini_set('session.use_only_cookies', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_httponly', 1);

$secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';

if ($secure) {
    ini_set('session.cookie_secure', 1);
}

if (session_status() === PHP_SESSION_NONE) {
    session_name("MEDICORE_SESSION");

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        // "Lax" (no "Strict"): necesario para que la cookie de sesión
        // sobreviva la redirección de vuelta desde Google OAuth2. Con
        // "Strict" el navegador bloquea la cookie en esa navegación
        // entre sitios, se pierde "oauth_state" y el sistema lo
        // detecta (incorrectamente) como CSRF. "Lax" sigue protegiendo
        // contra CSRF en formularios/peticiones normales.
        'samesite' => 'Lax'
    ]);

    session_start();
}

/* =====================================================
   HEADERS
===================================================== */

if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin');
    header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
}

/* =====================================================
   CONEXIÓN MYSQL
===================================================== */

$host = "localhost";
$user = "root";
$pass = "";
$db   = "MediCore_db";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conexion = new mysqli($host, $user, $pass, $db);
    $conexion->set_charset("utf8mb4");
} catch (Exception $e) {
    registrarLog("Fallo crítico de conexión MySQL: " . $e->getMessage(), "CRITICAL");

    die("
        <div style='font-family:Arial;background:#fff1f2;color:#991b1b;padding:30px;margin:40px;border-radius:18px;border:1px solid #fecdd3;'>
            <h2>⚠ Error de infraestructura</h2>
            <p>No fue posible conectar con el núcleo de datos de MediCore.</p>
        </div>
    ");
}

/* =====================================================
   SESIÓN ACTIVA
===================================================== */

if (!function_exists('verificarSesion')) {
    function verificarSesion()
    {
        if (empty($_SESSION['medico'])) {
            session_unset();
            session_destroy();
            header("Location: login.php?error=denegado");
            exit();
        }

        $tiempo_limite = 7200;

        if (isset($_SESSION['ultimo_acceso'])) {
            $tiempo_inactivo = time() - $_SESSION['ultimo_acceso'];

            if ($tiempo_inactivo > $tiempo_limite) {
                registrarLog("Sesión expirada por inactividad.", "WARNING");

                session_unset();
                session_destroy();

                header("Location: login.php?error=expirada");
                exit();
            }
        }

        $_SESSION['ultimo_acceso'] = time();

        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (!isset($_SESSION['user_agent'])) {
            $_SESSION['user_agent'] = $user_agent;
        } elseif ($_SESSION['user_agent'] !== $user_agent) {
            registrarLog("Posible secuestro de sesión detectado.", "CRITICAL");

            session_unset();
            session_destroy();

            header("Location: login.php?error=seguridad");
            exit();
        }

        if (!isset($_SESSION['regenerada'])) {
            session_regenerate_id(true);
            $_SESSION['regenerada'] = true;
        }
    }
}

/* =====================================================
   LIMPIEZA
===================================================== */

if (!function_exists('limpiar')) {
    function limpiar($dato)
    {
        if (is_array($dato)) {
            return array_map('limpiar', $dato);
        }

        return htmlspecialchars(trim($dato), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('limpiar_datos')) {
    function limpiar_datos($conexion, $dato)
    {
        if (is_array($dato)) {
            return array_map(function ($item) use ($conexion) {
                return limpiar_datos($conexion, $item);
            }, $dato);
        }

        $dato = trim($dato);
        $dato = stripslashes($dato);
        $dato = htmlspecialchars($dato, ENT_QUOTES, 'UTF-8');

        return mysqli_real_escape_string($conexion, $dato);
    }
}

/* =====================================================
   MÉTRICAS
===================================================== */

if (!function_exists('contar')) {
    function contar($tabla)
    {
        global $conexion;

        $permitidas = [
            "usuarios",
            "expedientes",
            "medicos",
            "contactos"
        ];

        if (!in_array($tabla, $permitidas)) {
            return 0;
        }

        $stmt = $conexion->prepare("SELECT COUNT(*) AS total FROM $tabla");
        $stmt->execute();

        $resultado = $stmt->get_result()->fetch_assoc();

        return intval($resultado['total'] ?? 0);
    }
}

if (!function_exists('contarPorEstado')) {
    function contarPorEstado($estado)
    {
        global $conexion;

        $permitidos = [
            "pendiente",
            "autorizada",
            "denegada"
        ];

        if (!in_array($estado, $permitidos)) {
            return 0;
        }

        $stmt = $conexion->prepare("
            SELECT COUNT(*) AS total
            FROM expedientes
            WHERE dieta_autorizada = ?
        ");

        $stmt->bind_param("s", $estado);
        $stmt->execute();

        $resultado = $stmt->get_result()->fetch_assoc();

        return intval($resultado['total'] ?? 0);
    }
}

/* =====================================================
   CSRF
===================================================== */

if (!function_exists('generarTokenCSRF')) {
    function generarTokenCSRF()
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('validarTokenCSRF')) {
    function validarTokenCSRF($token)
    {
        if (
            empty($token) ||
            !isset($_SESSION['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $token)
        ) {
            registrarLog("Intento CSRF detectado.", "CRITICAL");

            http_response_code(403);

            die("
                <div style='font-family:Arial;background:#fef2f2;color:#991b1b;padding:30px;margin:40px;border-radius:18px;border:1px solid #fecaca;'>
                    <h2>⚠ Violación de Seguridad</h2>
                    <p>La solicitud fue rechazada por protección CSRF.</p>
                </div>
            ");
        }
    }
}

/* =====================================================
   REDIRECCIÓN
===================================================== */

if (!function_exists('redirigir')) {
    function redirigir($ruta)
    {
        header("Location: $ruta");
        exit();
    }
}
