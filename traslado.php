<?php
include("config.php");
include("security.php");
require_once __DIR__ . "/config/esquema.php";
require_once __DIR__ . "/integrations/Traslados.php";
require_once __DIR__ . "/integrations/SendGridMailer.php";
require_once __DIR__ . "/integrations/WebPush.php";

/* ======================================================
   TRASLADO A LA CITA (lo usa la tarjeta del expediente)
   POST accion=crear    programa el traslado (ida, regreso o los dos)
   POST accion=estado   lo avanza: solicitado -> confirmado (con chofer) -> en camino -> completado
   POST accion=cancelar lo cancela
   Siempre regresa al expediente con un aviso arriba de la tarjeta.
====================================================== */

verificarSesion();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    redirigir("pacientes.php");
}

validarTokenCSRF($_POST['csrf_token'] ?? '');
prepararEsquemaInnovacion($conexion);

$paciente_id = intval($_POST['paciente_id'] ?? 0);
$medicoId = (int) ($_SESSION['medico_id'] ?? $_SESSION['medico']);
$accion = (string) ($_POST['accion'] ?? '');

$stmt = $conexion->prepare("SELECT id, nombre, email, telefono FROM usuarios WHERE id = ? AND rol = 'paciente' LIMIT 1");
$stmt->bind_param("i", $paciente_id);
$stmt->execute();
$paciente = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$paciente) {
    redirigir("pacientes.php");
}

function volverAlExpediente($pacienteId, $tipo, $texto)
{
    $_SESSION['aviso_traslado'] = ['tipo' => $tipo, 'texto' => $texto];
    redirigir("expediente.php?id=" . (int) $pacienteId . "#traslado");
}

try {
    if ($accion === 'crear') {
        list($datos, $error) = trasladoValidar($_POST);

        if ($error) {
            volverAlExpediente($paciente_id, 'error', $error);
        }

        trasladoCrear($conexion, $paciente_id, $medicoId, $datos, 'medico');
        registrarLog("Traslado: programado para el paciente ID $paciente_id ({$datos['tipo']}).", "INFO");
        volverAlExpediente($paciente_id, 'exito', 'Traslado programado. Asigna el chofer para confirmarlo.');
    }

    if ($accion === 'estado' || $accion === 'cancelar') {
        $nuevo = $accion === 'cancelar' ? 'cancelado' : (string) ($_POST['estado'] ?? '');
        $chofer = [];

        // Para confirmar hace falta el chofer: su nombre y teléfono van al paciente
        if ($nuevo === 'confirmado') {
            list($chofer, $error) = trasladoValidarChofer($_POST);

            if ($error) {
                volverAlExpediente($paciente_id, 'error', $error);
            }
        }

        $traslado = trasladoCambiarEstado($conexion, intval($_POST['traslado_id'] ?? 0), $paciente_id, $nuevo, $chofer);

        if (!$traslado) {
            volverAlExpediente($paciente_id, 'error', 'Ese traslado ya cambió. Revisa su estado actual.');
        }

        registrarLog("Traslado ID {$traslado['id']}: ahora está $nuevo.", "INFO");
        $textos = [
            'confirmado' => 'Traslado confirmado con ' . ($chofer['chofer_nombre'] ?? 'el chofer') . '.',
            'en_camino'  => 'Listo: el chofer va en camino por el paciente.',
            'completado' => 'Traslado completado.',
            'cancelado'  => 'Traslado cancelado.'
        ];
        $texto = $textos[$nuevo];

        // Al confirmarlo y cuando el chofer sale, al paciente le llega un correo con los datos
        if ($nuevo === 'confirmado' || $nuevo === 'en_camino') {
            $stmt = $conexion->prepare("SELECT fecha_cita, hora_cita FROM expedientes WHERE usuario_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->bind_param("i", $paciente_id);
            $stmt->execute();
            $cita = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();

            $detalle = trasladoDetalle($traslado, $paciente, $cita['fecha_cita'] ?? null, $cita['hora_cita'] ?? null, trasladoSucursales($conexion));

            try {
                if (trasladoCorreoPaciente($paciente, $traslado, $detalle, $nuevo)) {
                    $texto .= ' Le avisamos al paciente por correo.';
                }
            } catch (Exception $eCorreo) {
                registrarLog("Traslado: no se pudo enviar el correo: " . $eCorreo->getMessage(), "WARNING");
            }
        }

        volverAlExpediente($paciente_id, 'exito', $texto);
    }

    volverAlExpediente($paciente_id, 'error', 'Acción no válida.');
} catch (Exception $e) {
    registrarLog("Traslado: error " . $e->getMessage(), "CRITICAL");
    volverAlExpediente($paciente_id, 'error', 'No se pudo guardar el traslado. Intenta de nuevo.');
}
