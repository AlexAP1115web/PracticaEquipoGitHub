<?php
/* ======================================================
   SOMAMAP: HOJA PARA DESCRIBIR EL SÍNTOMA DE UNA ZONA
   La usan mapa_corporal.php (en consulta) y preconsulta.php (desde el celular del paciente).
   assets/js/mapa-corporal.js la llena y la abre al tocar una zona.
====================================================== */

if (!defined('MEDICORE_SOMAMAP')) {
    http_response_code(404);
    exit;
}
?>
        <!-- Hoja para describir el síntoma de una zona -->
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
