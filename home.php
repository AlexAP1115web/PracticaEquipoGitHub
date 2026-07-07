<?php
include("config.php");

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
    <title>MediCore | Plataforma Clínica Profesional</title>

    <meta name="description" content="MediCore Professional System - Plataforma clínica integral para gestión médica, expedientes electrónicos y seguimiento de pacientes.">

    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        .home-body {
            background:
                radial-gradient(circle at top left, rgba(0, 132, 61, .12), transparent 32%),
                radial-gradient(circle at bottom right, rgba(0, 166, 81, .10), transparent 35%),
                linear-gradient(180deg, #f7faf8 0%, #eef4f0 100%);
        }

        .public-navbar {
            width: min(1180px, calc(100% - 30px));
            position: fixed;
            top: 18px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 9999;
            padding: 14px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(2, 22, 12, .88);
            backdrop-filter: blur(18px);
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 24px;
            box-shadow: 0 18px 45px rgba(0, 0, 0, .22);
        }

        .public-logo {
            display: flex;
            align-items: center;
            gap: 13px;
        }

        .public-logo img {
            width: 56px;
            height: 56px;
            object-fit: contain;
            background: white;
            padding: 6px;
            border-radius: 17px;
        }

        .public-logo h1 {
            color: white;
            font-size: 25px;
            margin: 0;
            font-weight: 900;
        }

        .public-logo span {
            display: block;
            color: rgba(255, 255, 255, .68);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
            margin-top: 3px;
        }

        .public-menu {
            display: flex;
            align-items: center;
            gap: 24px;
        }

        .public-menu a {
            color: rgba(255, 255, 255, .82);
            font-size: 14px;
            font-weight: 800;
        }

        .public-menu a:hover {
            color: #dff5e8;
        }

        .menu-toggle {
            display: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            width: 46px;
            height: 46px;
            border-radius: 15px;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, .10);
        }

        .hero-public {
            min-height: 100vh;
            padding: 165px 24px 95px;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                linear-gradient(115deg, rgba(2, 22, 12, .95) 0%, rgba(6, 53, 27, .88) 50%, rgba(0, 132, 61, .62) 100%),
                url('https://images.unsplash.com/photo-1584982751601-97dcc096659c?q=80&w=2070&auto=format&fit=crop') center/cover;
        }

        .hero-layout {
            width: min(1180px, 100%);
            display: grid;
            grid-template-columns: 1.05fr .95fr;
            gap: 45px;
            align-items: center;
        }

        .hero-text {
            color: white;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 11px 18px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .10);
            border: 1px solid rgba(255, 255, 255, .16);
            color: white;
            font-size: 13px;
            font-weight: 900;
            margin-bottom: 25px;
        }

        .hero-text h2 {
            color: white;
            font-size: clamp(42px, 6vw, 72px);
            line-height: 1;
            letter-spacing: -.05em;
            margin-bottom: 24px;
        }

        .hero-text h2 span {
            color: #dff5e8;
        }

        .hero-text p {
            color: rgba(255, 255, 255, .84);
            font-size: 18px;
            line-height: 1.85;
            margin-bottom: 34px;
            max-width: 680px;
        }

        .hero-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 30px;
        }

        .btn-outline-home {
            border-radius: 16px;
            padding: 15px 24px;
            font-weight: 900;
            color: white;
            border: 1px solid rgba(255, 255, 255, .20);
            background: rgba(255, 255, 255, .08);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: .25s ease;
        }

        .btn-outline-home:hover {
            transform: translateY(-2px);
            background: rgba(255, 255, 255, .14);
        }

        .hero-trust {
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
            color: rgba(255, 255, 255, .82);
            font-size: 14px;
            font-weight: 800;
        }

        .hero-trust span {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .hero-panel {
            background: rgba(255, 255, 255, .12);
            border: 1px solid rgba(255, 255, 255, .18);
            backdrop-filter: blur(16px);
            border-radius: 32px;
            padding: 22px;
            box-shadow: var(--shadow-lg);
        }

        .preview-card {
            background: white;
            border-radius: 26px;
            overflow: hidden;
            border: 1px solid var(--border);
        }

        .preview-top {
            padding: 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
        }

        .preview-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .preview-brand img {
            width: 43px;
            height: 43px;
            object-fit: contain;
            border-radius: 13px;
            background: var(--primary-light);
            padding: 4px;
        }

        .preview-brand strong {
            color: var(--secondary);
            font-weight: 900;
        }

        .preview-brand small {
            color: var(--muted);
            font-weight: 700;
        }

        .preview-status {
            background: #dcfce7;
            color: #15803d;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 900;
        }

        .preview-content {
            padding: 22px;
        }

        .preview-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }

        .preview-mini {
            padding: 17px;
            border-radius: 18px;
            background: #f8fafc;
            border: 1px solid var(--border);
        }

        .preview-mini i {
            width: 38px;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 13px;
            color: white;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            margin-bottom: 12px;
        }

        .preview-mini h3 {
            font-size: 25px;
            color: var(--primary);
            margin-bottom: 3px;
        }

        .preview-mini p {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .preview-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 0;
            border-top: 1px solid var(--border);
        }

        .preview-row strong {
            color: var(--secondary);
        }

        .preview-row small {
            color: var(--muted);
            font-weight: 700;
        }

        .home-stats {
            max-width: 1180px;
            margin: -70px auto 40px;
            padding: 0 24px;
            position: relative;
            z-index: 5;
        }

        .home-section {
            max-width: 1180px;
            margin: auto;
            padding: 85px 24px;
        }

        .section-center {
            text-align: center;
            margin-bottom: 48px;
        }

        .section-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 16px;
            border-radius: 999px;
            background: var(--primary-light);
            color: var(--primary-dark);
            font-size: 13px;
            font-weight: 900;
            margin-bottom: 16px;
        }

        .section-center h2 {
            font-size: clamp(32px, 4vw, 48px);
            margin-bottom: 14px;
        }

        .section-center p {
            max-width: 780px;
            margin: auto;
            line-height: 1.8;
            font-size: 17px;
        }

        .home-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
        }

        .home-feature {
            background: white;
            border-radius: var(--radius-lg);
            padding: 34px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            transition: .25s ease;
        }

        .home-feature:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }

        .home-feature-icon {
            width: 70px;
            height: 70px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            margin-bottom: 22px;
        }

        .home-feature h3 {
            font-size: 22px;
            margin-bottom: 12px;
        }

        .home-feature p {
            line-height: 1.75;
        }

        .workflow-grid {
            display: grid;
            grid-template-columns: .9fr 1.1fr;
            gap: 26px;
            align-items: center;
        }

        .workflow-main {
            background: linear-gradient(135deg, #02160c, #06351b);
            color: white;
            border-radius: 28px;
            padding: 38px;
            box-shadow: var(--shadow-lg);
        }

        .workflow-main h3 {
            color: white;
            font-size: 31px;
            margin-bottom: 14px;
        }

        .workflow-main p {
            color: rgba(255, 255, 255, .78);
            line-height: 1.8;
        }

        .workflow-steps {
            display: grid;
            gap: 16px;
        }

        .workflow-step {
            display: flex;
            gap: 16px;
            background: white;
            padding: 22px;
            border-radius: 22px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
        }

        .step-number {
            min-width: 42px;
            height: 42px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
        }

        .contact-layout {
            display: grid;
            grid-template-columns: .78fr 1.22fr;
            gap: 26px;
        }

        .contact-info-home {
            background: linear-gradient(135deg, #02160c, #06351b);
            color: white;
            border-radius: 28px;
            padding: 36px;
            box-shadow: var(--shadow-lg);
        }

        .contact-info-home img {
            width: 78px;
            height: 78px;
            object-fit: contain;
            background: white;
            padding: 7px;
            border-radius: 22px;
            margin-bottom: 20px;
        }

        .contact-info-home h3 {
            color: white;
            font-size: 28px;
            margin-bottom: 12px;
        }

        .contact-info-home p {
            color: rgba(255, 255, 255, .78);
            line-height: 1.8;
            margin-bottom: 22px;
        }

        .contact-item {
            display: flex;
            gap: 12px;
            align-items: center;
            color: rgba(255, 255, 255, .90);
            font-weight: 800;
            margin-top: 14px;
        }

        .contact-item i {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, .12);
        }

        .contact-box-home {
            background: white;
            border-radius: 28px;
            padding: 34px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
        }

        .home-footer {
            margin-top: 35px;
            padding: 58px 24px;
            text-align: center;
            background: linear-gradient(135deg, #02160c, #020617);
            color: rgba(255, 255, 255, .70);
        }

        .home-footer img {
            width: 76px;
            height: 76px;
            object-fit: contain;
            background: white;
            padding: 7px;
            border-radius: 22px;
            margin: 0 auto 17px;
        }

        .home-footer h3 {
            color: white;
            font-size: 28px;
            margin-bottom: 10px;
        }

        .home-footer p {
            color: rgba(255, 255, 255, .70);
            margin: 8px 0;
        }

        .modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 10000;
            background: rgba(2, 6, 23, .82);
            backdrop-filter: blur(12px);
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 24px;
            opacity: 0;
            visibility: hidden;
            transition: .25s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-login {
            width: min(460px, 100%);
            background: white;
            border-radius: 30px;
            padding: 40px;
            position: relative;
            box-shadow: var(--shadow-lg);
            transform: translateY(25px);
            transition: .25s ease;
        }

        .modal-overlay.active .modal-login {
            transform: translateY(0);
        }

        .close-modal {
            position: absolute;
            top: 17px;
            right: 17px;
            width: 42px;
            height: 42px;
            border-radius: 14px;
            border: none;
            background: #f1f5f9;
            color: var(--muted);
            cursor: pointer;
            font-size: 18px;
        }

        .modal-top {
            text-align: center;
            margin-bottom: 27px;
        }

        .modal-top img {
            width: 92px;
            height: 92px;
            object-fit: contain;
            background: var(--primary-light);
            border-radius: 25px;
            padding: 8px;
            margin: 0 auto 16px;
        }

        .modal-top h3 {
            font-size: 30px;
            margin-bottom: 8px;
        }

        .modal-top p {
            line-height: 1.6;
        }

        @media(max-width: 960px) {
            .hero-layout,
            .workflow-grid,
            .contact-layout {
                grid-template-columns: 1fr;
            }

            .hero-text {
                text-align: center;
            }

            .hero-text p {
                margin-left: auto;
                margin-right: auto;
            }

            .hero-actions,
            .hero-trust {
                justify-content: center;
            }

            .home-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media(max-width: 760px) {
            .menu-toggle {
                display: flex;
            }

            .public-menu {
                position: absolute;
                top: calc(100% + 12px);
                left: 0;
                right: 0;
                background: rgba(2, 22, 12, .96);
                border-radius: 22px;
                padding: 18px;
                flex-direction: column;
                align-items: stretch;
                display: none;
            }

            .public-menu.active {
                display: flex;
            }

            .public-menu a,
            .public-menu button {
                width: 100%;
                justify-content: center;
            }

            .public-logo span {
                display: none;
            }

            .public-logo h1 {
                font-size: 20px;
            }

            .hero-public {
                padding-top: 145px;
            }

            .preview-grid,
            .home-grid {
                grid-template-columns: 1fr;
            }

            .home-section {
                padding: 62px 18px;
            }

            .home-stats {
                margin-top: -45px;
                padding: 0 18px;
            }

            .modal-login {
                padding: 30px;
            }
        }
    </style>
</head>

<body class="home-body">

    <nav class="public-navbar">
        <a href="#inicio" class="public-logo">
            <img src="assets/Medicore.png" alt="Logo MediCore">
            <div>
                <h1>MediCore</h1>
                <span>Professional System</span>
            </div>
        </a>

        <div class="menu-toggle" onclick="toggleMenu()">
            <i class="fas fa-bars"></i>
        </div>

        <div class="public-menu" id="menu">
            <a href="#inicio">Inicio</a>
            <a href="#servicios">Servicios</a>
            <a href="#flujo">Flujo Clínico</a>
            <a href="#arquitectura">Arquitectura</a>
            <a href="#roles">Usuarios</a>
            <a href="#contacto">Contacto</a>

            <button type="button" onclick="abrirModal()" class="btn-primary">
                <i class="fas fa-user-md"></i>
                Acceso Médico
            </button>
        </div>
    </nav>

    <section class="hero-public" id="inicio">
        <div class="hero-layout">

            <div class="hero-text">
                <div class="hero-badge">
                    <i class="fas fa-shield-heart"></i>
                    Plataforma Clínica Inteligente
                </div>

                <h2>
                    Gestión Médica <span>Profesional</span> de Nueva Generación
                </h2>

                <p>
                    MediCore centraliza expedientes clínicos, citas médicas,
                    diagnósticos, tratamientos y seguimiento nutricional
                    en una sola plataforma segura, moderna y optimizada
                    para instituciones de salud.
                </p>

                <div class="hero-actions">
                    <button type="button" onclick="abrirModal()" class="btn-primary">
                        <i class="fas fa-hospital-user"></i>
                        Ingresar al Sistema
                    </button>

                    <a href="#servicios" class="btn-outline-home">
                        <i class="fas fa-arrow-down"></i>
                        Ver Características
                    </a>
                </div>

                <div class="hero-trust">
                    <span><i class="fas fa-lock"></i> Acceso protegido</span>
                    <span><i class="fas fa-file-medical"></i> Expedientes digitales</span>
                    <span><i class="fas fa-chart-line"></i> Reportes clínicos</span>
                </div>
            </div>

            <div class="hero-panel">
                <div class="preview-card">
                    <div class="preview-top">
                        <div class="preview-brand">
                            <img src="assets/Medicore.png" alt="MediCore">
                            <div>
                                <strong>Panel Médico</strong><br>
                                <small>Vista clínica general</small>
                            </div>
                        </div>
                        <span class="preview-status">Activo</span>
                    </div>

                    <div class="preview-content">
                        <div class="preview-grid">
                            <div class="preview-mini">
                                <i class="fas fa-user-injured"></i>
                                <h3>128</h3>
                                <p>Pacientes</p>
                            </div>

                            <div class="preview-mini">
                                <i class="fas fa-calendar-check"></i>
                                <h3>18</h3>
                                <p>Citas</p>
                            </div>

                            <div class="preview-mini">
                                <i class="fas fa-file-prescription"></i>
                                <h3>42</h3>
                                <p>Recetas</p>
                            </div>

                            <div class="preview-mini">
                                <i class="fas fa-heart-pulse"></i>
                                <h3>95%</h3>
                                <p>Seguimiento</p>
                            </div>
                        </div>

                        <div class="preview-row">
                            <div>
                                <strong>Laura Sánchez</strong><br>
                                <small>Revisión nutricional</small>
                            </div>
                            <span class="badge success">Autorizada</span>
                        </div>

                        <div class="preview-row">
                            <div>
                                <strong>Antonio Pérez</strong><br>
                                <small>Expediente pendiente</small>
                            </div>
                            <span class="badge warning">Pendiente</span>
                        </div>

                        <div class="preview-row">
                            <div>
                                <strong>Aurora Evangeline</strong><br>
                                <small>Consulta de seguimiento</small>
                            </div>
                            <span class="badge success">Programada</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <section class="home-stats">
        <div class="stats-grid">

            <div class="stat-card">
                <div class="stat-top">
                    <div>
                        <h2>24/7</h2>
                        <p>Disponibilidad del Sistema</p>
                    </div>
                    <div class="stat-icon icon-primary">
                        <i class="fas fa-user-doctor"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div>
                        <h2>100%</h2>
                        <p>Expedientes Digitales</p>
                    </div>
                    <div class="stat-icon icon-success">
                        <i class="fas fa-file-medical"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div>
                        <h2>256</h2>
                        <p>Protección de Seguridad</p>
                    </div>
                    <div class="stat-icon icon-warning">
                        <i class="fas fa-lock"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div>
                        <h2>+95%</h2>
                        <p>Eficiencia Clínica</p>
                    </div>
                    <div class="stat-icon icon-primary">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <section class="home-section" id="servicios">
        <div class="section-center">
            <div class="section-kicker">
                <i class="fas fa-stethoscope"></i>
                Servicios Clínicos
            </div>

            <h2>Herramientas para la gestión médica profesional</h2>

            <p>
                MediCore fue diseñado para automatizar los procesos médicos más importantes
                dentro de una institución de salud moderna.
            </p>
        </div>

        <div class="home-grid">

            <div class="home-feature">
                <div class="home-feature-icon">
                    <i class="fas fa-notes-medical"></i>
                </div>
                <h3>Expedientes Clínicos</h3>
                <p>
                    Registro completo de diagnósticos, recetas, métricas biométricas,
                    tratamientos y evolución clínica del paciente.
                </p>
            </div>

            <div class="home-feature">
                <div class="home-feature-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <h3>Agenda Inteligente</h3>
                <p>
                    Organización automática de consultas, revisiones médicas y seguimientos
                    clínicos en tiempo real.
                </p>
            </div>

            <div class="home-feature">
                <div class="home-feature-icon">
                    <i class="fas fa-file-pdf"></i>
                </div>
                <h3>Exportación PDF</h3>
                <p>
                    Generación profesional de expedientes, recetas y reportes médicos listos
                    para impresión institucional.
                </p>
            </div>

        </div>
    </section>

    <section class="home-section" id="flujo">
        <div class="workflow-grid">

            <div class="workflow-main">
                <h3>Flujo clínico organizado</h3>
                <p>
                    El sistema permite que el médico consulte pacientes, registre diagnósticos,
                    autorice dietas, agende citas y genere reportes de forma ordenada,
                    segura y profesional.
                </p>
            </div>

            <div class="workflow-steps">

                <div class="workflow-step">
                    <div class="step-number">1</div>
                    <div>
                        <h3>Registro y consulta</h3>
                        <p>El médico visualiza la información del paciente desde el dashboard.</p>
                    </div>
                </div>

                <div class="workflow-step">
                    <div class="step-number">2</div>
                    <div>
                        <h3>Diagnóstico y tratamiento</h3>
                        <p>Se capturan datos clínicos, receta, IMC, observaciones y seguimiento.</p>
                    </div>
                </div>

                <div class="workflow-step">
                    <div class="step-number">3</div>
                    <div>
                        <h3>Reportes profesionales</h3>
                        <p>El sistema genera reportes y expedientes PDF para respaldo institucional.</p>
                    </div>
                </div>

            </div>

        </div>
    </section>

    <section class="home-section" id="arquitectura">
        <div class="section-center">
            <div class="section-kicker">
                <i class="fas fa-server"></i>
                Arquitectura del Sistema
            </div>

            <h2>Base técnica segura y escalable</h2>

            <p>
                Plataforma construida bajo estándares empresariales usando arquitectura LAMP
                y prácticas modernas de seguridad informática.
            </p>
        </div>

        <div class="home-grid">

            <div class="home-feature">
                <div class="home-feature-icon">
                    <i class="fab fa-linux"></i>
                </div>
                <h3>Infraestructura Linux</h3>
                <p>
                    Servidores Linux optimizados para rendimiento, estabilidad y seguridad institucional.
                </p>
            </div>

            <div class="home-feature">
                <div class="home-feature-icon">
                    <i class="fas fa-database"></i>
                </div>
                <h3>Base de Datos Segura</h3>
                <p>
                    Uso de MySQL/MariaDB con relaciones estructuradas, prepared statements y protección anti SQL Injection.
                </p>
            </div>

            <div class="home-feature">
                <div class="home-feature-icon">
                    <i class="fab fa-php"></i>
                </div>
                <h3>Backend Profesional</h3>
                <p>
                    PHP optimizado con manejo avanzado de sesiones, CSRF Tokens y auditoría de seguridad.
                </p>
            </div>

        </div>
    </section>

    <section class="home-section" id="roles">
        <div class="section-center">
            <div class="section-kicker">
                <i class="fas fa-users-gear"></i>
                Usuarios
            </div>

            <h2>Usuarios del Sistema</h2>

            <p>
                MediCore administra diferentes niveles de acceso dependiendo del perfil médico o clínico.
            </p>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Rol</th>
                        <th>Funciones</th>
                        <th>Módulos</th>
                    </tr>
                </thead>

                <tbody>
                    <tr>
                        <td>
                            <strong><i class="fas fa-user-doctor"></i> Médico</strong>
                        </td>

                        <td>
                            Gestión de expedientes, diagnósticos, tratamientos y seguimiento clínico.
                        </td>

                        <td>
                            Dashboard, Agenda, Pacientes, Expediente y Reportes PDF.
                        </td>
                    </tr>

                    <tr>
                        <td>
                            <strong><i class="fas fa-user-injured"></i> Paciente</strong>
                        </td>

                        <td>
                            Consulta de citas, seguimiento nutricional y revisión médica.
                        </td>

                        <td>
                            Portal de citas, seguimiento y control clínico.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="home-section" id="contacto">
        <div class="section-center">
            <div class="section-kicker">
                <i class="fas fa-headset"></i>
                Contacto y Soporte
            </div>

            <h2>Soporte técnico de MediCore</h2>

            <p>
                ¿Necesitas ayuda técnica con MediCore? Envíanos tu solicitud directamente.
            </p>
        </div>

        <div class="contact-layout">

            <div class="contact-info-home">
                <img src="assets/Medicore.png" alt="MediCore">

                <h3>Estamos para ayudarte</h3>

                <p>
                    Envía tu solicitud de soporte y el equipo técnico podrá revisar tu caso
                    dentro del sistema.
                </p>

                <div class="contact-item">
                    <i class="fas fa-envelope"></i>
                    Soporte institucional
                </div>

                <div class="contact-item">
                    <i class="fas fa-shield-alt"></i>
                    Comunicación segura
                </div>

                <div class="contact-item">
                    <i class="fas fa-building-columns"></i>
                    Universidad Tecnológica de Puebla
                </div>
            </div>

            <div class="contact-box-home">

                <?php if ($mensaje_alerta == "exito"): ?>

                    <div class="alerta exito">
                        ✅ Mensaje enviado correctamente.
                    </div>

                <?php elseif ($mensaje_alerta == "error"): ?>

                    <div class="alerta error">
                        ⚠️ Error al enviar el mensaje.
                    </div>

                <?php endif; ?>

                <form method="POST">

                    <input type="hidden" name="accion" value="contacto_emergencia">

                    <input type="hidden" name="csrf_token" value="<?= generarTokenCSRF() ?>">

                    <label>Nombre completo</label>
                    <input type="text" name="nombre" placeholder="Escribe tu nombre completo" required>

                    <label>Correo electrónico</label>
                    <input type="email" name="correo" placeholder="correo@ejemplo.com" required>

                    <label>Asunto</label>
                    <input type="text" name="asunto" placeholder="Asunto de la solicitud" required>

                    <label>Mensaje</label>
                    <textarea name="mensaje" placeholder="Describe tu problema..." required></textarea>

                    <button type="submit" class="btn-primary">
                        <i class="fas fa-paper-plane"></i>
                        Enviar Solicitud
                    </button>

                </form>

            </div>

        </div>
    </section>

    <footer class="home-footer">
        <img src="assets/Medicore.png" alt="MediCore">

        <h3>MediCore Professional System</h3>

        <p>
            Plataforma avanzada de gestión médica y expedientes clínicos.
        </p>

        <p>
            © 2026 MediCore. Todos los derechos reservados.
        </p>

        <p style="color:#dff5e8; font-weight:900;">
            Universidad Tecnológica de Puebla
        </p>

        <p style="margin-top:14px;">
            <a href="aviso_privacidad.php" style="color:#dff5e8; text-decoration:underline; font-weight:800;">
                <i class="fas fa-file-shield"></i> Aviso de Privacidad
            </a>
        </p>
    </footer>

    <div class="modal-overlay" id="modalLogin">

        <div class="modal-login">

            <button type="button" class="close-modal" onclick="cerrarModal()">
                <i class="fas fa-times"></i>
            </button>

            <div class="modal-top">

                <img src="assets/Medicore.png" alt="MediCore">

                <h3>Acceso Médico</h3>

                <p>
                    Ingresa tus credenciales institucionales.
                </p>

            </div>

            <form action="login.php" method="POST">

                <input type="hidden" name="csrf_token" value="<?= generarTokenCSRF() ?>">

                <label>Correo institucional</label>
                <input type="email" name="correo" placeholder="Correo institucional" required>

                <label>Contraseña</label>
                <input type="password" name="password" placeholder="Contraseña" required>

                <button type="submit" class="btn-primary" style="width:100%;">
                    <i class="fas fa-right-to-bracket"></i>
                    Iniciar Sesión
                </button>

            </form>

            <?php if (function_exists('apiHabilitada') && apiHabilitada(['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'])): ?>
                <a href="oauth_google.php" class="btn-outline-home" style="width:100%;margin-top:14px;color:#0f172a;border-color:var(--border);background:#fff;">
                    <i class="fab fa-google"></i>
                    Iniciar sesión con Google
                </a>
            <?php endif; ?>

        </div>

    </div>

    <script>
        const modal = document.getElementById('modalLogin');

        function abrirModal() {
            modal.classList.add('active');
        }

        function cerrarModal() {
            modal.classList.remove('active');
        }

        function toggleMenu() {
            document.getElementById('menu').classList.toggle('active');
        }

        window.addEventListener('click', function(e) {
            if (e.target === modal) {
                cerrarModal();
            }
        });

        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                cerrarModal();
            }
        });
    </script>

</body>

</html>