// Cola de notas sin conexión - MediCore
//
// Cuando el médico guarda una nota de consulta y no hay internet, la nota
// se guarda en IndexedDB (en este dispositivo) y se envía sola cuando
// vuelve la conexión:
//   - con Background Sync, el service worker la manda aunque la página
//     ya esté cerrada (Chrome, Edge y Android);
//   - en navegadores sin Background Sync, la página la manda en cuanto
//     detecta que volvió el internet.
// En cuanto el servidor confirma que la guardó, se borra del dispositivo.
//
// Este archivo lo usan las páginas y también sw.js (importScripts).

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

  // Ejecuta una operación sobre el almacén y espera a que termine la transacción
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

  /**
   * Manda al servidor todas las notas pendientes.
   * opciones.base: URL de la carpeta de la app (donde está api/).
   * opciones.csrf: token actual de la página (si no, se usa el que se
   *                guardó con la nota; sirve para el service worker).
   * Regresa { enviadas, pendientes, sinRed }.
   */
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

            // 401/403: sesión vencida o token viejo. Se queda guardada y se
            // vuelve a intentar cuando el médico abra MediCore otra vez.
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
