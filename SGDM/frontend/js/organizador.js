/* =====================================================
   TORNALYX – organizador.js
   Panel del organizador (y pestaña Torneos del admin):
   crear torneos contra el API y listar los propios.
   ===================================================== */

'use strict';

/* IIFE: evita colisiones de `const` de nivel superior con admin.js,
   que se carga en la misma página (panel de administración). */
(function () {

const { Api, Toast, Utils } = window.Tornalyx;

/** Pinta (o limpia) la vista previa de la foto de fondo del torneo. */
function pintarBannerPreview(url) {
  const preview = document.getElementById('torneoBannerPreview');
  if (!preview) return;
  preview.style.backgroundImage = url ? `url(${encodeURI(url)})` : '';
}

/* Torneos cargados, en el orden en que se muestran. */
let torneos = [];

/* Id del torneo que se está editando, o null si el formulario está en modo
   "crear". Lo usa initCreateForm() para decidir a qué endpoint mandar. */
let editandoId = null;

/**
 * Muestra u oculta el formulario de creación cuando vive dentro de un panel
 * (panel del admin). En el panel del organizador el formulario está en su
 * propia sección y este helper no aplica.
 * @param {boolean} mostrar
 */
function mostrarCreateCard(mostrar) {
  const card = document.getElementById('createCard');
  if (!card) return;
  card.classList.toggle('hidden', !mostrar);
  if (mostrar) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/**
 * Abre el formulario de creación esté donde esté: como sección propia
 * (organizador) o como tarjeta plegable dentro del panel (admin).
 */
function abrirCreacion() {
  if (document.getElementById('panel-crear')) {
    window.setSection('crear');
  } else {
    mostrarCreateCard(true);
  }
}

/** Título y botón del formulario según esté creando o editando un torneo. */
function actualizarTituloFormulario() {
  const h1  = document.querySelector('#panel-crear h1, #createCard h3');
  const btn = document.querySelector('#createForm button[type="submit"]');
  if (h1)  h1.textContent  = editandoId ? 'Editar torneo' : 'Crear torneo';
  if (btn) btn.textContent = editandoId ? 'Guardar cambios' : 'Crear torneo';

  // "Al crearlo" (publicar/borrador) solo aplica al crear: editar ajustes no
  // cambia si el torneo está publicado, así que se oculta para no confundir.
  const publicarGroup = document.getElementById('publicar')?.closest('.form-group');
  if (publicarGroup) publicarGroup.classList.toggle('hidden', !!editandoId);

  // La foto de fondo recién tiene sentido con un torneo ya creado (necesita
  // un id real contra el que subir).
  document.getElementById('torneoBannerRow')?.classList.toggle('hidden', !editandoId);
}

/** Vuelve el formulario a modo "crear" (se usa al abrirlo desde cero). */
function resetFormularioCreacion() {
  editandoId = null;
  document.getElementById('createForm')?.reset();
  pintarBannerPreview(null);
  actualizarTituloFormulario();
}

/** Carga los datos de un torneo propio en el formulario para editarlo. */
function iniciarEdicion(id) {
  const torneo = torneos.find(t => String(t.id) === String(id));
  const form = document.getElementById('createForm');
  if (!torneo || !form) return;

  editandoId = torneo.id;
  pintarBannerPreview(torneo.banner_url);
  const set = (nombre, valor) => {
    const el = form.elements.namedItem(nombre);
    if (el) el.value = valor ?? '';
  };
  set('nombre', torneo.nombre);
  set('disciplina', torneo.disciplina);
  set('formato', torneo.formato);
  set('max_participantes', torneo.max_participantes);
  set('fecha_inicio', torneo.fecha_inicio);
  set('fecha_fin', torneo.fecha_fin);
  set('descripcion', torneo.descripcion);
  set('reglamento', torneo.reglamento);
  set('premios', torneo.premios);
  set('discord_url', torneo.discord_url);
  set('requiere_equipos', torneo.requiere_equipos ? '1' : '0');
  set('publicar', torneo.publico ? '1' : '0');

  actualizarTituloFormulario();
  abrirCreacion();
}

/**
 * Elimina un torneo propio. Si el backend responde que ya tiene actividad
 * (inscriptos o partidos), ofrece cancelarlo en su lugar.
 */
async function eliminarTorneo(id) {
  if (!confirm('¿Eliminar este torneo? Esta acción no se puede deshacer.')) return;

  try {
    const res = await Api.post(`/api/torneo/${id}/eliminar`, {});
    Toast.success(res.mensaje || 'Torneo eliminado.');
    torneos = torneos.filter(t => String(t.id) !== String(id));
    renderTorneos();
  } catch (err) {
    if (err.data && err.data.sugerir_cancelar
        && confirm(err.message + '\n\n¿Querés cancelarlo en su lugar?')) {
      try {
        const res = await Api.post(`/api/torneo/${id}/cancelar`, {});
        Toast.success(res.mensaje || 'Torneo cancelado.');
        await cargarTorneos();
      } catch (err2) {
        Toast.error(err2.message);
      }
      return;
    }
    Toast.error(err.message);
  }
}

/**
 * Clave (data-section) del panel que contiene la lista de torneos, para volver
 * a él tras crear uno. Organizador → "inicio"; admin → "torneos".
 * @returns {string}
 */
function seccionDeLista() {
  const panel = document.getElementById('misTorneos')?.closest('.section-panel');
  return panel ? panel.id.replace(/^panel-/, '') : 'inicio';
}

/**
 * Pinta el HTML de una tarjeta de torneo del panel.
 * @param {Object} t Torneo devuelto por el API.
 * @returns {string}
 */
function torneoItemHtml(t) {
  const esc      = Utils.escapeHtml;
  const estado   = TorneoUI.estado(t.estado);
  const progreso = TorneoUI.progreso(t);
  const cupos    = TorneoUI.participantes(t);
  const ocupacion = t.max_participantes
    ? Math.min(100, Math.round(cupos / t.max_participantes * 100))
    : 0;

  /* Mientras no haya partidos, la barra muestra el llenado de cupos: es el
     dato útil en un torneo que todavía está juntando inscripciones. */
  const midiendoPartidos = Number(t.partidos) > 0;
  const barraLabel = midiendoPartidos ? 'Progreso' : 'Inscripciones';
  const barraValor = midiendoPartidos
    ? `${progreso}%`
    : `${cupos}/${t.max_participantes}`;
  const barraAncho = midiendoPartidos ? progreso : ocupacion;

  return `
    <div class="torneo-item" data-id="${t.id}">
      <div class="torneo-item__head">
        <div>
          <div class="torneo-item__title">${TorneoUI.icono(t.disciplina)} ${esc(t.nombre)}</div>
          <div class="torneo-item__meta">${esc(TorneoUI.resumen(t))}</div>
        </div>
        <span class="badge ${estado.badge}">${esc(estado.label)}</span>
      </div>
      <div style="display:flex;justify-content:space-between;margin-bottom:4px">
        <span style="font-size:12px;color:var(--muted)">${barraLabel}</span>
        <span style="font-size:12px;color:var(--muted-2);font-family:var(--mono)">${esc(barraValor)}</span>
      </div>
      <div class="progress-bar"><div class="progress-fill" style="width:${barraAncho}%"></div></div>
      <div style="margin-top:var(--space-3);display:flex;justify-content:space-between;align-items:center;gap:var(--space-2)">
        <a class="btn btn-ghost btn-sm" href="/torneo-detalle?id=${t.id}">Ver detalle</a>
        <div class="action-menu">
          <button type="button" class="action-menu__trigger" aria-haspopup="true" aria-expanded="false" aria-label="Más acciones para ${esc(t.nombre)}">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>
          </button>
          <div class="action-menu__list" role="menu">
            <button type="button" class="action-menu__item" role="menuitem" data-editar="${t.id}">
              <svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
              Editar ajustes
            </button>
            <button type="button" class="action-menu__item action-menu__item--danger" role="menuitem" data-eliminar="${t.id}">
              <svg viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
              Eliminar
            </button>
          </div>
        </div>
      </div>
    </div>`;
}

/** Placeholder de una card de torneo mientras se espera al API. */
function skeletonTorneoItem() {
  return `
    <div class="torneo-item" aria-hidden="true">
      <div class="torneo-item__head">
        <div style="flex:1">
          <span class="skeleton" style="display:block;height:16px;width:55%;margin-bottom:8px"></span>
          <span class="skeleton" style="display:block;height:12px;width:35%"></span>
        </div>
      </div>
      <span class="skeleton" style="display:block;height:8px;width:100%;margin-top:12px"></span>
    </div>`;
}

/** Vuelve a dibujar la lista de torneos y los KPIs. */
function renderTorneos() {
  const cont = document.getElementById('misTorneos');
  if (!cont) return;

  if (!torneos.length) {
    cont.innerHTML = `
      <div class="torneo-item" style="text-align:center;color:var(--muted)">
        Todavía no hay torneos.
        <a href="#" data-abrir-creacion style="color:var(--red-bright)">Creá el primero</a>.
      </div>`;
    /* El enlace recién creado necesita su handler para abrir el formulario. */
    cont.querySelectorAll('[data-abrir-creacion]').forEach(el =>
      el.addEventListener('click', e => { e.preventDefault(); abrirCreacion(); })
    );
  } else {
    cont.innerHTML = torneos.map(torneoItemHtml).join('');
    cont.querySelectorAll('[data-editar]').forEach(b =>
      b.addEventListener('click', () => iniciarEdicion(b.dataset.editar)));
    cont.querySelectorAll('[data-eliminar]').forEach(b =>
      b.addEventListener('click', () => eliminarTorneo(b.dataset.eliminar)));
  }

  renderKpis();
  renderSelectores();
}

/** Actualiza las tarjetas de métricas del encabezado. */
function renderKpis() {
  const activos = torneos.filter(t => t.estado === 'inscripcion' || t.estado === 'en_curso').length;
  const finalizados = torneos.filter(t => t.estado === 'finalizado').length;
  const participantes = torneos.reduce((n, t) => n + TorneoUI.participantes(t), 0);
  const jugados = torneos.reduce((n, t) => n + (Number(t.partidos_jugados) || 0), 0);
  const programados = torneos.reduce((n, t) => n + (Number(t.partidos) || 0), 0);

  const set = (id, valor) => {
    const el = document.getElementById(id);
    if (el) el.textContent = valor;
  };
  set('kpiActivos', activos);
  set('kpiActivosDelta', `${torneos.length} en total · ${finalizados} finalizado(s)`);
  set('kpiParticipantes', participantes);
  set('kpiParticipantesDelta', participantes === 1 ? '1 confirmado' : `${participantes} confirmados`);
  set('kpiPartidos', jugados);
  set('kpiPartidosDelta', `de ${programados} programados`);
}

/** Rellena los <select> que listan torneos del organizador. */
function renderSelectores() {
  document.querySelectorAll('[data-torneos-select]').forEach(sel => {
    const previo = sel.value;
    sel.innerHTML = torneos.length
      ? torneos.map(t => `<option value="${t.id}">${Utils.escapeHtml(t.nombre)}</option>`).join('')
      : '<option value="">Sin torneos todavía</option>';
    if (previo) sel.value = previo;
  });
}

/** Carga los torneos del usuario desde el API. */
async function cargarTorneos() {
  const cont = document.getElementById('misTorneos');
  if (cont) {
    cont.innerHTML = Array.from({ length: 3 }, skeletonTorneoItem).join('');
  }
  try {
    const data = await Api.get('/api/torneos/mios');
    torneos = data.torneos || [];
    renderTorneos();
  } catch (err) {
    if (cont) {
      cont.innerHTML = `<div class="torneo-item" style="color:var(--muted)">${Utils.escapeHtml(err.message)}</div>`;
    }
  }
}

/**
 * Marca un campo del formulario como erróneo y lo enfoca.
 * @param {string} campo Nombre del campo devuelto por el API.
 */
function marcarError(campo) {
  const el = document.querySelector(`#createForm [name="${campo}"]`);
  if (!el) return;
  el.focus();
  el.style.borderColor = 'var(--red)';
  el.addEventListener('input', () => { el.style.borderColor = ''; }, { once: true });
}

/** Conecta el formulario con POST /api/torneo/crear o .../{id}/editar. */
function initCreateForm() {
  const form = document.getElementById('createForm');
  if (!form) return;

  form.addEventListener('submit', async e => {
    e.preventDefault();

    const editando  = editandoId;
    const submitBtn = form.querySelector('button[type="submit"]');
    const original  = submitBtn ? submitBtn.textContent : '';
    if (submitBtn) {
      submitBtn.disabled = true;      // Evita el doble envío (torneo duplicado).
      submitBtn.textContent = editando ? 'Guardando…' : 'Creando…';
    }

    const campos = Object.fromEntries(new FormData(form).entries());

    try {
      const url  = editando ? `/api/torneo/${editando}/editar` : '/api/torneo/crear';
      const data = await Api.post(url, campos);
      Toast.success(data.mensaje || (editando ? 'Torneo actualizado.' : 'Torneo creado.'));
      if (data.torneo) {
        if (editando) {
          const i = torneos.findIndex(t => String(t.id) === String(editando));
          if (i !== -1) torneos[i] = data.torneo; else torneos.unshift(data.torneo);
        } else {
          torneos.unshift(data.torneo); // El listado va por fecha de creación desc.
        }
        renderTorneos();
      } else {
        await cargarTorneos();
      }
      resetFormularioCreacion();
      mostrarCreateCard(false);          // Repliega el formulario (panel del admin).
      window.setSection(seccionDeLista());
    } catch (err) {
      Toast.error(err.message);
      if (err.data && err.data.campo) marcarError(err.data.campo);
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = original;
      }
    }
  });
}

/**
 * Sube la foto de fondo del torneo que se está editando
 * (POST /api/torneo/{id}/banner). Solo tiene sentido en modo edición: sin
 * editandoId no hay id real todavía contra el que subir la imagen.
 */
function initBannerUpload() {
  const input = document.getElementById('torneoBannerInput');
  const btn   = document.getElementById('torneoBannerBtn');
  if (!input || !btn) return;

  btn.addEventListener('click', () => input.click());

  input.addEventListener('change', async () => {
    const file = input.files && input.files[0];
    input.value = '';
    if (!file || !editandoId) return;

    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
      Toast.error('Formato no admitido. Usá JPG, PNG o WebP.');
      return;
    }
    if (file.size > 4 * 1024 * 1024) {
      Toast.error('La imagen no puede superar los 4 MB.');
      return;
    }

    const data = new FormData();
    data.append('banner', file);
    try {
      const res = await fetch(`/api/torneo/${editandoId}/banner`, {
        method: 'POST',
        body: data,
        credentials: 'same-origin',
        headers: Utils.csrfHeaders(),
      });
      const json = await res.json();
      if (!json.success) throw new Error(json.error || 'No se pudo subir la imagen.');
      pintarBannerPreview(json.banner_url);
      const i = torneos.findIndex(t => String(t.id) === String(editandoId));
      if (i !== -1) torneos[i].banner_url = json.banner_url;
      Toast.success('Foto de fondo actualizada.');
    } catch (err) {
      Toast.error(err.message);
    }
  });
}

document.addEventListener('DOMContentLoaded', () => {
  if (!document.getElementById('misTorneos') && !document.getElementById('createForm')) return;

  /* Botón "+ Nuevo torneo" del panel del admin: alterna el formulario. */
  document.querySelectorAll('[data-toggle-create]').forEach(btn =>
    btn.addEventListener('click', () => {
      const card = document.getElementById('createCard');
      const mostrar = card ? card.classList.contains('hidden') : true;
      if (mostrar) resetFormularioCreacion();
      mostrarCreateCard(mostrar);
    })
  );

  /* Entradas al formulario "en limpio" (no vía Editar): topbar, sidebar y
     el enlace del estado vacío. Vuelven el formulario a modo creación. */
  document.querySelectorAll('[data-goto="crear"], .sidebar__item[data-section="crear"]').forEach(el =>
    el.addEventListener('click', resetFormularioCreacion)
  );

  initCreateForm();
  initBannerUpload();
  cargarTorneos();
});

})();
