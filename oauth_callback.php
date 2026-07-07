<?php
include("config.php");
require_once "security.php";
require_once __DIR__ . "/integrations/GoogleOAuth.php";

/* ======================================================
   CALLBACK DE GOOGLE OAUTH2
   Solo permite iniciar sesión a médicos que YA existen en
   la tabla "medicos" (por correo). No crea cuentas nuevas
   automáticamente: mantiene el control de acceso (RBAC) del
   sistema en manos del administrador.
====================================================== */

if (!googleOAuthDisponible()) {
    redirigir("login.php?error=denegado");
}

$estadoRecibido = $_GET['state'] ?? '';
$estadoEsperado = $_SESSION['oauth_state'] ?? '';
unset($_SESSION['oauth_state']);

if (empty($estadoRecibido) || !hash_equals($estadoEsperado, $estadoRecibido)) {
    registrarLog("Estado OAuth inválido en callback de Google (posible CSRF).", "CRITICAL");
    redirigir("login.php?error=seguridad");
}

if (!empty($_GET['error'])) {
    registrarLog("El usuario canceló o Google rechazó el login OAuth: " . $_GET['error'], "INFO");
    redirigir("login.php?error=denegado");
}

$codigo = $_GET['code'] ?? '';

if (empty($codigo)) {
    redirigir("login.php?error=denegado");
}

$token = intercambiarCodigoGooglePorToken($codigo);

if (empty($token['access_token'])) {
    registrarLog("No se pudo intercambiar el código OAuth de Google por un token.", "WARNING");
    redirigir("login.php?error=denegado");
}

$perfil = obtenerPerfilGoogle($token['access_token']);

if (empty($perfil['email'])) {
    registrarLog("No se pudo obtener el perfil de Google tras autenticar.", "WARNING");
    redirigir("login.php?error=denegado");
}

$googleId = $perfil['sub'] ?? '';
$correoGoogle = $perfil['email'];

/* ======================================================
   BUSCAR MÉDICO POR google_id O POR CORREO
====================================================== */

$stmt = $conexion->prepare("SELECT * FROM medicos WHERE google_id = ? OR correo = ? LIMIT 1");
$stmt->bind_param("ss", $googleId, $correoGoogle);
$stmt->execute();
$medico = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$medico) {
    registrarLog("Intento de login con Google no autorizado (correo no registrado): " . $correoGoogle, "WARNING");
    redirigir("login.php?error=denegado");
}

// Si el médico existía por correo pero aún no tenía vinculada su cuenta de Google, se vincula ahora.
if (empty($medico['google_id']) && !empty($googleId)) {
    $vincular = $conexion->prepare("UPDATE medicos SET google_id = ? WHERE id = ?");
    $vincular->bind_param("si", $googleId, $medico['id']);
    $vincular->execute();
    $vincular->close();

    registrarLog("Cuenta de Google vinculada al médico ID {$medico['id']}.", "INFO");
}

// Si Google devolvió un refresh_token (ocurre la primera vez que se
// autoriza el scope de Calendar), se guarda para poder sincronizar
// citas sin necesidad de que el médico esté conectado en ese momento.
if (!empty($token['refresh_token'])) {

    $guardarCalendar = $conexion->prepare("
        UPDATE medicos
        SET google_refresh_token = ?, google_calendar_conectado = 1
        WHERE id = ?
    ");
    $guardarCalendar->bind_param("si", $token['refresh_token'], $medico['id']);
    $guardarCalendar->execute();
    $guardarCalendar->close();

    registrarLog("Google Calendar vinculado para el médico ID {$medico['id']}.", "INFO");
}

/* ======================================================
   INICIAR SESIÓN (mismo esquema que login.php)
====================================================== */

session_regenerate_id(true);

$_SESSION['medico'] = $medico['id'];
$_SESSION['medico_id'] = $medico['id'];
$_SESSION['nombre_medico'] = $medico['nombre'];
$_SESSION['ultimo_acceso'] = time();
$_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
$_SESSION['login_attempts'] = 0;
$_SESSION['lockout_time'] = 0;
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

registrarLog("Inicio de sesión correcto vía Google OAuth2: " . $correoGoogle, "INFO");

redirigir("index.php");
