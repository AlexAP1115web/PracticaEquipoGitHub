<?php
include("config.php");
include("security.php");
require_once __DIR__ . "/config/esquema.php";

/* ======================================================
   ELIMINAR PACIENTE
   Se borra en orden (recordatorios, notas, expediente y usuario) dentro de una
   transacción, así funciona aunque la llave foránea no tenga ON DELETE CASCADE
====================================================== */

verificarSesion();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    redirigir("pacientes.php");
}

validarTokenCSRF($_POST['csrf_token'] ?? '');

$paciente_id = intval($_POST['paciente_id'] ?? 0);

if ($paciente_id <= 0) {
    redirigir("pacientes.php");
}

prepararEsquemaInnovacion($conexion);

$stmt = $conexion->prepare("SELECT id, nombre FROM usuarios WHERE id = ? AND rol = 'paciente' LIMIT 1");
$stmt->bind_param("i", $paciente_id);
$stmt->execute();
$paciente = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$paciente) {
    $_SESSION['aviso_pacientes'] = ['tipo' => 'error', 'texto' => 'Ese paciente ya no existe.'];
    redirigir("pacientes.php");
}

$consultas = [
    "DELETE FROM push_recordatorios WHERE expediente_id IN (SELECT id FROM expedientes WHERE usuario_id = ?)",
    // El historial del asistente es del médico, solo se le quita el paciente
    "UPDATE chatbot_consultas SET paciente_id = NULL WHERE paciente_id = ?",
    "DELETE FROM notas_consulta WHERE paciente_id = ?",
    "DELETE FROM expedientes WHERE usuario_id = ?",
    "DELETE FROM usuarios WHERE id = ? AND rol = 'paciente'"
];

try {
    $conexion->begin_transaction();

    foreach ($consultas as $sql) {
        $borrar = $conexion->prepare($sql);
        $borrar->bind_param("i", $paciente_id);
        $borrar->execute();
        $borrar->close();
    }

    $conexion->commit();

    $medicoId = (int) ($_SESSION['medico_id'] ?? $_SESSION['medico']);
    registrarLog("Paciente eliminado ID: $paciente_id por médico ID: $medicoId", "INFO");

    $_SESSION['aviso_pacientes'] = [
        'tipo'  => 'exito',
        'texto' => 'Se eliminó a ' . $paciente['nombre'] . ' junto con su expediente y sus notas.'
    ];
} catch (Exception $e) {
    $conexion->rollback();
    registrarLog("No se pudo eliminar al paciente ID $paciente_id: " . $e->getMessage(), "ERROR");

    $_SESSION['aviso_pacientes'] = [
        'tipo'  => 'error',
        'texto' => 'No se pudo eliminar al paciente. Intenta de nuevo.'
    ];
}

redirigir("pacientes.php");
