# MediCore Professional System — Seguridad, Certificaciones y Estándares

Este documento describe cómo MediCore se alinea con estándares y buenas
prácticas de seguridad reconocidos internacionalmente, y qué controles
técnicos ya están implementados en el código como evidencia de cada uno.

## 1. TLS / HTTPS ✅ Implementado y probado en local

- **Estado: activo.** Apache corre con `mod_ssl` habilitado, sirviendo
  MediCore por `https://localhost/MediCore_medicoAdmin/` con el
  certificado autofirmado que incluye XAMPP. La redirección automática
  `http://` → `https://` está **activa** en el `.htaccess` raíz (las
  reglas `RewriteCond`/`RewriteRule` ya no están comentadas).
- El sistema detecta automáticamente si la conexión es HTTPS
  (`config.php`, `$secure = isset($_SERVER['HTTPS'])...`) y marca las
  cookies de sesión como `secure` únicamente en ese caso — con HTTPS
  activo, esta protección ya está en efecto.
- La cookie de sesión usa `SameSite=Lax` (no `Strict`): es necesario para
  que la cookie sobreviva la redirección de vuelta desde Google OAuth2
  (con `Strict` el navegador la bloquea en esa navegación entre sitios).
  `Lax` sigue mitigando CSRF en formularios y peticiones normales.
- El `.htaccess` raíz también envía cabeceras de seguridad a nivel
  servidor (`X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`) como respaldo de las que ya se envían desde PHP.
- **Nota:** el navegador muestra una advertencia de certificado no
  confiable porque es autofirmado (normal y esperado en un entorno de
  desarrollo local). En un despliegue real de producción, este
  certificado debe sustituirse por uno emitido por una Autoridad
  Certificadora reconocida (Let's Encrypt, DigiCert, Sectigo).

**Cómo se habilitó HTTPS local (XAMPP) — proceso documentado:**

1. Abre el **Panel de Control de XAMPP** → Apache → **Config** →
   `httpd.conf`. Verifica que estén sin `#`:
   `LoadModule ssl_module modules/mod_ssl.so` y
   `Include conf/extra/httpd-ssl.conf`.
2. Abre `httpd-vhosts.conf` (en `conf/extra/`) y agrega un VirtualHost en
   el puerto 443 apuntando a `C:/xampp/htdocs/MediCore_medicoAdmin` (XAMPP
   ya trae un certificado autofirmado de prueba en `apache/conf/ssl.crt/`
   y `ssl.key/`, listo para usarse).
3. Reinicia Apache desde el Panel de Control.
4. Entra a `https://localhost/MediCore_medicoAdmin/` — el navegador
   mostrará una advertencia de certificado no confiable (normal, es
   autofirmado); acéptala para pruebas locales.
5. Descomenta las 3 líneas del `.htaccess` raíz para forzar la
   redirección HTTP → HTTPS.
6. **En producción real:** sustituir el certificado autofirmado por uno
   emitido por una Autoridad Certificadora reconocida (Let's Encrypt,
   DigiCert, Sectigo), que es lo que da la validez pública del candado
   en el navegador.

## 2. OWASP Top 10 — mitigaciones ya presentes

| Riesgo OWASP | Mitigación en MediCore |
|---|---|
| A01 Broken Access Control | `verificarSesion()` en cada página privada; RBAC básico por rol (`medicos` vs. `usuarios.rol`) |
| A02 Cryptographic Failures | Contraseñas con `password_hash()`/Bcrypt; tokens con `random_bytes()` |
| A03 Injection | 100% de las consultas usan *prepared statements* (mysqli) |
| A04 Insecure Design | Rate limiting de login, expiración de sesión, tokens de un solo uso para recuperación de contraseña y OTP |
| A05 Security Misconfiguration | Headers de seguridad (`X-Frame-Options`, `X-Content-Type-Options`, CSP) en `config.php`/`security.php`; `.htaccess` con `Deny from all` en `/logs` y `/config` |
| A07 Identification & Auth Failures | Verificación de contraseña con `password_verify()`, segundo factor OTP opcional (Twilio), regeneración de ID de sesión tras login |
| A08 Software/Data Integrity | `composer.lock`/`package-lock.json` fijan versiones de dependencias |
| A09 Security Logging & Monitoring | `registrarLog()` centralizado, auditoría en `logs/seguridad.log` |
| A10 SSRF | Las integraciones (SendGrid, Twilio, Google, OpenWeather) llaman siempre a dominios fijos y conocidos, nunca a URLs proporcionadas por el usuario |

## 3. ISO/IEC 27001 (referencia)

MediCore no busca una certificación formal ISO 27001 (requiere una
auditoría externa y un Sistema de Gestión de Seguridad de la Información
completo), pero documenta su alineación con los controles más relevantes
para una aplicación de este tamaño:

- **A.9 Control de acceso:** RBAC + sesiones seguras.
- **A.10 Criptografía:** Bcrypt para contraseñas, HTTPS/TLS para tránsito.
- **A.12 Seguridad de las operaciones:** logging y monitoreo vía `seguridad.log`.
- **A.14 Adquisición/desarrollo de sistemas:** uso de *prepared statements*,
  validación de entradas, revisión de dependencias vía Composer/NPM.

## 4. Protección de datos personales (México)

- Se alinea con la **Ley Federal de Protección de Datos Personales en
  Posesión de los Particulares** (LFPDPPP): minimización de datos,
  confidencialidad, y un aviso de privacidad simplificado publicado en
  `aviso_privacidad.php` (enlazado desde el pie de página de `home.php`).
- El aviso cubre los 5 elementos mínimos del Art. 22: identidad del
  responsable, finalidades del tratamiento, mecanismo para conocer el
  aviso integral, medios para ejercer derechos ARCO, y transferencias de
  datos (limitadas a los encargados del tratamiento: SendGrid, Twilio y
  Google, solo para las finalidades ya descritas).
- El envío de correos (SendGrid) y SMS (Twilio) solo se activa con
  consentimiento implícito del médico/paciente al registrar su contacto
  en el sistema.

## 5. Bcrypt para contraseñas

`password_hash($password, PASSWORD_DEFAULT)` (Bcrypt) se usa en:
login (`login.php`), alta de pacientes (`nuevo_paciente.php`), cambio de
contraseña (`configuracion.php`) y restablecimiento (`restablecer_password.php`).

## 6. CSRF Tokens

Todas las páginas con formularios POST generan (`generarTokenCSRF()`) y
validan (`validarTokenCSRF()`) un token por sesión, incluyendo los
formularios nuevos: `recuperar_password.php`, `restablecer_password.php`,
`otp_verificar.php`, `catalogo_enfermedades.php`.

## 7. Validación de entradas

- `limpiar()` sanitiza (`htmlspecialchars` + `trim`) toda entrada de
  usuario antes de mostrarla o guardarla.
- Validaciones específicas: `filter_var(..., FILTER_VALIDATE_EMAIL)`,
  reglas de contraseña (mínimo 8 caracteres, mayúscula, número),
  validación de MIME real en subida de archivos (`configuracion.php`).

## 8. RBAC (control de acceso por roles)

- Rol **médico**: acceso completo al panel administrativo (todas las
  páginas protegidas por `verificarSesion()`).
- Rol **paciente** (`usuarios.rol = 'paciente'`): actualmente sin panel
  propio (ver sección "Pendientes" del análisis del proyecto); el login
  con Google (`oauth_callback.php`) solo autentica correos que ya existen
  en la tabla `medicos`, para no otorgar acceso administrativo a cuentas
  no autorizadas.

## 9. Auditoría mediante `seguridad.log`

Todo evento relevante se registra con fecha, IP, usuario y navegador:
login/logout, cambios de contraseña, intentos CSRF, envíos de OTP,
sincronización con Google Calendar, envíos de correo, y accesos a los
nuevos módulos (Recomendaciones, Catálogo de Enfermedades, Ubicación).

## 10. Políticas de sesión segura

- Cookies `httponly`, `SameSite=Lax` (ver sección 1: necesario para el
  flujo de Google OAuth2), `secure` (si HTTPS).
- Expiración por inactividad (`SESSION_TIMEOUT`).
- Regeneración de ID de sesión tras login exitoso.
- Detección de cambio de User-Agent (mitigación básica de secuestro de
  sesión).
- El segundo factor OTP (Twilio) deja al usuario en un estado
  "pre-autenticado" (sin `$_SESSION['medico']`) hasta verificar el código,
  por lo que ninguna página protegida es accesible durante ese paso.

## Resumen de nuevas integraciones y su postura de seguridad

| Integración | Dato que sale del sistema | Protección aplicada |
|---|---|---|
| SendGrid | Correo, nombre, contenido del mensaje | Solo vía HTTPS a la API oficial; API key nunca se expone al cliente |
| Google OAuth2 | Ninguno (solo se reciben datos) | Validación de `state` anti-CSRF; no crea cuentas nuevas automáticamente |
| Google Calendar | Nombre del paciente, fecha/hora de la cita | Requiere refresh token vinculado por el propio médico; scope mínimo (`calendar.events`) |
| Twilio | Teléfono, código OTP | Código de un solo uso, expira en 5 minutos, se marca como usado |
| Google Maps | Dirección de la sucursal (dato público) | Solo lectura, API key restringida a "Maps Embed API" |
| OpenWeather | Nombre de ciudad (configurado por el admin, no por el usuario) | Solo lectura, sin datos personales |

## 11. OWASP ASVS — autoevaluación de nivel 1

MediCore no ha pasado por una auditoría externa con herramientas como
OWASP ZAP, Burp Suite o Nessus (paso 2 del proceso de certificación de
desarrollos web), pero se autoevaluó contra los controles de
**OWASP ASVS (Application Security Verification Standard) Nivel 1**,
el nivel mínimo recomendado para cualquier aplicación:

| Categoría ASVS | Requisito clave | Estado en MediCore |
|---|---|---|
| V2 Autenticación | Contraseñas con hash fuerte (Bcrypt/Argon2) | ✅ `password_hash()` (Bcrypt) |
| V2 Autenticación | Segundo factor disponible | ✅ OTP vía Twilio (opcional, por médico) |
| V3 Gestión de sesión | Tokens de sesión aleatorios, regenerados tras login | ✅ `session_regenerate_id(true)` |
| V3 Gestión de sesión | Expiración de sesión por inactividad | ✅ `SESSION_TIMEOUT` (2h) |
| V4 Control de acceso | Verificación de autorización en cada endpoint sensible | ✅ `verificarSesion()` en todas las páginas privadas |
| V5 Validación de entradas | Validación/sanitización del lado del servidor | ✅ `limpiar()`, `filter_var(FILTER_VALIDATE_EMAIL)` |
| V5 Validación de entradas | Prepared statements contra SQL Injection | ✅ 100% de las consultas con `mysqli` prepared statements |
| V7 Manejo de errores | Mensajes de error genéricos al usuario final | ✅ Mensajes controlados (ej. "Ocurrió un error al procesar la solicitud") |
| V7 Logging | Registro de eventos de seguridad relevantes | ✅ `registrarLog()` → `logs/seguridad.log` |
| V9 Comunicaciones | TLS para datos sensibles en tránsito | ✅ Activo en local (`https://`, redirección forzada vía `.htaccess`); pendiente sustituir el certificado autofirmado por uno de una CA real en producción |
| V12 Archivos | Validación de tipo MIME real en subida de archivos | ✅ Validación MIME en `configuracion.php` (foto de perfil) |
| V14 Configuración | Cabeceras de seguridad HTTP | ✅ `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` (PHP + `.htaccess`) |

**Pendiente para una evaluación más profunda:** ejecutar un escaneo
automatizado real (por ejemplo `OWASP ZAP` en modo *baseline scan* contra
`http://localhost/MediCore_medicoAdmin/`) y documentar sus hallazgos como
evidencia adicional; esto requiere instalar la herramienta en el equipo
local y no se ejecutó como parte de esta entrega.

## Cómo probar/activar cada integración

Todas las integraciones están **desactivadas por defecto** (no rompen el
sistema si no se configuran). Para activarlas, copia `.env.example` como
`.env` y llena las claves correspondientes. Ver también `database/schema.sql`
para las tablas/columnas nuevas que deben ejecutarse una sola vez en
phpMyAdmin antes de usar estos módulos.
