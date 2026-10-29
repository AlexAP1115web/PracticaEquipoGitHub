# MediCore Professional System

Sistema web de gestión clínica (PHP 8.2 + MySQL) instalable como aplicación web progresiva.
Publicado en Railway: https://practicaequipogithub-production.up.railway.app

## Flujo de liberación y despliegue continuo

Cada cambio que llega a `main` pasa por este flujo:

1. **Push o pull request a `main`** en GitHub.
2. **GitHub Actions (`.github/workflows/ci.yml`)** corre las pruebas en una máquina limpia con PHP 8.2 y MySQL 8.0:
   - Etapa 1: `pruebas/sintaxis.sh` revisa la sintaxis de todos los archivos PHP.
   - Etapa 2: `pruebas/preparar_bd.sh` crea una base de prueba con `database/schema.sql` y datos de ejemplo.
   - Etapa 3: `pruebas/humo.sh` levanta el sistema y revisa páginas públicas, sesión, módulos, esquema y APIs.
3. **Railway** tiene activado *Wait for CI*: el despliegue espera a que termine el flujo y se cancela si falla.
4. Railway construye la imagen con el `Dockerfile` y antes de dirigir el tráfico revisa `/salud.php` (healthcheck).
   Si no responde 200, la versión anterior sigue publicada.
5. **Monitoreo (`.github/workflows/monitoreo.yml`)**: cada 30 minutos revisa `/salud.php`, `/login.php` y `/home.php`
   en producción y falla si algo no responde o tarda más de lo acordado.

Detalle de cada prueba: [`pruebas/README.md`](pruebas/README.md).

## Entorno

| Entorno | Servidor | Base de datos |
|---|---|---|
| Desarrollo | XAMPP (Apache + PHP 8.2) | MySQL/MariaDB local `MediCore_db` |
| Pruebas | GitHub Actions, `ubuntu-latest`, servidor integrado de PHP | MySQL 8.0 desechable `medicore_ci` |
| Producción | Railway, imagen `shinsenter/php:8.2-fpm-apache` | MySQL de Railway con volumen |

Las variables (`DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME` y las llaves de las APIs) van en el `.env`
local o en las variables de Railway; nunca en el repositorio. Las tablas nuevas las crea `config/esquema.php`
la primera vez que se abre el sistema.

## Equipo

Pérez Alcántara Alejandro, De la Cruz Méndez Cristofer y Yáñez Garrido Alejandro.
Universidad Tecnológica de Puebla, 10° "D".
