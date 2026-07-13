# Complemento para sección 3.1 — Segunda metodología ágil evaluada

*(Pegar esto dentro de la sección 3.1 "Producto 1: Metodología ágil...", después del contenido actual de Scrum, como una nueva subsección, por ejemplo "3.1.1 Metodología ágil complementaria evaluada: XP".)*

---

## 3.1.1 Metodología ágil complementaria evaluada: Extreme Programming (XP)

Además de Scrum, se evaluó **Extreme Programming (XP)**, propuesta por Kent Beck en 1996, como segunda opción metodológica para el desarrollo de MediCore. XP se basa en cinco valores (comunicación, simplicidad, retroalimentación, coraje y respeto) y en un conjunto de prácticas técnicas concretas: integración continua, pruebas automatizadas antes de escribir la funcionalidad (TDD), diseño simple, refactorización constante, entregas frecuentes en ciclos muy cortos, propiedad colectiva del código y programación en pareja.

### Comparación Scrum vs. XP

| Criterio | Scrum | XP |
|---|---|---|
| Enfoque principal | Gestión y organización del trabajo en equipo | Prácticas técnicas de ingeniería de software |
| Duración de iteraciones | Sprints de 1 a 4 semanas | Iteraciones muy cortas (1-2 semanas) o entrega continua |
| Roles definidos | Product Owner, Scrum Master, equipo de desarrollo | No define roles formales, equipo autoorganizado |
| Énfasis | Planeación, revisión y retrospectiva por sprint | Calidad técnica del código mediante pruebas y refactorización constantes |
| Cambios a mitad de ciclo | Se evita modificar el sprint en curso | Acepta cambios de requisitos en cualquier momento |
| Prácticas técnicas obligatorias | No especifica prácticas de codificación | Integración continua, TDD, refactorización, pair programming |

### Justificación: por qué se adoptó Scrum como base y se incorporaron prácticas de XP

Para MediCore se decidió usar **Scrum como marco de organización del proyecto** (planeación por producto/entregable, revisión con la docente como stakeholder y retrospectiva entre entregas), pero durante la construcción real del sistema se identificó que varias de las **prácticas técnicas de XP se aplicaron de facto**, incluso sin haber adoptado XP de forma completa:

- **Integración continua y pruebas de regresión**: cada nueva API integrada (Google OAuth2, Google Calendar, SendGrid, Twilio OTP, OpenWeatherMap) se probó de inmediato contra el login y los módulos ya existentes antes de continuar, tal como exige la práctica de integración continua de XP (evidencia real: el cambio de `SameSite=Strict` a `SameSite=Lax` para permitir el flujo de OAuth2 se validó de inmediato contra el login tradicional, documentado en la sección 2.2 de la Actividad 4.1).
- **Refactorización constante**: al detectar que un fallo de envío de OTP por Twilio dejaba al usuario atrapado en la pantalla de verificación sin poder continuar, se refactorizó `login.php` para que el sistema recayera automáticamente en autenticación de un solo factor si el OTP no podía enviarse, sin romper el flujo existente.
- **Diseño simple y entregas frecuentes**: en lugar de planear de inicio la integración de las ocho APIs, se fueron incorporando una por una, cada una como una entrega funcional e independiente, reduciendo el riesgo de romper el sistema completo por un solo cambio grande.
- **Retroalimentación rápida**: el uso de `seguridad.log` con mensajes detallados de error (código HTTP, error de cURL, cuerpo de respuesta truncado) permitió obtener retroalimentación inmediata sobre fallas reales de integración (por ejemplo, el límite diario de mensajes de Twilio, error 63038) en vez de esperar a un ciclo de revisión formal.

**Conclusión de la comparación**: se optó por un enfoque híbrido donde Scrum aporta la estructura de planeación y entrega por producto (Producto 1, 2, 3...) exigida por la propia dinámica de la materia, mientras que XP aporta las prácticas técnicas de calidad (integración continua, refactorización, pruebas antes de avanzar) que en la práctica garantizaron que cada nueva funcionalidad no rompiera lo ya construido. Se descartó adoptar XP de forma completa porque prácticas como la programación en pareja formal no son viables con la carga de trabajo individual de la actividad, y porque Scrum se ajusta mejor a la entrega por productos parciales que exige la evaluación de la materia.
