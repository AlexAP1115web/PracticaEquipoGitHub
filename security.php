<?php
// ======================================================
// MEDICORE SECURITY CORE v3.3
// Sistema RASP + Protección Perimetral Empresarial
// ------------------------------------------------------
// Este archivo COMPLEMENTA a config.php, no lo duplica.
//
// Antes, config.php y security.php definían las mismas
// funciones (limpiar, generarTokenCSRF, validarTokenCSRF,
// verificarSesion, registrarLog, redirigir). Como todas
// las páginas incluyen config.php primero y las dos
// versiones estaban protegidas con function_exists(),
// las de security.php nunca llegaban a usarse: editar
// este archivo no cambiaba nada del comportamiento real,
// y además las dos versiones no coincidían (SameSite
// Strict contra Lax, 14400 contra 7200 segundos de
// inactividad, y dos archivos de log distintos).
//
// Ahora config.php es la única fuente de esas funciones
// y aquí sólo queda lo que es propio de este archivo:
// cabeceras de seguridad, control de intentos y utilidades.
// ======================================================

require_once __DIR__ . '/config.php';

/* ======================================================
   HEADERS SEGUROS
   ------------------------------------------------------
   frame-src es necesario para el <iframe> del mapa en
   ubicacion.php: sin declararlo hereda default-src 'self'
   y el navegador bloquea el mapa de Google en silencio.
====================================================== */

if (!headers_sent()) {
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin");
    header("X-XSS-Protection: 1; mode=block");
    header(
        "Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; "
        . "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; "
        . "img-src 'self' data: https:; "
        . "frame-src 'self' https://www.google.com https://meet.jit.si; "
        . "connect-src 'self';"
    );
}

/* ======================================================
   ESCAPE PARA SALIDA HTML
====================================================== */

if (!function_exists('e')) {
    function e($texto)
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
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
