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

// Tiempo máximo de inactividad (en segundos) antes de cerrar la sesión.
// Se define aquí una sola vez para que config.php y security.php usen
// exactamente el mismo valor; antes cada archivo manejaba el suyo (7200
// contra 14400) y el resultado dependía de cuál función terminaba
// ejecutándose primero.
if (!defined('SESSION_TIMEOUT')) {
    define('SESSION_TIMEOUT', 7200);
}

// Ruta explícita para guardar los archivos de sesión, dentro del propio
// proyecto. Motivo: en algunos entornos de contenedor (Railway/Docker con
// la imagen shinsenter/php) la ruta por defecto de sesiones de PHP (/tmp)
// puede no ser escribible o quedar fuera de open_basedir, lo que hace que
// session_start() nunca falle de forma visible pero tampoco persista nada:
// cada petición arranca con una sesión vacía, "csrf_token" nunca coincide,
// y CUALQUIER formulario (login incluido) se rechaza siempre por CSRF. Al
// forzar una carpeta propia (que sabemos que sí es escribible, igual que
// logs/ y uploads/) se evita depender de la configuración de sesiones del
// servidor. En XAMPP local no cambia nada: simplemente crea esta carpeta
// dentro del proyecto y sigue funcionando igual que antes.
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

// Usa variables de entorno si existen (necesario para despliegue en la nube:
// Railway, Render, etc. inyectan sus propias credenciales de MySQL). Si no
// existen (entorno local con XAMPP), cae en los valores de siempre, por lo
// que el entorno local sigue funcionando exactamente igual que antes.
$host = apiConfig('DB_HOST', 'localhost');
$user = apiConfig('DB_USER', 'root');
$pass = apiConfig('DB_PASS', '');
$db   = apiConfig('DB_NAME', 'MediCore_db');
$puerto = apiConfig('DB_PORT', '3306');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conexion = new mysqli($host, $user, $pass, $db, (int) $puerto);
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
   -----------------------------------------------------
   El token se rota al iniciar sesión (para cortar la
   fijación de sesión), pero al rotarlo se guarda el
   anterior durante unos minutos. Motivo: cuando el token
   cambia, cualquier formulario que el navegador ya tenía
   pintado (otra pestaña abierta, el botón "atrás", una
   página recuperada del caché) se queda con el token
   viejo y el sistema lo rechazaba como si fuera un
   ataque. Esos eran los "Intento CSRF detectado" del log
   de seguridad. Aceptar el token anterior durante una
   ventana corta resuelve el falso positivo sin abrir la
   puerta a un CSRF real: un atacante externo sigue sin
   poder leer ninguno de los dos tokens.
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

        // Se registra como WARNING, no como CRITICAL: la causa habitual es
        // un formulario vencido, no un ataque.
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
   Según la versión de la base, la tabla usuarios guarda la
   fecha de alta como "creado_en" o "fecha_registro" (o no la
   tiene). Se revisa cuál existe para que pacientes.php y
   reportes.php no truenen si falta alguna.
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
