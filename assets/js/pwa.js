// PWA de MediCore
// 1) Registra el Service Worker. La ruta es relativa para que funcione igual
//    en XAMPP (localhost/MediCore_medicoAdmin/) y en Railway (raíz del dominio).
// 2) Pone el número de citas de hoy sobre el ícono de la app instalada
//    (Badging API).
// 3) Manda las notas de consulta que se guardaron sin conexión cuando
//    vuelve el internet (respaldo para navegadores sin Background Sync).

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

    // El service worker avisa cuando terminó de mandar notas pendientes
    navigator.serviceWorker.addEventListener('message', function (evento) {
      if (evento.data && evento.data.tipo === 'notas-enviadas') {
        notasEnviadas(evento.data.enviadas);
      }
    });
  }

  // Solo en las páginas del panel (con sesión) existe este elemento
  function datosPanel() {
    return document.getElementById('datos-pwa');
  }

  /* ---------- Número sobre el ícono ---------- */

  function actualizarInsignia() {
    if (!('setAppBadge' in navigator)) return;

    const datos = datosPanel();

    if (!datos) {
      // Pantallas sin sesión (login, portal): se quita el número
      if (/login\.php|home\.php|logout\.php/.test(location.pathname)) {
        navigator.clearAppBadge().catch(function () {});
      }
      return;
    }

    const citas = parseInt(datos.getAttribute('data-citas-hoy'), 10) || 0;
    (citas > 0 ? navigator.setAppBadge(citas) : navigator.clearAppBadge()).catch(function () {});
  }

  /* ---------- Notas guardadas sin conexión ---------- */

  function mostrarAviso(texto) {
    const anterior = document.querySelector('.aviso-flotante');
    if (anterior) anterior.remove();

    const aviso = document.createElement('div');
    aviso.className = 'aviso-flotante';
    aviso.setAttribute('role', 'status');
    aviso.innerHTML = '<i class="fas fa-cloud-arrow-up" aria-hidden="true"></i><span></span>';
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
      // IndexedDB no disponible: no hay nada que mandar
    }).then(function () {
      enviando = false;
    });
  }

  window.addEventListener('online', enviarNotasPendientes);

  document.addEventListener('DOMContentLoaded', function () {
    actualizarInsignia();
    enviarNotasPendientes();
  });
})();
