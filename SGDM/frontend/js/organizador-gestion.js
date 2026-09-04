/* =====================================================
   TORNALYX – organizador-gestion.js
   Gestión de un torneo desde el panel del organizador:
   inscripciones, fixture (generar, programar, cargar
   resultados), tabla de posiciones y avisos.
   Todo contra el API, sin datos de ejemplo.
   ===================================================== */

'use strict';

(function () {

const { Api, Toast, Utils } = window.Tornalyx;
const esc = Utils.escapeHtml;
const $ = id => document.getElementById(id);

/** Torneo actualmente seleccionado en los <select> del panel. */
let torneoActual = null;

const ESTADO_INSCRIPCION = {
  pendiente: ['Pendiente', 'badge-yellow'],
  aprobada:  ['Aprobada',  'badge-green'],
  rechazada: ['Rechazada', 'badge-gray'],
};
const ESTADO_PARTIDO = {
  pendiente:  ['Pendiente',  'badge-gray'],
  en_curso:   ['En juego',   'badge-green'],
  finalizado: ['Finalizado', 'badge-blue'],
  aplazado:   ['Aplazado',   'badge-yellow'],
};

function badge(mapa, clave) {
  const [texto, clase] = mapa[clave] || [clave, 'badge-gray'];
  return `<span class="badge ${clase}">${esc(texto)}</span>`;
}

/** Valor para <input type="datetime-local"> a partir de un DATETIME de MySQL. */
function paraInput(valor) {
  return valor ? String(valor).replace(' ', 'T').slice(0, 16) : '';
}

/* ─── Inscripciones ────────────────────────────────── */
async function cargarInscripciones() {
  const body = $('inscripcionesBody');
  if (!body || !torneoActual) return;

  try {
    const data = await Api.get(`/api/torneo/${torneoActual}/inscripciones`);
    const filas = data.inscripciones || [];
    $('inscripcionesEmpty').classList.toggle('hidden', filas.length > 0);

    body.innerHTML = filas.map(i => {
      const nombre = `${i.nombre} ${i.apellido || ''}`.trim();
      const acciones = i.estado === 'pendiente'
        ? `<button class="btn btn-primary btn-sm" data-aprobar="${i.id}">Aprobar</button>
           <button class="btn btn-ghost btn-sm" data-rechazar="${i.id}">Rechazar</button>`
        : '<span style="color:var(--muted-2);font-size:12px">Resuelta</span>';
      return `
        <tr>
          <td>${esc(nombre)}<div style="font-size:12px;color:var(--muted-2)">${esc(i.email)}</div></td>
          <td>${esc(i.equipo || 'Individual')}</td>
          <td style="font-family:var(--mono);font-size:12px">${esc(Utils.formatDate(i.fecha_inscripcion))}</td>
          <td>${badge(ESTADO_INSCRIPCION, i.estado)}</td>
          <td style="display:flex;gap:6px;flex-wrap:wrap">${acciones}</td>
        </tr>`;
    }).join('');

    body.querySelectorAll('[data-aprobar]').forEach(b =>
      b.addEventListener('click', () => resolverInscripcion(b.dataset.aprobar, 'aprobar')));
    body.querySelectorAll('[data-rechazar]').forEach(b =>
      b.addEventListener('click', () => resolverInscripcion(b.dataset.rechazar, 'rechazar')));
  } catch (err) {
    Toast.error(err.message);
  }
}

async function resolverInscripcion(id, accion) {
  try {
    const res = await Api.post('/api/inscripcion/resolver', { id, accion });
    Toast.success(res.mensaje || 'Listo.');
    await cargarInscripciones();
  } catch (err) {
    Toast.error(err.message);
  }
}

/* ─── Fixture ──────────────────────────────────────── */
async function cargarFixture() {
  const cont = $('fixtureLista');
  if (!cont || !torneoActual) return;

  try {
    const data = await Api.get(`/api/torneo/${torneoActual}/partidos`);
    pintarFixture(data.partidos || []);
  } catch (err) {
    cont.innerHTML = `<p style="color:var(--muted)">${esc(err.message)}</p>`;
  }
}

function pintarFixture(partidos) {
  const cont = $('fixtureLista');
  if (!partidos.length) {
    cont.innerHTML = `<div class="card"><div class="card__body" style="color:var(--muted);text-align:center">
      Todavía no generaste el fixture de este torneo.
    </div></div>`;
    return;
  }

  const grupos = new Map();
  partidos.forEach(p => {
    const clave = p.ronda_nombre || ('Ronda ' + p.ronda);
    if (!grupos.has(clave)) grupos.set(clave, []);
    grupos.get(clave).push(p);
  });

  cont.innerHTML = [...grupos.entries()].map(([ronda, lista]) => `
    <div class="card" style="margin-bottom:var(--space-4)">
      <div class="card__header"><h3>${esc(ronda)}</h3></div>
      <div class="card__body" style="display:flex;flex-direction:column;gap:var(--space-4)">
        ${lista.map(filaPartido).join('')}
      </div>
    </div>`).join('');

  cont.querySelectorAll('[data-guardar]').forEach(b =>
    b.addEventListener('click', () => guardarPartido(b.dataset.guardar)));
}

function filaPartido(p) {
  const gl = p.goles_local ?? '';
  const gv = p.goles_visitante ?? '';
  return `
    <div data-partido="${p.id}" style="border:1px solid var(--line);border-radius:12px;padding:var(--space-4)">
      <div style="display:flex;justify-content:space-between;gap:var(--space-3);align-items:center;flex-wrap:wrap;margin-bottom:var(--space-3)">
        <strong style="color:var(--ink)">${esc(p.local_nombre || '—')} vs ${esc(p.visitante_nombre || '—')}</strong>
        ${badge(ESTADO_PARTIDO, p.estado)}
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:var(--space-3)">
        <div class="form-group" style="margin:0">
          <label class="form-label" style="font-size:11px">Fecha y hora</label>
          <input class="form-control" type="datetime-local" data-campo="fecha" value="${esc(paraInput(p.fecha_partido))}" />
        </div>
        <div class="form-group" style="margin:0">
          <label class="form-label" style="font-size:11px">Lugar</label>
          <input class="form-control" type="text" data-campo="lugar" maxlength="120"
                 value="${esc(p.lugar || '')}" placeholder="Cancha, servidor o mesa" />
        </div>
        <div class="form-group" style="margin:0">
          <label class="form-label" style="font-size:11px">Estado</label>
          <select class="form-control" data-campo="estado">
            ${Object.entries(ESTADO_PARTIDO).map(([k, [txt]]) =>
              `<option value="${k}"${p.estado === k ? ' selected' : ''}>${esc(txt)}</option>`).join('')}
          </select>
        </div>
        <div class="form-group" style="margin:0">
          <label class="form-label" style="font-size:11px">Marcador</label>
          <div style="display:flex;gap:6px;align-items:center">
            <input class="form-control" type="number" min="0" data-campo="gl" value="${esc(String(gl))}" style="width:70px" />
            <span style="color:var(--muted-2)">·</span>
            <input class="form-control" type="number" min="0" data-campo="gv" value="${esc(String(gv))}" style="width:70px" />
          </div>
        </div>
      </div>
      <button class="btn btn-primary btn-sm" data-guardar="${p.id}" style="margin-top:var(--space-3)">Guardar</button>
    </div>`;
}

/**
 * Guarda los cambios de un partido: programación, estado y —si se cargó un
 * marcador— el resultado, que recalcula la tabla de posiciones.
 */
async function guardarPartido(partidoId) {
  const fila = document.querySelector(`[data-partido="${partidoId}"]`);
  const val = campo => fila.querySelector(`[data-campo="${campo}"]`).value.trim();

  try {
    await Api.post('/api/partido/programar', {
      partido_id: partidoId,
      fecha_partido: val('fecha'),
      lugar: val('lugar'),
    });

    const gl = val('gl');
    const gv = val('gv');
    if (gl !== '' && gv !== '') {
      // Cargar el resultado ya deja el partido como finalizado.
      const res = await Api.post('/api/partido/resultado', {
        partido_id: partidoId,
        goles_local: gl,
        goles_visitante: gv,
      });
      pintarFixture(res.partidos || []);
      Toast.success(res.mensaje || 'Resultado guardado.');
      await cargarPosiciones();
      return;
    }

    const res = await Api.post('/api/partido/estado', {
      partido_id: partidoId,
      estado: val('estado'),
    });
    pintarFixture(res.partidos || []);
    Toast.success('Enfrentamiento actualizado.');
  } catch (err) {
    Toast.error(err.message);
  }
}

async function generarFixture() {
  if (!torneoActual) return;
  const btn = $('btnFixture');
  btn.disabled = true;
  try {
    const res = await Api.post('/api/torneo/fixture', { torneo_id: torneoActual });
    Toast.success(res.mensaje || 'Fixture generado.');
    pintarFixture(res.partidos || []);
  } catch (err) {
    Toast.error(err.message);
  } finally {
    btn.disabled = false;
  }
}

/* ─── Posiciones ───────────────────────────────────── */
async function cargarPosiciones() {
  const body = $('posicionesBody');
  if (!body || !torneoActual) return;

  try {
    const data = await Api.get('/api/torneo/' + torneoActual);
    const filas = data.posiciones || [];
    $('posicionesEmpty').classList.toggle('hidden', filas.length > 0);

    body.innerHTML = filas.map((p, i) => {
      const medalla = ['gold', 'silver', 'bronze'][i] || '';
      return `
        <tr>
          <td><span class="pos-num ${medalla}">${i + 1}</span></td>
          <td><strong style="color:var(--ink)">${esc(p.contendiente || '—')}</strong></td>
          <td>${Number(p.pj)}</td><td>${Number(p.pg)}</td><td>${Number(p.pe)}</td><td>${Number(p.pp)}</td>
          <td>${Number(p.gf)}</td><td>${Number(p.gc)}</td>
          <td>${Number(p.dg) > 0 ? '+' : ''}${Number(p.dg)}</td>
          <td><strong style="font-family:var(--head);color:var(--ink)">${Number(p.pts)}</strong></td>
        </tr>`;
    }).join('');
  } catch (err) {
    Toast.error(err.message);
  }
}

/* ─── Avisos ───────────────────────────────────────── */
async function cargarAvisos() {
  const cont = $('avisosLista');
  if (!cont || !torneoActual) return;

  try {
    const data = await Api.get(`/api/torneo/${torneoActual}/avisos`);
    pintarAvisos(data.avisos || []);
  } catch (err) {
    cont.innerHTML = `<p style="color:var(--muted)">${esc(err.message)}</p>`;
  }
}

function pintarAvisos(avisos) {
  const cont = $('avisosLista');
  if (!avisos.length) {
    cont.innerHTML = '<p style="color:var(--muted);font-size:var(--font-size-sm)">Todavía no publicaste avisos en este torneo.</p>';
    return;
  }
  cont.innerHTML = avisos.map(a => `
    <div class="card" style="margin-bottom:var(--space-3)"><div class="card__body">
      <div style="display:flex;justify-content:space-between;gap:var(--space-3);flex-wrap:wrap">
        <strong style="color:var(--ink)">${esc(a.titulo)}</strong>
        <span style="font-size:12px;color:var(--muted-2)">${esc(Utils.timeAgo(a.created_at))}</span>
      </div>
      ${a.cuerpo ? `<p style="color:var(--muted);margin-top:6px;font-size:var(--font-size-sm)">${esc(a.cuerpo)}</p>` : ''}
    </div></div>`).join('');
}

/* ─── Sincronización con el torneo elegido ─────────── */
function seleccionar(id) {
  if (!id || id === torneoActual) return;
  torneoActual = id;
  document.querySelectorAll('[data-torneos-select]').forEach(sel => {
    if (sel.value !== id) sel.value = id;
  });
  cargarInscripciones();
  cargarFixture();
  cargarPosiciones();
  cargarAvisos();
}

document.addEventListener('DOMContentLoaded', () => {
  if (!$('inscripcionesBody')) return;   // no es el panel del organizador

  $('btnFixture')?.addEventListener('click', generarFixture);

  $('avisoForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    try {
      const res = await Api.post('/api/aviso/publicar', {
        torneo_id: torneoActual,
        titulo: $('avisoTitulo').value.trim(),
        cuerpo: $('avisoCuerpo').value.trim(),
      });
      Toast.success(res.mensaje || 'Aviso publicado.');
      e.target.reset();
      pintarAvisos(res.avisos || []);
    } catch (err) {
      Toast.error(err.message);
    }
  });

  /* organizador.js llena los <select> tras cargar los torneos; escuchamos
     los cambios y también tomamos el primero disponible al inicio. */
  document.querySelectorAll('[data-torneos-select]').forEach(sel => {
    sel.addEventListener('change', () => seleccionar(sel.value));
  });

  const esperarTorneos = setInterval(() => {
    const sel = document.querySelector('[data-torneos-select]');
    if (sel && sel.value) {
      clearInterval(esperarTorneos);
      seleccionar(sel.value);
    }
  }, 300);
  setTimeout(() => clearInterval(esperarTorneos), 10000);
});

})();
