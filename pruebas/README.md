# Pruebas automáticas de MediCore

Las corre GitHub Actions en cada push o pull request a `main` (`.github/workflows/ci.yml`).
Railway tiene activado **Wait for CI**, así que solo publica un commit cuando estas pruebas pasan.

| Etapa | Script | Qué revisa |
|---|---|---|
| 1 | `sintaxis.sh` | `php -l` en todos los archivos PHP |
| 2 | `preparar_bd.sh` + `datos_prueba.php` | Crea una base MySQL nueva con `database/schema.sql` y datos de ejemplo |
| 3 | `humo.sh` + `humo.php` | Levanta el sistema y revisa páginas públicas, sesión, los 15 módulos, tablas creadas por `config/esquema.php`, APIs y cierre de sesión |

`monitoreo.sh` lo usa `.github/workflows/monitoreo.yml` cada 30 minutos para revisar producción
(`/salud.php`, `/login.php` y `/home.php`) contra los límites de tiempo acordados.

## Correrlas en local

```bash
export DB_HOST=127.0.0.1 DB_PORT=3306 DB_USER=root DB_PASS= DB_NAME=medicore_ci
bash pruebas/sintaxis.sh
bash pruebas/preparar_bd.sh   # borra y crea la base medicore_ci
bash pruebas/humo.sh
```

Usa una base aparte (`medicore_ci`); nunca la de XAMPP con datos.
