-- =====================================================
-- MEDICORE PROFESSIONAL SYSTEM
-- Migración: unificar la fecha de alta de usuarios
-- =====================================================
-- Crea creado_en en usuarios y copia lo que haya en fecha_registro.
-- Se puede correr más de una vez.
-- =====================================================

ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

SET @hay_fecha_registro = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'usuarios'
      AND COLUMN_NAME = 'fecha_registro'
);

SET @copiar = IF(
    @hay_fecha_registro > 0,
    'UPDATE usuarios SET creado_en = fecha_registro WHERE fecha_registro IS NOT NULL',
    'DO 0'
);

PREPARE copiar_fechas FROM @copiar;
EXECUTE copiar_fechas;
DEALLOCATE PREPARE copiar_fechas;
