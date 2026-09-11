/* =====================================================
   TORNALYX – jugadores.js
   Buscador público de jugadores (/jugadores): busca por
   nombre y muestra la ficha con torneos, rendimiento y
   próximos enfrentamientos (rival, fecha y lugar).
   ===================================================== */

'use strict';

(function () {

const { Api, Utils } = window.Tornalyx;
const esc = Utils.escapeHtml;

const $ = id => document.getElementById(id);

/** Iniciales de un jugador, para cuando no tiene foto. */
function iniciales(j) {
  return (((j.nombre || '')[0] || '') + ((j.apellido || '')[0] || '')).toUpperCase() || '?';
}

/** Avatar: foto de perfil o iniciales sobre el rojo de marca. */
function avatarHtml(j, clase = 'jugador-avatar') {
  return j.avatar_url
    ? `<span class="${clase}" style="background-image:url(${esc(encodeURI(j.avatar_url))})" role="img" aria-label="${esc(j.nombre)}"></span>`
    : `<span class="${clase}">${esc(iniciales(j))}</span>`;
}

/** Íconos de redes sociales del jugador, los que tenga cargados. */
const REDES_ICONS = {
  twitter_url: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4l16 16M20 4L4 20"/></svg>',
  facebook_url: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 21v-7h2.5l.5-3H14V9a1.5 1.5 0 0 1 1.5-1.5H17V4.6C16.5 4.5 15.6 4.4 14.7 4.4c-2.5 0-4.2 1.6-4.2 4.4V11H8v3h2.5v7"/></svg>',
  instagram_url: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.3" cy="6.7" r="1"/></svg>',
    youtube_url: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="4"/><path d="M10 9l5 3-5 3z"/></svg>',
    tiktok_url: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.5 12.5a4 4 0 1 0 4 4V4c.6 2.6 2.7 4.4 5.5 4.6"/></svg>',
    kick_url: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 4v16M17 4l-6 8 6 8"/></svg>',
    twitch_url: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 3h16v11l-4 4h-3l-3 3H8v-3H4z"/><line x1="11" y1="8" x2="11" y2="12"/><line x1="15" y1="8" x2="15" y2="12"/></svg>',
};
const REDES_LABELS = {
  twitter_url: 'X / Twitter', facebook_url: 'Facebook', instagram_url: 'Instagram',
  youtube_url: 'YouTube', tiktok_url: 'TikTok', kick_url: 'Kick', twitch_url: 'Twitch',
};

function redesHtml(j) {
  return Object.keys(REDES_ICONS)
    .filter(k => j[k])
    .map(k => `<a href="${esc(j[k])}" target="_blank" rel="noopener noreferrer" aria-label="${REDES_LABELS[k]}" title="${REDES_LABELS[k]}">${REDES_ICONS[k]}</a>`)
    .join('');
}

/** Fecha y hora local de un enfrentamiento. */
function fechaHora(valor) {
  if (!valor) return 'Horario a confirmar';
  const d = new Date(String(valor).replace(' ', 'T'));
  if (isNaN(d)) return 'Horario a confirmar';
  return d.toLocaleString('es-UY', {
    day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit'
  });
}

/* ─── Placeholders mientras se espera al API ─────────── */
function skeletonJugadorCard() {
  return `
    <div class="jugador-card" aria-hidden="true" style="cursor:default">
      <span class="skeleton" style="width:48px;height:48px;border-radius:50%;flex:none"></span>
      <span style="flex:1">
        <span class="skeleton" style="display:block;height:14px;width:60%;margin-bottom:8px"></span>
        <span class="skeleton" style="display:block;height:11px;width:40%"></span>
      </span>
    </div>`;
}

function skeletonFicha() {
  const stats = Array.from({ length: 5 }, () => '<span class="skeleton" style="height:36px"></span>').join('');
  return `
    <div class="card" aria-hidden="true">
      <div class="card__body">
        <div style="display:flex;align-items:center;gap:var(--space-4)">
          <span class="skeleton" style="width:64px;height:64px;border-radius:50%;flex:none"></span>
          <div style="flex:1">
            <span class="skeleton" style="display:block;height:20px;width:50%;margin-bottom:8px"></span>
            <span class="skeleton" style="display:block;height:14px;width:30%"></span>
          </div>
        </div>
      </div>
      <div class="card__footer" style="display:grid;grid-template-columns:repeat(5,1fr);gap:var(--space-2)">
        ${stats}
      </div>
    </div>`;
}

/* ─── Listado de resultados ────────────────────────── */
function renderResultados(jugadores) {
  const cont = $('resultados');
  if (!jugadores.length) {
    cont.innerHTML = `
      <div class="empty-state" style="padding:var(--space-8) var(--space-4)">
        <div class="icon">🔍</div>
        <h3 style="color:var(--ink);margin-bottom:8px;font-size:var(--font-size-base)">Sin resultados</h3>
        <p style="font-size:var(--font-size-sm)">No se encontraron jugadores con ese nombre.</p>
      </div>`;
    return;
  }
  cont.innerHTML = jugadores.map(j => {
    const displayNombre = j.nickname ? `${j.nickname}${j.tag ? ' #' + j.tag : ''}` : `${j.nombre} ${j.apellido || ''}`.trim();
    const sub = j.nickname ? `${j.nombre} ${j.apellido || ''}`.trim() + ' · ' : '';
    const esKratos = Utils.esPerfilKratos(j);
    return `
    <button class="jugador-card" data-id="${j.id}">
      ${avatarHtml(j)}
      <span style="flex:1;min-width:0">
        <span style="display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap">
          <span class="jugador-card__nombre">${esc(displayNombre)}</span>
          ${esKratos ? Utils.spidermanEasterEggHtml('mini') : ''}
        </span>
        <span class="jugador-card__meta" style="display:block">
          ${esc(sub)}${esc(j.rol)}${j.ubicacion ? ' · ' + esc(j.ubicacion) : ''}
        </span>
      </span>
    </button>`;
  }).join('');

  cont.querySelectorAll('.jugador-card').forEach(card => {
    card.addEventListener('click', () => {
      cont.querySelectorAll('.jugador-card').forEach(c => c.classList.remove('active'));
      card.classList.add('active');
      abrirFicha(card.dataset.id);
    });
  });
}

/* ─── Ficha del jugador ────────────────────────────── */
function renderFicha(data) {
  const j = data.jugador;
  const r = data.resumen || {};
  const nombrePrincipal = j.nickname ? `${j.nickname}${j.tag ? ' #' + j.tag : ''}` : `${j.nombre} ${j.apellido || ''}`.trim();
  const subNombre = j.nickname ? `${j.nombre} ${j.apellido || ''}`.trim() : null;
  const redes = redesHtml(j);

  const stats = [
    [r.pj || 0, 'Jugados'],
    [r.pg || 0, 'Ganados'],
    [r.pe || 0, 'Empatados'],
    [r.pp || 0, 'Perdidos'],
    [r.pts || 0, 'Puntos'],
  ];

  // Próximos enfrentamientos: quién es el rival y cuándo se juega.
  const proximos = (data.proximos || []).map(p => {
    const esLocal = String(p.local_id) === String(j.id);
    const rival = esLocal ? p.visitante_nombre : p.local_nombre;
    return `
      <div class="partido-row" style="display:flex;justify-content:space-between;gap:var(--space-3);flex-wrap:wrap;
                  border:1px solid var(--line);border-radius:12px;padding:var(--space-3);margin-bottom:var(--space-2);background:var(--bg-card)">
        <div>
          <div style="color:var(--ink);font-weight:600">vs ${esc(rival || '—')}</div>
          <div style="font-size:12px;color:var(--muted-2)">
            ${esc(p.torneo)} · ${esc(p.ronda_nombre || 'Ronda ' + p.ronda)}
          </div>
        </div>
        <div style="text-align:right;font-size:12px;color:var(--muted-2)">
          <div>📅 ${esc(fechaHora(p.fecha_partido))}</div>
          <div>📍 ${esc(p.lugar || 'Lugar a confirmar')}</div>
        </div>
      </div>`;
  }).join('');

  const torneos = (data.torneos || []).map(t => `
    <li style="display:flex;justify-content:space-between;gap:var(--space-3);padding:var(--space-2) 0;border-bottom:1px solid var(--line-soft)">
      <span style="color:var(--ink)">${esc(t.nombre)}</span>
      <span style="font-size:12px;color:var(--muted-2)">${esc(t.disciplina)} · ${esc(TorneoUI.estado(t.estado).label)}</span>
    </li>`).join('');

  $('ficha').innerHTML = `
    <div class="card">
      <div class="card__body">
        <div style="display:flex;align-items:center;gap:var(--space-4);flex-wrap:wrap">
          ${avatarHtml(j, 'jugador-avatar')}
          <div style="flex:1;min-width:0">
            <div style="display:inline-flex;align-items:center;gap:8px;position:relative;flex-wrap:wrap">
              <h2 style="font-size:var(--font-size-2xl);color:var(--ink);margin:0;display:inline-block">${esc(nombrePrincipal)}</h2>
              ${Utils.esPerfilKratos(j) ? Utils.spidermanEasterEggHtml() : ''}
            </div>
            <div style="font-size:var(--font-size-sm);color:var(--muted)">
              ${subNombre ? '👤 ' + esc(subNombre) + ' · ' : ''}${esc(j.rol)}${j.ubicacion ? ' · 📍 ' + esc(j.ubicacion) : ''}
            </div>
          </div>
        </div>
        ${j.bio ? `<p class="prosa" style="margin-top:var(--space-4)">${esc(j.bio)}</p>` : ''}
        ${redes ? `<div class="profile-redes">${redes}</div>` : ''}
      </div>
      <div class="card__footer" style="display:grid;grid-template-columns:repeat(5,1fr);gap:var(--space-2)">
        ${stats.map(([v, l]) => `
          <div class="ficha-stat">
            <div class="ficha-stat__valor">${Number(v)}</div>
            <div class="ficha-stat__label">${esc(l)}</div>
          </div>`).join('')}
      </div>
    </div>

    <div class="card" style="margin-top:var(--space-4)">
      <div class="card__header"><h3>Próximos enfrentamientos</h3></div>
      <div class="card__body">
        ${proximos || '<p style="color:var(--muted);font-size:var(--font-size-sm)">No tiene enfrentamientos programados.</p>'}
      </div>
    </div>

    <div class="card" style="margin-top:var(--space-4)">
      <div class="card__header"><h3>Torneos</h3></div>
      <div class="card__body">
        ${torneos ? `<ul>${torneos}</ul>` : '<p style="color:var(--muted);font-size:var(--font-size-sm)">Todavía no participa de ningún torneo.</p>'}
      </div>
    </div>`;
}

async function abrirFicha(id) {
  $('ficha').innerHTML = skeletonFicha();
  try {
    renderFicha(await Api.get('/api/jugador/' + id));
  } catch (err) {
    $('ficha').innerHTML = `<p style="color:var(--muted)">${esc(err.message)}</p>`;
  }
}

/* ─── Init ─────────────────────────────────────────── */
async function buscar(q) {
  if (q.trim().length < 2) {
    $('resultados').innerHTML = '<p style="color:var(--muted);font-size:var(--font-size-sm)">Escribí al menos 2 letras para buscar.</p>';
    return;
  }
  $('resultados').innerHTML = Array.from({ length: 3 }, skeletonJugadorCard).join('');
  try {
    const data = await Api.get('/api/jugadores?q=' + encodeURIComponent(q.trim()));
    renderResultados(data.jugadores || []);
  } catch (err) {
    $('resultados').innerHTML = `<p style="color:var(--muted)">${esc(err.message)}</p>`;
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const input = $('buscarJugador');
  if (!input) return;
  buscar('');
  input.addEventListener('input', Utils.debounce(() => buscar(input.value), 300));
});

})();
