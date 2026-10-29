<?php

/* =====================================================
   MEDICORE - DATOS DE EJEMPLO PARA LAS PRUEBAS AUTOMÁTICAS
   Médico de prueba, dos pacientes (uno con alergias) y un expediente con cita.
   Solo corre desde la terminal; nunca en el servidor.
===================================================== */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$bd = new mysqli(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASS'), getenv('DB_NAME'), (int) getenv('DB_PORT'));
$bd->set_charset('utf8mb4');

$correo = getenv('CI_CORREO') ?: 'ci@medicore.test';
$clave = password_hash(getenv('CI_PASSWORD') ?: 'PruebaCI-2026', PASSWORD_DEFAULT);
$nombre = 'Médico de Pruebas';

$stmt = $bd->prepare("INSERT INTO medicos (nombre, correo, password) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $nombre, $correo, $clave);
$stmt->execute();

$bd->query("
    INSERT INTO usuarios (nombre, email, telefono, direccion, edad, rol) VALUES
    ('Paciente de Prueba Uno', 'paciente1@medicore.test', '2220000001', 'Puebla', 34, 'paciente'),
    ('Paciente de Prueba Dos', 'paciente2@medicore.test', '2220000002', 'Puebla', 58, 'paciente')
");

$manana = date('Y-m-d', strtotime('+1 day'));
$bd->query("
    INSERT INTO expedientes (usuario_id, presion_arterial, peso, estatura, imc, diagnostico, tipo_dieta, receta, plan_entrenamiento, dieta_autorizada, fecha_cita, hora_cita)
    VALUES (1, '120/80', 70, 1.70, 24.22, 'Control general', 'Dieta Normocalórica', 'Paracetamol 500 mg', 'Caminata 30 min', 'autorizada', '$manana', '10:00:00')
");

echo "Datos de ejemplo listos: 1 médico, 2 pacientes, 1 expediente.\n";
