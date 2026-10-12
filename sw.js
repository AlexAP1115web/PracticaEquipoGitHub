// sw.js - Service Worker de MediCore (sistema medico)
//
// Importante: este sistema maneja datos de pacientes, por eso las paginas
// PHP NUNCA se guardan en cache. Solo se guardan archivos estaticos
// (estilos, logo, iconos) y una pagina "sin conexion" para mostrar
// cuando no hay internet.
//
// Las notas de consulta que se guardan sin internet esperan en IndexedDB
// (assets/js/cola-notas.js) y este service worker las manda con
// Background Sync en cuanto vuelve la conexion (seccion 6).

importScripts('assets/js/cola-notas.js');

const VERSION = 'medicore-admin-v4';

// Rutas relativas a la carpeta donde esta sw.js
const BASE = self.registration.scope;
const OFFLINE = new URL('offline.html', BASE).href;

const ARCHIVOS_APP = [
  'offline.html',
  'manifest.json',
  'assets/style.css',
  'assets/js/cola-notas.js',
  'assets/icons/icon-192.png',
  'assets/icons/icon-512.png'
].map((ruta) => new URL(ruta, BASE).href);

// Librerias externas (iconos Font Awesome y fuentes de Google)
const EXTERNOS = [
  'https://cdnjs.cloudflare.com',
  'https://cdn.jsdelivr.net',
  'https://fonts.googleapis.com',
  'https://fonts.gstatic.com'
];

const ES_ESTATICO = /\.(css|js|png|jpg|jpeg|gif|svg|webp|ico|woff2?|ttf)$/i;

// 1. INSTALL: guarda los archivos basicos
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(VERSION)
      .then((cache) => cache.addAll(ARCHIVOS_APP))
      .then(() => self.skipWaiting())
  );
});

// 2. ACTIVATE: borra caches de versiones anteriores
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((nombres) =>
      Promise.all(
        nombres
          .filter((nombre) => nombre !== VERSION)
          .map((nombre) => caches.delete(nombre))
      )
    ).then(() => self.clients.claim())
  );
});

// 3. FETCH
self.addEventListener('fetch', (event) => {
  const peticion = event.request;

  // Formularios (POST), login, guardar expediente, etc. van directo al servidor
  if (peticion.method !== 'GET') return;

  const url = new URL(peticion.url);

  // Paginas (PHP): siempre desde el servidor. Si no hay internet,
  // se muestra offline.html en lugar del error del navegador.
  if (peticion.mode === 'navigate') {
    event.respondWith(
      fetch(peticion).catch(() => caches.match(OFFLINE))
    );
    return;
  }

  const esPropio = url.origin === self.location.origin;
  const esExterno = EXTERNOS.includes(url.origin);

  // Uploads (fotos de perfil) y cualquier .php no se guardan
  if (esPropio && (url.pathname.includes('/uploads/') || url.pathname.endsWith('.php'))) return;

  // Estaticos propios y librerias externas: stale-while-revalidate
  if ((esPropio && ES_ESTATICO.test(url.pathname)) || esExterno) {
    // Las paginas piden style.css?v=<hora> para evitar el cache del
    // navegador; aqui se guarda sin el ?v= para no llenar el cache
    // con una copia nueva en cada recarga.
    const clave = esPropio ? url.origin + url.pathname : peticion;

    event.respondWith(
      caches.open(VERSION).then((cache) =>
        cache.match(clave).then((guardada) => {
          const desdeRed = fetch(peticion)
            .then((respuesta) => {
              if (respuesta.ok || respuesta.type === 'opaque') {
                cache.put(clave, respuesta.clone());
              }
              return respuesta;
            })
            .catch(() => guardada);
          return guardada || desdeRed;
        })
      )
    );
  }
});

// 4. PUSH: el servidor manda un aviso sin contenido; el service worker
// pide a api/push.php los mensajes pendientes de esta suscripcion y
// los muestra como notificaciones del sistema.
const ICONO = new URL('assets/icons/icon-192.png', BASE).href;

self.addEventListener('push', (event) => {
  event.waitUntil(
    self.registration.pushManager.getSubscription()
      .then((suscripcion) => fetch(new URL('api/push.php?accion=pendientes', BASE).href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ endpoint: suscripcion ? suscripcion.endpoint : '' })
      }))
      .then((respuesta) => respuesta.json())
      .then((datos) => {
        const mensajes = (datos && datos.mensajes) || [];

        if (mensajes.length === 0) {
          return self.registration.showNotification('MediCore', {
            body: 'Tienes avisos nuevos en MediCore.',
            icon: ICONO,
            badge: ICONO
          });
        }

        return Promise.all(mensajes.map((m) =>
          self.registration.showNotification(m.titulo, {
            body: m.cuerpo,
            icon: ICONO,
            badge: ICONO,
            tag: 'medicore-' + m.id,
            data: { url: new URL(m.url || 'index.php', BASE).href }
          })
        ));
      })
      .catch(() => self.registration.showNotification('MediCore', {
        body: 'Tienes avisos nuevos en MediCore.',
        icon: ICONO
      }))
  );
});

// 5. Al tocar la notificacion se abre (o se enfoca) MediCore en esa pagina
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const destino = (event.notification.data && event.notification.data.url) || BASE;

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((ventanas) => {
      for (const ventana of ventanas) {
        if (ventana.url.startsWith(BASE) && 'focus' in ventana) {
          ventana.navigate(destino);
          return ventana.focus();
        }
      }
      return self.clients.openWindow(destino);
    })
  );
});

// 6. BACKGROUND SYNC: manda las notas de consulta guardadas sin conexion.
// El navegador dispara este evento cuando vuelve el internet, aunque
// MediCore ya este cerrado. Si la red vuelve a fallar, se lanza un error
// para que el navegador lo reintente mas tarde.
self.addEventListener('sync', (event) => {
  if (event.tag === 'enviar-notas') {
    event.waitUntil(sincronizarNotas());
  }
});

async function sincronizarNotas() {
  const resultado = await self.MediCoreCola.enviarPendientes({ base: BASE });

  if (resultado.enviadas > 0) {
    const ventanas = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

    ventanas.forEach((ventana) => ventana.postMessage({ tipo: 'notas-enviadas', enviadas: resultado.enviadas }));

    // Si la app esta cerrada, se avisa con una notificacion (si hay permiso)
    if (ventanas.length === 0 && self.Notification && Notification.permission === 'granted') {
      await self.registration.showNotification('Notas enviadas', {
        body: resultado.enviadas === 1
          ? 'Se guardo en el expediente la nota que escribiste sin conexion.'
          : 'Se guardaron en los expedientes ' + resultado.enviadas + ' notas que escribiste sin conexion.',
        icon: ICONO,
        badge: ICONO,
        tag: 'medicore-notas'
      });
    }
  }

  if (resultado.sinRed) {
    throw new Error('Sin conexion: se reintentara despues.');
  }
}
