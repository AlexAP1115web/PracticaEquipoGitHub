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

// Inactividad máxima en segundos (2 horas)
if (!defined('SESSION_TIMEOUT')) {
    define('SESSION_TIMEOUT', 7200);
}

// Sesiones en una carpeta del proyecto: en Railway /tmp no siempre se puede escribir,
// la sesión no se guardaba y todos los formularios fallaban por CSRF
$rutaSesiones = __DIR__ . '/sessions_data';

if (!file_exists($rutaSesiones)) {
    @mkdir($rutaSesiones, 0755, true);
}

if (is_dir($rutaSesiones) && is_writable($rutaSesiones)) {
    session_save_path($rutaSesiones);
}

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
        // Lax y no Strict, con Strict se pierde oauth_state al regresar de Google
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
    // Micrófono y cámara solo para MediCore (dictado de notas y escáner), nunca para iframes
    header("Permissions-Policy: geolocation=(self), microphone=(self), camera=(self)");
}

/* =====================================================
   CONEXIÓN MYSQL
===================================================== */

// En Railway salen de las variables de entorno, en XAMPP usa los valores locales
$host = apiConfig('DB_HOST', 'localhost');
$user = apiConfig('DB_USER', 'root');
$pass = apiConfig('DB_PASS', '');
$db   = apiConfig('DB_NAME', 'MediCore_db');
$puerto = apiConfig('DB_PORT', '3306');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conexion = new mysqli($host, $user, $pass, $db, (int) $puerto);
    $conexion->set_charset("utf8mb4");

    // MySQL con la misma hora que PHP (México). En Railway el servidor está en UTC y en la noche
    // CURDATE() ya era el día siguiente: las citas de hoy contaban como de mañana
    $conexion->query("SET time_zone = '" . date('P') . "'");
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

        $tiempo_limite = SESSION_TIMEOUT;

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
   Al rotar el token el anterior sigue valiendo unos minutos (otras pestañas, botón atrás)
===================================================== */

if (!defined('CSRF_GRACIA')) {
    define('CSRF_GRACIA', 600); // 10 minutos
}

if (!function_exists('generarTokenCSRF')) {
    function generarTokenCSRF()
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('rotarTokenCSRF')) {
    function rotarTokenCSRF()
    {
        if (!empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token_anterior'] = $_SESSION['csrf_token'];
            $_SESSION['csrf_token_anterior_expira'] = time() + CSRF_GRACIA;
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('tokenCSRFValido')) {
    function tokenCSRFValido($token)
    {
        if (empty($token) || !is_string($token)) {
            return false;
        }

        if (
            !empty($_SESSION['csrf_token']) &&
            hash_equals($_SESSION['csrf_token'], $token)
        ) {
            return true;
        }

        if (
            !empty($_SESSION['csrf_token_anterior']) &&
            !empty($_SESSION['csrf_token_anterior_expira']) &&
            time() < $_SESSION['csrf_token_anterior_expira'] &&
            hash_equals($_SESSION['csrf_token_anterior'], $token)
        ) {
            return true;
        }

        return false;
    }
}

if (!function_exists('validarTokenCSRF')) {
    function validarTokenCSRF($token)
    {
        if (tokenCSRFValido($token)) {
            return;
        }

        $pagina = basename($_SERVER['SCRIPT_NAME'] ?? 'desconocida');

        // WARNING y no CRITICAL, casi siempre es un formulario vencido
        registrarLog("Token CSRF vencido o inválido en $pagina.", "WARNING");

        http_response_code(403);

        die("
            <div style='font-family:Arial,sans-serif;background:#fffbeb;color:#92400e;padding:30px;margin:40px auto;max-width:560px;border-radius:18px;border:1px solid #fde68a;'>
                <h2 style='margin:0 0 12px;'>El formulario expiró</h2>
                <p style='margin:0 0 18px;line-height:1.6;'>
                    Por seguridad, los formularios de MediCore caducan cuando la sesión
                    cambia o pasa demasiado tiempo abierta. No se guardó ningún dato:
                    vuelve a la página y envíalo de nuevo.
                </p>
                <a href='javascript:history.back()'
                   style='display:inline-block;background:#0ea5e9;color:#fff;text-decoration:none;padding:12px 22px;border-radius:12px;font-weight:600;'>
                    Volver e intentar de nuevo
                </a>
            </div>
        ");
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


/* =====================================================
   FECHA DE ALTA DE USUARIOS
   Puede ser creado_en o fecha_registro según la versión de la base
===================================================== */

if (!function_exists('columnaFechaAlta')) {
    function columnaFechaAlta()
    {
        global $conexion;
        static $columna = false;

        if ($columna !== false) {
            return $columna;
        }

        $columna = null;
        $res = $conexion->query("SHOW COLUMNS FROM usuarios");
        $existentes = [];
        while ($fila = $res->fetch_assoc()) {
            $existentes[] = $fila['Field'];
        }

        foreach (['creado_en', 'fecha_registro'] as $candidata) {
            if (in_array($candidata, $existentes, true)) {
                $columna = $candidata;
                break;
            }
        }

        return $columna;
    }
}
