// Módulo "Sin conexión" (cache.php): guarda páginas de consulta y muestra lo que hay en la caché.
// Usa la Cache API desde la página con window.caches; sw.js lee las mismas cachés

(function () {
  const CONSULTA = 'medicore-consulta'; // mismo nombre que en sw.js
  const base = new URL('./', location.href);

  const paginas = Array.from(document.querySelectorAll('.cache-pagina'));
  const btnGuardar = document.getElementById('btnGuardarPaginas');
  const btnActualizar = document.getElementById('btnActualizarPaginas');
  const mensaje = document.getElementById('mensajePaginas');
  const selectCache = document.getElementById('selectCache');
  const btnVaciar = document.getElementById('btnVaciarCache');
  const notaCache = document.getElementById('notaCache');
  const tabla = document.getElementById('tablaCache');

  if (!('caches' in window)) {
    document.getElementById('cacheNoDisponible').hidden = false;
    btnGuardar.disabled = true;
    btnActualizar.disabled = true;
    tabla.innerHTML = '<tr><td colspan="5" class="cache-vacio">Sin Cache API en este navegador.</td></tr>';
    return;
  }

  function absoluta(ruta) {
    return new URL(ruta, base).href;
  }

  function rutaCorta(url) {
    const u = new URL(url);
    return u.origin === location.origin ? u.pathname.replace(base.pathname, '') || '/' : u.hostname + u.pathname;
  }

  function tamano(bytes) {
    if (bytes === null || bytes === undefined) return '—';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1024 / 1024).toFixed(1) + ' MB';
  }

  function fecha(respuesta) {
    const texto = respuesta.headers.get('date');
    if (!texto) return '—';
    const f = new Date(texto);
    return f.toLocaleDateString('es-MX', { day: '2-digit', month: '2-digit' }) + ' ' +
      f.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
  }

  function tipo(url, respuesta) {
    const ct = (respuesta.headers.get('content-type') || '').toLowerCase();
    const ruta = new URL(url).pathname.toLowerCase();
    if (ct.includes('html') || ruta.endsWith('.php') || ruta.endsWith('.html')) return 'Página';
    if (ct.includes('css') || ruta.endsWith('.css') || ruta.includes('/css')) return 'Estilos';
    if (ct.includes('javascript') || ruta.endsWith('.js')) return 'Script';
    if (ct.includes('font') || /\.(woff2?|ttf)$/.test(ruta)) return 'Fuente';
    if (ct.includes('image') || /\.(png|jpe?g|gif|svg|webp|ico)$/.test(ruta)) return 'Imagen';
    if (ruta.endsWith('.json')) return 'Manifest';
    return 'Otro';
  }

  // Las respuestas de otros dominios (opacas) no dejan leer su tamaño
  async function peso(respuesta) {
    if (respuesta.type === 'opaque') return null;
    const largo = respuesta.headers.get('content-length');
    if (largo) return parseInt(largo, 10);
    const blob = await respuesta.clone().blob();
    return blob.size;
  }

  function avisar(texto, error) {
    mensaje.textContent = texto;
    mensaje.classList.toggle('error', !!error);
  }

  /* ---------- Páginas de consulta ---------- */

  async function revisarPaginas() {
    const hayConsulta = await caches.has(CONSULTA);

    for (const li of paginas) {
      // caches.match busca solo en la caché de consulta gracias a cacheName
      const guardada = hayConsulta ? await caches.match(absoluta(li.dataset.url), { cacheName: CONSULTA }) : undefined;
      const estado = li.querySelector('.cache-estado');

      estado.className = 'badge cache-estado ' + (guardada ? 'success' : 'warning');
      estado.textContent = guardada ? 'Guardada ' + fecha(guardada) : 'No guardada';
      li.querySelector('.cache-quitar').hidden = !guardada;
      li.classList.toggle('guardada', !!guardada);
    }
  }

  // fetch + put en lugar de add(): así se revisa que no sea la página de login por sesión vencida
  async function guardarPagina(cache, li) {
    const url = absoluta(li.dataset.url);
    const respuesta = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });

    if (!respuesta.ok || respuesta.redirected) {
      throw new Error('sesion');
    }

    await cache.put(url, respuesta);

    // Imágenes que usa la página (si una falla, la página igual queda guardada)
    const recursos = JSON.parse(li.dataset.recursos || '[]');
    for (const recurso of recursos) {
      try {
        await cache.add(absoluta(recurso));
      } catch (e) {
        console.warn('No se pudo guardar', recurso, e);
      }
    }
  }

  async function guardar(lista, textoListo) {
    if (lista.length === 0) {
      avisar('Selecciona al menos una página.', true);
      return;
    }
    if (!navigator.onLine) {
      avisar('Necesitas internet para guardar o actualizar las páginas.', true);
      return;
    }

    btnGuardar.disabled = true;
    btnActualizar.disabled = true;
    avisar('Guardando…');

    try {
      const cache = await caches.open(CONSULTA);
      for (const li of lista) {
        await guardarPagina(cache, li);
      }
      avisar(textoListo.replace('{n}', lista.length));
      selectCache.dataset.preferir = CONSULTA;
    } catch (error) {
      avisar(error.message === 'sesion'
        ? 'Tu sesión venció. Vuelve a iniciar sesión e inténtalo de nuevo.'
        : 'No se pudieron guardar las páginas. Revisa tu conexión.', true);
    }

    btnGuardar.disabled = false;
    btnActualizar.disabled = false;
    await refrescarTodo();
  }

  btnGuardar.addEventListener('click', function () {
    const elegidas = paginas.filter(function (li) { return li.querySelector('input').checked; });
    guardar(elegidas, elegidas.length === 1 ? 'Se guardó 1 página.' : 'Se guardaron {n} páginas.');
  });

  btnActualizar.addEventListener('click', function () {
    const guardadas = paginas.filter(function (li) { return li.classList.contains('guardada'); });
    if (guardadas.length === 0) {
      avisar('Todavía no hay páginas guardadas.', true);
      return;
    }
    guardar(guardadas, 'Páginas actualizadas.');
  });

  paginas.forEach(function (li) {
    li.querySelector('.cache-quitar').addEventListener('click', async function () {
      const cache = await caches.open(CONSULTA);
      await cache.delete(absoluta(li.dataset.url));
      for (const recurso of JSON.parse(li.dataset.recursos || '[]')) {
        await cache.delete(absoluta(recurso));
      }
      avisar('Se quitó la página de este dispositivo.');
      await refrescarTodo();
    });
  });

  /* ---------- Contenido de la caché ---------- */

  function nombreLegible(nombre) {
    if (nombre === CONSULTA) return 'Páginas guardadas (' + nombre + ')';
    if (nombre.indexOf('medicore-admin') === 0) return 'Archivos de la app (' + nombre + ')';
    return nombre;
  }

  async function llenarSelect() {
    const nombres = await caches.keys();
    // Después de guardar se muestra directo la caché de consulta
    const anterior = selectCache.dataset.preferir || selectCache.value;
    delete selectCache.dataset.preferir;

    selectCache.innerHTML = '';
    nombres.forEach(function (nombre) {
      const opcion = document.createElement('option');
      opcion.value = nombre;
      opcion.textContent = nombreLegible(nombre);
      selectCache.appendChild(opcion);
    });

    if (nombres.indexOf(anterior) !== -1) {
      selectCache.value = anterior;
    } else if (nombres.indexOf(CONSULTA) !== -1) {
      selectCache.value = CONSULTA;
    }

    return nombres;
  }

  async function pintarTabla() {
    const nombre = selectCache.value;
    tabla.innerHTML = '';

    if (!nombre) {
      notaCache.textContent = '';
      btnVaciar.hidden = true;
      tabla.innerHTML = '<tr><td colspan="5" class="cache-vacio">Todavía no hay cachés. Recarga la página para que el service worker guarde los archivos de la app.</td></tr>';
      return;
    }

    const esConsulta = nombre === CONSULTA;
    btnVaciar.hidden = !esConsulta;
    notaCache.textContent = esConsulta
      ? 'Páginas e imágenes que guardaste en este dispositivo.'
      : 'La administra el service worker: se llena sola y se borra cuando sale una versión nueva de MediCore.';

    const cache = await caches.open(nombre);
    const peticiones = await cache.keys();

    if (peticiones.length === 0) {
      tabla.innerHTML = '<tr><td colspan="5" class="cache-vacio">Esta caché está vacía.</td></tr>';
      return;
    }

    peticiones.sort(function (a, b) { return rutaCorta(a.url).localeCompare(rutaCorta(b.url)); });

    for (const peticion of peticiones) {
      const respuesta = await cache.match(peticion);
      if (!respuesta) continue;

      const fila = document.createElement('tr');
      const celdas = [
        rutaCorta(peticion.url),
        tipo(peticion.url, respuesta) + (respuesta.type === 'opaque' ? ' (externo)' : ''),
        tamano(await peso(respuesta)),
        fecha(respuesta)
      ];

      celdas.forEach(function (texto, i) {
        const td = document.createElement('td');
        td.textContent = texto;
        if (i === 0) td.className = 'cache-ruta';
        fila.appendChild(td);
      });

      const accion = document.createElement('td');
      if (esConsulta) {
        const quitar = document.createElement('button');
        quitar.type = 'button';
        quitar.className = 'btn-view';
        quitar.textContent = 'Quitar';
        quitar.addEventListener('click', async function () {
          await cache.delete(peticion);
          await refrescarTodo();
        });
        accion.appendChild(quitar);
      } else {
        accion.textContent = '—';
      }
      fila.appendChild(accion);
      tabla.appendChild(fila);
    }
  }

  btnVaciar.addEventListener('click', async function () {
    await caches.delete(CONSULTA);
    avisar('Se borraron las páginas guardadas.');
    await refrescarTodo();
  });

  selectCache.addEventListener('change', pintarTabla);

  /* ---------- Resumen ---------- */

  async function resumen(nombres) {
    let archivos = 0;
    for (const nombre of nombres) {
      const cache = await caches.open(nombre);
      archivos += (await cache.keys()).length;
    }

    document.getElementById('totalCaches').textContent = nombres.length;
    document.getElementById('totalArchivos').textContent = archivos;

    if (navigator.storage && navigator.storage.estimate) {
      const estimado = await navigator.storage.estimate();
      document.getElementById('espacioUsado').textContent = tamano(estimado.usage || 0);
    }
  }

  function estadoRed() {
    const enLinea = navigator.onLine;
    document.getElementById('estadoRed').textContent = enLinea ? 'En línea' : 'Sin conexión';
    const icono = document.getElementById('iconoRed');
    icono.className = 'stat-icon ' + (enLinea ? 'icon-success' : 'icon-warning');
    icono.innerHTML = enLinea ? '<i class="fas fa-wifi"></i>' : '<i class="fas fa-plane"></i>';
  }

  async function refrescarTodo() {
    const nombres = await llenarSelect();
    await Promise.all([revisarPaginas(), pintarTabla(), resumen(nombres)]);
  }

  window.addEventListener('online', estadoRed);
  window.addEventListener('offline', estadoRed);

  estadoRed();
  refrescarTodo();

  // El service worker llena su caché al instalarse; se vuelve a pintar cuando toma el control
  if (navigator.serviceWorker) {
    navigator.serviceWorker.addEventListener('controllerchange', function () {
      setTimeout(refrescarTodo, 500);
    });
  }
})();
