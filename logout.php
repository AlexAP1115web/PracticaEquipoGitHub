<?php

/**
 * ======================================================
 * MediCore Professional System
 * Secure Logout Controller
 * ======================================================
 */

include_once("config.php");

/* ======================================================
   AUDITORÍA DEL SISTEMA
====================================================== */

if (
   isset($_SESSION['medico']) &&
   function_exists('registrarLog')
) {
   $id_medico = $_SESSION['medico'];

   $nombre_medico =
      $_SESSION['nombre_medico']
      ?? 'Especialista';

   registrarLog(
      "Cierre de sesión seguro | Médico ID: {$id_medico} | {$nombre_medico}",
      "INFO"
   );
}

/* ======================================================
   LIMPIAR VARIABLES DE SESIÓN
====================================================== */

$_SESSION = [];

/* ======================================================
   ELIMINAR COOKIE DE SESIÓN
====================================================== */

if (ini_get("session.use_cookies")) {

   $params = session_get_cookie_params();

   setcookie(
      session_name(),
      '',
      time() - 42000,
      $params["path"] ?? '/',
      $params["domain"] ?? '',
      $params["secure"] ?? false,
      $params["httponly"] ?? true
   );
}

/* ======================================================
   DESTRUIR SESIÓN
====================================================== */

session_destroy();

/* ======================================================
   HEADERS ANTI CACHE
====================================================== */

header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

/* ======================================================
   REDIRECCIÓN FINAL
====================================================== */

header("Location: login.php?status=logout_success");
exit();
