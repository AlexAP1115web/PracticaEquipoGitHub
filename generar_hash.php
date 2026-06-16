<?php
require_once("config.php");

/* ======================================================
   MEDICORE SECURITY HASH GENERATOR
   Herramienta Administrativa Profesional
====================================================== */

// Opcional: proteger herramienta con sesión
// verificarSesion();

$hash_generado = "";
$mensaje = "";
$nivel = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    validarTokenCSRF($_POST['csrf_token'] ?? '');

    $password = trim($_POST['password'] ?? '');

    if (empty($password)) {

        $mensaje = "Debes ingresar una contraseña.";
        $nivel = "error";
    } elseif (strlen($password) < 6) {

        $mensaje = "La contraseña debe tener mínimo 6 caracteres.";
        $nivel = "warning";
    } else {

        $hash_generado = password_hash(
            $password,
            PASSWORD_BCRYPT,
            ['cost' => 12]
        );

        $mensaje = "Hash generado correctamente.";
        $nivel = "success";

        registrarLog(
            "Generación de hash de seguridad desde herramienta administrativa.",
            "INFO"
        );
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>
        Generador de Hash | MediCore Security
    </title>

    <link
        rel="stylesheet"
        href="assets/style_medicore.css">

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body {

            min-height: 100vh;

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 40px 20px;

            background:
                radial-gradient(circle at top right,
                    rgba(14, 165, 233, .18),
                    transparent 30%),
                linear-gradient(135deg,
                    #020617 0%,
                    #0f172a 50%,
                    #111827 100%);

            overflow-x: hidden;
        }

        .security-wrapper {

            width: 100%;

            max-width: 650px;

            position: relative;

            z-index: 2;
        }

        .security-card {

            background:
                rgba(255, 255, 255, .96);

            backdrop-filter: blur(18px);

            border:
                1px solid rgba(255, 255, 255, .15);

            border-radius: 32px;

            overflow: hidden;

            box-shadow:
                0 30px 60px rgba(0, 0, 0, .35);
        }

        .security-header {

            position: relative;

            padding: 45px 40px;

            background:
                linear-gradient(135deg,
                    var(--navy-2),
                    var(--navy),
                    var(--primary));

            overflow: hidden;
        }

        .security-header::before {

            content: '';

            position: absolute;

            width: 260px;
            height: 260px;

            border-radius: 50%;

            background:
                rgba(255, 255, 255, .06);

            top: -120px;
            right: -80px;
        }

        .security-header::after {

            content: '';

            position: absolute;

            width: 180px;
            height: 180px;

            border-radius: 50%;

            background:
                rgba(255, 255, 255, .04);

            bottom: -80px;
            left: -60px;
        }

        .security-icon {

            width: 80px;
            height: 80px;

            border-radius: 24px;

            display: flex;

            align-items: center;
            justify-content: center;

            background:
                rgba(255, 255, 255, .12);

            border:
                1px solid rgba(255, 255, 255, .12);

            margin-bottom: 20px;

            position: relative;

            z-index: 2;
        }

        .security-icon i {

            color: white;

            font-size: 34px;
        }

        .security-header h1 {

            color: white;

            font-size: 38px;

            margin-bottom: 10px;

            position: relative;

            z-index: 2;
        }

        .security-header p {

            color: #cbd5e1;

            font-size: 16px;

            line-height: 1.7;

            position: relative;

            z-index: 2;
        }

        .security-body {

            padding: 40px;
        }

        .security-alert {

            border-radius: 18px;

            padding: 18px 20px;

            margin-bottom: 25px;

            font-weight: 700;

            display: flex;

            align-items: center;

            gap: 14px;
        }

        .security-alert.success {

            background: #dcfce7;

            color: #166534;

            border: 1px solid #bbf7d0;
        }

        .security-alert.error {

            background: #fee2e2;

            color: #991b1b;

            border: 1px solid #fecaca;
        }

        .security-alert.warning {

            background: #fef3c7;

            color: #92400e;

            border: 1px solid #fde68a;
        }

        .form-group {

            margin-bottom: 24px;
        }

        .form-group label {

            display: flex;

            align-items: center;

            gap: 10px;

            margin-bottom: 12px;

            color: var(--navy);

            font-weight: 800;

            font-size: 15px;
        }

        .hash-input {

            width: 100%;

            background: white;

            border: 2px solid var(--border);

            border-radius: 18px;

            padding: 18px 20px;

            font-size: 16px;

            color: var(--text-main);

            transition: .25s ease;

            outline: none;
        }

        .hash-input:focus {

            border-color: var(--primary);

            box-shadow:
                0 0 0 5px rgba(2, 132, 199, .12);
        }

        .hash-btn {

            width: 100%;

            border: none;

            border-radius: 18px;

            padding: 18px;

            cursor: pointer;

            color: white;

            font-size: 16px;

            font-weight: 800;

            background:
                linear-gradient(135deg,
                    #0284c7,
                    #0ea5e9);

            display: flex;

            align-items: center;

            justify-content: center;

            gap: 12px;

            transition: .25s ease;

            box-shadow:
                0 18px 35px rgba(2, 132, 199, .22);
        }

        .hash-btn:hover {

            transform:
                translateY(-2px);

            box-shadow:
                0 22px 40px rgba(2, 132, 199, .30);
        }

        .security-info {

            margin-top: 28px;

            background:
                linear-gradient(135deg,
                    #f8fafc,
                    #f1f5f9);

            border:
                1px solid var(--border);

            border-left:
                5px solid var(--primary);

            border-radius: 22px;

            padding: 24px;
        }

        .security-info h3 {

            font-size: 18px;

            margin-bottom: 12px;

            color: var(--navy);

            display: flex;

            align-items: center;

            gap: 10px;
        }

        .security-info ul {

            padding-left: 22px;
        }

        .security-info li {

            margin-bottom: 10px;

            color: var(--text-muted);

            line-height: 1.7;
        }

        .hash-result {

            margin-top: 30px;

            background:
                linear-gradient(135deg,
                    #0f172a,
                    #111827);

            border-radius: 24px;

            padding: 28px;

            border:
                1px solid rgba(255, 255, 255, .08);

            overflow: hidden;

            position: relative;
        }

        .hash-result::before {

            content: '';

            position: absolute;

            inset: 0;

            background:
                linear-gradient(135deg,
                    rgba(14, 165, 233, .06),
                    transparent);
        }

        .hash-result-header {

            display: flex;

            align-items: center;

            gap: 12px;

            margin-bottom: 18px;

            position: relative;

            z-index: 2;
        }

        .hash-result-header i {

            color: #38bdf8;

            font-size: 22px;
        }

        .hash-result-header h3 {

            color: white;

            margin: 0;

            font-size: 20px;
        }

        .hash-box {

            position: relative;

            z-index: 2;

            background:
                rgba(255, 255, 255, .04);

            border:
                1px solid rgba(255, 255, 255, .08);

            border-radius: 18px;

            padding: 22px;

            overflow: auto;
        }

        .hash-code {

            color: #7dd3fc;

            font-size: 15px;

            line-height: 1.8;

            font-family:
                "Consolas",
                "Courier New",
                monospace;

            word-break: break-all;

            user-select: all;
        }

        .copy-tip {

            margin-top: 14px;

            color: #94a3b8;

            font-size: 13px;

            text-align: center;

            position: relative;

            z-index: 2;
        }

        .footer-note {

            margin-top: 28px;

            text-align: center;

            color: #94a3b8;

            font-size: 13px;
        }

        @media(max-width:768px) {

            body {

                padding: 20px;
            }

            .security-header {

                padding: 35px 25px;
            }

            .security-body {

                padding: 25px;
            }

            .security-header h1 {

                font-size: 30px;
            }

            .security-icon {

                width: 70px;
                height: 70px;
            }
        }
    </style>

</head>

<body>

    <div class="security-wrapper">

        <div class="security-card">

            <div class="security-header">

                <div class="security-icon">
                    <i class="fas fa-shield-halved"></i>
                </div>

                <h1>
                    MediCore Security
                </h1>

                <p>
                    Herramienta administrativa profesional para generar
                    hashes seguros con algoritmo Bcrypt de nivel empresarial.
                </p>

            </div>

            <div class="security-body">

                <?php if (!empty($mensaje)): ?>

                    <div class="security-alert <?= $nivel ?>">

                        <?php if ($nivel === 'success'): ?>
                            <i class="fas fa-circle-check"></i>
                        <?php elseif ($nivel === 'warning'): ?>
                            <i class="fas fa-triangle-exclamation"></i>
                        <?php else: ?>
                            <i class="fas fa-circle-xmark"></i>
                        <?php endif; ?>

                        <?= htmlspecialchars($mensaje) ?>

                    </div>

                <?php endif; ?>

                <form method="POST" autocomplete="off">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= generarTokenCSRF() ?>">

                    <div class="form-group">

                        <label>
                            <i class="fas fa-key"></i>
                            Contraseña en Texto Plano
                        </label>

                        <input
                            type="text"
                            name="password"
                            class="hash-input"
                            required
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="Ingresa la contraseña a encriptar...">

                    </div>

                    <button
                        type="submit"
                        class="hash-btn">

                        <i class="fas fa-lock"></i>
                        Generar Hash Seguro

                    </button>

                </form>

                <div class="security-info">

                    <h3>
                        <i class="fas fa-circle-info"></i>
                        Recomendaciones de Seguridad
                    </h3>

                    <ul>
                        <li>
                            Utiliza contraseñas complejas con mayúsculas,
                            números y caracteres especiales.
                        </li>

                        <li>
                            MediCore utiliza hashing Bcrypt con costo elevado
                            para protección avanzada.
                        </li>

                        <li>
                            Nunca almacenes contraseñas en texto plano
                            dentro de la base de datos.
                        </li>

                        <li>
                            Copia únicamente el hash generado para usarlo
                            en tus registros SQL.
                        </li>
                    </ul>

                </div>

                <?php if (!empty($hash_generado)): ?>

                    <div class="hash-result">

                        <div class="hash-result-header">

                            <i class="fas fa-fingerprint"></i>

                            <h3>
                                Hash Generado Correctamente
                            </h3>

                        </div>

                        <div class="hash-box">

                            <div class="hash-code">
                                <?= htmlspecialchars($hash_generado) ?>
                            </div>

                        </div>

                        <div class="copy-tip">
                            Haz clic sobre el hash para seleccionarlo y copiarlo.
                        </div>

                    </div>

                <?php endif; ?>

                <div class="footer-note">
                    MediCore Professional System • Security Layer 2026
                </div>

            </div>

        </div>

    </div>

</body>

</html>