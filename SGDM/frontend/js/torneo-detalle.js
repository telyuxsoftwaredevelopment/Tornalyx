/* =====================================================
   TORNALYX – torneo-detalle.js
   Página de detalle de un torneo (/torneo-detalle?id=N):
   carga desde GET /api/torneo/{id} y pinta cabecera,
   fixture, posiciones, participantes, novedades e
   información. Permite inscribirse en línea y confirmar
   asistencia a cada enfrentamiento.
   ===================================================== */

'use strict';

/* Envuelto en IIFE (como el resto de los scripts de página): un
   `const Api` de nivel superior en dos <script> clásicos que comparten
   el scope global choca con el `const Api` de main.js y tira
   "Identifier 'Api' has already been declared", abortando todo el
   archivo antes de que corra initDetalle. */
(function () {

const { Api, Toast, Utils } = window.Tornalyx;
const esc = Utils.escapeHtml;

/* Torneo cargado, para recargar tras inscribirse o confirmar asistencia. */
let datos = null;

/** Lee y valida el id del torneo de la query string. */
function torneoId() {
  const id = new URLSearchParams(window.location.search).get('id');
  return /^\d+$/.test(id || '') ? id : null;
}

/** Bloque de estado vacío reutilizable. */
function vacio(mensaje) {
  return `<p class="doc-empty">${esc(mensaje)}</p>`;
}

/** Etiqueta y color del estado de un enfrentamiento (RF-13). */
const ESTADO_PARTIDO = {
  pendiente:  ['Pendiente', 'badge-gray'],
  en_curso:   ['En juego',  'badge-green'],
  finalizado: ['Finalizado', 'badge-blue'],
  aplazado:   ['Aplazado',  'badge-yellow'],
};

/** Fecha y hora de un partido, en formato local. */
function fechaHora(valor) {
  if (!valor) return '';
  const d = new Date(String(valor).replace(' ', 'T'));
  if (isNaN(d)) return '';
  return d.toLocaleString('es-UY', {
    day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit'
  });
}

/* ─── Cabecera (hero + breadcrumb) ─────────────────── */
function renderHero(t) {
  const estado = TorneoUI.estado(t.estado);

  document.title = 'Tornalyx | ' + t.nombre;
  const bc = document.getElementById('bcNombre');
  bc.textContent = t.nombre;
  bc.style.color = 'var(--ink)';
  document.getElementById('heroNombre').textContent = t.nombre;

  const chip = txt =>
    `<span class="badge" style="background:rgba(255,255,255,.06);color:var(--muted);border:1px solid var(--line)">${txt}</span>`;
  document.getElementById('heroBadges').innerHTML = [
    `<span class="badge ${estado.badge}">${esc(estado.label)}</span>`,
    chip(`${TorneoUI.icono(t.disciplina)} ${esc(t.disciplina)}`),
    chip(esc(TorneoUI.formato(t.formato))),
    t.requiere_equipos ? chip('Por equipos') : chip('Individual')
  ].join('');

  const desc = document.getElementById('heroDesc');
  if (t.descripcion) {
    desc.textContent = t.descripcion;
    desc.style.display = '';
  }

  const org = [t.org_nombre, t.org_apellido].filter(Boolean).join(' ');
  const meta = [];
  const inicio = TorneoUI.fecha(t.fecha_inicio);
  const fin = TorneoUI.fecha(t.fecha_fin);
  if (inicio) meta.push(`📅 Inicio: ${esc(inicio)}`);
  if (fin) meta.push(`🏁 Finaliza: ${esc(fin)}`);
  if (org) meta.push(`🎽 Organiza: ${esc(org)}`);
  document.getElementById('heroMeta').innerHTML = meta.map(m => `<span>${m}</span>`).join('');
}

/* ─── Inscripción en línea (RF-02, RF-03, RF-06) ───── */
function renderInscripcion(t, miInscripcion, equipos) {
  const box = document.getElementById('heroAction');

  if (miInscripcion) {
    const estados = {
      pendiente: ['Inscripción pendiente de aprobación', 'badge-yellow'],
      aprobada:  ['¡Estás inscripto!', 'badge-green'],
      rechazada: ['Tu inscripción fue rechazada', 'badge-gray'],
    };
    const [texto, badge] = estados[miInscripcion.estado] || ['Inscripto', 'badge-gray'];
    const puedeCancelar = t.estado === 'inscripcion' && miInscripcion.estado !== 'rechazada';
    box.innerHTML = `
      <div class="inscribir-box">
        <h4>Tu inscripción</h4>
        <span class="badge ${badge}">${esc(texto)}</span>
        ${puedeCancelar
          ? '<button class="btn btn-ghost btn-sm" id="btnCancelar" style="margin-top:var(--space-4);width:100%">Cancelar inscripción</button>'
          : ''}
      </div>`;
    document.getElementById('btnCancelar')?.addEventListener('click', cancelarInscripcion);
    return;
  }

  if (t.estado !== 'inscripcion') {
    box.innerHTML = '';
    return;
  }

  // Torneo por equipos: elegir uno existente o crear el propio.
  const selectorEquipo = t.requiere_equipos ? `
    <div class="form-group">
      <label class="form-label" for="equipoSel">Equipo</label>
      <select class="form-control" id="equipoSel">
        <option value="">— Crear un equipo nuevo —</option>
        ${equipos.map(e => `<option value="${e.id}">${esc(e.nombre)}</option>`).join('')}
      </select>
    </div>
    <div class="form-group" id="equipoNuevoBox">
      <label class="form-label" for="equipoNombre">Nombre del equipo</label>
      <input class="form-control" type="text" id="equipoNombre" maxlength="80" placeholder="ej: Atlético Norte" />
    </div>` : '';

  box.innerHTML = `
    <div class="inscribir-box">
      <h4>Inscribirse</h4>
      <p style="font-size:12px;color:var(--muted-2);margin-bottom:var(--space-4)">
        ${TorneoUI.participantes(t)} de ${t.max_participantes} cupos ocupados
      </p>
      ${selectorEquipo}
      <button class="btn btn-primary btn-full" id="btnInscribir">Inscribirme</button>
    </div>`;

  const sel = document.getElementById('equipoSel');
  const nuevoBox = document.getElementById('equipoNuevoBox');
  sel?.addEventListener('change', () => {
    nuevoBox.style.display = sel.value ? 'none' : '';
  });
  document.getElementById('btnInscribir').addEventListener('click', inscribirse);
}

async function inscribirse() {
  const campos = { torneo_id: torneoId() };
  const sel = document.getElementById('equipoSel');
  if (sel) {
    if (sel.value) {
      campos.equipo_id = sel.value;
    } else {
      const nombre = document.getElementById('equipoNombre').value.trim();
      if (!nombre) {
        Toast.error('Poné el nombre de tu equipo o elegí uno de la lista.');
        return;
      }
      campos.equipo_nombre = nombre;
    }
  }

  try {
    const res = await Api.post('/api/inscripcion', campos);
    Toast.success(res.mensaje || 'Inscripción registrada.');
    await cargar();
  } catch (err) {
    // Sin sesión el API responde 401 y Api ya redirige al login.
    Toast.error(err.message);
  }
}

async function cancelarInscripcion() {
  try {
    const res = await Api.post('/api/inscripcion/cancelar', { torneo_id: torneoId() });
    Toast.success(res.mensaje || 'Inscripción cancelada.');
    await cargar();
  } catch (err) {
    Toast.error(err.message);
  }
}

/* ─── Info boxes ───────────────────────────────────── */
function renderInfo(t) {
  const participantes = TorneoUI.participantes(t);
  const jugados = Number(t.partidos_jugados) || 0;
  const total = Number(t.partidos) || 0;
  const progreso = TorneoUI.progreso(t);

  const boxes = [
    [`${participantes} / ${t.max_participantes}`, 'Participantes'],
    [total ? `${jugados} / ${total}` : '—', 'Partidos jugados'],
    [total ? `${progreso}%` : '—', 'Progreso']
  ];
  document.getElementById('infoGrid').innerHTML = boxes.map(([valor, label]) =>
    `<div class="info-box">
       <div class="info-box__value">${esc(String(valor))}</div>
       <div class="info-box__label">${esc(label)}</div>
     </div>`
  ).join('');
}

/* ─── Fixture: emparejamientos por ronda (RF-07/08/13/25/26) ─── */
function renderFixture(partidos) {
  const box = document.getElementById('tab-fixture');
  if (!partidos.length) {
    box.innerHTML = vacio('Todavía no se publicó el fixture. Cuando el organizador lo genere vas a ver acá tu rival, la fecha y el lugar.');
    return;
  }

  const grupos = new Map();
  partidos.forEach(p => {
    const clave = p.ronda_nombre || ('Ronda ' + p.ronda);
    if (!grupos.has(clave)) grupos.set(clave, []);
    grupos.get(clave).push(p);
  });

  box.innerHTML = [...grupos.entries()].map(([ronda, lista]) => `
    <h3 style="margin:var(--space-5) 0 var(--space-4);color:var(--ink)">${esc(ronda)}</h3>
    ${lista.map(filaPartido).join('')}
  `).join('');

  box.querySelectorAll('[data-asistir]').forEach(btn => {
    btn.addEventListener('click', () => confirmarAsistencia(btn.dataset.asistir));
  });
}

function filaPartido(p) {
  const [txtEstado, badge] = ESTADO_PARTIDO[p.estado] || [p.estado, 'badge-gray'];
  const marcador = p.goles_local !== null && p.goles_local !== undefined
    ? `${Number(p.goles_local)} · ${Number(p.goles_visitante)}`
    : 'vs';

  const meta = [];
  const cuando = fechaHora(p.fecha_partido);
  meta.push(cuando ? `📅 ${esc(cuando)}` : '📅 Horario a confirmar');
  meta.push(p.lugar ? `📍 ${esc(p.lugar)}` : '📍 Lugar a confirmar');
  meta.push(`<span class="badge ${badge}" style="font-size:11px">${esc(txtEstado)}</span>`);

  // Solo tiene sentido confirmar asistencia antes de jugar.
  const asistir = p.estado === 'pendiente'
    ? `<button class="btn btn-ghost btn-sm" data-asistir="${p.id}">Confirmar asistencia</button>`
    : '';

  return `
    <div class="partido-row">
      <span class="partido-row__side" style="text-align:right">${esc(p.local_nombre || '—')}</span>
      <div class="partido-score">${esc(marcador)}</div>
      <span class="partido-row__side">${esc(p.visitante_nombre || '—')}</span>
      <div class="partido-row__meta">${meta.join('')}${asistir}</div>
    </div>`;
}

async function confirmarAsistencia(partidoId) {
  try {
    const res = await Api.post('/api/partido/asistencia', {
      partido_id: partidoId,
      estado: 'confirmada',
    });
    Toast.success(res.mensaje || 'Asistencia confirmada.');
  } catch (err) {
    Toast.error(err.message);
  }
}

/* ─── Posiciones ───────────────────────────────────── */
function badgePos(indice) {
  const n = indice + 1;
  return n <= 3 ? `<span class="pos-badge pos-${n}">${n}</span>` : String(n);
}

function renderPosiciones(rows) {
  const box = document.getElementById('tab-posiciones');
  if (!rows.length) {
    box.innerHTML = `<div class="card"><div class="card__body">${vacio('Aún no hay posiciones. Se calculan solas a medida que se cargan los resultados.')}</div></div>`;
    return;
  }
  const filas = rows.map((p, i) => `
    <tr>
      <td>${badgePos(i)}</td>
      <td><span class="equipo-tag">${esc(p.contendiente || '—')}</span></td>
      <td>${Number(p.pj)}</td><td>${Number(p.pg)}</td><td>${Number(p.pe)}</td><td>${Number(p.pp)}</td>
      <td>${Number(p.gf)}</td><td>${Number(p.gc)}</td>
      <td>${Number(p.dg) > 0 ? '+' : ''}${Number(p.dg)}</td>
      <td><strong style="font-family:var(--head);color:var(--ink)">${Number(p.pts)}</strong></td>
    </tr>`).join('');

  box.innerHTML = `
    <div class="card">
      <div class="card__header"><h3>Tabla de posiciones</h3></div>
      <div style="overflow-x:auto">
        <table class="table-pos">
          <thead>
            <tr><th>#</th><th>Equipo / Participante</th><th>PJ</th><th>G</th><th>E</th><th>P</th><th>GF</th><th>GC</th><th>DG</th><th>Pts</th></tr>
          </thead>
          <tbody>${filas}</tbody>
        </table>
      </div>
      <div class="card__footer" style="font-size:12px;color:var(--muted-2);font-family:var(--mono)">
        PJ: Jugados · G: Ganados · E: Empatados · P: Perdidos · GF/GC: A favor/En contra · DG: Diferencia · Pts: Puntos
      </div>
    </div>`;
}

/* ─── Participantes / equipos ──────────────────────── */
function renderEquipos(equipos, torneo) {
  const box = document.getElementById('tab-equipos');
  if (!equipos.length) {
    box.innerHTML = vacio(torneo.requiere_equipos
      ? 'Todavía no hay equipos confirmados.'
      : 'Todavía no hay participantes confirmados.');
    return;
  }
  box.innerHTML = `<div class="cards-grid">${equipos.map(e => `
    <div class="card"><div class="card__body">
      <h4 style="color:var(--ink);margin-bottom:6px">🛡️ ${esc(e.nombre)}</h4>
      ${e.capitan ? `<p style="font-size:12px;color:var(--muted-2)">Capitán: ${esc(e.capitan)}</p>` : ''}
      ${e.jugadores !== undefined ? `<p style="font-size:12px;color:var(--muted-2)">${Number(e.jugadores)} jugador(es)</p>` : ''}
    </div></div>`).join('')}</div>`;
}

/* ─── Novedades del torneo (RF-10, RF-20) ──────────── */
function renderAvisos(avisos) {
  const box = document.getElementById('tab-avisos');
  if (!avisos.length) {
    box.innerHTML = vacio('Todavía no hay novedades publicadas.');
    return;
  }
  box.innerHTML = avisos.map(a => `
    <div class="card" style="margin-bottom:var(--space-3)"><div class="card__body">
      <div style="display:flex;justify-content:space-between;gap:var(--space-3);flex-wrap:wrap">
        <h4 style="color:var(--ink)">${esc(a.titulo)}</h4>
        <span style="font-size:12px;color:var(--muted-2)">${esc(Utils.timeAgo(a.created_at))}</span>
      </div>
      ${a.cuerpo ? `<p style="color:var(--muted);margin-top:var(--space-2);font-size:var(--font-size-sm)">${esc(a.cuerpo)}</p>` : ''}
    </div></div>`).join('');
}

/* ─── Información: datos, reglamento, premios y canal ─ */
function renderInfoTab(t) {
  const org = [t.org_nombre, t.org_apellido].filter(Boolean).join(' ');
  const filas = [
    ['Disciplina', t.disciplina],
    ['Formato', TorneoUI.formato(t.formato)],
    ['Modalidad', t.requiere_equipos ? 'Por equipos' : 'Individual'],
    ['Estado', TorneoUI.estado(t.estado).label],
    ['Máximo de participantes', t.max_participantes],
    ['Inicio', TorneoUI.fecha(t.fecha_inicio) || '—'],
    ['Fin', TorneoUI.fecha(t.fecha_fin) || '—'],
    ['Organiza', org || '—']
  ];

  const bloque = (titulo, texto) => texto
    ? `<div class="card" style="margin-top:var(--space-4)"><div class="card__body">
         <h3 style="margin-bottom:var(--space-3);color:var(--ink)">${esc(titulo)}</h3>
         <p class="prosa">${esc(texto)}</p>
       </div></div>`
    : '';

  const canal = t.discord_url
    ? `<div class="card" style="margin-top:var(--space-4)"><div class="card__body">
         <h3 style="margin-bottom:var(--space-3);color:var(--ink)">Canal oficial</h3>
         <a class="btn btn-ghost btn-sm" href="${esc(t.discord_url)}" target="_blank" rel="noopener noreferrer">
           Entrar al canal del torneo
         </a>
       </div></div>`
    : '';

  document.getElementById('tab-info').innerHTML = `
    <div class="card"><div class="card__body">
      <h3 style="margin-bottom:var(--space-4);color:var(--ink)">Información del torneo</h3>
      ${t.descripcion ? `<p class="prosa" style="margin-bottom:var(--space-5)">${esc(t.descripcion)}</p>` : ''}
      <ul style="display:flex;flex-direction:column;gap:var(--space-3)">
        ${filas.map(([k, v]) => `
          <li style="display:flex;justify-content:space-between;gap:var(--space-4);color:var(--muted);font-size:var(--font-size-sm);border-bottom:1px solid var(--line-soft);padding-bottom:var(--space-2)">
            <span>${esc(k)}</span>
            <span style="color:var(--ink);font-weight:500">${esc(String(v))}</span>
          </li>`).join('')}
      </ul>
    </div></div>
    ${bloque('Reglamento', t.reglamento)}
    ${bloque('Premios', t.premios)}
    ${canal}`;
}

/* ─── Placeholder mientras se espera al API ──────────── */
function skeletonInicial() {
  const badges = document.getElementById('heroBadges');
  const nombre = document.getElementById('heroNombre');
  const fixture = document.getElementById('tab-fixture');
  if (badges) {
    badges.innerHTML = '<span class="skeleton" style="height:22px;width:100px;border-radius:999px"></span>'
      + '<span class="skeleton" style="height:22px;width:80px;border-radius:999px"></span>';
  }
  if (nombre) {
    nombre.innerHTML = '<span class="skeleton" style="display:inline-block;height:1.1em;width:min(420px,60%)"></span>';
  }
  if (fixture) {
    fixture.innerHTML = Array.from({ length: 3 }, () => `
      <div style="display:flex;justify-content:space-between;gap:var(--space-3);
                  border:1px solid var(--line);border-radius:12px;
                  padding:var(--space-3);margin-bottom:var(--space-2)">
        <span class="skeleton" style="height:16px;width:45%"></span>
        <span class="skeleton" style="height:16px;width:20%"></span>
      </div>`).join('');
  }
}

/* ─── Carga ────────────────────────────────────────── */
async function cargar() {
  const id = torneoId();
  datos = await Api.get('/api/torneo/' + id);

  renderHero(datos.torneo);
  renderInfo(datos.torneo);
  renderInscripcion(datos.torneo, datos.mi_inscripcion, datos.equipos || []);
  renderFixture(datos.partidos || []);
  renderPosiciones(datos.posiciones || []);
  renderEquipos(datos.equipos || [], datos.torneo);
  renderAvisos(datos.avisos || []);
  renderInfoTab(datos.torneo);
}

async function initDetalle() {
  const root = document.getElementById('detalleRoot');
  if (!root) return;

  const error = mensaje => {
    root.innerHTML = `<div class="wrap" style="padding:var(--space-12) 0;text-align:center">
      ${vacio(mensaje)}
      <a class="btn btn-ghost btn-sm" href="/torneos">Ver todos los torneos</a></div>`;
  };

  if (!torneoId()) {
    error('No se indicó qué torneo ver.');
    return;
  }

  skeletonInicial();

  try {
    await cargar();
  } catch (err) {
    error(err.status === 404 ? 'Torneo no encontrado.' : (err.message || 'No se pudo cargar el torneo.'));
  }
}

document.addEventListener('DOMContentLoaded', initDetalle);

})();
