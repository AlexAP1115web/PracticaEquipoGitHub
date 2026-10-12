<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   ACTUALIZACIÓN AUTOMÁTICA DEL ESQUEMA
   Tablas de alergias, chatbot, push y notas. Corre una vez y deja una marca
   (el mismo SQL está en database/migracion_innovacion.sql)
===================================================== */

if (!function_exists('prepararEsquemaInnovacion')) {
    function prepararEsquemaInnovacion($conexion)
    {
        static $listo = false;

        if ($listo) {
            return;
        }

        // Marca en logs/, o en sessions_data/ si en Railway no deja escribir
        // v2 = ya incluye notas_consulta
        $marcas = [
            __DIR__ . '/../logs/.esquema_innovacion_v2',
            __DIR__ . '/../sessions_data/.esquema_innovacion_v2'
        ];

        foreach ($marcas as $marca) {
            if (file_exists($marca)) {
                $listo = true;
                return;
            }
        }

        try {
            // Alergias del paciente
            $columna = $conexion->query("SHOW COLUMNS FROM usuarios LIKE 'alergias'");

            if ($columna && $columna->num_rows === 0) {
                $conexion->query("ALTER TABLE usuarios ADD COLUMN alergias VARCHAR(255) NULL AFTER edad");
            }

            // Historial del asistente de medicamentos
            $conexion->query("
                CREATE TABLE IF NOT EXISTS chatbot_consultas (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    medico_id INT NOT NULL,
                    paciente_id INT NULL,
                    pregunta VARCHAR(300) NOT NULL,
                    medicamento VARCHAR(255) NULL,
                    tema VARCHAR(80) NULL,
                    fuente VARCHAR(80) NULL,
                    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Dispositivos suscritos a notificaciones push
            $conexion->query("
                CREATE TABLE IF NOT EXISTS push_suscripciones (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    medico_id INT NOT NULL,
                    endpoint TEXT NOT NULL,
                    endpoint_hash CHAR(64) NOT NULL UNIQUE,
                    p256dh VARCHAR(255) NULL,
                    auth VARCHAR(255) NULL,
                    navegador VARCHAR(255) NULL,
                    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Mensajes que el service worker recoge al recibir el aviso
            $conexion->query("
                CREATE TABLE IF NOT EXISTS push_mensajes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    suscripcion_id INT NOT NULL,
                    titulo VARCHAR(150) NOT NULL,
                    cuerpo VARCHAR(500) NOT NULL,
                    url VARCHAR(255) NULL,
                    entregado TINYINT(1) NOT NULL DEFAULT 0,
                    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_push_mensajes_suscripcion
                        FOREIGN KEY (suscripcion_id) REFERENCES push_suscripciones(id)
                        ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Citas que ya recibieron recordatorio (evita avisos repetidos)
            $conexion->query("
                CREATE TABLE IF NOT EXISTS push_recordatorios (
                    expediente_id INT NOT NULL,
                    fecha_cita DATE NOT NULL,
                    enviado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (expediente_id, fecha_cita)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Notas SOAP. id_cliente viene del navegador para no duplicar notas offline
            $conexion->query("
                CREATE TABLE IF NOT EXISTS notas_consulta (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    id_cliente CHAR(36) NOT NULL UNIQUE,
                    paciente_id INT NOT NULL,
                    medico_id INT NOT NULL,
                    transcripcion TEXT NULL,
                    subjetivo TEXT NULL,
                    objetivo TEXT NULL,
                    analisis TEXT NULL,
                    plan TEXT NULL,
                    origen VARCHAR(20) NOT NULL DEFAULT 'texto',
                    creado_en DATETIME NOT NULL,
                    INDEX idx_notas_paciente (paciente_id, creado_en),
                    CONSTRAINT fk_notas_consulta_paciente
                        FOREIGN KEY (paciente_id) REFERENCES usuarios(id)
                        ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            foreach ($marcas as $marca) {
                if (@file_put_contents($marca, date('Y-m-d H:i:s')) !== false) {
                    break;
                }
            }
            $listo = true;

            if (function_exists('registrarLog')) {
                registrarLog("Esquema de módulos nuevos verificado (alergias, chatbot, push, notas de consulta).", "INFO");
            }
        } catch (Exception $e) {
            if (function_exists('registrarLog')) {
                registrarLog("No se pudo preparar el esquema de módulos nuevos: " . $e->getMessage(), "CRITICAL");
            }
        }
    }
}
