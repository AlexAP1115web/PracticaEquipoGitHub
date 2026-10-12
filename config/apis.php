<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   CARGADOR DE VARIABLES DE ENTORNO (.env) Y CONFIG DE APIs
   Lee el .env sin Composer. Si falta una clave apiConfig() regresa vacío
===================================================== */

if (!function_exists('cargarVariablesEntorno')) {
    function cargarVariablesEntorno()
    {
        static $cargado = false;

        if ($cargado) {
            return;
        }

        $rutaEnv = __DIR__ . '/../.env';

        if (file_exists($rutaEnv)) {

            $lineas = file($rutaEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach ($lineas as $linea) {

                $linea = trim($linea);

                if ($linea === '' || strpos($linea, '#') === 0) {
                    continue;
                }

                if (strpos($linea, '=') === false) {
                    continue;
                }

                [$clave, $valor] = explode('=', $linea, 2);

                $clave = trim($clave);
                $valor = trim($valor);

                // Soporta referencias simples tipo ${APP_URL}
                if (preg_match('/\$\{([A-Z0-9_]+)\}/', $valor, $ref)) {
                    $valorRef = getenv($ref[1]) ?: ($_ENV[$ref[1]] ?? '');
                    $valor = str_replace($ref[0], $valorRef, $valor);
                }

                // Se sobreescribe siempre: en XAMPP (Windows) putenv() se queda entre peticiones
                if (!empty($clave)) {
                    putenv("$clave=$valor");
                    $_ENV[$clave] = $valor;
                }
            }
        }

        $cargado = true;
    }
}

cargarVariablesEntorno();

if (!function_exists('apiConfig')) {
    function apiConfig($clave, $valorPorDefecto = '')
    {
        $valor = getenv($clave);

        if ($valor === false || $valor === '') {
            return $valorPorDefecto;
        }

        return $valor;
    }
}

if (!function_exists('apiHabilitada')) {
    // true si todas las claves están configuradas (para ocultar botones de APIs sin configurar)
    function apiHabilitada(array $clavesRequeridas)
    {
        foreach ($clavesRequeridas as $clave) {
            if (apiConfig($clave) === '') {
                return false;
            }
        }

        return true;
    }
}
