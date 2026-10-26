-- =====================================================
-- MEDICORE PROFESSIONAL SYSTEM
-- Mapa corporal de molestias
-- Lo crea solo config/esquema.php al abrir el sistema; este archivo es por si se quiere
-- importar a mano en phpMyAdmin (XAMPP o Railway)
-- =====================================================

-- Cada registro es lo que marcó el paciente en una consulta
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Molestias de cada registro: zona, qué siente, desde cuándo e intensidad (1 a 10)
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
