// Nota de consulta - MediCore
// 1) Dictado: la Web Speech API del navegador convierte la voz en texto.
// 2) "Ordenar con IA": api/notas.php acomoda el texto en formato SOAP
//    (Gemini) y revisa el plan contra las alergias del paciente.
// 3) "Guardar nota": se guarda en el expediente. Si no hay internet,
//    se queda en este dispositivo (cola-notas.js) y se envía sola después.

(function () {
  const tarjeta = document.getElementById('notaConsulta');
  if (!tarjeta) return;

  const pacienteId = tarjeta.getAttribute('data-paciente');
  const csrf = tarjeta.getAttribute('data-csrf');
  const iaActiva = tarjeta.getAttribute('data-ia') === '1';

  const texto = document.getElementById('notaTexto');
  const parcial = document.getElementById('dictadoParcial');
  const estado = document.getElementById('dictadoEstado');
  const btnDictar = document.getElementById('btnDictar');
  const btnOrdenar = document.getElementById('btnOrdenar');
  const btnPasar = document.getElementById('btnPasarExpediente');
  const btnGuardar = document.getElementById('btnGuardarNota');
  const soap = document.getElementById('notaSoap');
  const alertasCaja = document.getElementById('notaAlertas');
  const mensaje = document.getElementById('notaMensaje');
  const lista = document.getElementById('notasLista');
  const vacio = document.getElementById('notasVacio');
  const avisoRed = document.getElementById('notaRed');
  const campos = Array.prototype.slice.call(soap.querySelectorAll('textarea[data-campo]'));

  const TITULOS = { subjetivo: 'Subjetivo', objetivo: 'Objetivo', analisis: 'Análisis', plan: 'Plan' };
  const ORIGENES = { voz: 'Dictada', texto: 'Escrita', sin_conexion: 'Guardada sin conexión' };

  let seDicto = false;

  function escapar(valor) {
    const div = document.createElement('div');
    div.textContent = valor == null ? '' : String(valor);
    return div.innerHTML;
  }

  function avisar(textoMensaje, tipo) {
    mensaje.textContent = textoMensaje || '';
    mensaje.className = 'nota-mensaje' + (tipo ? ' ' + tipo : '');
  }

  function nuevoId() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      const r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }

  function fechaCorta(fecha) {
    const dos = function (n) { return String(n).padStart(2, '0'); };
    return dos(fecha.getDate()) + '/' + dos(fecha.getMonth() + 1) + '/' + fecha.getFullYear() +
      ' ' + dos(fecha.getHours()) + ':' + dos(fecha.getMinutes());
  }

  function valoresSOAP() {
    const nota = {};
    campos.forEach(function (c) { nota[c.getAttribute('data-campo')] = c.value.trim(); });
    return nota;
  }

  function hayNotaOrdenada() {
    const n = valoresSOAP();
    return !soap.hidden && (n.subjetivo + n.objetivo + n.analisis + n.plan) !== '';
  }

  /* ---------- Dictado por voz ---------- */

  const Reconocimiento = window.SpeechRecognition || window.webkitSpeechRecognition;
  let reconocedor = null;
  let escuchando = false;
  let ultimoInicio = 0;
  let reiniciosRapidos = 0;

  function ponerEstado(textoEstado, grabando) {
    estado.textContent = textoEstado;
    estado.classList.toggle('grabando', !!grabando);
  }

  function agregarDictado(frase) {
    frase = frase.trim();
    if (!frase) return;
    const actual = texto.value.replace(/\s+$/, '');
    texto.value = actual ? actual + ' ' + frase : frase.charAt(0).toUpperCase() + frase.slice(1);
    seDicto = true;
  }

  function detenerDictado() {
    escuchando = false;
    btnDictar.setAttribute('aria-pressed', 'false');
    btnDictar.querySelector('span').textContent = 'Dictar';
    btnDictar.querySelector('i').className = 'fas fa-microphone';
    parcial.textContent = '';
    if (reconocedor) {
      try { reconocedor.stop(); } catch (e) { /* ya estaba detenido */ }
    }
  }

  function iniciarDictado() {
    reconocedor = new Reconocimiento();
    reconocedor.lang = 'es-MX';
    reconocedor.continuous = true;
    reconocedor.interimResults = true;

    reconocedor.onresult = function (evento) {
      let enCurso = '';
      for (let i = evento.resultIndex; i < evento.results.length; i++) {
        const resultado = evento.results[i];
        if (resultado.isFinal) {
          agregarDictado(resultado[0].transcript);
        } else {
          enCurso += resultado[0].transcript;
        }
      }
      parcial.textContent = enCurso;
    };

    reconocedor.onerror = function (evento) {
      if (evento.error === 'no-speech' || evento.error === 'aborted') return;

      const motivos = {
        'not-allowed': 'No hay permiso para usar el micrófono. Actívalo desde el candado de la barra de direcciones.',
        'service-not-allowed': 'No hay permiso para usar el micrófono. Actívalo desde el candado de la barra de direcciones.',
        'audio-capture': 'No se encontró un micrófono conectado.',
        'network': 'El dictado necesita internet. Puedes escribir la nota y guardarla.'
      };

      detenerDictado();
      ponerEstado(motivos[evento.error] || 'El dictado se detuvo. Intenta de nuevo.', false);
    };

    // Chrome corta el dictado tras unos segundos de silencio: si el médico
    // no lo detuvo, se vuelve a encender.
    reconocedor.onend = function () {
      if (escuchando) {
        // Si se corta una y otra vez en menos de un segundo, algo falla
        // (micrófono ocupado, sin servicio): se detiene en lugar de ciclarse.
        reiniciosRapidos = Date.now() - ultimoInicio < 1000 ? reiniciosRapidos + 1 : 0;

        if (reiniciosRapidos >= 3) {
          detenerDictado();
          ponerEstado('No se pudo mantener el dictado. Revisa el micrófono e intenta de nuevo.', false);
          return;
        }

        try {
          ultimoInicio = Date.now();
          reconocedor.start();
        } catch (e) {
          detenerDictado();
        }
      } else {
        ponerEstado('Dictado detenido. Revisa el texto y ordénalo o guárdalo.', false);
      }
    };

    ultimoInicio = Date.now();
    reiniciosRapidos = 0;
    reconocedor.start();
    escuchando = true;
    btnDictar.setAttribute('aria-pressed', 'true');
    btnDictar.querySelector('span').textContent = 'Detener';
    btnDictar.querySelector('i').className = 'fas fa-stop';
    ponerEstado('Escuchando… habla con normalidad.', true);
  }

  if (!Reconocimiento) {
    btnDictar.disabled = true;
    ponerEstado('Este navegador no permite dictar (usa Chrome o Edge). Puedes escribir la nota.', false);
  } else {
    btnDictar.addEventListener('click', function () {
      if (escuchando) {
        detenerDictado();
      } else {
        try {
          iniciarDictado();
        } catch (e) {
          ponerEstado('No se pudo iniciar el dictado. Intenta de nuevo.', false);
        }
      }
    });
  }

  /* ---------- Ordenar con IA ---------- */

  function pintarAlertas(alertas) {
    alertasCaja.innerHTML = (alertas || []).map(function (a) {
      return '<div class="alerta ' + (a.nivel === 'precaucion' ? 'precaucion' : 'error') + '" role="alert">' +
        '<i class="fas fa-triangle-exclamation" aria-hidden="true"></i> ' +
        '<span><strong>' + (a.nivel === 'precaucion' ? 'Precaución con ' : 'Alergia: revisa ') + escapar(a.medicamento) + '.</strong> ' +
        escapar(a.motivo) + '</span></div>';
    }).join('');
  }

  async function llamar(accion, cuerpo) {
    const respuesta = await fetch('api/notas.php?accion=' + accion, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(cuerpo)
    });

    let datos;
    try {
      datos = await respuesta.json();
    } catch (e) {
      datos = { ok: false, mensaje: 'Tu sesión terminó. Recarga la página e inicia sesión de nuevo.' };
    }
    datos.estado = respuesta.status;
    return datos;
  }

  if (btnOrdenar) {
    btnOrdenar.addEventListener('click', async function () {
      if (escuchando) detenerDictado();

      const contenido = texto.value.trim();
      if (contenido.length < 15) {
        avisar('Dicta o escribe un poco más antes de ordenar la nota.', 'error');
        texto.focus();
        return;
      }

      if (!navigator.onLine) {
        avisar('Para ordenar con IA se necesita internet. Puedes guardar la nota tal como está.', 'error');
        return;
      }

      btnOrdenar.disabled = true;
      avisar('Ordenando la nota…', '');

      try {
        const datos = await llamar('estructurar', { paciente_id: pacienteId, texto: contenido });

        if (!datos.ok) {
          avisar(datos.mensaje || 'No se pudo ordenar la nota.', 'error');
          return;
        }

        campos.forEach(function (c) {
          c.value = datos.nota[c.getAttribute('data-campo')] || '';
        });

        pintarAlertas(datos.alertas);
        soap.hidden = false;
        btnPasar.hidden = false;
        avisar('Revisa la nota, corrige lo que haga falta y guárdala.', 'ok');
        campos[0].focus();
      } catch (e) {
        avisar('Se perdió la conexión. Puedes guardar la nota tal como está.', 'error');
      } finally {
        btnOrdenar.disabled = false;
      }
    });
  }

  /* ---------- Pasar al formulario del expediente ---------- */

  btnPasar.addEventListener('click', function () {
    const nota = valoresSOAP();
    const diagnostico = document.querySelector('textarea[name="diagnostico"]');
    const receta = document.querySelector('textarea[name="receta"]');

    function agregar(destino, valor) {
      if (!destino || !valor) return;
      destino.value = destino.value.trim() ? destino.value.trim() + '\n\n' + valor : valor;
    }

    agregar(diagnostico, nota.analisis);
    agregar(receta, nota.plan);

    if (diagnostico) {
      diagnostico.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
      diagnostico.focus({ preventScroll: true });
    }

    avisar('Se copió el análisis al diagnóstico y el plan a la receta. Revisa y guarda el expediente.', 'ok');
  });

  /* ---------- Lista de notas ---------- */

  function htmlNota(nota, pendiente) {
    const resumen = String(nota.analisis || nota.subjetivo || nota.transcripcion || '').split('\n')[0];
    const tieneSOAP = ['subjetivo', 'objetivo', 'analisis', 'plan'].some(function (c) { return (nota[c] || '').trim() !== ''; });
    const fecha = nota.creado_en_texto || fechaCorta(new Date(nota.escrita_en || Date.now()));
    const origen = pendiente ? 'pendiente' : nota.origen;
    const etiqueta = pendiente ? 'Pendiente de enviar' : (ORIGENES[nota.origen] || 'Escrita');

    let cuerpo = '';
    if (tieneSOAP) {
      cuerpo = '<dl class="nota-soap">' + Object.keys(TITULOS).map(function (c) {
        return (nota[c] || '').trim() ? '<dt>' + TITULOS[c] + '</dt><dd>' + escapar(nota[c]) + '</dd>' : '';
      }).join('') + '</dl>';
    } else {
      cuerpo = '<p class="nota-libre">' + escapar(nota.transcripcion) + '</p>';
    }

    return '<details><summary>' +
      '<span class="nota-fecha">' + escapar(fecha) + '</span>' +
      '<span class="nota-origen ' + escapar(origen) + '">' + escapar(etiqueta) + '</span>' +
      '<span class="nota-resumen">' + escapar(resumen.length > 90 ? resumen.slice(0, 89) + '…' : resumen) + '</span>' +
      '</summary>' + cuerpo + '</details>';
  }

  function ponerEnLista(nota, pendiente) {
    let li = lista.querySelector('li[data-id="' + nota.id_cliente + '"]');

    if (!li) {
      li = document.createElement('li');
      li.className = 'nota';
      li.setAttribute('data-id', nota.id_cliente);
      lista.insertBefore(li, lista.firstChild);
    }

    li.innerHTML = htmlNota(nota, pendiente);
    vacio.hidden = true;
  }

  function limpiarFormulario() {
    texto.value = '';
    parcial.textContent = '';
    campos.forEach(function (c) { c.value = ''; });
    alertasCaja.innerHTML = '';
    soap.hidden = true;
    btnPasar.hidden = true;
    seDicto = false;
  }

  /* ---------- Guardar (en línea o sin conexión) ---------- */

  async function guardarSinConexion(nota) {
    nota.origen = 'sin_conexion';
    nota.csrf = csrf;
    await window.MediCoreCola.guardar(nota);

    // Background Sync: el service worker la manda cuando vuelva el internet
    try {
      const registro = await Promise.race([
        navigator.serviceWorker.ready,
        new Promise(function (_, rechazar) { setTimeout(rechazar, 2000); })
      ]);
      if (registro && 'sync' in registro) {
        await registro.sync.register('enviar-notas');
      }
    } catch (e) {
      // Sin Background Sync: pwa.js la manda al volver la conexión
    }

    ponerEnLista(nota, true);
    limpiarFormulario();
    avisar('Sin conexión: la nota se guardó en este dispositivo y se enviará sola cuando vuelva el internet.', 'ok');
  }

  btnGuardar.addEventListener('click', async function () {
    if (escuchando) detenerDictado();

    const nota = Object.assign({
      id_cliente: nuevoId(),
      paciente_id: Number(pacienteId),
      transcripcion: texto.value.trim(),
      origen: seDicto ? 'voz' : 'texto',
      escrita_en: new Date().toISOString()
    }, hayNotaOrdenada() ? valoresSOAP() : { subjetivo: '', objetivo: '', analisis: '', plan: '' });

    if (!nota.transcripcion && !hayNotaOrdenada()) {
      avisar('La nota está vacía. Dicta o escribe algo primero.', 'error');
      texto.focus();
      return;
    }

    btnGuardar.disabled = true;

    try {
      if (!navigator.onLine && window.MediCoreCola) {
        await guardarSinConexion(nota);
        return;
      }

      avisar('Guardando…', '');
      let datos;

      try {
        datos = await llamar('guardar', nota);
      } catch (e) {
        // Se cayó la red justo al guardar
        if (window.MediCoreCola) {
          await guardarSinConexion(nota);
        } else {
          avisar('No hay conexión y este navegador no puede guardar la nota sin internet.', 'error');
        }
        return;
      }

      if (!datos.ok) {
        avisar(datos.mensaje || 'No se pudo guardar la nota.', 'error');
        return;
      }

      ponerEnLista(datos.nota, false);
      limpiarFormulario();
      pintarAlertas(datos.alertas);
      avisar(datos.mensaje || 'Nota guardada en el expediente.', 'ok');
    } finally {
      btnGuardar.disabled = false;
    }
  });

  /* ---------- Notas que siguen en este dispositivo ---------- */

  async function mostrarPendientes() {
    if (!window.MediCoreCola) return;

    try {
      const pendientes = await window.MediCoreCola.listar();
      const ids = pendientes.map(function (n) { return n.id_cliente; });

      pendientes
        .filter(function (n) { return String(n.paciente_id) === String(pacienteId); })
        .sort(function (a, b) { return String(a.escrita_en).localeCompare(String(b.escrita_en)); })
        .forEach(function (n) { ponerEnLista(n, true); });

      // Las que ya se enviaron cambian su etiqueta
      lista.querySelectorAll('.nota-origen.pendiente').forEach(function (etiqueta) {
        const li = etiqueta.closest('li');
        if (li && ids.indexOf(li.getAttribute('data-id')) === -1) {
          etiqueta.className = 'nota-origen sin_conexion';
          etiqueta.textContent = 'Enviada';
        }
      });
    } catch (e) {
      // IndexedDB no disponible (modo privado): no pasa nada
    }
  }

  window.addEventListener('medicore:notas-enviadas', mostrarPendientes);

  function actualizarRed() {
    avisoRed.hidden = navigator.onLine;
    if (btnOrdenar) btnOrdenar.disabled = !navigator.onLine;
  }

  window.addEventListener('online', actualizarRed);
  window.addEventListener('offline', actualizarRed);
  actualizarRed();
  mostrarPendientes();

  if (!iaActiva && btnPasar) btnPasar.hidden = true;
})();
