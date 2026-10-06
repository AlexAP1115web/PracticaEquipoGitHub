// Registro del Service Worker de MediCore
// La ruta es relativa para que funcione igual en XAMPP
// (localhost/MediCore_medicoAdmin/) y en Railway (raiz del dominio).
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
}
