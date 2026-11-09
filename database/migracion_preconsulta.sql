-- =====================================================
-- MEDICORE PROFESSIONAL SYSTEM
-- Pre-consulta por QR
-- Lo crea solo config/esquema.php al abrir el sistema; este archivo es por si se quiere
-- importar a mano en phpMyAdmin (XAMPP o Railway)
-- =====================================================

-- Cada fila es un enlace de un solo uso que el médico genera para un paciente.
-- El paciente lo abre con el QR, contesta el cuestionario y marca en SomaMap dónde siente algo.
CREATE TABLE IF NOT EXISTS preconsultas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token CHAR(48) NOT NULL UNIQUE,
    paciente_id INT NOT NULL,
    medico_id INT NOT NULL,
    estado VARCHAR(12) NOT NULL DEFAULT 'pendiente',   -- pendiente o respondida
    respuestas TEXT NULL,                               -- cuestionario en JSON
    registro_mapa_id INT NULL,                          -- registro de SomaMap que se guardó
    resumen TEXT NULL,                                  -- resumen para el médico
    resumen_fuente VARCHAR(10) NULL,                    -- ia o auto
    creado_en DATETIME NOT NULL,
    expira_en DATETIME NOT NULL,
    respondida_en DATETIME NULL,
    INDEX idx_preconsulta_paciente (paciente_id, creado_en),
    CONSTRAINT fk_preconsultas_paciente
        FOREIGN KEY (paciente_id) REFERENCES usuarios(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
