// Asistente de medicamentos (chatbot.php)
// Manda la pregunta o la foto a la API y pinta la respuesta.
// Lo que viene del servidor siempre pasa por escapar() antes de ir al innerHTML

(function () {
  const form = document.getElementById('formChat');
  const input = document.getElementById('pregunta');
  const boton = document.getElementById('btnEnviar');
  const mensajes = document.getElementById('mensajes');
  const selectPaciente = document.getElementById('paciente');
  const avisoAlergias = document.getElementById('aviso-alergias');
  const historial = document.getElementById('historial');
  const historialVacio = document.getElementById('historialVacio');
  const botonFoto = document.getElementById('btnFoto');
  const entradaFoto = document.getElementById('entradaFoto');
  const csrf = document.querySelector('meta[name="csrf-token"]').content;

  function escapar(texto) {
    const div = document.createElement('div');
    div.textContent = texto == null ? '' : String(texto);
    return div.innerHTML;
  }

  // Escapa y luego arma párrafos y listas, solo deja pasar las **negritas**
  function formatear(texto) {
    const lineas = escapar(texto).split(/\n+/);
    let html = '';
    let enLista = false;

    lineas.forEach(function (linea) {
      let l = linea.trim();
      if (!l) return;
      l = l.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');

      if (/^[-•*]\s+/.test(l)) {
        if (!enLista) { html += '<ul>'; enLista = true; }
        html += '<li>' + l.replace(/^[-•*]\s+/, '') + '</li>';
      } else {
        if (enLista) { html += '</ul>'; enLista = false; }
        html += '<p>' + l + '</p>';
      }
    });

    if (enLista) html += '</ul>';
    return html;
  }

  function agregarMensaje(clase, html) {
    const div = document.createElement('div');
    div.className = 'msg ' + clase;
    div.innerHTML = html;
    mensajes.appendChild(div);
    mensajes.scrollTop = mensajes.scrollHeight;
    return div;
  }

  function nombreLegible(texto) {
    const t = String(texto || '').trim();
    if (!t) return 'Medicamento sin identificar';
    return t.charAt(0).toUpperCase() + t.slice(1).toLowerCase();
  }

  function fechaCorta(fecha) {
    const dos = function (n) { return String(n).padStart(2, '0'); };
    return dos(fecha.getDate()) + '/' + dos(fecha.getMonth() + 1) + '/' + fecha.getFullYear() +
      ' ' + dos(fecha.getHours()) + ':' + dos(fecha.getMinutes());
  }

  // Actualiza "Tus últimas consultas" sin recargar (máx. 8)
  function agregarAlHistorial(pregunta, medicamento, paciente) {
    if (!historial) return;

    const li = document.createElement('li');
    li.innerHTML = '<p>' + escapar(pregunta) + '</p>' +
      '<small>' + escapar(nombreLegible(medicamento)) + '<br>' +
      escapar(fechaCorta(new Date())) + (paciente ? ', ' + escapar(paciente) : '') + '</small>';

    historial.insertBefore(li, historial.firstChild);

    while (historial.children.length > 8) {
      historial.removeChild(historial.lastChild);
    }

    if (historialVacio) historialVacio.hidden = true;
  }

  function mostrarAlergiasPaciente() {
    const opcion = selectPaciente.options[selectPaciente.selectedIndex];

    if (!opcion || !opcion.value) {
      avisoAlergias.hidden = true;
      return;
    }

    const nombre = opcion.getAttribute('data-nombre') || opcion.textContent.trim();
    const alergias = opcion.getAttribute('data-alergias');

    avisoAlergias.hidden = false;

    if (alergias) {
      avisoAlergias.className = 'contexto-paciente riesgo';
      avisoAlergias.innerHTML = '<i class="fas fa-triangle-exclamation" aria-hidden="true"></i>' +
        '<span>' + escapar(nombre) + ' es alérgico(a) a: <strong>' + escapar(alergias) + '</strong></span>';
    } else {
      avisoAlergias.className = 'contexto-paciente sin-alergias';
      avisoAlergias.innerHTML = '<i class="fas fa-circle-check" aria-hidden="true"></i>' +
        '<span>' + escapar(nombre) + ' no tiene alergias registradas.</span>';
    }
  }

  function htmlAlertas(alertas) {
    let html = '';
    (alertas || []).forEach(function (a) {
      html += '<div class="alerta-alergia ' + (a.nivel === 'precaucion' ? 'precaucion' : '') + '" role="alert">' +
        '<i class="fas fa-triangle-exclamation" aria-hidden="true"></i><p>' +
        '<strong>' + (a.nivel === 'precaucion' ? 'Precaución con ' : 'Alergia: no recetes ') + escapar(a.medicamento) + '.</strong> ' +
        escapar(a.motivo) + '</p></div>';
    });
    return html;
  }

  function bloquear(estado) {
    boton.disabled = estado;
    if (botonFoto) botonFoto.disabled = estado;
  }

  async function llamarApi(ruta, cuerpo) {
    const respuesta = await fetch(ruta, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(cuerpo)
    });

    try {
      return await respuesta.json();
    } catch (e) {
      return { ok: false, mensaje: 'Tu sesión terminó. Recarga la página e inicia sesión de nuevo.' };
    }
  }

  async function enviar(pregunta) {
    agregarMensaje('medico', escapar(pregunta));
    const pensando = agregarMensaje('bot', '<span class="escribiendo"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Consultando la ficha técnica…</span>');
    bloquear(true);

    try {
      const datos = await llamarApi('api/chatbot.php', { pregunta: pregunta, paciente_id: selectPaciente.value });

      if (!datos.ok) {
        pensando.innerHTML = '<p>' + escapar(datos.mensaje || 'Ocurrió un error.') + '</p>';
        return;
      }

      let html = '';

      if (datos.encontrado) {
        html += '<div class="msg-meta">' +
          '<span class="chip"><i class="fas fa-capsules" aria-hidden="true"></i> ' + escapar(datos.principio) + '</span>' +
          '<span class="chip">' + escapar(datos.tema) + '</span>' +
          (datos.paciente ? '<span class="chip"><i class="fas fa-user" aria-hidden="true"></i> ' + escapar(datos.paciente) + '</span>' : '') +
          '</div>';
      }

      html += htmlAlertas(datos.alertas);
      html += formatear(datos.respuesta);

      if (datos.encontrado) {
        html += '<div class="msg-fuente">Fuente: ' + escapar(datos.fuente) + ', ' + escapar(datos.medicamento) +
          '<br><a href="' + escapar(datos.enlace) + '" target="_blank" rel="noopener">Ver ficha técnica completa</a></div>';
        agregarAlHistorial(pregunta, datos.medicamento, datos.paciente);
      }

      pensando.innerHTML = html;
      mensajes.scrollTop = mensajes.scrollHeight;
    } catch (error) {
      pensando.innerHTML = '<p>No hay conexión. El asistente necesita internet para consultar la información oficial.</p>';
    } finally {
      bloquear(false);
      input.focus();
    }
  }

  /* ---------- Escanear medicamento con la cámara ---------- */

  // Se achica a 1280 px en JPEG para que no tarde en subir
  function prepararFoto(archivo) {
    return new Promise(function (resolver, rechazar) {
      const lector = new FileReader();
      lector.onerror = function () { rechazar(new Error('No se pudo leer la foto.')); };
      lector.onload = function () {
        const imagen = new Image();
        imagen.onerror = function () { rechazar(new Error('El archivo no es una imagen válida.')); };
        imagen.onload = function () {
          const maximo = 1280;
          const escala = Math.min(1, maximo / Math.max(imagen.width, imagen.height));
          const lienzo = document.createElement('canvas');
          lienzo.width = Math.round(imagen.width * escala);
          lienzo.height = Math.round(imagen.height * escala);
          lienzo.getContext('2d').drawImage(imagen, 0, 0, lienzo.width, lienzo.height);
          resolver(lienzo.toDataURL('image/jpeg', 0.82));
        };
        imagen.src = lector.result;
      };
      lector.readAsDataURL(archivo);
    });
  }

  function htmlEscaneo(datos) {
    if (!datos.es_medicamento || !datos.medicamentos || datos.medicamentos.length === 0) {
      return '<p>' + escapar(datos.mensaje || 'No encontré un medicamento en la foto. Intenta con la caja de frente, con buena luz y el nombre completo a la vista.') + '</p>';
    }

    let html = '<div class="msg-meta"><span class="chip"><i class="fas fa-camera" aria-hidden="true"></i> Foto analizada</span>' +
      (datos.paciente ? '<span class="chip"><i class="fas fa-user" aria-hidden="true"></i> ' + escapar(datos.paciente) + '</span>' : '') +
      '</div>';

    datos.medicamentos.forEach(function (m) {
      const detalle = [m.concentracion, m.forma].filter(Boolean).join(', ');

      html += '<div class="escaneo">' +
        '<p class="escaneo-nombre"><strong>' + escapar(m.nombre_detectado || nombreLegible(m.principios.join(' + '))) + '</strong>' +
        (detalle ? ' <span>' + escapar(detalle) + '</span>' : '') + '</p>' +
        '<p class="escaneo-principio">Principio activo: ' + escapar(m.principios.join(' + ') || 'no se pudo leer') + '</p>' +
        htmlAlertas(m.alertas);

      if (m.encontrado) {
        html += '<p class="escaneo-cima"><i class="fas fa-circle-check" aria-hidden="true"></i> En CIMA: ' + escapar(nombreLegible(m.nombre_cima)) +
          (m.receta ? ' (' + escapar(m.receta.toLowerCase()) + ')' : '') +
          ' <a href="' + escapar(m.enlace) + '" target="_blank" rel="noopener">Ficha técnica</a></p>';
      } else {
        html += '<p class="escaneo-cima sin-cima"><i class="fas fa-circle-info" aria-hidden="true"></i> No está en CIMA (base española); revisa la información del fabricante.</p>';
      }

      if (selectPaciente.value && (!m.alertas || m.alertas.length === 0)) {
        html += '<p class="escaneo-ok"><i class="fas fa-shield-heart" aria-hidden="true"></i> Sin conflicto con las alergias registradas.</p>';
      }

      html += '</div>';
    });

    if (datos.resumen) {
      html += '<div class="escaneo-resumen">' + formatear(datos.resumen) + '</div>';
    }

    if (datos.sugerencias && datos.sugerencias.length) {
      html += '<div class="escaneo-acciones">';
      datos.sugerencias.forEach(function (s) {
        html += '<button type="button" data-pregunta="' + escapar(s.pregunta) + '">' + escapar(s.texto) + '</button>';
      });
      html += '</div>';
    }

    html += '<div class="msg-fuente">Lectura de la foto con Gemini; información oficial de CIMA (AEMPS). Confirma siempre con el empaque.</div>';
    return html;
  }

  async function escanear(archivo) {
    let foto;

    try {
      foto = await prepararFoto(archivo);
    } catch (e) {
      agregarMensaje('bot', '<p>' + escapar(e.message) + '</p>');
      return;
    }

    agregarMensaje('medico foto', '<img src="' + foto + '" alt="Foto del medicamento enviada"><span>Foto del medicamento</span>');
    const pensando = agregarMensaje('bot', '<span class="escribiendo"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Leyendo la foto…</span>');
    bloquear(true);

    try {
      const datos = await llamarApi('api/escanear.php', {
        imagen: foto.split(',')[1],
        tipo: 'image/jpeg',
        paciente_id: selectPaciente.value
      });

      if (!datos.ok) {
        pensando.innerHTML = '<p>' + escapar(datos.mensaje || 'No se pudo leer la foto.') + '</p>';
        return;
      }

      pensando.innerHTML = htmlEscaneo(datos);

      (datos.medicamentos || []).forEach(function (m) {
        agregarAlHistorial('Foto: ' + (m.nombre_detectado || m.principios.join(' + ')), m.nombre_cima || m.principios.join(' + '), datos.paciente);
      });

      mensajes.scrollTop = mensajes.scrollHeight;
    } catch (error) {
      pensando.innerHTML = '<p>No hay conexión. Para leer la foto se necesita internet.</p>';
    } finally {
      bloquear(false);
    }
  }

  if (botonFoto && entradaFoto) {
    botonFoto.addEventListener('click', function () {
      entradaFoto.click();
    });

    entradaFoto.addEventListener('change', function () {
      const archivo = entradaFoto.files && entradaFoto.files[0];
      entradaFoto.value = '';
      if (archivo) escanear(archivo);
    });

    // Shortcut del manifest (?escanear=1)
    if (new URLSearchParams(location.search).get('escanear') === '1') {
      botonFoto.classList.add('resaltado');
      botonFoto.focus();
    }
  }

  /* ---------- Eventos ---------- */

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    const pregunta = input.value.trim();
    if (pregunta.length < 4) return;
    input.value = '';
    enviar(pregunta);
  });

  // Sugerencias fijas y las que salen después de escanear
  document.addEventListener('click', function (e) {
    const b = e.target.closest('.sugerencias button, .escaneo-acciones button');
    if (b && !boton.disabled) {
      enviar(b.getAttribute('data-pregunta'));
    }
  });

  selectPaciente.addEventListener('change', mostrarAlergiasPaciente);
  mostrarAlergiasPaciente();
})();
