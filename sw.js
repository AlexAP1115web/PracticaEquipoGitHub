// sw.js - Service Worker de MediCore
// Las paginas de pacientes NUNCA se cachean, solo estaticos, offline.html y las paginas de
// consulta que el medico guarda en cache.php (catalogo, material educativo, aviso de privacidad).
// Tambien manda las notas guardadas sin internet con Background Sync (seccion 6)

importScripts('assets/js/cola-notas.js');

const VERSION = 'medicore-admin-v9';

// Cache de paginas de consulta que llena cache.php (no cambia con la version)
const CONSULTA = 'medicore-consulta';

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

// 2. ACTIVATE: borra caches de versiones anteriores (la de consulta se queda)
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((nombres) =>
      Promise.all(
        nombres
          .filter((nombre) => nombre !== VERSION && nombre !== CONSULTA)
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

  // Paginas: primero la red; sin internet la copia guardada o offline.html
  if (peticion.mode === 'navigate') {
    event.respondWith(paginaDesdeRed(peticion));
    return;
  }

  const esPropio = url.origin === self.location.origin;
  const esExterno = EXTERNOS.includes(url.origin);

  // Uploads (fotos de perfil) y cualquier .php no se guardan
  if (esPropio && (url.pathname.includes('/uploads/') || url.pathname.endsWith('.php'))) return;

  // Estaticos propios y librerias externas: stale-while-revalidate
  if ((esPropio && ES_ESTATICO.test(url.pathname)) || esExterno) {
    // style.css?v=<hora> se guarda sin el ?v= para no llenar el cache con una copia por recarga
    const clave = esPropio ? url.origin + url.pathname : peticion;

    event.respondWith(
      caches.open(VERSION).then((cache) =>
        // Si no esta en la cache de la app puede estar en la de consulta (imagenes de paginas guardadas)
        cache.match(clave).then((guardada) => guardada || caches.match(clave)).then((guardada) => {
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

// Network first. Si el medico guardo esta pagina en cache.php se actualiza la copia;
// si no la guardo, nunca se escribe en la cache (asi no quedan datos de pacientes)
async function paginaDesdeRed(peticion) {
  const clave = peticion.url.split('#')[0].split('?')[0];

  try {
    const respuesta = await fetch(peticion);

    // caches.match con cacheName no crea la cache si no existe (caches.open si)
    if (respuesta.ok && await caches.match(clave, { cacheName: CONSULTA })) {
      const cache = await caches.open(CONSULTA);
      await cache.put(clave, respuesta.clone());
    }

    return respuesta;
  } catch (error) {
    return (await caches.match(clave, { cacheName: CONSULTA })) || (await caches.match(OFFLINE));
  }
}

// 4. PUSH: el aviso llega vacio, los mensajes se piden a api/push.php?accion=pendientes
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

// 6. BACKGROUND SYNC: manda las notas guardadas sin conexion aunque MediCore este cerrado.
// Si la red falla otra vez se lanza error para que el navegador reintente
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
