<?php
include("config.php");
include("security.php");

verificarSesion();

require_once __DIR__ . "/vendor/autoload.php";

use Mpdf\Mpdf;

date_default_timezone_set("America/Mexico_City");

/* ======================================================
   MÉTRICAS GENERALES
====================================================== */

$total_pacientes = 0;
$autorizadas = 0;
$pendientes = 0;
$denegadas = 0;

$stmtTotal = $conexion->prepare("
    SELECT COUNT(*) AS total
    FROM usuarios
    WHERE rol = 'paciente'
");
$stmtTotal->execute();
$resTotal = $stmtTotal->get_result()->fetch_assoc();
$total_pacientes = intval($resTotal['total'] ?? 0);
$stmtTotal->close();

function contarDietasPDF($conexion, $estado)
{
    $stmt = $conexion->prepare("
        SELECT COUNT(*) AS total
        FROM expedientes
        WHERE dieta_autorizada = ?
    ");

    $stmt->bind_param("s", $estado);
    $stmt->execute();

    $res = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    return intval($res['total'] ?? 0);
}

$autorizadas = contarDietasPDF($conexion, "autorizada");
$pendientes  = contarDietasPDF($conexion, "pendiente");
$denegadas   = contarDietasPDF($conexion, "denegada");

/* ======================================================
   PACIENTES
====================================================== */

$stmtPacientes = $conexion->prepare("
    SELECT
        u.nombre,
        u.email,
        u.telefono,
        u.edad,
        e.imc,
        e.dieta_autorizada,
        e.fecha_cita
    FROM usuarios u
    LEFT JOIN expedientes e ON u.id = e.usuario_id
    WHERE u.rol = 'paciente'
    ORDER BY u.nombre ASC
");

$stmtPacientes->execute();
$resultPacientes = $stmtPacientes->get_result();

$filas = "";

while ($row = $resultPacientes->fetch_assoc()) {

    $estado = $row['dieta_autorizada'] ?? "pendiente";

    if ($estado === "autorizada") {
        $estadoTexto = "Autorizada";
        $estadoClase = "ok";
    } elseif ($estado === "denegada") {
        $estadoTexto = "Denegada";
        $estadoClase = "bad";
    } else {
        $estadoTexto = "Pendiente";
        $estadoClase = "warn";
    }

    $nombre = htmlspecialchars($row['nombre'] ?? "Sin nombre", ENT_QUOTES, "UTF-8");
    $email = htmlspecialchars($row['email'] ?? "Sin correo", ENT_QUOTES, "UTF-8");
    $telefono = htmlspecialchars($row['telefono'] ?? "No registrado", ENT_QUOTES, "UTF-8");
    $edad = !empty($row['edad']) ? intval($row['edad']) . " años" : "—";
    $imc = !empty($row['imc']) ? number_format(floatval($row['imc']), 2) : "—";
    $fecha = !empty($row['fecha_cita'])
        ? date("d/m/Y", strtotime($row['fecha_cita']))
        : "Sin cita";

    $filas .= "
        <tr>
            <td>{$nombre}</td>
            <td>{$email}</td>
            <td>{$telefono}</td>
            <td class='center'>{$edad}</td>
            <td class='center'>{$imc}</td>
            <td class='center'>{$fecha}</td>
            <td class='center'><span class='badge {$estadoClase}'>{$estadoTexto}</span></td>
        </tr>
    ";
}

$stmtPacientes->close();

if (empty($filas)) {
    $filas = "
        <tr>
            <td colspan='7' class='center'>No existen pacientes registrados.</td>
        </tr>
    ";
}

/* ======================================================
   LOGO
====================================================== */

$logoPath = __DIR__ . "/assets/logo.png";
$logoHtml = file_exists($logoPath)
    ? "<img src='{$logoPath}' class='logo'>"
    : "<div class='logo-text'>MediCore</div>";

/* ======================================================
   mPDF
====================================================== */

$mpdf = new Mpdf([
    "mode" => "utf-8",
    "format" => "A4-L",
    "margin_top" => 12,
    "margin_bottom" => 14,
    "margin_left" => 10,
    "margin_right" => 10,
    "default_font" => "dejavusans"
]);

$mpdf->SetTitle("Reporte MediCore");

$mpdf->SetHTMLFooter("
<table width='100%' style='border-top:1px solid #d9e4dc; padding-top:5px; font-size:9px; color:#64748b;'>
    <tr>
        <td width='60%'>MediCore Professional System | Reporte Confidencial</td>
        <td width='40%' style='text-align:right;'>Página {PAGENO} de {nbpg}</td>
    </tr>
</table>
");

$html = "
<!DOCTYPE html>
<html lang='es'>
<head>
<meta charset='UTF-8'>
<style>
    body{
        font-family:dejavusans, Arial, sans-serif;
        color:#0f172a;
        font-size:10px;
        line-height:1.35;
    }

    .header{
        width:100%;
        border-collapse:collapse;
        margin-bottom:10px;
        border-bottom:3px solid #00843d;
        padding-bottom:8px;
    }

    .header td{
        vertical-align:middle;
        border:none;
    }

    .title{
        font-size:22px;
        font-weight:bold;
        color:#06351b;
        margin:0;
    }

    .subtitle{
        font-size:10px;
        color:#64748b;
        margin-top:4px;
    }

    .logo{
        height:50px;
        width:auto;
    }

    .logo-text{
        font-size:18px;
        font-weight:bold;
        color:#00843d;
    }

    .section-title{
        font-size:12px;
        font-weight:bold;
        color:#06351b;
        margin:12px 0 7px 0;
        text-transform:uppercase;
    }

    .metrics{
        width:100%;
        border-collapse:collapse;
        margin-bottom:10px;
    }

    .metrics td{
        width:25%;
        padding:4px;
        border:none;
    }

    .metric-card{
        border:1px solid #d9e4dc;
        border-left:4px solid #00843d;
        background:#f7faf8;
        padding:8px 6px;
        border-radius:6px;
        text-align:center;
    }

    .metric-card.green{
        border-left-color:#059669;
    }

    .metric-card.orange{
        border-left-color:#d97706;
    }

    .metric-card.red{
        border-left-color:#dc2626;
    }

    .metric-number{
        font-size:19px;
        font-weight:bold;
        color:#06351b;
    }

    .metric-label{
        font-size:8.5px;
        color:#64748b;
        font-weight:bold;
        text-transform:uppercase;
    }

    .info{
        font-size:9px;
        color:#64748b;
        margin-bottom:8px;
    }

    table.data{
        width:100%;
        border-collapse:collapse;
        table-layout:fixed;
    }

    table.data thead{
        display:table-header-group;
    }

    table.data th{
        background:#06351b;
        color:#ffffff;
        padding:7px 5px;
        font-size:8.5px;
        text-transform:uppercase;
        text-align:left;
        border:1px solid #06351b;
    }

    table.data td{
        padding:6px 5px;
        font-size:8.7px;
        border:1px solid #d9e4dc;
        vertical-align:middle;
        word-wrap:break-word;
    }

    table.data tbody tr:nth-child(even) td{
        background:#f8fafc;
    }

    .center{
        text-align:center;
    }

    .badge{
        display:inline-block;
        padding:3px 6px;
        border-radius:8px;
        font-size:8px;
        font-weight:bold;
    }

    .badge.ok{
        background:#dcfce7;
        color:#166534;
    }

    .badge.warn{
        background:#fef3c7;
        color:#92400e;
    }

    .badge.bad{
        background:#fee2e2;
        color:#991b1b;
    }
</style>
</head>

<body>

<table class='header'>
    <tr>
        <td width='78%'>
            <div class='title'>Reporte Operativo General</div>
            <div class='subtitle'>Sistema Clínico MediCore / NutriVida</div>
        </td>
        <td width='22%' style='text-align:right;'>
            {$logoHtml}
        </td>
    </tr>
</table>

<div class='section-title'>Resumen Estadístico</div>

<table class='metrics'>
    <tr>
        <td>
            <div class='metric-card'>
                <div class='metric-number'>{$total_pacientes}</div>
                <div class='metric-label'>Pacientes</div>
            </div>
        </td>
        <td>
            <div class='metric-card green'>
                <div class='metric-number'>{$autorizadas}</div>
                <div class='metric-label'>Autorizadas</div>
            </div>
        </td>
        <td>
            <div class='metric-card orange'>
                <div class='metric-number'>{$pendientes}</div>
                <div class='metric-label'>Pendientes</div>
            </div>
        </td>
        <td>
            <div class='metric-card red'>
                <div class='metric-number'>{$denegadas}</div>
                <div class='metric-label'>Denegadas</div>
            </div>
        </td>
    </tr>
</table>

<div class='info'>
    <strong>Fecha de emisión:</strong> " . date("d/m/Y - H:i:s") . " hrs.
</div>

<div class='section-title'>Listado Detallado de Pacientes</div>

<table class='data'>
    <thead>
        <tr>
            <th width='20%'>Paciente</th>
            <th width='23%'>Correo</th>
            <th width='14%'>Teléfono</th>
            <th width='8%' class='center'>Edad</th>
            <th width='8%' class='center'>IMC</th>
            <th width='12%' class='center'>Cita</th>
            <th width='15%' class='center'>Estado</th>
        </tr>
    </thead>
    <tbody>
        {$filas}
    </tbody>
</table>

</body>
</html>
";

$mpdf->WriteHTML($html);
$mpdf->Output("Reporte_MediCore_" . date("Y-m-d") . ".pdf", "I");
exit;
