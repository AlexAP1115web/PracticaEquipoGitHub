# MediCore Professional System — Seguridad, Certificaciones y Estándares

Este documento describe cómo MediCore se alinea con estándares y buenas
prácticas de seguridad reconocidos internacionalmente, y qué controles
técnicos ya están implementados en el código como evidencia de cada uno.

## 1. TLS / HTTPS

- El sistema detecta automáticamente si la conexión es HTTPS
  (`config.php`, `$secure = isset($_SERVER['HTTPS'])...`) y marca las
  cookies de sesión como `secure` únicamente en ese caso.
- **Pendiente en producción:** instalar un certificado TLS (por ejemplo
  Let's Encrypt) en el servidor y forzar la redirección HTTP → HTTPS.
  En XAMPP local esto se puede simular activando SSL en Apache
  (`httpd-ssl.conf`) o usando un proxy como ngrok/Cloudflare Tunnel para
  pruebas con HTTPS real.

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
  confidencialidad, y próximamente un aviso de privacidad en `home.php`.
- El envío de correos (SendGrid) y SMS (Twilio) solo se activa con
  consentimiento implícito del médico/paciente al registrar su contacto
  en el sistema.
- **Pendiente:** publicar un aviso de privacidad simplificado (Art. 22 del
  Reglamento) accesible desde `home.php`, indicando responsable,
  finalidad del tratamiento y mecanismo para ejercer derechos ARCO.

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

- Cookies `httponly`, `SameSite=Strict`, `secure` (si HTTPS).
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

## Cómo probar/activar cada integración

Todas las integraciones están **desactivadas por defecto** (no rompen el
sistema si no se configuran). Para activarlas, copia `.env.example` como
`.env` y llena las claves correspondientes. Ver también `database/schema.sql`
para las tablas/columnas nuevas que deben ejecutarse una sola vez en
phpMyAdmin antes de usar estos módulos.
