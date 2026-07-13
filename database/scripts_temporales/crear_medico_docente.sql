-- =====================================================
-- MEDICORE PROFESSIONAL SYSTEM
-- Cuenta de acceso para la docente (evaluación)
-- Docente: M.T.I. Haydeé Gómez Díaz
-- Correo:  haydee.gomez@utpuebla.edu.mx
-- Password: MediCore2026!  (bcrypt, 12 rounds)
-- Sin teléfono registrado -> el login NO pedirá OTP,
-- entra directo con correo + contraseña.
-- =====================================================

INSERT INTO medicos (nombre, correo, password)
VALUES (
    'Haydeé Gómez Díaz',
    'haydee.gomez@utpuebla.edu.mx',
    '$2b$12$sP7KgeJt5Exz5pwtHjCY1eHX.kNarpReSSlOL1.Xg/PdiQX9c38tu'
);
