-- =====================================================
-- MEDICORE PROFESSIONAL SYSTEM
-- ESQUEMA DE BASE DE DATOS (MySQL / MariaDB - XAMPP)
-- =====================================================
-- Este archivo documenta las tablas que el sistema ya usa
-- (inferidas del código, ya que no existía un .sql previo)
-- y agrega las tablas/columnas nuevas necesarias para las
-- mejoras: recuperación de contraseña, OTP, login con
-- Google, catálogo de enfermedades, videollamadas y
-- sucursales/ubicación.
--
-- CÓMO USARLO:
-- 1. Abre phpMyAdmin -> base de datos "MediCore_db" -> pestaña SQL.
-- 2. Pega y ejecuta este archivo completo UNA SOLA VEZ.
--    Las tablas existentes usan "CREATE TABLE IF NOT EXISTS",
--    así que no se dañan tus datos actuales si ya existen.
-- 3. Los "ALTER TABLE" agregan columnas nuevas; si ya las
--    ejecutaste antes, quita esas líneas para no duplicar.
-- =====================================================

-- -----------------------------------------------------
-- TABLAS EXISTENTES (documentadas, no destructivas)
-- -----------------------------------------------------

CREATE TABLE IF NOT EXISTS medicos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    correo VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    foto_perfil VARCHAR(255) NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    telefono VARCHAR(20) NULL,
    direccion VARCHAR(255) NULL,
    edad INT NULL,
    password VARCHAR(255) NULL,
    rol ENUM('paciente','medico','admin') NOT NULL DEFAULT 'paciente',
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expedientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    peso DECIMAL(6,2) NULL,
    estatura DECIMAL(4,2) NULL,
    imc DECIMAL(6,2) NULL,
    presion_arterial VARCHAR(20) NULL,
    diagnostico TEXT NULL,
    receta TEXT NULL,
    tipo_dieta VARCHAR(100) NULL,
    dieta_autorizada ENUM('pendiente','autorizada','denegada') NOT NULL DEFAULT 'pendiente',
    fecha_cita DATE NULL,
    hora_cita TIME NULL,
    plan_entrenamiento TEXT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_expedientes_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contactos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    correo VARCHAR(150) NOT NULL,
    asunto VARCHAR(200) NULL,
    mensaje TEXT NOT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- TABLAS NUEVAS (para las integraciones y módulos)
-- -----------------------------------------------------

-- Recuperación de contraseña vía SendGrid
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medico_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expira DATETIME NOT NULL,
    usado TINYINT(1) NOT NULL DEFAULT 0,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_password_resets_medico
        FOREIGN KEY (medico_id) REFERENCES medicos(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Códigos OTP vía Twilio (segundo factor opcional)
CREATE TABLE IF NOT EXISTS otp_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medico_id INT NOT NULL,
    codigo VARCHAR(6) NOT NULL,
    expira DATETIME NOT NULL,
    usado TINYINT(1) NOT NULL DEFAULT 0,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_otp_codes_medico
        FOREIGN KEY (medico_id) REFERENCES medicos(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Catálogo de tipos de enfermedades
CREATE TABLE IF NOT EXISTS enfermedades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    categoria VARCHAR(100) NULL,
    descripcion TEXT NULL,
    recomendaciones TEXT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sucursales / clínicas para el módulo de Ubicación (Google Maps)
CREATE TABLE IF NOT EXISTS sucursales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    direccion VARCHAR(255) NOT NULL,
    lat DECIMAL(10,7) NULL,
    lng DECIMAL(10,7) NULL,
    telefono VARCHAR(20) NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- COLUMNAS NUEVAS EN TABLAS EXISTENTES
-- (ejecutar UNA SOLA VEZ; si ya existen, quita la línea)
-- -----------------------------------------------------

-- Login con Google OAuth2 (vincula la cuenta de Google al médico)
ALTER TABLE medicos ADD COLUMN google_id VARCHAR(255) NULL UNIQUE AFTER foto_perfil;

-- Teléfono del médico, requerido para enviar el código OTP por Twilio
ALTER TABLE medicos ADD COLUMN telefono VARCHAR(20) NULL AFTER google_id;

-- Vinculación con Google Calendar (para sincronizar citas)
ALTER TABLE medicos ADD COLUMN google_refresh_token TEXT NULL AFTER telefono;
ALTER TABLE medicos ADD COLUMN google_calendar_conectado TINYINT(1) NOT NULL DEFAULT 0 AFTER google_refresh_token;

-- Liga de videollamada (Jitsi Meet) generada por cita
ALTER TABLE expedientes ADD COLUMN video_link VARCHAR(255) NULL AFTER hora_cita;

-- Relación opcional con el catálogo de enfermedades (no obliga a usarlo)
ALTER TABLE expedientes ADD COLUMN enfermedad_id INT NULL AFTER video_link;
ALTER TABLE expedientes ADD CONSTRAINT fk_expedientes_enfermedad
    FOREIGN KEY (enfermedad_id) REFERENCES enfermedades(id) ON DELETE SET NULL;

-- -----------------------------------------------------
-- DATOS DE EJEMPLO (opcional, para probar los módulos nuevos)
-- -----------------------------------------------------

INSERT INTO enfermedades (nombre, categoria, descripcion, recomendaciones) VALUES
('Diabetes tipo 2', 'Metabólica', 'Trastorno crónico del metabolismo de la glucosa.', 'Controlar consumo de azúcares, actividad física regular, monitoreo de glucosa.'),
('Hipertensión arterial', 'Cardiovascular', 'Presión arterial elevada de forma sostenida.', 'Reducir consumo de sodio, evitar estrés, control de peso.'),
('Obesidad', 'Metabólica', 'Exceso de grasa corporal (IMC >= 30).', 'Plan nutricional supervisado, actividad física progresiva.'),
('Asma', 'Respiratoria', 'Inflamación crónica de las vías respiratorias.', 'Evitar alérgenos, uso de inhalador según indicación médica.'),
('Gripe estacional', 'Infecciosa', 'Infección viral respiratoria común.', 'Hidratación, reposo, vacunación anual.');

INSERT INTO sucursales (nombre, direccion, lat, lng, telefono) VALUES
('MediCore Puebla Centro', 'Universidad Tecnológica de Puebla, Puebla, Pue.', 19.111, -98.174, '222-000-0000');
