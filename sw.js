// sw.js - Service Worker de MediCore (sistema medico)
//
// Importante: este sistema maneja datos de pacientes, por eso las paginas
// PHP NUNCA se guardan en cache. Solo se guardan archivos estaticos
// (estilos, logo, iconos) y una pagina "sin conexion" para mostrar
// cuando no hay internet.

const VERSION = 'medicore-admin-v1';

// Rutas relativas a la carpeta donde esta sw.js
const BASE = self.registration.scope;
const OFFLINE = new URL('offline.html', BASE).href;

const ARCHIVOS_APP = [
  'offline.html',
  'manifest.json',
  'assets/style.css',
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
