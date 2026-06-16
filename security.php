<?php
// ======================================================
// MEDICORE SECURITY CORE v3.2
// Sistema RASP + Protección Perimetral Empresarial
// ======================================================

if (session_status() === PHP_SESSION_NONE) {

    $secure = (
        isset($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off'
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);

    session_name("MEDICORE_SESSION");
    session_start();
}

date_default_timezone_set('America/Mexico_City');

if (!defined('SESSION_TIMEOUT')) {
    define('SESSION_TIMEOUT', 14400);
}

/* ======================================================
   HEADERS SEGUROS
====================================================== */

if (!headers_sent()) {
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin");
    header("X-XSS-Protection: 1; mode=block");
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https:;");
}

/* ======================================================
   INACTIVIDAD Y ANTI HIJACKING
   SOLO SI YA HAY MÉDICO LOGUEADO
====================================================== */

$usuarioLogueado = (
    isset($_SESSION['medico']) &&
    !empty($_SESSION['medico'])
);

if ($usuarioLogueado) {

    if (isset($_SESSION['ultimo_acceso'])) {

        $tiempo_transcurrido = time() - $_SESSION['ultimo_acceso'];

        if ($tiempo_transcurrido > SESSION_TIMEOUT) {

            session_unset();
            session_destroy();

            header("Location: login.php?error=expirada");
            exit();
        }
    }

    $_SESSION['ultimo_acceso'] = time();

    if (!isset($_SESSION['user_agent'])) {
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    }

    if ($_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {

        session_unset();
        session_destroy();

        header("Location: login.php?error=seguridad");
        exit();
    }
}

/* ======================================================
   SANITIZACIÓN
====================================================== */

if (!function_exists('limpiar')) {
    function limpiar($dato)
    {
        if (is_array($dato)) {
            return array_map('limpiar', $dato);
        }

        $dato = trim($dato);
        $dato = stripslashes($dato);

        return htmlspecialchars($dato, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('e')) {
    function e($texto)
    {
        return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
    }
}

/* ======================================================
   CSRF
====================================================== */

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
            empty($_SESSION['csrf_token']) ||
            empty($token) ||
            !hash_equals($_SESSION['csrf_token'], $token)
        ) {
            http_response_code(403);
            die("Error de seguridad: Token CSRF inválido o expirado.");
        }
    }
}

/* ======================================================
   SESIÓN
====================================================== */

if (!function_exists('verificarSesion')) {
    function verificarSesion()
    {
        $sesionValida = (
            isset($_SESSION['medico']) &&
            !empty($_SESSION['medico'])
        );

        if (!$sesionValida) {
            session_unset();
            session_destroy();
            header("Location: login.php?error=denegado");
            exit();
        }
    }
}

/* ======================================================
   REDIRECCIÓN
====================================================== */

if (!function_exists('redirigir')) {
    function redirigir($ruta)
    {
        header("Location: " . $ruta);
        exit();
    }
}

/* ======================================================
   RATE LIMIT
====================================================== */

if (!function_exists('controlarIntentos')) {
    function controlarIntentos($clave = 'global', $maxIntentos = 5, $bloqueo = 60)
    {
        if (!isset($_SESSION['rate_limit'])) {
            $_SESSION['rate_limit'] = [];
        }

        if (!isset($_SESSION['rate_limit'][$clave])) {
            $_SESSION['rate_limit'][$clave] = [
                'intentos' => 0,
                'ultimo' => time()
            ];
        }

        $data = &$_SESSION['rate_limit'][$clave];

        if (
            $data['intentos'] >= $maxIntentos &&
            (time() - $data['ultimo']) < $bloqueo
        ) {
            return false;
        }

        if ((time() - $data['ultimo']) > $bloqueo) {
            $data['intentos'] = 0;
        }

        return true;
    }
}

if (!function_exists('registrarIntentoFallido')) {
    function registrarIntentoFallido($clave = 'global')
    {
        if (!isset($_SESSION['rate_limit'][$clave])) {
            $_SESSION['rate_limit'][$clave] = [
                'intentos' => 0,
                'ultimo' => time()
            ];
        }

        $_SESSION['rate_limit'][$clave]['intentos']++;
        $_SESSION['rate_limit'][$clave]['ultimo'] = time();
    }
}

if (!function_exists('limpiarIntentos')) {
    function limpiarIntentos($clave = 'global')
    {
        if (isset($_SESSION['rate_limit'][$clave])) {
            $_SESSION['rate_limit'][$clave]['intentos'] = 0;
            $_SESSION['rate_limit'][$clave]['ultimo'] = time();
        }
    }
}

/* ======================================================
   LOGS
====================================================== */

if (!function_exists('registrarLog')) {
    function registrarLog($mensaje, $nivel = "INFO")
    {
        $carpeta = __DIR__ . "/logs";

        if (!file_exists($carpeta)) {
            mkdir($carpeta, 0777, true);
        }

        $archivo = $carpeta . "/medicore_" . date("Y-m-d") . ".log";

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $fecha = date("Y-m-d H:i:s");
        $usuario = $_SESSION['nombre_medico'] ?? 'Sistema';

        $linea = "[$fecha] [$nivel] [$ip] [$usuario] $mensaje" . PHP_EOL;

        file_put_contents($archivo, $linea, FILE_APPEND);
    }
}

/* ======================================================
   UTILIDADES
====================================================== */

if (!function_exists('soloPOST')) {
    function soloPOST()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            die("Método no permitido.");
        }
    }
}

if (!function_exists('validarEmail')) {
    function validarEmail($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}

if (!function_exists('passwordSegura')) {
    function passwordSegura($password)
    {
        return strlen($password) >= 8;
    }
}

if (!function_exists('tokenAleatorio')) {
    function tokenAleatorio($longitud = 32)
    {
        return bin2hex(random_bytes($longitud));
    }
}
