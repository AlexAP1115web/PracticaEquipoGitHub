-- =====================================================
-- MEDICORE PROFESSIONAL SYSTEM
-- Migración: alergias, asistente de medicamentos,
-- notificaciones push y notas de consulta
-- =====================================================
-- config/esquema.php ya las crea solo, esto es por si
-- hay que hacerlo a mano (phpMyAdmin o Data en Railway).
-- =====================================================

-- Alergias del paciente separadas por comas
ALTER TABLE usuarios ADD COLUMN alergias VARCHAR(255) NULL AFTER edad;

-- Historial del asistente de medicamentos
CREATE TABLE IF NOT EXISTS chatbot_consultas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medico_id INT NOT NULL,
    paciente_id INT NULL,
    pregunta VARCHAR(300) NOT NULL,
    medicamento VARCHAR(255) NULL,
    tema VARCHAR(80) NULL,
    fuente VARCHAR(80) NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dispositivos suscritos a notificaciones push
CREATE TABLE IF NOT EXISTS push_suscripciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medico_id INT NOT NULL,
    endpoint TEXT NOT NULL,
    endpoint_hash CHAR(64) NOT NULL UNIQUE,
    p256dh VARCHAR(255) NULL,
    auth VARCHAR(255) NULL,
    navegador VARCHAR(255) NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Mensajes pendientes que recoge el service worker
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Citas que ya recibieron recordatorio
CREATE TABLE IF NOT EXISTS push_recordatorios (
    expediente_id INT NOT NULL,
    fecha_cita DATE NOT NULL,
    enviado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (expediente_id, fecha_cita)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notas de consulta en formato SOAP (voz o texto)
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
