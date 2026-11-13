<?php
/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   SESIONES EN MYSQL
   Railway crea un contenedor nuevo en cada despliegue y borraba la carpeta sessions_data,
   así que cada versión nueva cerraba la sesión de todos los médicos. Guardadas en la base de
   datos, las sesiones sobreviven a los despliegues y a los reinicios.
   - Usa su propia conexión a MySQL para no mezclarse con las consultas de la página.
   - Un candado por sesión (GET_LOCK) evita que dos peticiones al mismo tiempo se pisen,
     igual que hacía el archivo.
   - Las sesiones sin uso por más de SESSION_TIMEOUT se borran solas.
===================================================== */

if (!class_exists('SesionesMySQL')) {
    class SesionesMySQL implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
    {
        private $bd;
        private $vida;
        private $candado = null;

        public function __construct(mysqli $bd, int $vida)
        {
            $this->bd = $bd;
            $this->vida = $vida;
        }

        private function crearTabla(): void
        {
            $this->bd->query("
                CREATE TABLE IF NOT EXISTS sesiones (
                    id VARCHAR(128) NOT NULL PRIMARY KEY,
                    datos MEDIUMBLOB NOT NULL,
                    actualizado INT UNSIGNED NOT NULL,
                    INDEX idx_sesiones_actualizado (actualizado)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        // Ejecuta la consulta y, si la tabla aún no existe (error 1146), la crea y reintenta
        private function consulta(string $sql, string $tipos, ...$valores)
        {
            for ($intento = 0; $intento < 2; $intento++) {
                try {
                    $stmt = $this->bd->prepare($sql);
                    if ($tipos !== '') {
                        $stmt->bind_param($tipos, ...$valores);
                    }
                    $stmt->execute();
                    return $stmt;
                } catch (mysqli_sql_exception $e) {
                    if ($e->getCode() !== 1146 || $intento > 0) {
                        throw $e;
                    }
                    $this->crearTabla();
                }
            }
            return null;
        }

        public function open(string $path, string $name): bool
        {
            return true;
        }

        public function close(): bool
        {
            if ($this->candado !== null) {
                $this->bd->query("SELECT RELEASE_LOCK('{$this->candado}')")->free();
                $this->candado = null;
            }
            return true;
        }

        public function read(string $id): string|false
        {
            // El nombre del candado solo tiene letras y el md5, no necesita escaparse
            $this->candado = 'medicore_sesion_' . md5($id);
            $this->bd->query("SELECT GET_LOCK('{$this->candado}', 10)")->free();

            $limite = time() - $this->vida;
            $fila = $this->consulta("SELECT datos FROM sesiones WHERE id = ? AND actualizado > ?", 'si', $id, $limite)
                ->get_result()->fetch_row();

            return $fila ? $fila[0] : '';
        }

        public function write(string $id, string $data): bool
        {
            $ahora = time();
            $this->consulta("
                INSERT INTO sesiones (id, datos, actualizado) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE datos = ?, actualizado = ?
            ", 'ssisi', $id, $data, $ahora, $data, $ahora);
            return true;
        }

        public function destroy(string $id): bool
        {
            $this->consulta("DELETE FROM sesiones WHERE id = ?", 's', $id);
            return true;
        }

        public function gc(int $max_lifetime): int|false
        {
            $limite = time() - $this->vida;
            return $this->consulta("DELETE FROM sesiones WHERE actualizado < ?", 'i', $limite)->affected_rows;
        }

        // Con session.use_strict_mode solo se aceptan identificadores que existen en la tabla
        public function validateId(string $id): bool
        {
            $limite = time() - $this->vida;
            return (bool) $this->consulta("SELECT 1 FROM sesiones WHERE id = ? AND actualizado > ?", 'si', $id, $limite)
                ->get_result()->fetch_row();
        }

        // La sesión no cambió: solo se renueva la hora para que no caduque mientras se usa
        public function updateTimestamp(string $id, string $data): bool
        {
            $ahora = time();
            $this->consulta("UPDATE sesiones SET actualizado = ? WHERE id = ?", 'is', $ahora, $id);
            return true;
        }
    }
}
