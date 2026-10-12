<?php
include("config.php");
require_once "security.php";

$mensaje_alerta = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['accion']) && $_POST['accion'] == 'contacto_emergencia') {

    validarTokenCSRF($_POST['csrf_token']);

    $nombre  = limpiar($_POST['nombre']);
    $correo  = limpiar($_POST['correo']);
    $asunto  = limpiar($_POST['asunto']);
    $mensaje = limpiar($_POST['mensaje']);

    $stmt = $conexion->prepare("
        INSERT INTO contactos(nombre, correo, asunto, mensaje)
        VALUES(?, ?, ?, ?)
    ");

    $stmt->bind_param("ssss", $nombre, $correo, $asunto, $mensaje);

    if ($stmt->execute()) {
        $mensaje_alerta = "exito";

        registrarLog(
            "Nuevo mensaje de contacto recibido desde portal público.",
            "INFO"
        );
    } else {
        $mensaje_alerta = "error";
    }

    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MediCore | Expediente clínico con asistente de medicamentos</title>
    <meta name="description" content="MediCore reúne expedientes, citas, reportes y un asistente de medicamentos que revisa las alergias del paciente con la información oficial de CIMA.">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        :root {
            --pino: #0E3B2C;
            --pino-2: #164A39;
            --verde: #00843D;
            --verde-osc: #006B31;
            --verde-claro: #2FA86A;
            --menta: #E4F1E8;
            --papel: #F4F7F5;
            --linea: #D9E2DC;
            --tinta: #15241D;
            --gris: #5E6E66;
            --alerta: #B42318;
            --alerta-bg: #FEF3F2;
            --alerta-borde: #FECDCA;
            --precaucion: #A15C07;
            --precaucion-bg: #FFFAEB;
            --info: #1F4E8C;
            --ok: #067647;
            --ok-bg: #ECFDF3;
            --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            --ancho: 1180px;
            --barra: 68px;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            scroll-behavior: smooth;
            scroll-padding-top: calc(var(--barra) + 16px);
        }

        body {
            font-family: 'Onest', system-ui, -apple-system, 'Segoe UI', sans-serif;
            font-size: 17px;
            line-height: 1.6;
            color: var(--tinta);
            background: #fff;
            -webkit-font-smoothing: antialiased;
        }

        img {
            max-width: 100%;
            display: block;
        }

        a {
            color: inherit;
        }

        a:focus-visible,
        button:focus-visible,
        input:focus-visible,
        textarea:focus-visible {
            outline: 2px solid var(--verde);
            outline-offset: 2px;
        }

        .contenedor {
            width: 100%;
            max-width: var(--ancho);
            margin: 0 auto;
            padding: 0 24px;
        }

        .saltar {
            position: absolute;
            left: 16px;
            top: -60px;
            z-index: 30;
            padding: 10px 14px;
            border-radius: 8px;
            background: var(--tinta);
            color: #fff;
            text-decoration: none;
        }

        .saltar:focus {
            top: 12px;
        }

        /* ---------- Botones ---------- */

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 46px;
            padding: 0 20px;
            border-radius: 9px;
            border: 1px solid transparent;
            font: inherit;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: background-color 150ms ease, border-color 150ms ease, transform 120ms var(--ease-out);
        }

        .btn:active {
            transform: scale(.97);
        }

        .btn-verde {
            background: var(--verde);
            color: #fff;
        }

        .btn-claro {
            background: transparent;
            color: #fff;
            border-color: rgba(255, 255, 255, .35);
        }

        .btn-borde {
            background: #fff;
            color: var(--tinta);
            border-color: var(--linea);
        }

        /* ---------- Barra de navegación ---------- */

        .barra {
            position: sticky;
            top: 0;
            z-index: 20;
            background: rgba(255, 255, 255, .96);
            border-bottom: 1px solid var(--linea);
        }

        .barra .contenedor {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            height: var(--barra);
        }

        .marca {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.01em;
            text-decoration: none;
        }

        .marca img {
            width: 38px;
            height: 38px;
            object-fit: contain;
        }

        .menu {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .menu a:not(.btn) {
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 500;
            color: var(--gris);
            text-decoration: none;
            transition: color 150ms ease;
        }

        .menu .btn {
            margin-left: 12px;
            min-height: 42px;
            font-size: 15px;
        }

        .menu-boton {
            display: none;
            width: 44px;
            height: 44px;
            border: 1px solid var(--linea);
            border-radius: 9px;
            background: #fff;
            color: var(--tinta);
            font-size: 18px;
            cursor: pointer;
        }

        /* ---------- Hero ---------- */

        .hero {
            position: relative;
            background: var(--pino);
            color: #fff;
            overflow: hidden;
            isolation: isolate;
        }

        .hero .contenedor {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1.05fr);
            align-items: center;
            gap: 56px;
            padding-top: 88px;
            padding-bottom: 104px;
        }

        .hero h1 {
            font-size: clamp(36px, 4.2vw, 56px);
            line-height: 1.06;
            font-weight: 600;
            letter-spacing: -0.03em;
            text-wrap: balance;
        }

        .hero-texto > p {
            margin-top: 22px;
            max-width: 34rem;
            font-size: 18px;
            line-height: 1.6;
            color: rgba(228, 241, 232, .8);
        }

        .hero-acciones {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 34px;
        }

        .hero-nota {
            margin-top: 26px;
            font-size: 14px;
            color: rgba(228, 241, 232, .62);
        }

        .hero-nota i {
            margin-right: 6px;
            color: var(--verde-claro);
        }

        /* Electrocardiograma: el único movimiento automático de la página */
        .ecg {
            position: absolute;
            left: 0;
            bottom: 0;
            width: 100%;
            height: 96px;
            z-index: -1;
            pointer-events: none;
        }

        .ecg path {
            fill: none;
            stroke: var(--verde-claro);
            stroke-width: 2;
            stroke-linejoin: round;
            stroke-linecap: round;
            opacity: .4;
            stroke-dasharray: 1;
            stroke-dashoffset: 0;
            animation: trazo 1.8s var(--ease-out) .15s both;
        }

        @keyframes trazo {
            from { stroke-dashoffset: 1; }
            to { stroke-dashoffset: 0; }
        }

        /* Maqueta del expediente */
        .maqueta {
            position: relative;
        }

        .ventana {
            background: #fff;
            color: var(--tinta);
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, .25);
            overflow: hidden;
        }

        .ventana-cab {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 18px;
            border-bottom: 1px solid var(--linea);
            font-size: 13px;
            color: var(--gris);
        }

        .ventana-cab strong {
            color: var(--tinta);
            font-weight: 600;
        }

        .ventana-cuerpo {
            padding: 18px;
        }

        .paciente {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .iniciales {
            display: grid;
            place-items: center;
            flex: none;
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: var(--menta);
            color: var(--pino);
            font-weight: 700;
            font-size: 15px;
        }

        .paciente h3 {
            font-size: 18px;
            line-height: 1.25;
            font-weight: 600;
        }

        .paciente p {
            font-size: 13px;
            color: var(--gris);
        }

        .etiqueta {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .etiqueta-alerta {
            background: var(--alerta-bg);
            color: var(--alerta);
        }

        .etiqueta-ok {
            background: var(--ok-bg);
            color: var(--ok);
        }

        .paciente .etiqueta {
            margin-left: auto;
        }

        .signos {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            margin-top: 16px;
            border: 1px solid var(--linea);
            border-radius: 10px;
        }

        .signos div {
            padding: 10px 12px;
        }

        .signos div + div {
            border-left: 1px solid var(--linea);
        }

        .signos span {
            display: block;
            font-size: 12px;
            color: var(--gris);
        }

        .signos strong {
            font-size: 16px;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }

        .chat {
            margin-top: 16px;
            padding: 14px;
            border-radius: 10px;
            background: var(--papel);
        }

        .chat-titulo {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 600;
            color: var(--gris);
        }

        .chat-titulo i {
            color: var(--verde);
        }

        .burbuja {
            margin: 10px 0 10px auto;
            width: fit-content;
            max-width: 88%;
            padding: 9px 13px;
            border-radius: 12px 12px 4px 12px;
            background: var(--pino);
            color: #fff;
            font-size: 14px;
            line-height: 1.45;
        }

        .respuesta {
            display: flex;
            gap: 10px;
            padding: 11px 13px;
            border-radius: 10px;
            background: #fff;
            border: 1px solid var(--alerta-borde);
            font-size: 14px;
            line-height: 1.5;
        }

        .respuesta > i {
            margin-top: 3px;
            color: var(--alerta);
        }

        .respuesta strong {
            color: var(--alerta);
            font-weight: 600;
        }

        .fuente {
            display: block;
            margin-top: 6px;
            font-size: 12px;
            color: var(--gris);
        }

        .notificacion {
            position: absolute;
            left: -36px;
            bottom: -58px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            width: min(320px, 86%);
            padding: 13px 15px;
            border-radius: 12px;
            background: #fff;
            color: var(--tinta);
            border: 1px solid var(--linea);
            box-shadow: 0 12px 32px rgba(8, 31, 23, .28);
            font-size: 13px;
            line-height: 1.45;
            animation: aparecer 320ms var(--ease-out) 1.6s both;
        }

        .notificacion i {
            display: grid;
            place-items: center;
            flex: none;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: var(--pino);
            color: #fff;
            font-size: 14px;
        }

        .notificacion strong {
            display: block;
            font-size: 13px;
            font-weight: 600;
        }

        .notificacion span {
            color: var(--gris);
        }

        @keyframes aparecer {
            from {
                opacity: 0;
                transform: translateY(10px) scale(.97);
            }
            to {
                opacity: 1;
                transform: none;
            }
        }

        /* ---------- Secciones ---------- */

        .seccion {
            padding: 112px 0;
        }

        .seccion-papel {
            background: var(--papel);
        }

        .seccion h2 {
            font-size: clamp(30px, 3.4vw, 44px);
            line-height: 1.12;
            font-weight: 600;
            letter-spacing: -0.025em;
            text-wrap: balance;
        }

        .seccion-intro {
            margin-top: 16px;
            max-width: 36rem;
            color: var(--gris);
            font-size: 18px;
        }

        /* Funciones: encabezado fijo a la izquierda y lista a la derecha */
        .funciones {
            display: grid;
            grid-template-columns: minmax(0, 0.8fr) minmax(0, 1.2fr);
            gap: 72px;
            align-items: start;
        }

        .funciones-cab {
            position: sticky;
            top: calc(var(--barra) + 40px);
        }

        .lista-funciones {
            list-style: none;
            border-top: 1px solid var(--linea);
        }

        .lista-funciones li {
            display: grid;
            grid-template-columns: 44px minmax(0, 1fr);
            gap: 18px;
            padding: 26px 0;
            border-bottom: 1px solid var(--linea);
        }

        .lista-funciones i {
            display: grid;
            place-items: center;
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: var(--menta);
            color: var(--verde);
            font-size: 17px;
        }

        .lista-funciones li.destacada i {
            background: var(--pino);
            color: #fff;
        }

        .lista-funciones h3 {
            font-size: 19px;
            line-height: 1.3;
            font-weight: 600;
        }

        .lista-funciones p {
            margin-top: 6px;
            color: var(--gris);
            font-size: 16px;
            max-width: 36rem;
        }

        .nueva {
            display: inline-block;
            margin-left: 8px;
            padding: 2px 8px;
            border-radius: 999px;
            background: var(--menta);
            color: var(--verde-osc);
            font-size: 12px;
            font-weight: 600;
            vertical-align: 3px;
        }

        /* Consulta paso a paso */
        .consulta {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 72px;
            margin-top: 56px;
        }

        .pasos {
            list-style: none;
            counter-reset: paso;
        }

        .paso {
            position: relative;
            padding-left: 64px;
            padding-bottom: 40px;
        }

        .paso::before {
            counter-increment: paso;
            content: counter(paso);
            position: absolute;
            left: 0;
            top: 0;
            display: grid;
            place-items: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 1px solid var(--linea);
            background: #fff;
            font-size: 15px;
            font-weight: 600;
            color: var(--gris);
            transition: background-color 200ms ease, color 200ms ease, border-color 200ms ease;
        }

        .paso::after {
            content: "";
            position: absolute;
            left: 19.5px;
            top: 48px;
            bottom: 8px;
            width: 1px;
            background: var(--linea);
        }

        .paso:last-child::after {
            display: none;
        }

        .paso > h3 {
            font-size: 21px;
            line-height: 1.3;
            font-weight: 600;
            padding-top: 6px;
        }

        .paso > p {
            margin-top: 8px;
            color: var(--gris);
            max-width: 30rem;
        }

        .paso-visual {
            margin-top: 22px;
        }

        .escenario {
            display: none;
        }

        /* Con JavaScript en pantallas anchas: la maqueta se queda fija
           y cambia según el paso que se está leyendo */
        @media (min-width: 961px) {
            .con-escenario .paso {
                min-height: 52vh;
                padding-bottom: 0;
            }

            .con-escenario .paso .paso-visual {
                display: none;
            }

            .con-escenario .paso > h3,
            .con-escenario .paso > p {
                opacity: .45;
                transition: opacity 200ms ease;
            }

            .con-escenario .paso.activo > h3,
            .con-escenario .paso.activo > p {
                opacity: 1;
            }

            .con-escenario .paso.activo::before {
                background: var(--verde);
                border-color: var(--verde);
                color: #fff;
            }

            .con-escenario .escenario {
                display: block;
                position: sticky;
                top: calc(50vh - 190px + var(--barra) / 2);
                height: 380px;
            }

            .escenario .paso-visual {
                position: absolute;
                inset: 0;
                margin: 0;
                opacity: 0;
                transform: translateY(12px);
                transition: opacity 260ms var(--ease-out), transform 260ms var(--ease-out);
                pointer-events: none;
            }

            .escenario .paso-visual.activo {
                opacity: 1;
                transform: none;
            }
        }

        /* Tarjetas de cada paso */
        .tarjeta {
            background: #fff;
            border: 1px solid var(--linea);
            border-radius: 14px;
            padding: 22px;
        }

        .tarjeta-titulo {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 600;
            color: var(--gris);
            margin-bottom: 16px;
        }

        .tarjeta-titulo i {
            color: var(--verde);
        }

        .datos {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px 18px;
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px solid var(--linea);
        }

        .datos span {
            display: block;
            font-size: 12px;
            color: var(--gris);
        }

        .datos strong {
            font-size: 15px;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }

        .datos .rojo {
            color: var(--alerta);
        }

        .campo-chat {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 14px;
            padding: 10px 12px;
            border: 1px solid var(--linea);
            border-radius: 9px;
            font-size: 14px;
            color: var(--gris);
        }

        .campo-chat i {
            margin-left: auto;
            color: var(--verde);
        }

        .seccion-cima {
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: 10px;
            background: var(--papel);
            font-size: 14px;
            line-height: 1.5;
        }

        .seccion-cima strong {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--info);
            margin-bottom: 4px;
        }

        .telefono {
            max-width: 300px;
            margin: 0 auto;
            padding: 14px;
            border-radius: 28px;
            background: var(--pino);
        }

        .telefono-hora {
            padding: 18px 0 22px;
            text-align: center;
            color: #fff;
            font-size: 44px;
            font-weight: 600;
            letter-spacing: -0.02em;
            font-variant-numeric: tabular-nums;
        }

        .telefono-hora small {
            display: block;
            font-size: 13px;
            font-weight: 400;
            letter-spacing: 0;
            color: rgba(228, 241, 232, .7);
        }

        .telefono .notificacion {
            position: static;
            width: 100%;
            box-shadow: none;
            animation: none;
        }

        /* Seguridad */
        .seguridad {
            display: grid;
            grid-template-columns: minmax(0, 0.8fr) minmax(0, 1.2fr);
            gap: 72px;
        }

        .medidas {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0 40px;
            list-style: none;
        }

        .medidas li {
            padding: 20px 0;
            border-top: 1px solid var(--linea);
        }

        .medidas h3 {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 17px;
            font-weight: 600;
        }

        .medidas h3 i {
            color: var(--verde);
            font-size: 15px;
        }

        .medidas p {
            margin-top: 6px;
            color: var(--gris);
            font-size: 15px;
            line-height: 1.55;
        }

        .tecnologia {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-top: 56px;
            padding-top: 28px;
            border-top: 1px solid var(--linea);
        }

        .tecnologia p {
            margin-right: 8px;
            font-size: 15px;
            font-weight: 600;
        }

        .tecnologia span {
            padding: 6px 12px;
            border-radius: 999px;
            border: 1px solid var(--linea);
            background: #fff;
            font-size: 14px;
            color: var(--gris);
        }

        /* Contacto */
        .contacto {
            display: grid;
            grid-template-columns: minmax(0, 0.8fr) minmax(0, 1.2fr);
            gap: 72px;
            align-items: start;
        }

        .datos-contacto {
            list-style: none;
            margin-top: 32px;
        }

        .datos-contacto li {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            font-size: 16px;
        }

        .datos-contacto i {
            width: 20px;
            text-align: center;
            color: var(--verde);
        }

        .formulario {
            background: #fff;
            border: 1px solid var(--linea);
            border-radius: 14px;
            padding: 32px;
        }

        .fila {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .campo {
            margin-bottom: 18px;
        }

        .campo label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 500;
        }

        .campo input,
        .campo textarea {
            width: 100%;
            min-height: 46px;
            padding: 11px 14px;
            border: 1px solid var(--linea);
            border-radius: 9px;
            background: #fff;
            font: inherit;
            font-size: 16px;
            color: var(--tinta);
            transition: border-color 150ms ease, box-shadow 150ms ease;
        }

        .campo textarea {
            min-height: 130px;
            resize: vertical;
        }

        .campo input:focus,
        .campo textarea:focus {
            outline: none;
            border-color: var(--verde);
            box-shadow: 0 0 0 3px rgba(0, 132, 61, .18);
        }

        .campo input::placeholder,
        .campo textarea::placeholder {
            color: #95A39C;
        }

        .aviso {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            margin-bottom: 22px;
            padding: 12px 14px;
            border-radius: 9px;
            font-size: 15px;
        }

        .aviso i {
            margin-top: 4px;
        }

        .aviso-exito {
            background: var(--ok-bg);
            border: 1px solid #ABEFC6;
            color: var(--ok);
        }

        .aviso-error {
            background: var(--alerta-bg);
            border: 1px solid var(--alerta-borde);
            color: var(--alerta);
        }

        /* Pie */
        .pie {
            background: var(--pino);
            color: rgba(228, 241, 232, .75);
            font-size: 15px;
        }

        .pie .contenedor {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 24px;
            padding-top: 40px;
            padding-bottom: 40px;
        }

        .pie .marca {
            color: #fff;
        }

        .pie .marca span.logo {
            display: grid;
            place-items: center;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: #fff;
        }

        .pie .marca img {
            width: 32px;
            height: 32px;
        }

        .pie-enlaces {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 24px;
        }

        .pie-enlaces a {
            color: #fff;
            text-decoration: underline;
            text-underline-offset: 3px;
            text-decoration-color: rgba(255, 255, 255, .35);
        }

        /* ---------- Hover solo en dispositivos con puntero ---------- */

        @media (hover: hover) and (pointer: fine) {
            .btn-verde:hover { background: var(--verde-osc); }
            .btn-claro:hover { border-color: #fff; }
            .btn-borde:hover { background: var(--papel); }
            .menu a:not(.btn):hover { color: var(--tinta); }
            .pie-enlaces a:hover { text-decoration-color: #fff; }
        }

        /* ---------- Responsive ---------- */

        @media (max-width: 960px) {
            .hero .contenedor,
            .funciones,
            .consulta,
            .seguridad,
            .contacto {
                grid-template-columns: 1fr;
                gap: 48px;
            }

            .hero .contenedor {
                padding-top: 56px;
                padding-bottom: 88px;
            }

            .notificacion {
                left: 12px;
            }

            .funciones-cab {
                position: static;
            }

            .consulta {
                margin-top: 40px;
            }

            .seccion {
                padding: 80px 0;
            }
        }

        @media (max-width: 820px) {
            .menu-boton {
                display: grid;
                place-items: center;
            }

            .menu {
                display: none;
                position: absolute;
                top: var(--barra);
                left: 0;
                right: 0;
                flex-direction: column;
                align-items: stretch;
                gap: 0;
                padding: 8px 16px 18px;
                background: #fff;
                border-bottom: 1px solid var(--linea);
            }

            .menu.abierto {
                display: flex;
            }

            .menu a:not(.btn) {
                padding: 14px 8px;
                font-size: 16px;
                border-bottom: 1px solid var(--linea);
                border-radius: 0;
            }

            .menu .btn {
                margin: 14px 0 0;
            }
        }

        @media (max-width: 640px) {
            body {
                font-size: 16px;
            }

            .contenedor {
                padding: 0 18px;
            }

            .hero-acciones .btn {
                flex: 1 1 100%;
            }

            .signos strong {
                font-size: 15px;
            }

            .paciente {
                flex-wrap: wrap;
            }

            .paciente .etiqueta {
                margin-left: 60px;
            }

            .medidas,
            .fila {
                grid-template-columns: 1fr;
            }

            .medidas {
                gap: 0;
            }

            .formulario {
                padding: 22px;
            }

            .paso {
                padding-left: 56px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            .ecg path { animation: none; }
            .notificacion { animation: none; }
            .btn,
            .campo input,
            .campo textarea,
            .paso::before { transition: none; }
            .btn:active { transform: none; }
            .con-escenario .paso > h3,
            .con-escenario .paso > p { transition: none; }
            .escenario .paso-visual { transition: none; transform: none; }
        }
    </style>

    <?php include __DIR__ . "/pwa_head.php"; ?>
</head>

<body>

    <a class="saltar" href="#contenido">Saltar al contenido</a>

    <!-- ======================================================
         NAVEGACIÓN
    ====================================================== -->
    <header class="barra">
        <div class="contenedor">
            <a class="marca" href="#inicio">
                <img src="assets/Medicore.png" alt="">
                MediCore
            </a>

            <button
                type="button"
                class="menu-boton"
                id="menuBoton"
                aria-label="Abrir menú"
                aria-expanded="false"
                aria-controls="menu">
                <i class="fas fa-bars" aria-hidden="true"></i>
            </button>

            <nav class="menu" id="menu" aria-label="Secciones">
                <a href="#funciones">Funciones</a>
                <a href="#consulta">Cómo funciona</a>
                <a href="#seguridad">Seguridad</a>
                <a href="#contacto">Contacto</a>
                <a href="login.php" class="btn btn-verde">Acceso médico</a>
            </nav>
        </div>
    </header>

    <main id="contenido">

        <!-- ======================================================
             HERO
        ====================================================== -->
        <section class="hero" id="inicio">

            <svg class="ecg" viewBox="0 0 1200 96" preserveAspectRatio="none" aria-hidden="true">
                <path pathLength="1" d="M0 64 H930 L944 64 L954 50 L964 64 L978 64 L992 12 L1008 92 L1022 64 L1040 64 L1052 54 L1066 64 H1200" />
            </svg>

            <div class="contenedor">
                <div class="hero-texto">
                    <h1>El expediente clínico que revisa las alergias antes de recetar.</h1>
                    <p>
                        MediCore reúne pacientes, citas y reportes con un asistente de medicamentos
                        que consulta la información oficial de CIMA y te avisa si un fármaco
                        choca con las alergias registradas.
                    </p>

                    <div class="hero-acciones">
                        <a href="login.php" class="btn btn-verde">
                            <i class="fas fa-user-doctor" aria-hidden="true"></i>
                            Acceso médico
                        </a>
                        <a href="#consulta" class="btn btn-claro">Ver cómo funciona</a>
                    </div>

                    <p class="hero-nota">
                        <i class="fas fa-mobile-screen" aria-hidden="true"></i>
                        Se instala como app en el celular o la computadora.
                    </p>
                </div>

                <div class="maqueta" aria-hidden="true">
                    <div class="ventana">
                        <div class="ventana-cab">
                            <strong>Expediente clínico</strong>
                            <span>Consulta de hoy, 10:30</span>
                        </div>
                        <div class="ventana-cuerpo">
                            <div class="paciente">
                                <span class="iniciales">AH</span>
                                <div>
                                    <h3>Ana Lucía Herrera</h3>
                                    <p>34 años, expediente 0004</p>
                                </div>
                                <span class="etiqueta etiqueta-alerta">
                                    <i class="fas fa-triangle-exclamation"></i>
                                    Penicilina
                                </span>
                            </div>

                            <div class="signos">
                                <div><span>IMC</span><strong>23.3</strong></div>
                                <div><span>Peso</span><strong>61 kg</strong></div>
                                <div><span>Estado</span><strong>Autorizada</strong></div>
                            </div>

                            <div class="chat">
                                <div class="chat-titulo">
                                    <i class="fas fa-robot"></i>
                                    Asistente de medicamentos
                                </div>
                                <p class="burbuja">¿Qué efectos secundarios tiene la amoxicilina?</p>
                                <div class="respuesta">
                                    <i class="fas fa-triangle-exclamation"></i>
                                    <p>
                                        <strong>No la recetes a esta paciente.</strong>
                                        La amoxicilina es una penicilina y Ana Lucía tiene alergia registrada.
                                        <span class="fuente">Fuente: CIMA, ficha técnica, 4.3 Contraindicaciones</span>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="notificacion">
                        <i class="fas fa-bell"></i>
                        <p>
                            <strong>Cita mañana a las 10:00</strong>
                            <span>Jorge Ramírez Soto, revisión de tratamiento</span>
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ======================================================
             FUNCIONES
        ====================================================== -->
        <section class="seccion" id="funciones">
            <div class="contenedor funciones">
                <div class="funciones-cab">
                    <h2>Todo lo que pasa en la consulta, en un solo sistema.</h2>
                    <p class="seccion-intro">
                        Pensado para el médico que atiende, receta y da seguimiento
                        sin cambiar de programa.
                    </p>
                </div>

                <ul class="lista-funciones">
                    <li>
                        <i class="fas fa-notes-medical" aria-hidden="true"></i>
                        <div>
                            <h3>Expediente clínico</h3>
                            <p>Diagnóstico, receta, peso, talla e IMC calculado, observaciones y la evolución de cada visita.</p>
                        </div>
                    </li>
                    <li class="destacada">
                        <i class="fas fa-robot" aria-hidden="true"></i>
                        <div>
                            <h3>Asistente de medicamentos</h3>
                            <p>Pregunta por dosis, efectos secundarios, interacciones o contraindicaciones. Responde con la ficha técnica oficial de CIMA y la resume con Gemini.</p>
                        </div>
                    </li>
                    <li class="destacada">
                        <i class="fas fa-hand-dots" aria-hidden="true"></i>
                        <div>
                            <h3>Alergias del paciente</h3>
                            <p>Se registran al dar de alta al paciente y aparecen en rojo en su expediente. El asistente las cruza con cada medicamento consultado.</p>
                        </div>
                    </li>
                    <li class="destacada">
                        <i class="fas fa-camera" aria-hidden="true"></i>
                        <div>
                            <h3>Escanear medicamento</h3>
                            <p>Tómale foto a la caja o al frasco desde el celular. MediCore reconoce el medicamento, lo busca en CIMA y revisa las alergias del paciente.</p>
                        </div>
                    </li>
                    <li class="destacada">
                        <i class="fas fa-microphone-lines" aria-hidden="true"></i>
                        <div>
                            <h3>Notas de consulta por voz</h3>
                            <p>Dicta la consulta y Gemini la ordena en formato SOAP. Si se cae el internet, la nota se guarda en el dispositivo y se envía sola al volver la conexión.</p>
                        </div>
                    </li>
                    <li>
                        <i class="fas fa-calendar-check" aria-hidden="true"></i>
                        <div>
                            <h3>Agenda de citas</h3>
                            <p>Las próximas consultas del día y de la semana, con acceso directo al expediente.</p>
                        </div>
                    </li>
                    <li class="destacada">
                        <i class="fas fa-bell" aria-hidden="true"></i>
                        <div>
                            <h3>Notificaciones push</h3>
                            <p>Recordatorios de las citas de hoy y mañana en el celular o la computadora, aunque MediCore esté cerrado.</p>
                        </div>
                    </li>
                    <li>
                        <i class="fas fa-file-pdf" aria-hidden="true"></i>
                        <div>
                            <h3>Reportes en PDF</h3>
                            <p>Expedientes y reportes listos para imprimir o compartir con el paciente.</p>
                        </div>
                    </li>
                </ul>
            </div>
        </section>

        <!-- ======================================================
             CONSULTA PASO A PASO
        ====================================================== -->
        <section class="seccion seccion-papel" id="consulta">
            <div class="contenedor">
                <h2>Una consulta con MediCore</h2>
                <p class="seccion-intro">Así se ve el asistente dentro de una consulta real, de principio a fin.</p>

                <div class="consulta">
                    <ol class="pasos">
                        <li class="paso">
                            <h3>Abres el expediente</h3>
                            <p>Ves los datos del paciente, su última consulta y, arriba de todo, sus alergias.</p>

                            <div class="paso-visual">
                                <div class="tarjeta">
                                    <div class="paciente">
                                        <span class="iniciales">AH</span>
                                        <div>
                                            <h3>Ana Lucía Herrera</h3>
                                            <p>34 años, Puebla</p>
                                        </div>
                                    </div>
                                    <div class="datos">
                                        <div><span>Alergias</span><strong class="rojo">Penicilina</strong></div>
                                        <div><span>IMC</span><strong>23.3</strong></div>
                                        <div><span>Diagnóstico</span><strong>Faringitis aguda</strong></div>
                                        <div><span>Próxima cita</span><strong>14/10/2026</strong></div>
                                    </div>
                                </div>
                            </div>
                        </li>

                        <li class="paso">
                            <h3>Preguntas por un medicamento</h3>
                            <p>Escribes como le preguntarías a un colega. El asistente identifica el medicamento y el tema.</p>

                            <div class="paso-visual">
                                <div class="tarjeta">
                                    <div class="tarjeta-titulo">
                                        <i class="fas fa-robot"></i>
                                        Asistente de medicamentos
                                    </div>
                                    <p class="burbuja">¿Qué interacciones tiene el ibuprofeno?</p>
                                    <div class="seccion-cima">
                                        <strong>CIMA, 4.5 Interacciones</strong>
                                        Evita combinarlo con otros antiinflamatorios y vigila su uso junto con anticoagulantes.
                                    </div>
                                    <div class="campo-chat">
                                        Escribe tu pregunta
                                        <i class="fas fa-paper-plane"></i>
                                    </div>
                                </div>
                            </div>
                        </li>

                        <li class="paso">
                            <h3>MediCore revisa sus alergias</h3>
                            <p>Si el medicamento choca con una alergia registrada, el aviso aparece antes que cualquier otra información.</p>

                            <div class="paso-visual">
                                <div class="tarjeta">
                                    <div class="tarjeta-titulo">
                                        <i class="fas fa-robot"></i>
                                        Asistente de medicamentos
                                    </div>
                                    <p class="burbuja">¿Puedo recetarle amoxicilina?</p>
                                    <div class="respuesta">
                                        <i class="fas fa-triangle-exclamation"></i>
                                        <p>
                                            <strong>Alergia registrada: penicilina.</strong>
                                            La amoxicilina pertenece a ese grupo. Elige otra opción.
                                            <span class="fuente">Fuente: CIMA, 4.3 Contraindicaciones</span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </li>

                        <li class="paso">
                            <h3>Te llega el recordatorio</h3>
                            <p>Un día antes de la siguiente cita, MediCore manda la notificación a tus dispositivos.</p>

                            <div class="paso-visual">
                                <div class="telefono">
                                    <p class="telefono-hora">
                                        <small>martes 13 de octubre</small>
                                        18:00
                                    </p>
                                    <div class="notificacion">
                                        <i class="fas fa-bell"></i>
                                        <p>
                                            <strong>Cita mañana a las 10:00</strong>
                                            <span>Ana Lucía Herrera, revisión de faringitis</span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </li>
                    </ol>

                    <div class="escenario" id="escenario" aria-hidden="true"></div>
                </div>
            </div>
        </section>

        <!-- ======================================================
             SEGURIDAD Y TECNOLOGÍA
        ====================================================== -->
        <section class="seccion" id="seguridad">
            <div class="contenedor">
                <div class="seguridad">
                    <div>
                        <h2>Datos clínicos protegidos en cada paso.</h2>
                        <p class="seccion-intro">
                            La información de los pacientes solo la ve el médico que inició sesión.
                        </p>
                    </div>

                    <ul class="medidas">
                        <li>
                            <h3><i class="fas fa-key" aria-hidden="true"></i>Contraseñas cifradas</h3>
                            <p>Se guardan con hash seguro; nadie puede leerlas, ni siquiera en la base de datos.</p>
                        </li>
                        <li>
                            <h3><i class="fas fa-user-lock" aria-hidden="true"></i>Sesiones con límite</h3>
                            <p>La sesión se cierra por inactividad y se bloquea si cambia el navegador.</p>
                        </li>
                        <li>
                            <h3><i class="fas fa-shield-halved" aria-hidden="true"></i>Formularios con token</h3>
                            <p>Cada formulario lleva un token CSRF que impide envíos desde otros sitios.</p>
                        </li>
                        <li>
                            <h3><i class="fas fa-database" aria-hidden="true"></i>Consultas preparadas</h3>
                            <p>Todas las consultas a MySQL usan parámetros para evitar inyección SQL.</p>
                        </li>
                        <li>
                            <h3><i class="fas fa-hand" aria-hidden="true"></i>Límite de intentos</h3>
                            <p>Tras varios accesos fallidos, el inicio de sesión se pausa un minuto.</p>
                        </li>
                        <li>
                            <h3><i class="fas fa-lock" aria-hidden="true"></i>HTTPS y cabeceras</h3>
                            <p>Conexión cifrada y política de seguridad de contenido en todas las páginas.</p>
                        </li>
                    </ul>
                </div>

                <div class="tecnologia">
                    <p>Construido con</p>
                    <span>PHP 8.2</span>
                    <span>MySQL</span>
                    <span>PWA con service worker</span>
                    <span>API de CIMA (AEMPS)</span>
                    <span>Google Gemini</span>
                    <span>Web Push</span>
                    <span>Web Speech API</span>
                    <span>Background Sync</span>
                    <span>Railway</span>
                </div>
            </div>
        </section>

        <!-- ======================================================
             CONTACTO
        ====================================================== -->
        <section class="seccion seccion-papel" id="contacto">
            <div class="contenedor contacto">
                <div>
                    <h2>¿Necesitas ayuda con tu cuenta?</h2>
                    <p class="seccion-intro">
                        Escríbenos y el equipo de soporte revisará tu solicitud.
                    </p>

                    <ul class="datos-contacto">
                        <li><i class="fas fa-envelope" aria-hidden="true"></i>Soporte institucional de MediCore</li>
                        <li><i class="fas fa-building-columns" aria-hidden="true"></i>Universidad Tecnológica de Puebla</li>
                        <li><i class="fas fa-file-shield" aria-hidden="true"></i><a href="aviso_privacidad.php">Aviso de privacidad</a></li>
                    </ul>
                </div>

                <div class="formulario">
                    <?php if ($mensaje_alerta == "exito"): ?>
                        <div class="aviso aviso-exito" role="status">
                            <i class="fas fa-circle-check" aria-hidden="true"></i>
                            <span>Mensaje enviado. Te responderemos por correo.</span>
                        </div>
                    <?php elseif ($mensaje_alerta == "error"): ?>
                        <div class="aviso aviso-error" role="alert">
                            <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                            <span>No se pudo enviar el mensaje. Inténtalo de nuevo en unos minutos.</span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="home.php#contacto">
                        <input type="hidden" name="accion" value="contacto_emergencia">
                        <input type="hidden" name="csrf_token" value="<?= generarTokenCSRF() ?>">

                        <div class="fila">
                            <div class="campo">
                                <label for="c-nombre">Nombre completo</label>
                                <input type="text" id="c-nombre" name="nombre" autocomplete="name" required>
                            </div>
                            <div class="campo">
                                <label for="c-correo">Correo electrónico</label>
                                <input type="email" id="c-correo" name="correo" autocomplete="email" placeholder="correo@ejemplo.com" required>
                            </div>
                        </div>

                        <div class="campo">
                            <label for="c-asunto">Asunto</label>
                            <input type="text" id="c-asunto" name="asunto" required>
                        </div>

                        <div class="campo">
                            <label for="c-mensaje">Mensaje</label>
                            <textarea id="c-mensaje" name="mensaje" placeholder="Cuéntanos qué pasó y desde qué dispositivo" required></textarea>
                        </div>

                        <button type="submit" class="btn btn-verde">
                            <i class="fas fa-paper-plane" aria-hidden="true"></i>
                            Enviar mensaje
                        </button>
                    </form>
                </div>
            </div>
        </section>
    </main>

    <!-- ======================================================
         PIE DE PÁGINA
    ====================================================== -->
    <footer class="pie">
        <div class="contenedor">
            <a class="marca" href="#inicio">
                <span class="logo"><img src="assets/Medicore.png" alt=""></span>
                MediCore
            </a>
            <p>© <?= date("Y") ?> MediCore Professional System. Universidad Tecnológica de Puebla.</p>
            <div class="pie-enlaces">
                <a href="aviso_privacidad.php">Aviso de privacidad</a>
                <a href="login.php">Acceso médico</a>
            </div>
        </div>
    </footer>

    <script>
        (function () {
            // Menú en celulares
            const boton = document.getElementById("menuBoton");
            const menu = document.getElementById("menu");

            function cerrarMenu() {
                menu.classList.remove("abierto");
                boton.setAttribute("aria-expanded", "false");
                boton.setAttribute("aria-label", "Abrir menú");
                boton.firstElementChild.className = "fas fa-bars";
            }

            boton.addEventListener("click", function () {
                const abierto = menu.classList.toggle("abierto");
                boton.setAttribute("aria-expanded", abierto ? "true" : "false");
                boton.setAttribute("aria-label", abierto ? "Cerrar menú" : "Abrir menú");
                boton.firstElementChild.className = abierto ? "fas fa-xmark" : "fas fa-bars";
            });

            menu.querySelectorAll("a").forEach(function (enlace) {
                enlace.addEventListener("click", cerrarMenu);
            });

            document.addEventListener("keydown", function (e) {
                if (e.key === "Escape" && menu.classList.contains("abierto")) {
                    cerrarMenu();
                    boton.focus();
                }
            });

            // Consulta paso a paso: la maqueta de la derecha cambia según el paso
            const escenario = document.getElementById("escenario");
            const pasos = Array.prototype.slice.call(document.querySelectorAll(".paso"));

            if (!escenario || !("IntersectionObserver" in window)) {
                return;
            }

            const vistas = pasos.map(function (paso) {
                const copia = paso.querySelector(".paso-visual").cloneNode(true);
                escenario.appendChild(copia);
                return copia;
            });

            function activar(indice) {
                pasos.forEach(function (paso, i) {
                    paso.classList.toggle("activo", i === indice);
                    vistas[i].classList.toggle("activo", i === indice);
                });
            }

            activar(0);
            document.documentElement.classList.add("con-escenario");

            const observador = new IntersectionObserver(function (entradas) {
                entradas.forEach(function (entrada) {
                    if (entrada.isIntersecting) {
                        activar(pasos.indexOf(entrada.target));
                    }
                });
            }, { rootMargin: "-45% 0px -50% 0px" });

            pasos.forEach(function (paso) {
                observador.observe(paso);
            });
        })();
    </script>
</body>

</html>
