<?php
include("config.php");
include("security.php");
require_once __DIR__ . "/config/esquema.php";
require_once __DIR__ . "/integrations/MapaCorporal.php";

/* ======================================================
   MAPA CORPORAL DE MOLESTIAS
   - Evolución (médico): reproduce los registros del paciente para ver cómo cambiaron
     sus molestias de una consulta a otra.
   - Modo paciente (?modo=paciente): el médico le pasa el dispositivo al paciente,
     que toca la silueta y dice qué siente, desde cuándo y con qué intensidad.
   El dibujo y la interacción están en assets/js/mapa-corporal.js
====================================================== */

verificarSesion();
prepararEsquemaInnovacion($conexion);

$paciente_id = intval($_GET['id'] ?? 0);
$paciente = $paciente_id > 0 ? mapaPaciente($conexion, $paciente_id) : null;

if (!$paciente) {
    registrarLog("Mapa corporal: paciente no encontrado (ID $paciente_id).", "WARNING");
    redirigir("pacientes.php");
}

$registros = mapaRegistros($conexion, $paciente_id);
$modoPaciente = ($_GET['modo'] ?? '') === 'paciente';
$primerNombre = explode(' ', trim($paciente['nombre']))[0];

$datosMapa = [
    'paciente'    => ['id' => (int) $paciente['id'], 'nombre' => $paciente['nombre'], 'primerNombre' => $primerNombre],
    'zonas'       => mapaZonas(),
    'sensaciones' => mapaSensaciones(),
    'registros'   => $registros,
    'modo'        => $modoPaciente ? 'registro' : 'evolucion',
    'csrf'        => generarTokenCSRF(),
    'api'         => 'api/mapa_corporal.php',
    'hoy'         => date('Y-m-d')
];
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mapa de molestias | <?= htmlspecialchars($paciente['nombre']) ?></title>

    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<?php include __DIR__ . "/pwa_head.php"; ?>
</head>

<body class="<?= $modoPaciente ? 'modo-paciente' : '' ?>">

    <?php include("sidebar.php"); ?>

    <div class="content" id="mapaCorporal">

        <!-- ==================================================
             EVOLUCIÓN (lo ve el médico)
        ================================================== -->
        <div class="mapa-vista" id="vistaEvolucion" <?= $modoPaciente ? 'hidden' : '' ?>>

            <section class="top-actions">
                <a href="expediente.php?id=<?= (int) $paciente_id ?>" class="btn-refresh">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                    Regresar al expediente
                </a>
                <button type="button" class="btn-primary" data-accion="nuevo-registro">
                    <i class="fas fa-hand-pointer" aria-hidden="true"></i>
                    Nuevo registro con el paciente
                </button>
            </section>

            <header class="mapa-cab">
                <h2>
                    <i class="fas fa-person" aria-hidden="true"></i>
                    Mapa de molestias
                </h2>
                <p>
                    <strong><?= htmlspecialchars($paciente['nombre']) ?></strong>.
                    Lo que marcó el paciente en cada consulta. Reproduce los registros para ver cómo cambiaron sus molestias.
                </p>
            </header>

            <section class="card mapa-vacio" id="mapaVacio" hidden>
                <i class="fas fa-hand-pointer" aria-hidden="true"></i>
                <h3>Todavía no hay registros</h3>
                <p>Pásale el dispositivo al paciente para que toque en la silueta dónde siente molestias.</p>
                <button type="button" class="btn-primary" data-accion="nuevo-registro">
                    Empezar el primer registro
                </button>
            </section>

            <section class="card mapa-evolucion" id="mapaEvolucion" hidden>
                <div class="mapa-evolucion-cuerpo">
                    <div class="mapa-figuras" data-figuras="evolucion"></div>

                    <div class="mapa-detalle">
                        <p class="mapa-registro-num" id="evoNumero"></p>
                        <h3 class="mapa-registro-fecha" id="evoFecha"></h3>

                        <h4 class="mapa-subtitulo">Cambios desde el registro anterior</h4>
                        <ul class="mapa-cambios" id="evoCambios"></ul>

                        <h4 class="mapa-subtitulo">Molestias en este registro</h4>
                        <ul class="mapa-lista" id="evoLista"></ul>

                        <blockquote class="mapa-comentario" id="evoComentario" hidden></blockquote>
                    </div>
                </div>

                <div class="mapa-linea">
                    <button type="button" class="btn-reproducir" id="btnReproducir" aria-pressed="false">
                        <i class="fas fa-play" aria-hidden="true"></i>
                        <span>Reproducir</span>
                    </button>
                    <div class="mapa-linea-pista">
                        <label for="evoRango" class="sr-only">Registro</label>
                        <input type="range" id="evoRango" min="0" max="0" value="0" step="1">
                        <ol class="mapa-marcas" id="evoMarcas"></ol>
                    </div>
                </div>

                <div class="mapa-leyenda" aria-hidden="true">
                    <span>Intensidad</span>
                    <ol>
                        <li data-nivel="1">1–2</li>
                        <li data-nivel="2">3–4</li>
                        <li data-nivel="3">5–6</li>
                        <li data-nivel="4">7–8</li>
                        <li data-nivel="5">9–10</li>
                    </ol>
                    <span class="mapa-leyenda-resuelta">Ya no se marcó</span>
                </div>

                <p class="sr-only" id="evoAnuncio" aria-live="polite"></p>
            </section>
        </div>

        <!-- ==================================================
             MODO PACIENTE (registro)
        ================================================== -->
        <div class="mapa-vista mapa-paciente" id="vistaRegistro" <?= $modoPaciente ? '' : 'hidden' ?>>

            <header class="mapa-paciente-cab">
                <div>
                    <p class="mapa-saludo">Hola, <?= htmlspecialchars($primerNombre) ?></p>
                    <h2>¿Dónde sientes molestia?</h2>
                    <p class="mapa-instruccion">Toca la parte del cuerpo. Puedes marcar varias.</p>
                </div>
                <button type="button" class="btn-salir-paciente" data-accion="salir-paciente">
                    <i class="fas fa-user-doctor" aria-hidden="true"></i>
                    <span>Salir (médico)</span>
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
                            <i class="fas fa-check" aria-hidden="true"></i>
                            Terminar y guardar
                        </button>
                        <button type="button" class="btn-sin-molestias" id="btnSinMolestias">
                            No tengo molestias
                        </button>
                    </div>
                </aside>
            </div>

            <section class="mapa-listo" id="registroListo" hidden tabindex="-1">
                <i class="fas fa-circle-check" aria-hidden="true"></i>
                <h2>¡Listo, gracias!</h2>
                <p>Tus molestias quedaron guardadas. Devuelve el dispositivo al médico.</p>
                <button type="button" class="btn-primary btn-grande" data-accion="ver-evolucion">
                    <i class="fas fa-user-doctor" aria-hidden="true"></i>
                    Ver la evolución (médico)
                </button>
            </section>
        </div>

        <!-- Hoja para describir la molestia de una zona -->
        <dialog class="mapa-hoja" id="mapaHoja" aria-labelledby="hojaTitulo">
            <form method="dialog" id="hojaForm" novalidate>
                <header class="mapa-hoja-cab">
                    <h3 id="hojaTitulo">Zona</h3>
                    <button type="button" class="mapa-hoja-cerrar" data-accion="cerrar-hoja" aria-label="Cerrar">
                        <i class="fas fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <fieldset class="mapa-campo">
                    <legend>¿Qué sientes?</legend>
                    <div class="mapa-chips" id="hojaSensaciones"></div>
                </fieldset>

                <fieldset class="mapa-campo">
                    <legend>¿Desde cuándo?</legend>
                    <div class="mapa-chips" id="hojaDesde">
                        <label><input type="radio" name="desde" value="0"><span>Hoy</span></label>
                        <label><input type="radio" name="desde" value="1"><span>Ayer</span></label>
                        <label><input type="radio" name="desde" value="3"><span>Hace unos días</span></label>
                        <label><input type="radio" name="desde" value="7"><span>Hace una semana</span></label>
                        <label><input type="radio" name="desde" value="30"><span>Hace un mes</span></label>
                        <label><input type="radio" name="desde" value="fecha"><span>Otra fecha</span></label>
                    </div>
                    <label class="mapa-fecha" id="hojaFechaCampo" hidden>
                        <span class="sr-only">Fecha en que empezó</span>
                        <input type="date" id="hojaFecha">
                    </label>
                </fieldset>

                <fieldset class="mapa-campo">
                    <legend>¿Qué tan fuerte es? <small>1 es muy leve y 10 es lo más fuerte</small></legend>
                    <div class="mapa-intensidad" id="hojaIntensidad"></div>
                    <div class="mapa-intensidad-guia" aria-hidden="true">
                        <span>Leve</span><span>Moderada</span><span>Muy fuerte</span>
                    </div>
                </fieldset>

                <label class="mapa-campo mapa-detalle-campo">
                    <span>¿Algo más? <small>(opcional)</small></span>
                    <input type="text" id="hojaDetalle" maxlength="200" placeholder="Ej. Empeora al caminar">
                </label>

                <footer class="mapa-hoja-acciones">
                    <p class="mapa-error" id="hojaError" role="alert" hidden></p>
                    <button type="submit" class="btn-primary btn-grande" id="hojaGuardar">Guardar en el mapa</button>
                    <button type="button" class="btn-quitar" id="hojaQuitar" hidden>Quitar del mapa</button>
                </footer>
            </form>
        </dialog>

    </div>

    <script type="application/json" id="mapaDatos"><?= json_encode($datosMapa, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script src="assets/js/mapa-corporal.js?v=1" defer></script>

</body>

</html>
