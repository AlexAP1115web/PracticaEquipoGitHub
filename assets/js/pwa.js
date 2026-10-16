// PWA: registra sw.js, pone las citas de hoy en el ícono (Badging API) y manda
// las notas pendientes al volver el internet si no hay Background Sync.
// La ruta de sw.js va relativa para que jale igual en XAMPP (subcarpeta) y en Railway

(function () {
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js')
        .then(function (registro) {
          console.log('Service Worker registrado. Alcance:', registro.scope);
        })
        .catch(function (error) {
          console.log('No se pudo registrar el Service Worker:', error);
        });
    });

    // sw.js avisa cuando termina de mandar las notas pendientes
    navigator.serviceWorker.addEventListener('message', function (evento) {
      if (evento.data && evento.data.tipo === 'notas-enviadas') {
        notasEnviadas(evento.data.enviadas);
      }
    });
  }

  // #datos-pwa solo está en las páginas con sesión (lo pone sidebar.php)
  function datosPanel() {
    return document.getElementById('datos-pwa');
  }

  /* ---------- Número sobre el ícono ---------- */

  function actualizarInsignia() {
    if (!('setAppBadge' in navigator)) return;

    const datos = datosPanel();

    if (!datos) {
      // En login/portal se quita el número
      if (/login\.php|home\.php|logout\.php/.test(location.pathname)) {
        navigator.clearAppBadge().catch(function () {});
      }
      return;
    }

    const citas = parseInt(datos.getAttribute('data-citas-hoy'), 10) || 0;
    (citas > 0 ? navigator.setAppBadge(citas) : navigator.clearAppBadge()).catch(function () {});
  }

  /* ---------- Notas guardadas sin conexión ---------- */

  function mostrarAviso(texto, icono) {
    const anterior = document.querySelector('.aviso-flotante');
    if (anterior) anterior.remove();

    const aviso = document.createElement('div');
    aviso.className = 'aviso-flotante';
    aviso.setAttribute('role', 'status');
    aviso.innerHTML = '<i class="fas ' + (icono || 'fa-cloud-arrow-up') + '" aria-hidden="true"></i><span></span>';
    aviso.querySelector('span').textContent = texto;
    document.body.appendChild(aviso);

    setTimeout(function () { aviso.remove(); }, 6000);
  }

  function notasEnviadas(cantidad) {
    if (cantidad > 0) {
      mostrarAviso(cantidad === 1
        ? 'Se envió la nota que guardaste sin conexión.'
        : 'Se enviaron ' + cantidad + ' notas que guardaste sin conexión.');
    }
    window.dispatchEvent(new CustomEvent('medicore:notas-enviadas', { detail: { enviadas: cantidad } }));
  }

  let enviando = false;

  function enviarNotasPendientes() {
    const datos = datosPanel();
    if (!datos || !window.MediCoreCola || !navigator.onLine || enviando) return;

    enviando = true;

    window.MediCoreCola.enviarPendientes({
      base: new URL('./', location.href).href,
      csrf: datos.getAttribute('data-csrf')
    }).then(function (resultado) {
      if (resultado.enviadas > 0) notasEnviadas(resultado.enviadas);
    }).catch(function () {
      // Sin IndexedDB no hay nada que mandar
    }).then(function () {
      enviando = false;
    });
  }

  /* ---------- Páginas guardadas (cache.php) ---------- */

  // Al cerrar sesión se borran las páginas de consulta guardadas en este dispositivo
  function limpiarPaginasGuardadas() {
    if ('caches' in window && /login\.php/.test(location.pathname) && /logout/.test(location.search)) {
      caches.delete('medicore-consulta').catch(function () {});
    }
  }

  // Sin internet, una página con panel solo pudo venir de la caché
  function avisarCopiaGuardada() {
    if (!navigator.onLine && datosPanel()) {
      mostrarAviso('Sin conexión: estás viendo la copia guardada de esta página.', 'fa-plane');
    }
  }

  window.addEventListener('online', enviarNotasPendientes);

  document.addEventListener('DOMContentLoaded', function () {
    actualizarInsignia();
    enviarNotasPendientes();
    limpiarPaginasGuardadas();
    avisarCopiaGuardada();
  });
})();
