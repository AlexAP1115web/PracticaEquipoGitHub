# Guion para el video — Producto 3 (MediCore)

Duración total objetivo: **4 minutos** (rango permitido 3–5 min). Graba con OBS Studio, Loom o el grabador de pantalla de Windows (Win+G). Sube el resultado a YouTube como "No listado" o a Drive con acceso por enlace, y pega el link en la Sección 9 del documento.

Antes de grabar: ten abiertos VS Code (con el proyecto MediCore) y el navegador ya logueado como médico en `http://localhost/MediCore_medicoAdmin`. Cierra pestañas/ventanas que no quieras mostrar.

---

## 0:00 – 0:25 | Introducción (habla a cámara o solo voz sobre el escritorio)

**Decir:**
"Hola, somos el equipo de MediCore Professional System, del noveno cuatrimestre de Ingeniería en Desarrollo de Software Multiplataforma. Este video corresponde al Producto 3 de Desarrollo Web Integral, donde mostramos los mecanismos de seguridad y los web services propios y de terceros que integramos al sistema."

**Mostrar:** el home de MediCore o el escritorio.

---

## 0:25 – 1:10 | Mecanismos de seguridad (JWT + RBAC)

**Mostrar en VS Code:** abre `api/JwtHandler.php` (o la ruta donde esté tu clase JWT) y muestra las funciones `generarToken()` y `validarToken()`.

**Decir:**
"La seguridad de MediCore se implementó en tres capas. La primera son encabezados HTTP como X-Frame-Options y Content-Security-Policy. La segunda es autenticación con JSON Web Tokens: aquí generamos un token firmado con HMAC-SHA256 que expira en una hora. Y la tercera es autorización basada en roles, RBAC, donde cada endpoint valida que el usuario tenga el rol correcto antes de dejarlo continuar."

**Mostrar:** el fragmento de `crearReceta()` donde se valida `$usuarioAutenticado['rol'] !== 'medico'`.

---

## 1:10 – 2:00 | Web services propios (endpoints REST)

**Mostrar:** Postman, Insomnia o el navegador contra `api_producto3.php` (según cómo lo tengan armado), haciendo una petición real a `/recetas` o `/videollamadas` y mostrando la respuesta JSON.

**Decir:**
"Desarrollamos 14 endpoints RESTful propios para recetas, videollamadas, enfermedades, recomendaciones de clima y ubicaciones. Aquí ven una petición POST a /recetas creando una receta nueva, y la respuesta del servidor confirmando la creación con su ID."

Si tienen tiempo, muestren también un GET simple a `/enfermedades` o `/ubicaciones`.

---

## 2:00 – 2:40 | Web services de terceros

**Mostrar:** el módulo de Recomendaciones (clima) y el módulo de Ubicación funcionando en el navegador logueado como médico.

**Decir:**
"Integramos tres servicios externos. OpenWeatherMap nos da el clima actual y genera recomendaciones automáticas según la temperatura. Con las coordenadas de las sucursales calculamos distancia usando la fórmula de Haversine para encontrar la clínica más cercana. Y con Daily.co generamos salas de videollamada privadas para las consultas de seguimiento."

---

## 2:40 – 3:25 | Ampliación: integraciones adicionales reales

**Mostrar:** (rápido, 3-4 pantallas)
1. El botón "Iniciar sesión con Google" en el login y el dashboard tras entrar con esa cuenta.
2. Una cita guardada en el expediente y el mismo evento apareciendo en Google Calendar real.
3. El código de teléfono llegando por SMS (Twilio) en el paso de verificación en dos pasos.

**Decir:**
"Como ampliación del equipo, se integraron de forma real cuatro APIs adicionales: inicio de sesión institucional con Google OAuth2, sincronización automática de citas con Google Calendar, verificación en dos pasos por SMS con Twilio, y notificaciones automáticas por correo con SendGrid. Todo esto funcionando en vivo, no simulado."

---

## 3:25 – 3:50 | Repositorio y control de versiones

**Mostrar:** el repositorio de GitHub (`https://github.com/AlexAP1115web/MediCore`) con el historial de commits de los integrantes.

**Decir:**
"Todo el código está versionado en GitHub, con commits de los cuatro integrantes del equipo a lo largo del desarrollo del proyecto."

---

## 3:50 – 4:10 | Cierre

**Decir:**
"Con esto concluye la demostración del Producto 3 de MediCore. Gracias por su atención."

---

### Checklist antes de subir el video
- [ ] Duración entre 3 y 5 minutos
- [ ] Se ve código real (no solo la pantalla en blanco)
- [ ] Se ve al menos un endpoint funcionando con respuesta real
- [ ] Se ve el clima, la videollamada o el login con Google funcionando
- [ ] Subido como "No listado" en YouTube o con acceso por enlace en Drive
- [ ] Link pegado en la Sección 9 del documento Producto 3 (reemplazando el recuadro rojo)
