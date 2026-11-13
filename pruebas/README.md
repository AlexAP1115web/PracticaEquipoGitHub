# Pruebas y scripts del flujo de liberación de MediCore

Los scripts del repositorio se agrupan en los cuatro tipos que pide el flujo de liberación y despliegue continuo.

## 1. Scripts del flujo de trabajo (pipeline)

| Archivo | Qué hace |
|---|---|
| `.github/workflows/ci.yml` | **CI MediCore.** En cada push o pull request a `main` levanta una máquina limpia con PHP 8.2 y MySQL 8.0 y corre las tres etapas de pruebas. Railway tiene activado *Wait for CI*: solo publica el commit cuando este flujo termina bien. |
| `.github/workflows/despliegue.yml` | **Verificar despliegue.** Cuando CI MediCore aprueba un push a `main`, espera con `verificar_despliegue.sh` a que producción publique ese commit y registra el tiempo de entrega. |
| `.github/workflows/monitoreo.yml` | **Monitoreo MediCore.** Cada 30 minutos revisa producción con `monitoreo.sh` y falla si algo no responde o tarda más de lo acordado. |

## 2. Scripts para la generación del entorno de liberación

| Archivo | Qué hace |
|---|---|
| `entorno.sh` | Prepara una máquina limpia: revisa PHP y sus extensiones, instala las dependencias de Composer, crea las carpetas del sistema y llama a `preparar_bd.sh`. |
| `preparar_bd.sh` | Borra y crea la base de datos de prueba con `database/schema.sql`. Las tablas nuevas las crea el propio sistema con `config/esquema.php` en la primera visita, igual que en Railway. |
| `datos_prueba.php` | Carga el médico de prueba, dos pacientes y un expediente con cita. |

## 3. Scripts de pruebas en el entorno de liberación

| Etapa | Archivo | Qué revisa |
|---|---|---|
| 1 | `sintaxis.sh` | `php -l` en todos los archivos PHP. |
| 2 | `preparar_bd.sh` | Que el esquema se importe sin errores en una base nueva. |
| 3 | `humo.sh` + `humo.php` | Levanta el sistema y corre 63 pruebas de humo: páginas públicas, la sesión guardada en MySQL, la hora de la base de datos (`salud.php` reporta el desfase con la hora de México), sesión, los módulos del médico, el reporte en PDF, las tablas que crea `config/esquema.php`, las APIs (SomaMap, pre-consulta y traslados), el correo de prueba, la zona horaria de las citas y el cierre de sesión. También revisa que el registro del servidor no tenga errores de PHP. |
| Producción | `monitoreo.sh` | `/salud.php`, `/login.php` y `/home.php` contra los límites de tiempo acordados (1,500 y 3,000 ms) y el desfase de hora de la base de datos. |

## 4. Scripts para la generación del despliegue

| Archivo | Qué hace |
|---|---|
| `../Dockerfile` | Imagen que construye Railway: `shinsenter/php:8.2-fpm-apache`, extensión mbstring, carpetas del sistema y `.env` vacío para que manden las variables de Railway. |
| `../docker-entrypoint.sh` | Pone a Apache a escuchar en el puerto que asigna Railway (`PORT`) y lo arranca. |
| `../.dockerignore` | Deja fuera de la imagen el código de pruebas, los flujos de GitHub, los registros y el `.env`. |
| `../salud.php` | Punto de salud que Railway consulta antes de activar una versión (healthcheck) y que revisa el monitoreo. Reporta la versión publicada, el tiempo de la base de datos y su desfase de hora. |
| `verificar_despliegue.sh` | Después del push espera a que `/salud.php` reporte el commit nuevo (máximo 15 minutos) y mide el tiempo de entrega. |

## Correrlo en local

```bash
export DB_HOST=127.0.0.1 DB_PORT=3306 DB_USER=root DB_PASS= DB_NAME=medicore_ci
bash pruebas/entorno.sh     # PHP, Composer, carpetas y base de prueba
bash pruebas/sintaxis.sh
bash pruebas/humo.sh
```

Usa una base aparte (`medicore_ci`); nunca la de XAMPP con datos.
