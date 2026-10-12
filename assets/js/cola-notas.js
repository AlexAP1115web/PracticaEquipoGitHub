// Cola de notas sin conexión (IndexedDB)
// Si no hay internet la nota se guarda aquí y se manda después: con Background Sync
// desde sw.js o, si el navegador no lo tiene, desde pwa.js cuando vuelve la red.
// Lo cargan las páginas y sw.js con importScripts

(function (global) {
  'use strict';

  const NOMBRE_BD = 'medicore-pwa';
  const VERSION_BD = 1;
  const ALMACEN = 'notas_pendientes';

  function abrir() {
    return new Promise(function (resolver, rechazar) {
      const peticion = indexedDB.open(NOMBRE_BD, VERSION_BD);

      peticion.onupgradeneeded = function () {
        const bd = peticion.result;
        if (!bd.objectStoreNames.contains(ALMACEN)) {
          bd.createObjectStore(ALMACEN, { keyPath: 'id_cliente' });
        }
      };

      peticion.onsuccess = function () { resolver(peticion.result); };
      peticion.onerror = function () { rechazar(peticion.error); };
    });
  }

  // Resuelve hasta el oncomplete de la transacción, no solo de la petición
  function operar(modo, accion) {
    return abrir().then(function (bd) {
      return new Promise(function (resolver, rechazar) {
        const transaccion = bd.transaction(ALMACEN, modo);
        const peticion = accion(transaccion.objectStore(ALMACEN));

        transaccion.oncomplete = function () {
          bd.close();
          resolver(peticion ? peticion.result : undefined);
        };
        transaccion.onerror = function () {
          bd.close();
          rechazar(transaccion.error);
        };
      });
    });
  }

  function guardar(nota) {
    return operar('readwrite', function (almacen) { return almacen.put(nota); });
  }

  function listar() {
    return operar('readonly', function (almacen) { return almacen.getAll(); })
      .then(function (notas) { return notas || []; });
  }

  function borrar(idCliente) {
    return operar('readwrite', function (almacen) { return almacen.delete(idCliente); });
  }

  // opciones: { base, csrf }. Sin csrf usa el que se guardó con la nota (así lo llama el sw)
  function enviarPendientes(opciones) {
    opciones = opciones || {};
    const url = new URL('api/notas.php?accion=guardar', opciones.base || global.location.href).href;

    return listar().then(function (notas) {
      const resultado = { enviadas: 0, pendientes: 0, sinRed: false };

      return notas.reduce(function (cadena, nota) {
        return cadena.then(function () {
          if (resultado.sinRed) {
            resultado.pendientes++;
            return;
          }

          const cuerpo = Object.assign({}, nota);
          delete cuerpo.csrf;
          delete cuerpo.error;

          return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': opciones.csrf || nota.csrf || ''
            },
            body: JSON.stringify(cuerpo)
          }).then(function (respuesta) {
            if (respuesta.ok) {
              resultado.enviadas++;
              return borrar(nota.id_cliente);
            }

            // 401/403 (sesión vencida o token viejo): se queda para el siguiente intento
            resultado.pendientes++;
            nota.error = respuesta.status;
            return guardar(nota);
          }).catch(function () {
            resultado.sinRed = true;
            resultado.pendientes++;
          });
        });
      }, Promise.resolve()).then(function () {
        return resultado;
      });
    });
  }

  global.MediCoreCola = {
    guardar: guardar,
    listar: listar,
    borrar: borrar,
    enviarPendientes: enviarPendientes
  };
})(self);
