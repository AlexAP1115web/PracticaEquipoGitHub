<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   HORA DE LA BASE DE DATOS
   MySQL de Railway trabaja en UTC y PHP con la hora de México: en la noche CURDATE()
   ya era el día siguiente y las citas de hoy contaban como de mañana (incidente del 26/10).
   La usan config.php y salud.php; la prueba de humo revisa que el desfase sea cero.
===================================================== */

if (!function_exists('igualarHoraBaseDatos')) {
    function igualarHoraBaseDatos($conexion)
    {
        $conexion->query("SET time_zone = '" . date('P') . "'");
    }
}

if (!function_exists('desfaseHoraBaseDatos')) {
    // Segundos de diferencia entre la hora de MySQL y la de PHP (0 = misma hora)
    function desfaseHoraBaseDatos($conexion)
    {
        $horaBd = $conexion->query("SELECT NOW()")->fetch_row()[0];
        return strtotime($horaBd) - time();
    }
}
