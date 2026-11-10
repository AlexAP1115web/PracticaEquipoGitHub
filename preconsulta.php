<?php
include("config.php");
include("security.php");
require_once __DIR__ . "/config/esquema.php";
require_once __DIR__ . "/integrations/Preconsulta.php";
require_once __DIR__ . "/integrations/WebPush.php";

define('MEDICORE_SOMAMAP', true);

/* ======================================================
   PRE-CONSULTA POR QR (página pública, sin cuenta)
   El paciente la abre con el QR que generó su médico en el expediente:
   1. Contesta por qué viene, desde cuándo, qué medicamentos toma, sus alergias y si
      necesita que lo lleven a la cita (traslado).
   2. Marca en SomaMap dónde siente algo (assets/js/mapa-corporal.js en modo preconsulta).
   3. Al médico le llega el resumen en el expediente y un aviso push.
   El enlace sirve una sola vez y vence en 72 horas.
====================================================== */

prepararEsquemaInnovacion($conexion);

$token = (string) ($_GET['t'] ?? '');
$pre = preconsultaPorToken($conexion, $token);
$estado = $pre ? preconsultaEstado($pre) : 'invalida';

if ($estado === 'invalida') {
    http_response_code(404);
    registrarLog("Pre-consulta: abrieron un enlace que no existe.", "WARNING");
} elseif ($estado !== 'valida') {
    http_response_code(410);
}

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$primerNombre = $pre ? explode(' ', trim($pre['paciente']))[0] : '';
$medico = $pre && !empty($pre['medico']) ? $pre['medico'] : 'Tu médico';

// La cita solo se muestra si todavía no pasa
$textoCita = '';

if ($pre && !empty($pre['fecha_cita']) && $pre['fecha_cita'] >= date('Y-m-d')) {
    $textoCita = ucfirst(fechaCitaEnTexto($pre['fecha_cita']));

    if (!empty($pre['hora_cita'])) {
        $textoCita .= ', ' . date('H:i', strtotime($pre['hora_cita'])) . ' h';
    }
}

$avisos = [
    'invalida'   => ['fa-link-slash', 'Este enlace no es válido', 'Revisa que hayas escaneado el código completo o pide a tu médico uno nuevo.'],
    'vencida'    => ['fa-clock', 'Este enlace ya venció', 'Los enlaces de pre-consulta duran 72 horas. Pide a tu médico que genere uno nuevo.'],
    'respondida' => ['fa-circle-check', 'Ya enviaste tu pre-consulta', 'Tu médico ya tiene tus respuestas. Si algo cambió, coméntaselo en tu cita.']
];

$datosMapa = null;

if ($estado === 'valida') {
    $datosMapa = [
        'paciente'    => ['id' => (int) $pre['paciente_id'], 'nombre' => $primerNombre, 'primerNombre' => $primerNombre],
        'zonas'       => mapaZonas(),
        'sensaciones' => mapaSensaciones(),
        'registros'   => [],
        'modo'        => 'preconsulta',
        'csrf'        => generarTokenCSRF(),
        'api'         => 'api/preconsulta.php',
        'token'       => $token,
        'hoy'         => date('Y-m-d')
    ];
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0E3B2C">
    <title>Pre-consulta | MediCore</title>

    <link rel="icon" href="assets/icons/icon-192.png">
    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body class="modo-paciente pagina-preconsulta">

    <div class="content preconsulta" id="mapaCorporal">

        <header class="pre-marca">
            <img src="assets/icons/icon-192.png" alt="" width="40" height="40">
            <div>
                <strong>MediCore</strong>
                <span>Pre-consulta</span>
            </div>
        </header>

        <?php if ($estado !== 'valida'): ?>

            <section class="pre-aviso" data-estado="<?= e($estado) ?>">
                <i class="fas <?= e($avisos[$estado][0]) ?>" aria-hidden="true"></i>
                <h1><?= e($avisos[$estado][1]) ?></h1>
                <p><?= e($avisos[$estado][2]) ?></p>
            </section>

        <?php else: ?>

            <ol class="pre-pasos" id="prePasos">
                <li aria-current="step"><span>1</span> Tus respuestas</li>
                <li><span>2</span> SomaMap</li>
                <li><span>3</span> Listo</li>
            </ol>

            <!-- ==================================================
                 PASO 1: CUESTIONARIO
            ================================================== -->
            <section class="pre-tarjeta" id="pasoPreguntas">
                <p class="mapa-saludo">Hola, <?= e($primerNombre) ?></p>
                <h1 class="pre-titulo">Antes de tu cita</h1>
                <p class="pre-intro">
                    <strong><?= e($medico) ?></strong> te pide llenar esto. Toma unos dos minutos y le ayuda a prepararse para atenderte.
                </p>

                <?php if ($textoCita): ?>
                    <p class="pre-cita">
                        <i class="fas fa-calendar-check" aria-hidden="true"></i>
                        <span>Tu cita: <strong><?= e($textoCita) ?></strong></span>
                    </p>
                <?php endif; ?>

                <form id="preForm" novalidate>
                    <label class="pre-campo">
                        <span>¿Por qué vienes a consulta?</span>
                        <textarea name="motivo" id="preMotivo" rows="3" maxlength="300" required placeholder="Ej. Me duele la rodilla al subir escaleras"></textarea>
                    </label>

                    <fieldset class="mapa-campo pre-campo">
                        <legend>¿Desde cuándo te sientes así?</legend>
                        <div class="mapa-chips">
                            <?php foreach (preconsultaOpcionesInicio() as $clave => $texto): ?>
                                <label><input type="radio" name="inicio" value="<?= e($clave) ?>"><span><?= e($texto) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <label class="pre-campo">
                        <span>¿Qué medicamentos tomas ahora? <small>(opcional)</small></span>
                        <input type="text" name="medicamentos" maxlength="200" autocomplete="off" placeholder="Ej. Metformina 850 mg, losartán">
                    </label>

                    <label class="pre-campo">
                        <span>¿Eres alérgico a algún medicamento? <small>(opcional)</small></span>
                        <input type="text" name="alergias" maxlength="150" autocomplete="off" placeholder="Ej. Penicilina">
                    </label>

                    <label class="pre-campo">
                        <span>¿Qué te gustaría preguntarle a tu médico? <small>(opcional)</small></span>
                        <textarea name="pregunta" rows="2" maxlength="300"></textarea>
                    </label>

                    <fieldset class="mapa-campo pre-campo">
                        <legend>¿Necesitas que te llevemos a tu cita? <small>(solo citas en el consultorio)</small></legend>
                        <div class="mapa-chips">
                            <label><input type="radio" name="traslado" value="no" checked><span>No, gracias</span></label>
                            <?php foreach (trasladoTipos() as $clave => $texto): ?>
                                <label><input type="radio" name="traslado" value="<?= e($clave) ?>"><span><?= e($texto) ?></span></label>
                            <?php endforeach; ?>
                        </div>

                        <div class="pre-traslado-datos" id="preTrasladoDatos" hidden>
                            <label class="pre-campo">
                                <span>¿Dónde pasamos por ti?</span>
                                <input type="text" name="traslado_direccion" id="preTrasladoDireccion" maxlength="255" autocomplete="street-address" placeholder="Calle, número, colonia y referencias">
                            </label>
                            <label class="pre-campo">
                                <span>¿Algo que debamos saber? <small>(opcional)</small></span>
                                <input type="text" name="traslado_notas" maxlength="200" autocomplete="off" placeholder="Ej. uso silla de ruedas, voy con un acompañante">
                            </label>
                        </div>
                    </fieldset>

                    <p class="mapa-error" id="preError" role="alert" hidden></p>

                    <button type="submit" class="btn-primary btn-grande">
                        Siguiente: marcar en el cuerpo
                        <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </button>
                </form>

                <p class="pre-privacidad">
                    <i class="fas fa-lock" aria-hidden="true"></i>
                    Solo tu médico ve tus respuestas. Este enlace sirve una vez.
                    <a href="aviso_privacidad.php" target="_blank" rel="noopener">Aviso de privacidad</a>
                </p>
            </section>

            <!-- ==================================================
                 PASO 2: SOMAMAP
            ================================================== -->
            <div class="mapa-vista mapa-paciente" id="vistaRegistro" hidden>

                <header class="mapa-paciente-cab">
                    <div>
                        <p class="mapa-saludo">Paso 2: SomaMap</p>
                        <h2 tabindex="-1" id="preTituloMapa">¿Dónde sientes algún síntoma?</h2>
                        <p class="mapa-instruccion">Toca la parte del cuerpo. Puedes marcar varias. Si no sientes nada, elige «No tengo síntomas».</p>
                    </div>
                    <button type="button" class="btn-salir-paciente" data-pre="regresar">
                        <i class="fas fa-arrow-left" aria-hidden="true"></i>
                        <span>Regresar</span>
                    </button>
                </header>

                <div class="mapa-paciente-cuerpo" id="registroCuerpo">
                    <div class="mapa-paciente-figuras">
                        <div class="mapa-vista-selector" role="group" aria-label="Lado del cuerpo">
                            <button type="button" data-vista="frente" aria-pressed="true">Frente</button>
                            <button type="button" data-vista="espalda" aria-pressed="false">Espalda</button>
                        </div>
                        <div class="mapa-figuras" data-figuras="registro" data-vista-activa="frente"></div>
                    </div>

                    <aside class="mapa-paciente-panel">
                        <h3>Lo que marcaste</h3>
                        <ul class="mapa-lista mapa-lista-editable" id="registroLista"></ul>
                        <p class="mapa-lista-vacia" id="registroVacio">Aún no marcas ninguna zona.</p>

                        <button type="button" class="btn-anterior" id="btnUsarAnterior" hidden>
                            <i class="fas fa-clock-rotate-left" aria-hidden="true"></i>
                            Partir de lo que marqué la vez pasada
                        </button>

                        <label class="mapa-comentario-campo">
                            <span>¿Algo más que quieras decirle al médico? <small>(opcional)</small></span>
                            <textarea id="registroComentario" rows="2" maxlength="300"></textarea>
                        </label>

                        <p class="mapa-error" id="registroError" role="alert" hidden></p>

                        <div class="mapa-paciente-acciones">
                            <button type="button" class="btn-primary btn-grande" id="btnGuardarRegistro" disabled>
                                <i class="fas fa-paper-plane" aria-hidden="true"></i>
                                Enviar a mi médico
                            </button>
                            <button type="button" class="btn-sin-molestias" id="btnSinMolestias">
                                No tengo síntomas
                            </button>
                        </div>
                    </aside>
                </div>

                <section class="mapa-listo" id="registroListo" hidden tabindex="-1">
                    <i class="fas fa-circle-check" aria-hidden="true"></i>
                    <h2>¡Gracias, <?= e($primerNombre) ?>!</h2>
                    <p>Tu médico ya recibió tus respuestas. Ya puedes cerrar esta página.</p>
                </section>
            </div>

            <?php include __DIR__ . "/componentes/somamap_hoja.php"; ?>

        <?php endif; ?>

    </div>

    <?php if ($datosMapa): ?>
        <script type="application/json" id="mapaDatos"><?= json_encode($datosMapa, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        <script src="assets/js/mapa-corporal.js?v=2" defer></script>
        <script>
            // Paso 1 -> paso 2 y respuestas que se mandan junto con SomaMap
            (function () {
                'use strict';

                const datos = JSON.parse(document.getElementById('mapaDatos').textContent);
                const raiz = document.getElementById('mapaCorporal');
                const form = document.getElementById('preForm');
                const error = document.getElementById('preError');
                const paso1 = document.getElementById('pasoPreguntas');
                const paso2 = document.getElementById('vistaRegistro');
                const pasos = document.querySelectorAll('#prePasos li');
                let respuestas = null;

                function marcarPaso(n) {
                    pasos.forEach(function (li, i) {
                        if (i === n - 1) {
                            li.setAttribute('aria-current', 'step');
                        } else {
                            li.removeAttribute('aria-current');
                        }
                        li.classList.toggle('hecho', i < n - 1);
                    });
                }

                // Traslado: la dirección solo aparece si pide que lo lleven
                const trasladoDatos = document.getElementById('preTrasladoDatos');
                const trasladoDireccion = document.getElementById('preTrasladoDireccion');

                // El aviso de "falta contestar" se quita en cuanto el paciente corrige
                form.addEventListener('input', function () {
                    error.hidden = true;
                });

                form.addEventListener('change', function (e) {
                    if (e.target.name === 'traslado') {
                        trasladoDatos.hidden = e.target.value === 'no';
                        if (e.target.value !== 'no') {
                            trasladoDireccion.focus();
                        }
                    }
                });

                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    const motivo = form.motivo.value.trim();
                    const inicio = form.querySelector('input[name="inicio"]:checked');
                    const traslado = form.querySelector('input[name="traslado"]:checked').value;
                    const direccion = trasladoDireccion.value.trim();
                    const faltan = [];

                    if (motivo.length < 3) faltan.push('por qué vienes');
                    if (!inicio) faltan.push('desde cuándo te sientes así');
                    if (traslado !== 'no' && direccion.length < 8) faltan.push('dónde pasamos por ti');

                    if (faltan.length) {
                        error.textContent = 'Falta contestar ' + faltan.join(', ').replace(/, ([^,]*)$/, ' y $1') + '.';
                        error.hidden = false;
                        (motivo.length < 3 ? form.motivo : (!inicio ? form.querySelector('input[name="inicio"]') : trasladoDireccion)).focus();
                        return;
                    }

                    error.hidden = true;
                    respuestas = {
                        motivo: motivo,
                        inicio: inicio.value,
                        medicamentos: form.medicamentos.value.trim(),
                        alergias: form.alergias.value.trim(),
                        pregunta: form.pregunta.value.trim(),
                        traslado: traslado === 'no' ? { tipo: 'no' } : {
                            tipo: traslado,
                            direccion: direccion,
                            notas: form.traslado_notas.value.trim()
                        }
                    };

                    paso1.hidden = true;
                    paso2.hidden = false;
                    marcarPaso(2);
                    window.scrollTo(0, 0);
                    document.getElementById('preTituloMapa').focus({ preventScroll: true });
                });

                raiz.addEventListener('click', function (e) {
                    if (e.target.closest('[data-pre="regresar"]')) {
                        paso2.hidden = true;
                        paso1.hidden = false;
                        marcarPaso(1);
                        window.scrollTo(0, 0);
                    }
                });

                raiz.addEventListener('somamap:guardado', function () {
                    marcarPaso(3);
                    pasos[2].classList.add('hecho');
                    paso2.querySelector('.mapa-paciente-cab').hidden = true;
                });

                window.somaMapAntesDeEnviar = function () {
                    if (!respuestas) {
                        paso2.hidden = true;
                        paso1.hidden = false;
                        marcarPaso(1);
                        return false;
                    }
                    return { token: datos.token, respuestas: respuestas };
                };
            })();
        </script>
    <?php endif; ?>

</body>

</html>
