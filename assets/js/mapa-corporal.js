/* =====================================================
   MEDICORE: MAPA CORPORAL DE MOLESTIAS
   - Dibuja la silueta (frente y espalda) en SVG; cada zona es un elemento que se puede tocar
   - Modo paciente: el paciente toca una zona y dice qué siente, desde cuándo y qué tan fuerte
   - Evolución: el médico recorre los registros y ve cómo cambiaron las molestias
===================================================== */

(function () {
  'use strict';

  const raiz = document.getElementById('mapaCorporal');
  const fuente = document.getElementById('mapaDatos');

  if (!raiz || !fuente) {
    return;
  }

  const datos = JSON.parse(fuente.textContent);
  const ZONAS = datos.zonas;
  const SENSACIONES = datos.sensaciones;
  const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
  const MESES_LARGOS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  const PASO_MS = 1800;

  let registros = datos.registros || [];

  /* ---------------------------------------------------
     1. SILUETA
     Las zonas del lado izquierdo de la pantalla se reflejan para el lado derecho.
     De frente, el lado derecho del paciente queda a la izquierda de la pantalla;
     de espaldas, al revés.
  --------------------------------------------------- */

  const SVG_NS = 'http://www.w3.org/2000/svg';
  const TORSO = 'M78,86 C92,82 128,82 142,86 C146,110 143,140 139,160 C136,178 134,192 135,206 ' +
    'C138,222 142,238 143,258 L77,258 C78,238 82,222 85,206 C86,192 84,178 81,160 C77,140 74,110 78,86 Z';

  // Cada forma guarda su centro para poner encima el número de intensidad
  function capsula(cx, cy, w, h, angulo) {
    return {
      cx: cx, cy: cy,
      svg: '<rect x="' + (cx - w / 2) + '" y="' + (cy - h / 2) + '" width="' + w + '" height="' + h +
        '" rx="' + (w / 2) + '" transform="rotate(' + angulo + ' ' + cx + ' ' + cy + ')"/>'
    };
  }

  function elipse(cx, cy, rx, ry, angulo) {
    return {
      cx: cx, cy: cy,
      svg: '<ellipse cx="' + cx + '" cy="' + cy + '" rx="' + rx + '" ry="' + ry +
        '" transform="rotate(' + (angulo || 0) + ' ' + cx + ' ' + cy + ')"/>'
    };
  }

  function circulo(cx, cy, r) {
    return { cx: cx, cy: cy, svg: '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '"/>' };
  }

  function piezas(vista) {
    const frente = vista === 'frente';
    const izqPantalla = frente ? 'der' : 'izq';
    const derPantalla = frente ? 'izq' : 'der';
    const lista = [];
    const agregar = function (zona, forma) {
      lista.push({ zona: zona, forma: forma });
    };
    const nombre = function (deFrente, deEspalda) {
      return function (lado) {
        return (frente ? deFrente : deEspalda) + '_' + lado;
      };
    };

    agregar(frente ? 'cabeza' : 'cabeza_post', elipse(110, 40, 23, 28));
    agregar(frente ? 'cuello' : 'nuca', { cx: 110, cy: 75, svg: '<rect x="99" y="67" width="22" height="16" rx="6"/>' });

    const bandas = frente
      ? ['pecho', 'abdomen_sup', 'abdomen_inf', 'pelvis']
      : ['espalda_alta', 'espalda_media', 'espalda_baja', 'gluteos'];
    const alturas = [[84, 150], [152, 194], [196, 226], [228, 258]];

    bandas.forEach(function (zona, i) {
      agregar(zona, {
        cx: 110,
        cy: (alturas[i][0] + alturas[i][1]) / 2,
        svg: '<rect x="60" y="' + alturas[i][0] + '" width="100" height="' +
          (alturas[i][1] - alturas[i][0]) + '" clip-path="url(#torso-' + vista + ')"/>'
      });
    });

    const hombro = nombre('hombro', 'hombro_post');
    const brazo = nombre('brazo', 'brazo_post');
    const antebrazo = nombre('antebrazo', 'codo');
    const mano = nombre('mano', 'mano_dorso');
    const muslo = nombre('muslo', 'muslo_post');
    const rodilla = nombre('rodilla', 'corva');
    const pierna = nombre('pierna', 'pantorrilla');
    const pie = nombre('pie', 'talon');

    agregar(hombro(izqPantalla), circulo(72, 98, 14));
    agregar(hombro(derPantalla), circulo(148, 98, 14));
    agregar(brazo(izqPantalla), capsula(64, 146, 19, 66, 7));
    agregar(brazo(derPantalla), capsula(156, 146, 19, 66, -7));
    agregar(antebrazo(izqPantalla), capsula(56, 213, 17, 64, 7));
    agregar(antebrazo(derPantalla), capsula(164, 213, 17, 64, -7));
    agregar(mano(izqPantalla), elipse(50, 262, 10, 14.5, 7));
    agregar(mano(derPantalla), elipse(170, 262, 10, 14.5, -7));
    agregar(muslo(izqPantalla), capsula(94, 296, 29, 74, -2));
    agregar(muslo(derPantalla), capsula(126, 296, 29, 74, 2));
    agregar(rodilla(izqPantalla), circulo(96, 346, 12));
    agregar(rodilla(derPantalla), circulo(124, 346, 12));
    agregar(pierna(izqPantalla), capsula(97, 392, 22, 64, -1));
    agregar(pierna(derPantalla), capsula(123, 392, 22, 64, 1));
    agregar(pie(izqPantalla), elipse(97, 436, 12, 9));
    agregar(pie(derPantalla), elipse(123, 436, 12, 9));

    return lista;
  }

  function escapar(texto) {
    return String(texto == null ? '' : texto)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // Crea una figura; interactiva = las zonas se pueden tocar (modo paciente)
  function crearFigura(vista, interactiva) {
    const frente = vista === 'frente';
    const zonasSvg = piezas(vista).map(function (p) {
      const etiqueta = ZONAS[p.zona].nombre;
      const extra = interactiva ? ' tabindex="0" role="button" aria-label="' + escapar(etiqueta) + '"' : ' aria-hidden="true"';
      return '<g class="zona" data-zona="' + p.zona + '" data-cx="' + p.forma.cx + '" data-cy="' + p.forma.cy + '"' + extra +
        '><title>' + escapar(etiqueta) + '</title>' + p.forma.svg + '</g>';
    }).join('');

    const figura = document.createElement('figure');
    figura.className = 'mapa-figura';
    figura.dataset.vista = vista;
    figura.innerHTML =
      '<svg viewBox="0 0 220 466" xmlns="' + SVG_NS + '" role="group" aria-label="Silueta ' + (frente ? 'de frente' : 'de espalda') + '">' +
      '<defs><clipPath id="torso-' + vista + '-' + (interactiva ? 'r' : 'e') + '"><path d="' + TORSO + '"/></clipPath></defs>' +
      '<g class="zonas">' + zonasSvg.replace(/url\(#torso-(frente|espalda)\)/g, 'url(#torso-$1-' + (interactiva ? 'r' : 'e') + ')') + '</g>' +
      '<g class="marcas"></g>' +
      '<text class="lado" x="40" y="462">' + (frente ? 'Derecha' : 'Izquierda') + '</text>' +
      '<text class="lado" x="180" y="462">' + (frente ? 'Izquierda' : 'Derecha') + '</text>' +
      '</svg>' +
      '<figcaption>' + (frente ? 'Frente' : 'Espalda') + '</figcaption>';

    return figura;
  }

  function nivel(intensidad) {
    return Math.min(5, Math.max(1, Math.ceil(intensidad / 2)));
  }

  // Pinta las molestias en las figuras de un contenedor; resueltas = zonas que ya no se marcaron
  // En la evolución las figuras son más chicas, por eso el número va más grande
  function pintar(contenedor, molestias, resueltas, editando) {
    const grande = contenedor.dataset.figuras === 'evolucion';
    const radio = grande ? 9.5 : 8;
    const porZona = {};
    molestias.forEach(function (m) {
      porZona[m.zona] = m;
    });

    contenedor.querySelectorAll('.zona').forEach(function (g) {
      const m = porZona[g.dataset.zona];
      if (m) {
        g.dataset.nivel = nivel(m.intensidad);
      } else {
        delete g.dataset.nivel;
      }
      g.classList.toggle('resuelta', !m && !!resueltas && resueltas.indexOf(g.dataset.zona) !== -1);
      g.classList.toggle('editando', g.dataset.zona === editando);

      if (g.hasAttribute('tabindex')) {
        const base = ZONAS[g.dataset.zona].nombre;
        g.setAttribute('aria-label', m ? base + ': ' + SENSACIONES[m.sensacion] + ', intensidad ' + m.intensidad : base);
      }
    });

    // Número de intensidad sobre cada zona marcada (no depender solo del color)
    contenedor.querySelectorAll('svg').forEach(function (svg) {
      const capa = svg.querySelector('.marcas');
      capa.textContent = '';

      svg.querySelectorAll('.zona').forEach(function (g) {
        const m = porZona[g.dataset.zona];
        if (!m) {
          return;
        }
        const cx = parseFloat(g.dataset.cx);
        const cy = parseFloat(g.dataset.cy);
        const marca = document.createElementNS(SVG_NS, 'g');
        marca.setAttribute('class', 'marca');
        marca.innerHTML = '<circle cx="' + cx + '" cy="' + cy + '" r="' + radio + '"/>' +
          '<text x="' + cx + '" y="' + (cy + (grande ? 4 : 3.4)) + '"' + (grande ? ' class="grande"' : '') + '>' + m.intensidad + '</text>';
        capa.appendChild(marca);
      });
    });
  }

  /* ---------------------------------------------------
     2. FECHAS
  --------------------------------------------------- */

  function partes(fecha) {
    // 'AAAA-MM-DD HH:MM:SS' en hora de México, sin pasar por zonas horarias
    const m = String(fecha || '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    return m ? { a: +m[1], m: +m[2], d: +m[3], h: m[4] || '00', min: m[5] || '00' } : null;
  }

  function fechaLarga(fecha) {
    const p = partes(fecha);
    return p ? p.d + ' de ' + MESES_LARGOS[p.m - 1] + ' de ' + p.a + ', ' + p.h + ':' + p.min : '';
  }

  function fechaCorta(fecha) {
    const p = partes(fecha);
    return p ? p.d + ' ' + MESES[p.m - 1] : '';
  }

  function diasEntre(desde, hasta) {
    const a = partes(desde);
    const b = partes(hasta);
    if (!a || !b) {
      return null;
    }
    return Math.round((Date.UTC(b.a, b.m - 1, b.d) - Date.UTC(a.a, a.m - 1, a.d)) / 86400000);
  }

  function hace(dias) {
    if (dias <= 0) return 'hoy';
    if (dias === 1) return 'ayer';
    if (dias < 14) return 'hace ' + dias + ' días';
    if (dias < 60) return 'hace ' + Math.round(dias / 7) + ' semanas';
    return 'hace ' + Math.round(dias / 30) + ' meses';
  }

  // Para el paciente: "Empezó hace 3 días". Para el médico: relativo al día del registro
  function textoDesde(desde, referencia, paraMedico) {
    if (!desde) {
      return paraMedico ? 'Sin fecha de inicio' : '';
    }
    const dias = diasEntre(desde, referencia);
    const p = partes(desde);
    if (paraMedico) {
      const fecha = String(p.d).padStart(2, '0') + '/' + String(p.m).padStart(2, '0') + '/' + p.a;
      return 'Desde el ' + fecha + (dias > 0 ? ' (' + dias + (dias === 1 ? ' día' : ' días') + ' antes)' : ' (ese día)');
    }
    return 'Empezó ' + hace(dias);
  }

  function fechaMenosDias(dias) {
    const p = partes(datos.hoy);
    const d = new Date(Date.UTC(p.a, p.m - 1, p.d) - dias * 86400000);
    return d.toISOString().slice(0, 10);
  }

  function uuid() {
    if (window.crypto && crypto.randomUUID) {
      return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      const r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }

  /* ---------------------------------------------------
     3. EVOLUCIÓN (médico)
  --------------------------------------------------- */

  const vistaEvolucion = document.getElementById('vistaEvolucion');
  const figurasEvo = vistaEvolucion.querySelector('[data-figuras="evolucion"]');
  const rango = document.getElementById('evoRango');
  const marcasLinea = document.getElementById('evoMarcas');
  const btnReproducir = document.getElementById('btnReproducir');
  const anuncio = document.getElementById('evoAnuncio');
  let indice = 0;
  let temporizador = null;

  figurasEvo.appendChild(crearFigura('frente', false));
  figurasEvo.appendChild(crearFigura('espalda', false));

  function cambios(actual, anterior) {
    if (!anterior) {
      return null;
    }
    const antes = {};
    anterior.molestias.forEach(function (m) {
      antes[m.zona] = m;
    });
    const lista = [];

    actual.molestias.forEach(function (m) {
      const previa = antes[m.zona];
      if (!previa) {
        lista.push({ tipo: 'nueva', zona: m.zona, ahora: m.intensidad });
      } else {
        const tipo = m.intensidad > previa.intensidad ? 'empeoro' : (m.intensidad < previa.intensidad ? 'mejoro' : 'igual');
        lista.push({ tipo: tipo, zona: m.zona, antes: previa.intensidad, ahora: m.intensidad });
        delete antes[m.zona];
      }
    });

    Object.keys(antes).forEach(function (zona) {
      lista.push({ tipo: 'desaparecio', zona: zona, antes: antes[zona].intensidad });
    });

    const orden = { nueva: 0, empeoro: 1, mejoro: 2, desaparecio: 3, igual: 4 };
    return lista.sort(function (a, b) {
      return orden[a.tipo] - orden[b.tipo];
    });
  }

  const TEXTO_CAMBIO = {
    nueva: ['fa-plus', 'Nueva'],
    empeoro: ['fa-arrow-up', 'Aumentó'],
    mejoro: ['fa-arrow-down', 'Bajó'],
    desaparecio: ['fa-check', 'Ya no se marcó'],
    igual: ['fa-equals', 'Igual']
  };

  function htmlMolestia(m, referencia, paraMedico) {
    const detalle = m.detalle ? '<span class="mapa-item-detalle">“' + escapar(m.detalle) + '”</span>' : '';
    return '<span class="mapa-intensidad-chip" data-nivel="' + nivel(m.intensidad) + '">' + m.intensidad + '</span>' +
      '<span class="mapa-item-texto"><strong>' + escapar(ZONAS[m.zona].nombre) + '</strong>' +
      '<span>' + escapar(SENSACIONES[m.sensacion] || m.sensacion) + '. ' + escapar(textoDesde(m.desde, referencia, paraMedico)) + '</span>' +
      detalle + '</span>';
  }

  function mostrar(i) {
    indice = i;
    const actual = registros[i];
    const anterior = i > 0 ? registros[i - 1] : null;
    const lista = cambios(actual, anterior);
    const resueltas = (lista || []).filter(function (c) {
      return c.tipo === 'desaparecio';
    }).map(function (c) {
      return c.zona;
    });

    pintar(figurasEvo, actual.molestias, resueltas);

    document.getElementById('evoNumero').textContent = 'Registro ' + (i + 1) + ' de ' + registros.length;
    document.getElementById('evoFecha').textContent = fechaLarga(actual.fecha);

    const ulCambios = document.getElementById('evoCambios');
    if (!lista) {
      ulCambios.innerHTML = '<li class="mapa-cambio-primero">Es el primer registro del paciente.</li>';
    } else if (!lista.length) {
      ulCambios.innerHTML = '<li class="mapa-cambio-primero">Sin molestias en los dos registros.</li>';
    } else {
      ulCambios.innerHTML = lista.map(function (c) {
        const t = TEXTO_CAMBIO[c.tipo];
        let valores = '';
        if (c.tipo === 'nueva') valores = 'intensidad ' + c.ahora;
        if (c.tipo === 'empeoro' || c.tipo === 'mejoro' || c.tipo === 'igual') valores = c.antes + ' → ' + c.ahora;
        if (c.tipo === 'desaparecio') valores = 'antes ' + c.antes;
        return '<li class="mapa-cambio ' + c.tipo + '"><i class="fas ' + t[0] + '" aria-hidden="true"></i>' +
          '<span><strong>' + escapar(ZONAS[c.zona].nombre) + '</strong> ' + t[1].toLowerCase() + '</span>' +
          '<span class="mapa-cambio-valor">' + valores + '</span></li>';
      }).join('');
    }

    const ulLista = document.getElementById('evoLista');
    ulLista.innerHTML = actual.molestias.length
      ? actual.molestias.map(function (m) {
        return '<li class="mapa-item">' + htmlMolestia(m, actual.fecha, true) + '</li>';
      }).join('')
      : '<li class="mapa-cambio-primero">El paciente indicó que no tiene molestias.</li>';

    const comentario = document.getElementById('evoComentario');
    comentario.hidden = !actual.comentario;
    comentario.textContent = actual.comentario ? 'Comentario del paciente: ' + actual.comentario : '';

    rango.value = i;
    marcasLinea.querySelectorAll('button').forEach(function (b, j) {
      b.setAttribute('aria-current', j === i ? 'true' : 'false');
    });
    anuncio.textContent = 'Registro ' + (i + 1) + ' de ' + registros.length + ', ' + fechaLarga(actual.fecha);
  }

  function pausar() {
    clearInterval(temporizador);
    temporizador = null;
    btnReproducir.setAttribute('aria-pressed', 'false');
    btnReproducir.querySelector('i').className = 'fas fa-play';
    btnReproducir.querySelector('span').textContent = indice >= registros.length - 1 ? 'Reproducir de nuevo' : 'Reproducir';
  }

  function reproducir() {
    if (temporizador) {
      pausar();
      return;
    }
    if (indice >= registros.length - 1) {
      mostrar(0);
    }
    btnReproducir.setAttribute('aria-pressed', 'true');
    btnReproducir.querySelector('i').className = 'fas fa-pause';
    btnReproducir.querySelector('span').textContent = 'Pausar';
    temporizador = setInterval(function () {
      if (indice >= registros.length - 1) {
        pausar();
        return;
      }
      mostrar(indice + 1);
    }, PASO_MS);
  }

  function prepararEvolucion() {
    pausar();
    const hay = registros.length > 0;
    document.getElementById('mapaVacio').hidden = hay;
    document.getElementById('mapaEvolucion').hidden = !hay;

    if (!hay) {
      return;
    }

    rango.max = registros.length - 1;
    rango.disabled = registros.length < 2;
    btnReproducir.disabled = registros.length < 2;
    marcasLinea.innerHTML = registros.map(function (r, j) {
      return '<li><button type="button" data-indice="' + j + '">' + fechaCorta(r.fecha) + '</button></li>';
    }).join('');
    marcasLinea.style.setProperty('--marcas', registros.length);
    mostrar(registros.length - 1);
    btnReproducir.querySelector('span').textContent = 'Reproducir';
  }

  btnReproducir.addEventListener('click', reproducir);
  rango.addEventListener('input', function () {
    pausar();
    mostrar(parseInt(rango.value, 10));
  });
  marcasLinea.addEventListener('click', function (e) {
    const b = e.target.closest('button[data-indice]');
    if (b) {
      pausar();
      mostrar(parseInt(b.dataset.indice, 10));
    }
  });

  /* ---------------------------------------------------
     4. MODO PACIENTE (registro)
  --------------------------------------------------- */

  const vistaRegistro = document.getElementById('vistaRegistro');
  const figurasReg = vistaRegistro.querySelector('[data-figuras="registro"]');
  const hoja = document.getElementById('mapaHoja');
  const hojaForm = document.getElementById('hojaForm');
  const hojaFechaCampo = document.getElementById('hojaFechaCampo');
  const hojaFecha = document.getElementById('hojaFecha');
  const hojaError = document.getElementById('hojaError');
  const listaReg = document.getElementById('registroLista');
  const btnGuardar = document.getElementById('btnGuardarRegistro');
  const btnSinMolestias = document.getElementById('btnSinMolestias');
  const btnAnterior = document.getElementById('btnUsarAnterior');
  const errorReg = document.getElementById('registroError');
  const comentarioReg = document.getElementById('registroComentario');

  let borrador = {};
  let zonaActual = null;
  let idCliente = uuid();
  let guardando = false;

  figurasReg.appendChild(crearFigura('frente', true));
  figurasReg.appendChild(crearFigura('espalda', true));
  hojaFecha.max = datos.hoy;

  document.getElementById('hojaSensaciones').innerHTML = Object.keys(SENSACIONES).map(function (clave) {
    return '<label><input type="radio" name="sensacion" value="' + clave + '"><span>' + escapar(SENSACIONES[clave]) + '</span></label>';
  }).join('');

  let botonesIntensidad = '';
  for (let n = 1; n <= 10; n++) {
    botonesIntensidad += '<label data-nivel="' + nivel(n) + '"><input type="radio" name="intensidad" value="' + n + '"><span>' + n + '</span></label>';
  }
  document.getElementById('hojaIntensidad').innerHTML = botonesIntensidad;

  function listaBorrador() {
    return Object.keys(borrador).map(function (z) {
      return borrador[z];
    }).sort(function (a, b) {
      return b.intensidad - a.intensidad;
    });
  }

  function refrescarRegistro() {
    const lista = listaBorrador();
    pintar(figurasReg, lista, null, hoja.open ? zonaActual : null);
    listaReg.innerHTML = lista.map(function (m) {
      return '<li><button type="button" class="mapa-item" data-zona="' + m.zona + '">' + htmlMolestia(m, datos.hoy, false) +
        '<i class="fas fa-pen mapa-item-editar" aria-hidden="true"></i><span class="sr-only">Cambiar</span></button></li>';
    }).join('');
    document.getElementById('registroVacio').hidden = lista.length > 0;
    btnGuardar.disabled = lista.length === 0 || guardando;
    btnSinMolestias.hidden = lista.length > 0;
    btnAnterior.hidden = !(registros.length && registros[registros.length - 1].molestias.length && !lista.length);
  }

  function abrirHoja(zona) {
    zonaActual = zona;
    const m = borrador[zona];
    hojaForm.reset();
    hojaError.hidden = true;
    document.getElementById('hojaTitulo').textContent = ZONAS[zona].nombre;
    document.getElementById('hojaQuitar').hidden = !m;
    hojaFechaCampo.hidden = true;

    if (m) {
      hojaForm.querySelector('input[name="sensacion"][value="' + m.sensacion + '"]').checked = true;
      hojaForm.querySelector('input[name="intensidad"][value="' + m.intensidad + '"]').checked = true;
      const opcion = hojaForm.querySelector('input[name="desde"][value="' + (m.opcionDesde || 'fecha') + '"]');
      if (opcion) {
        opcion.checked = true;
      }
      if ((m.opcionDesde || 'fecha') === 'fecha') {
        hojaFechaCampo.hidden = false;
        hojaFecha.value = m.desde || '';
      }
      document.getElementById('hojaDetalle').value = m.detalle || '';
    }

    hoja.showModal();
    refrescarRegistro();
  }

  function cerrarHoja() {
    hoja.close();
  }

  hoja.addEventListener('close', function () {
    zonaActual = null;
    refrescarRegistro();
  });

  // Tocar fuera de la hoja la cierra
  hoja.addEventListener('click', function (e) {
    if (e.target === hoja) {
      cerrarHoja();
    }
  });

  hojaForm.addEventListener('change', function (e) {
    if (e.target.name === 'desde') {
      hojaFechaCampo.hidden = e.target.value !== 'fecha';
      if (e.target.value === 'fecha') {
        hojaFecha.focus();
      }
    }
    hojaError.hidden = true;
  });

  hojaForm.addEventListener('submit', function (e) {
    e.preventDefault();
    const sensacion = hojaForm.querySelector('input[name="sensacion"]:checked');
    const intensidad = hojaForm.querySelector('input[name="intensidad"]:checked');
    const desde = hojaForm.querySelector('input[name="desde"]:checked');
    let faltan = [];

    if (!sensacion) faltan.push('qué sientes');
    if (!desde || (desde.value === 'fecha' && !hojaFecha.value)) faltan.push('desde cuándo');
    if (!intensidad) faltan.push('qué tan fuerte es');

    if (desde && desde.value === 'fecha' && hojaFecha.value && hojaFecha.value > datos.hoy) {
      faltan = ['una fecha que no sea futura'];
    }

    if (faltan.length) {
      hojaError.textContent = 'Falta elegir ' + faltan.join(', ').replace(/, ([^,]*)$/, ' y $1') + '.';
      hojaError.hidden = false;
      return;
    }

    borrador[zonaActual] = {
      zona: zonaActual,
      sensacion: sensacion.value,
      intensidad: parseInt(intensidad.value, 10),
      opcionDesde: desde.value,
      desde: desde.value === 'fecha' ? hojaFecha.value : fechaMenosDias(parseInt(desde.value, 10)),
      detalle: document.getElementById('hojaDetalle').value.trim().slice(0, 200)
    };
    errorReg.hidden = true;
    cerrarHoja();
  });

  document.getElementById('hojaQuitar').addEventListener('click', function () {
    delete borrador[zonaActual];
    cerrarHoja();
  });

  figurasReg.addEventListener('click', function (e) {
    const g = e.target.closest('.zona');
    if (g) {
      abrirHoja(g.dataset.zona);
    }
  });

  figurasReg.addEventListener('keydown', function (e) {
    const g = e.target.closest('.zona');
    if (g && (e.key === 'Enter' || e.key === ' ')) {
      e.preventDefault();
      abrirHoja(g.dataset.zona);
    }
  });

  listaReg.addEventListener('click', function (e) {
    const b = e.target.closest('button[data-zona]');
    if (b) {
      abrirHoja(b.dataset.zona);
    }
  });

  // Frente o espalda en pantallas angostas
  vistaRegistro.querySelector('.mapa-vista-selector').addEventListener('click', function (e) {
    const b = e.target.closest('button[data-vista]');
    if (!b) {
      return;
    }
    this.querySelectorAll('button').forEach(function (x) {
      x.setAttribute('aria-pressed', x === b ? 'true' : 'false');
    });
    figurasReg.dataset.vistaActiva = b.dataset.vista;
  });

  // Copia lo del registro anterior para que el paciente solo actualice
  btnAnterior.addEventListener('click', function () {
    const ultimo = registros[registros.length - 1];
    ultimo.molestias.forEach(function (m) {
      borrador[m.zona] = {
        zona: m.zona,
        sensacion: m.sensacion,
        intensidad: m.intensidad,
        opcionDesde: 'fecha',
        desde: m.desde,
        detalle: m.detalle || ''
      };
    });
    refrescarRegistro();
  });

  function guardar(sinMolestias) {
    if (guardando) {
      return;
    }
    guardando = true;
    errorReg.hidden = true;
    btnGuardar.disabled = true;
    btnSinMolestias.disabled = true;
    btnGuardar.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Guardando…';

    fetch(datos.api + '?accion=guardar', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': datos.csrf },
      body: JSON.stringify({
        id_cliente: idCliente,
        paciente_id: datos.paciente.id,
        comentario: comentarioReg.value.trim(),
        sin_molestias: !!sinMolestias,
        molestias: sinMolestias ? [] : listaBorrador().map(function (m) {
          return { zona: m.zona, sensacion: m.sensacion, intensidad: m.intensidad, desde: m.desde, detalle: m.detalle };
        })
      })
    })
      .then(function (r) {
        return r.json().catch(function () {
          return { ok: false, mensaje: 'Respuesta inválida del servidor.' };
        });
      })
      .then(function (res) {
        if (!res.ok) {
          throw new Error(res.mensaje || 'No se pudo guardar.');
        }
        registros = res.registros;
        borrador = {};
        idCliente = uuid();
        comentarioReg.value = '';
        document.getElementById('registroCuerpo').hidden = true;
        const listo = document.getElementById('registroListo');
        listo.hidden = false;
        listo.focus();
      })
      .catch(function (err) {
        errorReg.textContent = navigator.onLine === false
          ? 'No hay conexión a internet. Lo que marcaste sigue aquí; intenta de nuevo en un momento.'
          : err.message;
        errorReg.hidden = false;
      })
      .finally(function () {
        guardando = false;
        btnSinMolestias.disabled = false;
        btnGuardar.innerHTML = '<i class="fas fa-check" aria-hidden="true"></i> Terminar y guardar';
        refrescarRegistro();
      });
  }

  btnGuardar.addEventListener('click', function () {
    guardar(false);
  });

  // Un toque para confirmar, otro para guardar (evita guardarlo por accidente)
  btnSinMolestias.addEventListener('click', function () {
    if (btnSinMolestias.dataset.confirmar === '1') {
      delete btnSinMolestias.dataset.confirmar;
      guardar(true);
      return;
    }
    btnSinMolestias.dataset.confirmar = '1';
    btnSinMolestias.textContent = 'Toca otra vez para confirmar';
    setTimeout(function () {
      delete btnSinMolestias.dataset.confirmar;
      btnSinMolestias.textContent = 'No tengo molestias';
    }, 4000);
  });

  /* ---------------------------------------------------
     5. CAMBIO DE MODO
  --------------------------------------------------- */

  function entrarModoPaciente() {
    pausar();
    borrador = {};
    idCliente = uuid();
    document.body.classList.add('modo-paciente');
    vistaEvolucion.hidden = true;
    vistaRegistro.hidden = false;
    document.getElementById('registroCuerpo').hidden = false;
    document.getElementById('registroListo').hidden = true;
    errorReg.hidden = true;
    refrescarRegistro();
    window.scrollTo(0, 0);
    history.replaceState(null, '', '?id=' + datos.paciente.id + '&modo=paciente');
  }

  function salirModoPaciente() {
    document.body.classList.remove('modo-paciente');
    vistaRegistro.hidden = true;
    vistaEvolucion.hidden = false;
    prepararEvolucion();
    window.scrollTo(0, 0);
    history.replaceState(null, '', '?id=' + datos.paciente.id);
  }

  raiz.addEventListener('click', function (e) {
    const b = e.target.closest('[data-accion]');
    if (!b) {
      return;
    }
    const accion = b.dataset.accion;

    if (accion === 'nuevo-registro') {
      entrarModoPaciente();
    }
    if (accion === 'ver-evolucion') {
      salirModoPaciente();
    }
    if (accion === 'cerrar-hoja') {
      cerrarHoja();
    }
    if (accion === 'salir-paciente') {
      if (Object.keys(borrador).length && b.dataset.confirmar !== '1') {
        b.dataset.confirmar = '1';
        b.querySelector('span').textContent = 'Hay molestias sin guardar. Toca otra vez para salir';
        setTimeout(function () {
          delete b.dataset.confirmar;
          b.querySelector('span').textContent = 'Salir (médico)';
        }, 4000);
        return;
      }
      delete b.dataset.confirmar;
      b.querySelector('span').textContent = 'Salir (médico)';
      salirModoPaciente();
    }
  });

  window.addEventListener('beforeunload', function (e) {
    if (!vistaRegistro.hidden && Object.keys(borrador).length) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  if (datos.modo === 'registro') {
    entrarModoPaciente();
  } else {
    prepararEvolucion();
  }
})();
