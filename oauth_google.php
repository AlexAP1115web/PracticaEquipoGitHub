<?php
include("config.php");
require_once "security.php";
require_once __DIR__ . "/integrations/GoogleOAuth.php";

/* ======================================================
   INICIA EL FLUJO DE LOGIN CON GOOGLE OAUTH2
====================================================== */

if (!googleOAuthDisponible()) {
    registrarLog("Intento de acceso a oauth_google.php sin configuración de Google OAuth2.", "WARNING");
    redirigir("login.php?error=denegado");
}

// Token anti-CSRF propio del flujo OAuth (parámetro "state")
$_SESSION['oauth_state'] = bin2hex(random_bytes(16));

$incluirCalendar = apiConfig('GOOGLE_CALENDAR_ENABLED', 'false') === 'true';

header("Location: " . construirUrlAutorizacionGoogle($incluirCalendar));
exit();
