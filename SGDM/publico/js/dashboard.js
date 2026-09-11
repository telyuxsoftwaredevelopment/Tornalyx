/* =====================================================
   TORNALYX – dashboard.js
   Navegación SPA-lite para paneles privados
   (Admin y Organizador)
   ===================================================== */

'use strict';

/**
 * Lee el título de una sección desde el atributo data-title
 * del ítem del sidebar, o hace fallback al texto del ítem.
 * @param {Element} item - Elemento .sidebar__item
 * @returns {string}
 */
function getSectionTitle(item) {
  if (!item) return '';
  return item.dataset.title || item.textContent.trim();
}

/**
 * Activa una sección del dashboard y actualiza el sidebar.
 * @param {string} key - Valor de data-section
 */
function setSection(key) {
  document.querySelectorAll('.sidebar__item').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.section-panel').forEach(el => el.classList.remove('active'));

  const item  = document.querySelector(`.sidebar__item[data-section="${key}"]`);
  const panel = document.getElementById('panel-' + key);

  if (item)  item.classList.add('active');
  if (panel) panel.classList.add('active');

  const title = getSectionTitle(item);
  const titleEl = document.getElementById('topbarTitle');
  if (titleEl) titleEl.textContent = title;

  /* Actualizar URL sin recargar (SPA-lite) */
  if (history.pushState) {
    history.pushState({ section: key }, '', '#' + key);
  }
}

/**
 * Inicializa la navegación sidebar y el overlay mobile.
 */
function initDashboard() {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  const toggle  = document.getElementById('menuToggle');
  if (!sidebar) return;

  /* Navegación por secciones */
  document.querySelectorAll('.sidebar__item[data-section]').forEach(item => {
    item.addEventListener('click', function () {
      setSection(this.dataset.section);
      closeSidebar();
    });
  });

  /* Botones con data-goto activan una sección directamente */
  document.querySelectorAll('[data-goto]').forEach(btn => {
    btn.addEventListener('click', function () {
      setSection(this.dataset.goto);
    });
  });

  /* Overlay mobile — el toggle además refleja el estado (☰ anima a ✕,
     mismo tratamiento que .burger en las páginas públicas) para dar
     feedback visual de que el sidebar está abierto. */
  function openSidebar() {
    sidebar.classList.add('open');
    if (overlay) overlay.classList.add('show');
    if (toggle)  { toggle.classList.add('open'); toggle.setAttribute('aria-expanded', 'true'); }
    document.body.style.overflow = 'hidden';
  }
  function closeSidebar() {
    sidebar.classList.remove('open');
    if (overlay) overlay.classList.remove('show');
    if (toggle)  { toggle.classList.remove('open'); toggle.setAttribute('aria-expanded', 'false'); }
    document.body.style.overflow = '';
  }

  /* Solo abre: .topbar tiene su propio z-index (crea un stacking context),
     así que una vez abierto el sidebar (z-index más alto) lo tapa y ya no
     se puede volver a tocar — cerrar se resuelve con el overlay, Escape o
     tocando un ítem del sidebar, que sí quedan siempre alcanzables. */
  if (toggle)  toggle.addEventListener('click', openSidebar);
  if (overlay) overlay.addEventListener('click', closeSidebar);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });

  /* Restaurar sección desde hash de URL */
  const hash = location.hash.replace('#', '');
  if (hash && document.getElementById('panel-' + hash)) {
    setSection(hash);
  }

  /* Scroll reveal dentro del dashboard */
  document.querySelectorAll('[data-reveal]').forEach(el => el.classList.add('visible'));
}

document.addEventListener('DOMContentLoaded', initDashboard);

/* Exponer setSection globalmente por si se necesita desde HTML */
window.setSection = setSection;
