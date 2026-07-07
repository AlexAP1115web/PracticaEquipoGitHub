<?php
/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   AVISO DE PRIVACIDAD SIMPLIFICADO
   -----------------------------------------------------
   Página pública (no requiere sesión). Cumple con el
   contenido mínimo del Art. 22 del Reglamento de la Ley
   Federal de Protección de Datos Personales en Posesión
   de los Particulares (LFPDPPP):
     1. Identidad del responsable.
     2. Finalidades del tratamiento.
     3. Mecanismos para conocer el aviso integral.
     4. Medios para ejercer los derechos ARCO.
     5. Transferencias de datos, cuando existan.
===================================================== */
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aviso de Privacidad | MediCore</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        :root {
            --primary: #00843d;
            --primary-dark: #05602c;
            --navy: #0f172a;
            --muted: #64748b;
            --border: #d9e5df;
            --light: #f6faf7;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Arial, sans-serif;
        }

        body {
            background: var(--light);
            color: var(--navy);
            line-height: 1.7;
        }

        header.aviso-header {
            background: linear-gradient(135deg, #02160c, #06351b);
            color: white;
            padding: 40px 24px;
            text-align: center;
        }

        header.aviso-header img {
            width: 68px;
            height: 68px;
            object-fit: contain;
            background: white;
            padding: 7px;
            border-radius: 18px;
            margin-bottom: 14px;
        }

        header.aviso-header h1 {
            font-size: 28px;
            margin-bottom: 6px;
        }

        header.aviso-header p {
            color: rgba(255, 255, 255, .78);
            font-size: 14px;
        }

        main {
            max-width: 860px;
            margin: 0 auto;
            padding: 45px 24px 70px;
        }

        .volver {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary-dark);
            font-weight: 700;
            text-decoration: none;
            margin-bottom: 26px;
        }

        section.bloque {
            background: white;
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 26px 30px;
            margin-bottom: 22px;
        }

        section.bloque h2 {
            color: var(--primary-dark);
            font-size: 19px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        section.bloque p,
        section.bloque li {
            color: #334155;
            font-size: 15px;
        }

        section.bloque ul {
            padding-left: 20px;
            margin-top: 8px;
        }

        .fecha-vigencia {
            font-size: 13px;
            color: var(--muted);
            text-align: center;
            margin-top: 30px;
        }
    </style>
</head>

<body>

    <header class="aviso-header">
        <img src="assets/Medicore.png" alt="Logo MediCore" onerror="this.style.display='none'">
        <h1>Aviso de Privacidad Simplificado</h1>
        <p>MediCore Professional System &mdash; Universidad Tecnológica de Puebla</p>
    </header>

    <main>

        <a href="home.php" class="volver">
            <i class="fas fa-arrow-left"></i> Regresar al inicio
        </a>

        <section class="bloque">
            <h2><i class="fas fa-id-card"></i> 1. Identidad del responsable</h2>
            <p>
                <strong>MediCore Professional System</strong> es responsable del tratamiento
                de los datos personales que se recaban a través de esta plataforma, con fines
                académicos y de práctica profesional dentro del proyecto desarrollado en la
                Universidad Tecnológica de Puebla, Ingeniería en Desarrollo y Gestión de Software.
            </p>
        </section>

        <section class="bloque">
            <h2><i class="fas fa-bullseye"></i> 2. Finalidades del tratamiento</h2>
            <p>Los datos personales recabados (nombre, correo, teléfono, dirección, datos clínicos y biométricos) se utilizan única y exclusivamente para:</p>
            <ul>
                <li>Gestionar el expediente clínico digital del paciente.</li>
                <li>Agendar y confirmar citas médicas (incluyendo notificaciones por correo y SMS).</li>
                <li>Generar reportes y recetas médicas en formato PDF.</li>
                <li>Verificar la identidad del médico mediante autenticación segura (incluyendo el segundo factor OTP).</li>
                <li>Sincronizar citas con Google Calendar, únicamente cuando el propio médico autoriza esta conexión.</li>
            </ul>
            <p>No se utilizan los datos para fines de mercadotecnia, publicidad ni se venden a terceros.</p>
        </section>

        <section class="bloque">
            <h2><i class="fas fa-file-shield"></i> 3. Mecanismos para conocer el aviso integral</h2>
            <p>
                Este es un aviso de privacidad en su <strong>modalidad simplificada</strong>. El aviso de
                privacidad integral, con el detalle completo de los tratamientos, plazos de conservación
                y transferencias, puede solicitarse directamente al responsable del sistema a través del
                formulario de contacto disponible en la página de inicio (sección "Contacto y Soporte").
            </p>
        </section>

        <section class="bloque">
            <h2><i class="fas fa-user-shield"></i> 4. Derechos ARCO (Acceso, Rectificación, Cancelación, Oposición)</h2>
            <p>
                El titular de los datos puede en cualquier momento solicitar acceder, rectificar, cancelar
                u oponerse al tratamiento de su información personal, enviando su solicitud a través del
                formulario de contacto del portal o directamente con el médico responsable de su expediente.
                La solicitud será atendida conforme a los plazos establecidos por la Ley Federal de
                Protección de Datos Personales en Posesión de los Particulares.
            </p>
        </section>

        <section class="bloque">
            <h2><i class="fas fa-right-left"></i> 5. Transferencia de datos</h2>
            <p>MediCore no vende ni transfiere datos personales a terceros con fines comerciales. Únicamente se comparte información estrictamente necesaria con proveedores de servicios tecnológicos que actúan como encargados del tratamiento, limitados a:</p>
            <ul>
                <li><strong>SendGrid</strong> (envío de correos de confirmación de citas y recuperación de contraseña).</li>
                <li><strong>Twilio</strong> (envío de códigos de verificación OTP por SMS).</li>
                <li><strong>Google (OAuth2 / Calendar)</strong> (inicio de sesión institucional y sincronización de citas, solo si el médico lo autoriza expresamente).</li>
            </ul>
            <p>Todas las comunicaciones con estos proveedores se realizan mediante conexiones cifradas (HTTPS/TLS) directamente a sus APIs oficiales.</p>
        </section>

        <section class="bloque">
            <h2><i class="fas fa-lock"></i> Medidas de seguridad aplicadas</h2>
            <p>Para proteger los datos personales, MediCore implementa: cifrado de contraseñas (Bcrypt), tokens anti-CSRF, sesiones seguras, control de acceso por roles (RBAC), validación y sanitización de entradas, y auditoría de eventos en bitácora de seguridad. Ver <code>SECURITY.md</code> para el detalle técnico completo.</p>
        </section>

        <p class="fecha-vigencia">
            Última actualización: <?= date("d/m/Y") ?> &mdash; Este aviso puede modificarse para reflejar
            cambios en la plataforma o en la normativa aplicable.
        </p>

    </main>

</body>

</html>
