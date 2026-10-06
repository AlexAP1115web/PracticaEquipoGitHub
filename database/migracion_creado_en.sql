-- =====================================================
-- MEDICORE PROFESSIONAL SYSTEM
-- Migración: unificar la fecha de alta de usuarios
-- =====================================================
-- pacientes.php y reportes.php consultaban la columna
-- "fecha_registro" de la tabla usuarios, pero el esquema
-- del proyecto (database/schema.sql) la define como
-- "creado_en". Con ese nombre desalineado las dos páginas
-- terminaban en error 500 (pantalla en blanco).
--
-- El código ya usa "creado_en". Este script deja la base
-- de datos en ese mismo estado sin perder información:
--   1. Crea "creado_en" si todavía no existe.
--   2. Si la tabla traía "fecha_registro" con datos, los
--      copia a "creado_en".
--
-- Se puede ejecutar varias veces sin causar daño.
--
-- CÓMO USARLO:
--   phpMyAdmin -> base de datos "MediCore_db" -> pestaña SQL
--   -> pegar y ejecutar.
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
