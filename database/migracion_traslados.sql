-- =====================================================
-- MEDICORE PROFESSIONAL SYSTEM
-- Traslado a la cita presencial
-- Lo crea solo config/esquema.php al abrir el sistema; este archivo es por si se quiere
-- importar a mano en phpMyAdmin (XAMPP o Railway)
-- =====================================================

-- El consultorio lleva y trae al paciente a su cita presencial.
-- Lo pide el médico desde el expediente o el paciente desde su pre-consulta por QR.
CREATE TABLE IF NOT EXISTS traslados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paciente_id INT NOT NULL,
    medico_id INT NULL,
    sucursal_id INT NULL,                                -- a qué sucursal se lleva
    tipo VARCHAR(12) NOT NULL,                           -- ida, vuelta o ida_vuelta
    direccion VARCHAR(255) NOT NULL,                     -- dónde se recoge al paciente
    notas VARCHAR(200) NULL,                             -- silla de ruedas, acompañante...
    minutos_antes SMALLINT UNSIGNED NOT NULL DEFAULT 45, -- cuánto antes de la cita se le recoge
    estado VARCHAR(12) NOT NULL DEFAULT 'solicitado',    -- solicitado, confirmado, en_camino, completado o cancelado
    origen VARCHAR(10) NOT NULL DEFAULT 'medico',        -- medico o paciente
    chofer_nombre VARCHAR(100) NULL,                     -- se asigna al confirmar
    chofer_telefono VARCHAR(20) NULL,
    vehiculo VARCHAR(80) NULL,                           -- modelo, color y placas
    creado_en DATETIME NOT NULL,
    actualizado_en DATETIME NOT NULL,
    confirmado_en DATETIME NULL,                         -- hora de cada cambio de estado
    en_camino_en DATETIME NULL,
    completado_en DATETIME NULL,
    cancelado_en DATETIME NULL,
    INDEX idx_traslados_paciente (paciente_id, estado),
    CONSTRAINT fk_traslados_paciente
        FOREIGN KEY (paciente_id) REFERENCES usuarios(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
