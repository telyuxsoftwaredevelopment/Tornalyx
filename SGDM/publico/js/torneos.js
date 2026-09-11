/* =====================================================
   TORNALYX – torneos.js
   Listado público de torneos (/torneos):
   carga desde el API y filtra en el cliente.
   ===================================================== */

'use strict';

/* Envuelto en IIFE (como el resto de los scripts de página): un
   `const Api` de nivel superior en dos <script> clásicos que comparten
   el scope global choca con el `const Api` de main.js y tira
   "Identifier 'Api' has already been declared", abortando todo el
   archivo antes de que corra initTorneos. */
(function () {

const { Api, Utils } = window.Tornalyx;

/* Todos los torneos públicos traídos del API. */
let catalogo = [];

/**
 * Fondo de la cabecera de cada card, por disciplina: micro-degradados
 * oscuros dentro de la paleta del sitio (nunca colores sueltos ajenos a la
 * marca), variando ángulo e intensidad de rojo para distinguir disciplinas.
 * @param {string} disciplina
 * @returns {string} valor CSS de background
 */
function fondoDisciplina(disciplina) {
  const paletas = [
    'linear-gradient(135deg, #1a1012 0%, #2a1416 100%)',
    'linear-gradient(135deg, #1a1012 0%, #431316 100%)',
    'linear-gradient(160deg, #130c0d 0%, #5c1418 100%)',
    'linear-gradient(135deg, #1f1113 0%, #6b1318 100%)',
    'linear-gradient(200deg, #1a1012 0%, #3d1216 100%)',
    'linear-gradient(115deg, #130c0d 0%, #501317 100%)'
  ];
  /* Hash simple del nombre: la misma disciplina siempre recibe el mismo degradé. */
  const texto = String(disciplina || '');
  let hash = 0;
  for (let i = 0; i < texto.length; i++) hash = (hash + texto.charCodeAt(i)) % paletas.length;
  return paletas[hash];
}

/**
 * HTML de una card del listado público.
 * @param {Object} t
 * @returns {string}
 */
function cardHtml(t) {
  const esc     = Utils.escapeHtml;
  const estado  = TorneoUI.estado(t.estado);
  const cupos   = TorneoUI.participantes(t);
  const inicio  = TorneoUI.fecha(t.fecha_inicio);
  const fin     = TorneoUI.fecha(t.fecha_fin);
  const abierto = t.estado === 'inscripcion';
  const org     = [t.org_nombre, t.org_apellido].filter(Boolean).join(' ');

  const lineas = [];
  if (inicio) lineas.push(`📅 Inicio: ${inicio}`);
  else if (fin) lineas.push(`🏁 Finaliza: ${fin}`);
  lineas.push(`👥 ${cupos} de ${t.max_participantes} inscritos`);
  if (org) lineas.push(`🎽 Organiza: ${esc(org)}`);

  // Con foto de fondo subida, la cabecera crece y muestra un reveal en
  // vidrio esmerilado al pasar el mouse (o al enfocar con teclado) en vez
  // del ícono genérico de disciplina, que ya queda cubierto por la foto.
  const tieneFoto = !!t.banner_url;
  const sportStyle = tieneFoto
    ? `background-image:url('${esc(t.banner_url)}')`
    : `background:${fondoDisciplina(t.disciplina)}`;
  const descripcion = (t.descripcion || '').trim();
  const descripcionCorta = descripcion.length > 140 ? descripcion.slice(0, 140) + '…' : descripcion;

  return `
    <article class="torneo-card"
             data-id="${t.id}"
             data-disciplina="${esc(t.disciplina)}"
             data-estado="${esc(t.estado)}"
             data-sistema="${esc(t.formato)}"
             data-nombre="${esc(String(t.nombre).toLowerCase())}"
             data-fecha="${esc(t.fecha_inicio || '')}">
      <div class="torneo-card__sport${tieneFoto ? ' torneo-card__sport--photo' : ''}" style="${sportStyle}">
        ${tieneFoto ? '' : TorneoUI.icono(t.disciplina)}
        ${tieneFoto ? `
          <div class="torneo-card__reveal">
            <span class="torneo-card__reveal-disc">${esc(t.disciplina)}</span>
            ${descripcionCorta ? `<p>${esc(descripcionCorta)}</p>` : ''}
          </div>` : ''}
      </div>
      <div class="torneo-card__body">
        <div class="torneo-card__meta">
          <span class="badge ${estado.badge}">${esc(estado.label)}</span>
          <span class="badge badge-muted">${esc(TorneoUI.formato(t.formato))}</span>
        </div>
        <h3 class="torneo-card__title">${esc(t.nombre)}</h3>
        <div class="torneo-card__info">${lineas.map(l => `<span>${l}</span>`).join('')}</div>
      </div>
      <div class="torneo-card__footer">
        <span class="${abierto ? 'status-next' : 'status-live'}">${esc(estado.label)}</span>
        <a href="/torneo-detalle?id=${t.id}" class="btn ${abierto ? 'btn-ghost' : 'btn-primary'} btn-sm">
          ${abierto ? 'Inscribirse' : 'Ver'}
        </a>
      </div>
    </article>`;
}

/** Placeholder de una card mientras se espera al API. */
function skeletonCardHtml() {
  return `
    <article class="torneo-card" aria-hidden="true">
      <div class="skeleton" style="height:80px;border-radius:0"></div>
      <div class="torneo-card__body">
        <div class="skeleton" style="height:20px;width:60%;margin-bottom:10px"></div>
        <div class="skeleton" style="height:14px;width:90%;margin-bottom:6px"></div>
        <div class="skeleton" style="height:14px;width:70%"></div>
      </div>
    </article>`;
}

/** Dibuja el grid completo a partir del catálogo cargado. */
function renderGrid() {
  const grid = document.getElementById('torneosGrid');
  if (!grid) return;
  grid.innerHTML = catalogo.map(cardHtml).join('');
  aplicarFiltros();
}

/** Rellena el select de disciplinas con las que existen realmente. */
function renderDisciplinas() {
  const sel = document.getElementById('filterDeporte');
  if (!sel) return;
  const previo = sel.value;
  const unicas = [...new Set(catalogo.map(t => t.disciplina).filter(Boolean))].sort();
  sel.innerHTML = '<option value="">Todas</option>' +
    unicas.map(d => `<option value="${Utils.escapeHtml(d)}">${Utils.escapeHtml(d)}</option>`).join('');
  if (previo) sel.value = previo;
}

/** Muestra u oculta las cards según los filtros activos. */
function aplicarFiltros() {
  const valor = id => (document.getElementById(id)?.value || '').trim();
  const q          = valor('searchQ').toLowerCase();
  const disciplina = valor('filterDeporte');
  const estado     = valor('filterEstado');
  const sistema    = valor('filterSistema');

  let visibles = 0;
  document.querySelectorAll('#torneosGrid .torneo-card').forEach(card => {
    const show =
      (!q          || card.dataset.nombre.includes(q)) &&
      (!disciplina || card.dataset.disciplina === disciplina) &&
      (!estado     || card.dataset.estado     === estado) &&
      (!sistema    || card.dataset.sistema    === sistema);
    card.style.display = show ? '' : 'none';
    if (show) visibles++;
  });

  const countEl = document.querySelector('#resultsCount strong');
  if (countEl) countEl.textContent = visibles;

  const emptyEl = document.getElementById('emptyState');
  if (emptyEl) emptyEl.classList.toggle('hidden', visibles > 0);
}

/** Reordena las cards según el criterio elegido. */
function aplicarOrden() {
  const grid = document.getElementById('torneosGrid');
  const sel  = document.getElementById('sortBy');
  if (!grid || !sel) return;

  const cards = [...grid.querySelectorAll('.torneo-card')];
  const criterio = sel.value;
  cards.sort((a, b) => {
    if (criterio === 'nombre') {
      return a.dataset.nombre.localeCompare(b.dataset.nombre);
    }
    if (criterio === 'fecha') {
      /* Los torneos sin fecha de inicio van al final. */
      const fa = a.dataset.fecha || '9999-12-31';
      const fb = b.dataset.fecha || '9999-12-31';
      return fa.localeCompare(fb);
    }
    return 0;   // "Más recientes": ya vienen ordenados por el API.
  });
  cards.forEach(c => grid.appendChild(c));
}

/** Carga los torneos públicos y engancha los controles de filtrado. */
async function initTorneos() {
  const grid = document.getElementById('torneosGrid');
  if (!grid) return;

  grid.innerHTML = Array.from({ length: 6 }, skeletonCardHtml).join('');

  try {
    const data = await Api.get('/api/torneos');
    catalogo = data.torneos || [];
    renderDisciplinas();
    renderGrid();
  } catch (err) {
    grid.innerHTML = `<p style="color:var(--muted)">${Utils.escapeHtml(err.message)}</p>`;
    return;
  }

  document.getElementById('btnFiltrar')?.addEventListener('click', aplicarFiltros);
  ['filterDeporte', 'filterEstado', 'filterSistema'].forEach(id =>
    document.getElementById(id)?.addEventListener('change', aplicarFiltros)
  );

  const search = document.getElementById('searchQ');
  if (search) {
    search.addEventListener('input', Utils.debounce(aplicarFiltros, 250));
    search.addEventListener('keydown', e => { if (e.key === 'Enter') aplicarFiltros(); });
  }

  document.getElementById('sortBy')?.addEventListener('change', aplicarOrden);
}

document.addEventListener('DOMContentLoaded', initTorneos);

})();
