<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   CARGADOR DE VARIABLES DE ENTORNO (.env) Y CONFIG DE APIs
   -----------------------------------------------------
   No depende de Composer ni de ninguna librería externa.
   Lee el archivo .env (si existe) y expone sus valores
   mediante la función apiConfig(). Si una clave no está
   configurada, se regresa una cadena vacía y el módulo
   que la use debe desactivarse solo (ver integrations/).
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

                // Siempre se sobreescribe con el valor actual del .env (no solo
                // la primera vez), porque Apache en Windows corre como un solo
                // proceso multihilo y putenv() persiste entre peticiones. Sin
                // esto, un valor vacío quedaba "pegado" y nunca se refrescaba
                // aunque después se llenara la clave real en el .env.
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
    /**
     * Indica si un módulo/API tiene TODAS sus claves requeridas
     * configuradas. Se usa para ocultar botones/menús cuando la
     * integración aún no está lista, sin romper el resto del sistema.
     */
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
