<?php

/* =====================================================
   MEDICORE PROFESSIONAL SYSTEM
   ACTUALIZACIÓN AUTOMÁTICA DEL ESQUEMA
   Tablas de alergias, chatbot, push, notas y mapa corporal, y llaves con ON DELETE CASCADE para que
   al borrar un paciente se borre todo lo suyo. Corre una vez y deja una marca
   (el mismo SQL está en database/migracion_innovacion.sql, database/migracion_cascada.sql
   y database/migracion_mapa_corporal.sql)
===================================================== */

if (!function_exists('asegurarBorradoEnCascada')) {
    // Deja la llave $tabla.$columna -> $tablaPadre.id con ON DELETE CASCADE.
    // Las bases importadas de antes tenían expedientes_ibfk_1 sin CASCADE (error #1451 al borrar)
    function asegurarBorradoEnCascada($conexion, $tabla, $columna, $tablaPadre, $nombreLlave)
    {
        $stmt = $conexion->prepare("
            SELECT k.CONSTRAINT_NAME, r.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
            WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.COLUMN_NAME = ? AND k.REFERENCED_TABLE_NAME = ?
        ");
        $stmt->bind_param("sss", $tabla, $columna, $tablaPadre);
        $stmt->execute();
        $llaves = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($llaves as $llave) {
            if ($llave['DELETE_RULE'] === 'CASCADE') {
                return;
            }
        }

        foreach ($llaves as $llave) {
            $conexion->query("ALTER TABLE `$tabla` DROP FOREIGN KEY `" . str_replace('`', '', $llave['CONSTRAINT_NAME']) . "`");
        }

        // Registros que apuntan a un paciente o expediente que ya no existe (si no, la llave no se puede crear)
        $conexion->query("DELETE FROM `$tabla` WHERE `$columna` IS NOT NULL AND `$columna` NOT IN (SELECT id FROM `$tablaPadre`)");
        $conexion->query("
            ALTER TABLE `$tabla` ADD CONSTRAINT `$nombreLlave`
            FOREIGN KEY (`$columna`) REFERENCES `$tablaPadre`(id) ON DELETE CASCADE
        ");
    }
}

if (!function_exists('prepararEsquemaInnovacion')) {
    function prepararEsquemaInnovacion($conexion)
    {
        static $listo = false;

        if ($listo) {
            return;
        }

        // Marca en logs/, o en sessions_data/ si en Railway no deja escribir
        // v3 = notas_consulta y borrado en cascada de todo lo del paciente
        // v4 = mapa corporal de molestias y tres avisos por cita (semana, mañana y hoy)
        $marcas = [
            __DIR__ . '/../logs/.esquema_innovacion_v4',
            __DIR__ . '/../sessions_data/.esquema_innovacion_v4'
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

            // Avisos de cita ya enviados (evita repetidos). aviso = semana, manana u hoy
            $conexion->query("
                CREATE TABLE IF NOT EXISTS push_recordatorios (
                    expediente_id INT NOT NULL,
                    fecha_cita DATE NOT NULL,
                    aviso VARCHAR(12) NOT NULL DEFAULT 'hoy',
                    enviado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (expediente_id, fecha_cita, aviso)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Bases de antes: un solo aviso por cita. Se agrega la columna aviso a la llave
            $columnaAviso = $conexion->query("SHOW COLUMNS FROM push_recordatorios LIKE 'aviso'");

            if ($columnaAviso && $columnaAviso->num_rows === 0) {
                $conexion->query("
                    ALTER TABLE push_recordatorios
                        ADD COLUMN aviso VARCHAR(12) NOT NULL DEFAULT 'hoy' AFTER fecha_cita,
                        DROP PRIMARY KEY,
                        ADD PRIMARY KEY (expediente_id, fecha_cita, aviso)
                ");
                // Lo que ya se avisó un día antes queda como "manana" para no repetirlo
                $conexion->query("UPDATE push_recordatorios SET aviso = 'manana' WHERE fecha_cita > DATE(enviado_en)");
            }

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

            // Mapa corporal: cada registro es lo que marcó el paciente en una consulta
            $conexion->query("
                CREATE TABLE IF NOT EXISTS mapa_registros (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    id_cliente CHAR(36) NOT NULL UNIQUE,
                    paciente_id INT NOT NULL,
                    medico_id INT NOT NULL,
                    comentario VARCHAR(300) NULL,
                    creado_en DATETIME NOT NULL,
                    INDEX idx_mapa_paciente (paciente_id, creado_en),
                    CONSTRAINT fk_mapa_registros_paciente
                        FOREIGN KEY (paciente_id) REFERENCES usuarios(id)
                        ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Molestias de cada registro: zona del cuerpo, qué siente, desde cuándo e intensidad (1 a 10)
            $conexion->query("
                CREATE TABLE IF NOT EXISTS mapa_molestias (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    registro_id INT NOT NULL,
                    zona VARCHAR(40) NOT NULL,
                    sensacion VARCHAR(30) NOT NULL,
                    intensidad TINYINT UNSIGNED NOT NULL,
                    desde DATE NULL,
                    detalle VARCHAR(200) NULL,
                    INDEX idx_mapa_zona (zona),
                    CONSTRAINT fk_mapa_molestias_registro
                        FOREIGN KEY (registro_id) REFERENCES mapa_registros(id)
                        ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Al borrar un paciente (desde la app o desde phpMyAdmin) se borra todo lo suyo
            $cascadas = [
                ['expedientes', 'usuario_id', 'usuarios', 'fk_expedientes_usuario'],
                ['notas_consulta', 'paciente_id', 'usuarios', 'fk_notas_consulta_paciente'],
                ['chatbot_consultas', 'paciente_id', 'usuarios', 'fk_chatbot_paciente'],
                ['push_recordatorios', 'expediente_id', 'expedientes', 'fk_recordatorio_expediente'],
                ['mapa_registros', 'paciente_id', 'usuarios', 'fk_mapa_registros_paciente']
            ];

            foreach ($cascadas as $c) {
                try {
                    asegurarBorradoEnCascada($conexion, $c[0], $c[1], $c[2], $c[3]);
                } catch (Exception $e) {
                    if (function_exists('registrarLog')) {
                        registrarLog("No se pudo poner ON DELETE CASCADE en {$c[0]}.{$c[1]}: " . $e->getMessage(), "WARNING");
                    }
                }
            }

            foreach ($marcas as $marca) {
                if (@file_put_contents($marca, date('Y-m-d H:i:s')) !== false) {
                    break;
                }
            }
            $listo = true;

            if (function_exists('registrarLog')) {
                registrarLog("Esquema de módulos nuevos verificado (alergias, chatbot, push, notas de consulta, mapa corporal y borrado en cascada).", "INFO");
            }
        } catch (Exception $e) {
            if (function_exists('registrarLog')) {
                registrarLog("No se pudo preparar el esquema de módulos nuevos: " . $e->getMessage(), "CRITICAL");
            }
        }
    }
}
