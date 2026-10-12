// Notificaciones push (panel de configuracion.php)
// Activar/desactivar en este dispositivo, mandar prueba y recordatorios de citas

(function () {
  const caja = document.getElementById('push-panel');
  if (!caja) return;

  const llavePublica = caja.getAttribute('data-vapid');
  const csrf = caja.getAttribute('data-csrf');
  const estado = document.getElementById('push-estado');
  const btnActivar = document.getElementById('push-activar');
  const btnDesactivar = document.getElementById('push-desactivar');
  const btnPrueba = document.getElementById('push-prueba');
  const btnRecordatorios = document.getElementById('push-recordatorios');

  function mostrar(texto, tipo) {
    estado.textContent = texto;
    estado.className = 'push-estado ' + (tipo || '');
  }

  function llaveABytes(base64) {
    const relleno = '='.repeat((4 - base64.length % 4) % 4);
    const crudo = atob((base64 + relleno).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(crudo, function (c) { return c.charCodeAt(0); });
  }

  async function llamar(accion, cuerpo) {
    const r = await fetch('api/push.php?accion=' + accion, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(cuerpo || {})
    });
    try {
      return await r.json();
    } catch (e) {
      return { ok: false, mensaje: 'Tu sesión terminó. Recarga la página.' };
    }
  }

  async function actualizarBotones() {
    const registro = await navigator.serviceWorker.ready;
    const suscripcion = await registro.pushManager.getSubscription();
    const activa = !!suscripcion && Notification.permission === 'granted';

    // Con hidden no se ocultan porque la clase del botón ya trae display
    btnActivar.style.display = activa ? 'none' : '';
    btnDesactivar.style.display = activa ? '' : 'none';
    btnPrueba.disabled = !activa;

    if (Notification.permission === 'denied') {
      mostrar('Bloqueaste las notificaciones para este sitio. Actívalas desde el candado de la barra de direcciones.', 'error');
    } else if (activa) {
      mostrar('Notificaciones activas en este dispositivo.', 'ok');
    } else {
      mostrar('Las notificaciones están desactivadas en este dispositivo.', '');
    }
  }

  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
    mostrar('Este navegador no soporta notificaciones push. En iPhone, primero instala MediCore en la pantalla de inicio.', 'error');
    btnActivar.disabled = true;
    btnPrueba.disabled = true;
    return;
  }

  if (!llavePublica) {
    mostrar('El servidor todavía no tiene configuradas las llaves VAPID.', 'error');
    btnActivar.disabled = true;
    btnPrueba.disabled = true;
    btnRecordatorios.disabled = true;
    return;
  }

  btnActivar.addEventListener('click', async function () {
    try {
      const permiso = await Notification.requestPermission();
      if (permiso !== 'granted') {
        mostrar('No se dio permiso para mostrar notificaciones.', 'error');
        return;
      }

      const registro = await navigator.serviceWorker.ready;
      const suscripcion = await registro.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: llaveABytes(llavePublica)
      });

      const r = await llamar('suscribir', suscripcion.toJSON());
      mostrar(r.mensaje, r.ok ? 'ok' : 'error');
    } catch (e) {
      mostrar('No se pudo activar: ' + e.message, 'error');
    }
    actualizarBotones();
  });

  btnDesactivar.addEventListener('click', async function () {
    const registro = await navigator.serviceWorker.ready;
    const suscripcion = await registro.pushManager.getSubscription();
    if (suscripcion) {
      await llamar('cancelar', { endpoint: suscripcion.endpoint });
      await suscripcion.unsubscribe();
    }
    actualizarBotones();
  });

  btnPrueba.addEventListener('click', async function () {
    mostrar('Enviando…', '');
    const r = await llamar('prueba');
    mostrar(r.mensaje, r.ok ? 'ok' : 'error');
  });

  btnRecordatorios.addEventListener('click', async function () {
    mostrar('Revisando citas de hoy y mañana…', '');
    const r = await llamar('recordatorios');
    mostrar(r.mensaje, r.ok ? 'ok' : 'error');
  });

  actualizarBotones();
})();
