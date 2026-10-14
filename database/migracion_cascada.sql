-- Borrado en cascada: al borrar un paciente de usuarios se borra todo lo suyo
-- (expediente, citas, recordatorios push, notas de consulta y consultas del asistente).
-- Correr en phpMyAdmin > medicore_db > SQL. La app también lo hace sola (config/esquema.php).

-- Registros que apuntan a pacientes o expedientes que ya no existen
DELETE FROM push_recordatorios WHERE expediente_id NOT IN (SELECT id FROM expedientes);
DELETE FROM chatbot_consultas WHERE paciente_id IS NOT NULL AND paciente_id NOT IN (SELECT id FROM usuarios);

-- La llave vieja no tenía CASCADE (error #1451)
ALTER TABLE expedientes DROP FOREIGN KEY expedientes_ibfk_1;
ALTER TABLE expedientes ADD CONSTRAINT fk_expedientes_usuario
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE;

ALTER TABLE chatbot_consultas ADD CONSTRAINT fk_chatbot_paciente
    FOREIGN KEY (paciente_id) REFERENCES usuarios(id) ON DELETE CASCADE;

ALTER TABLE push_recordatorios ADD CONSTRAINT fk_recordatorio_expediente
    FOREIGN KEY (expediente_id) REFERENCES expedientes(id) ON DELETE CASCADE;
