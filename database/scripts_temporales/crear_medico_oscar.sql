-- Crea la cuenta de médico para Oscar Estrada Pacheco
-- Contraseña temporal (sin cifrar, solo para que se la compartas): MediCore2026!
-- Sin teléfono registrado => no le pedirá OTP al iniciar sesión.

INSERT INTO medicos (nombre, correo, password)
VALUES (
    'Oscar Estrada Pacheco',
    '2311081304@alumno.utpuebla.edu.mx',
    '$2b$12$.3lnVHVK4Gd64OZ2Z/4kG.wv3FT8dyN8FQCzeQ6DambwdNk9AbRRW'
);
