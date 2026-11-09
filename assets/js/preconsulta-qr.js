/* =====================================================
   MEDICORE: PRE-CONSULTA POR QR (expediente.php)
   Pide a api/preconsulta.php el enlace del paciente y dibuja el QR con assets/js/qrcode.js
   (sin internet externo: la librería está en el proyecto).
   El paciente lo escanea, contesta en preconsulta.php y el resumen aparece en el expediente.
===================================================== */

(function () {
  'use strict';

  const boton = document.querySelector('[data-preconsulta="generar"]');
  const dialogo = document.getElementById('preDialogo');

  if (!boton || !dialogo) {
    return;
  }

  const cajaQR = document.getElementById('preQR');
  const enlace = document.getElementById('preEnlace');
  const vence = document.getElementById('preVence');
  const error = document.getElementById('preDialogoError');
  const whatsapp = document.getElementById('preWhatsapp');
  const copiar = dialogo.querySelector('[data-preconsulta="copiar"]');
  let url = '';

  function dibujarQR(texto) {
    if (typeof window.qrcode !== 'function') {
      cajaQR.innerHTML = '<span class="pre-qr-cargando">No se pudo dibujar el QR. Usa el enlace.</span>';
      return;
    }
    const qr = window.qrcode(0, 'M');
    qr.addData(texto);
    qr.make();
    // SVG: se ve nítido en cualquier pantalla y al proyectarlo
    cajaQR.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
    const svg = cajaQR.querySelector('svg');
    if (svg) {
      svg.setAttribute('aria-hidden', 'true');
      svg.style.width = '100%';
      svg.style.height = 'auto';
    }
  }

  function mostrarError(mensaje) {
    error.textContent = mensaje;
    error.hidden = false;
    cajaQR.innerHTML = '<span class="pre-qr-cargando">Sin código</span>';
  }

  boton.addEventListener('click', function () {
    error.hidden = true;
    enlace.textContent = '';
    vence.textContent = '';
    cajaQR.innerHTML = '<span class="pre-qr-cargando">Generando…</span>';
    dialogo.showModal();

    fetch('api/preconsulta.php?accion=crear', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': boton.dataset.csrf },
      body: JSON.stringify({ paciente_id: parseInt(boton.dataset.paciente, 10) })
    })
      .then(function (r) {
        return r.json().catch(function () {
          return { ok: false, mensaje: 'Respuesta inválida del servidor.' };
        });
      })
      .then(function (res) {
        if (!res.ok) {
          throw new Error(res.mensaje || 'No se pudo generar el código.');
        }
        // Misma carpeta que el expediente: sirve igual en XAMPP y en Railway
        url = new URL(res.ruta, window.location.href).href;
        dibujarQR(url);
        enlace.textContent = url;
        vence.textContent = 'Sirve una vez. Vence el ' + res.expira_texto + '.';
        const mensaje = 'Hola ' + res.paciente + ', antes de tu cita llena tu pre-consulta de MediCore aquí: ' + url;
        whatsapp.href = 'https://wa.me/?text=' + encodeURIComponent(mensaje);
      })
      .catch(function (err) {
        mostrarError(navigator.onLine === false ? 'No hay conexión a internet.' : err.message);
      });
  });

  copiar.addEventListener('click', function () {
    if (!url) {
      return;
    }
    const etiqueta = copiar.querySelector('span');
    const listo = function () {
      etiqueta.textContent = 'Enlace copiado';
      setTimeout(function () {
        etiqueta.textContent = 'Copiar enlace';
      }, 2000);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(url).then(listo).catch(function () {
        window.getSelection().selectAllChildren(enlace);
      });
    } else {
      window.getSelection().selectAllChildren(enlace);
    }
  });

  dialogo.addEventListener('click', function (e) {
    if (e.target === dialogo || e.target.closest('[data-preconsulta="cerrar"]')) {
      dialogo.close();
    }
  });

  // Si era un enlace nuevo, al cerrar se recarga para que la tarjeta diga "Esperando al paciente"
  dialogo.addEventListener('close', function () {
    if (url && boton.dataset.estado !== 'valida' && !boton.dataset.recargado) {
      boton.dataset.recargado = '1';
      window.location.hash = 'preconsulta';
      window.location.reload();
    }
  });
})();
