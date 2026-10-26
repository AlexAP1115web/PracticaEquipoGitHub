-- =====================================================
-- MEDICORE PROFESSIONAL SYSTEM
-- Tres avisos push por cita: una semana antes, un día antes y el mismo día
-- Lo hace solo config/esquema.php al abrir el sistema; este archivo es por si se quiere
-- correr a mano en phpMyAdmin (XAMPP o Railway). Solo una vez.
-- =====================================================

ALTER TABLE push_recordatorios
    ADD COLUMN aviso VARCHAR(12) NOT NULL DEFAULT 'hoy' AFTER fecha_cita,
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (expediente_id, fecha_cita, aviso);

UPDATE push_recordatorios SET aviso = 'manana' WHERE fecha_cita > DATE(enviado_en);
