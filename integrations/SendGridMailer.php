<?php
/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   Compatibilidad: los correos ahora salen por integrations/Correo.php (Brevo o SendGrid)
===================================================== */

require_once __DIR__ . '/Correo.php';

if (!function_exists('sendGridDisponible')) {
    function sendGridDisponible()
    {
        return proveedorCorreo() !== null;
    }
}

if (!function_exists('enviarCorreoSendGrid')) {
    function enviarCorreoSendGrid($destinatarioEmail, $destinatarioNombre, $asunto, $contenidoHtml)
    {
        return enviarCorreo($destinatarioEmail, $destinatarioNombre, $asunto, $contenidoHtml)['ok'];
    }
}
