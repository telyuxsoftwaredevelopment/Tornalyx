/* =====================================================
   TORNALYX – SGDM | Script Principal
   Vanilla JS – Sin dependencias externas
   ===================================================== */

'use strict';

/* ─── Mobile Nav (burger) ───────────────────────────── */
function initMobileNav() {
  const burger    = document.getElementById('burgerBtn');
  const mobileNav = document.getElementById('mobileNav');
  const closeBtn  = document.getElementById('mobileClose');
  if (!burger || !mobileNav) return;

  function openMenu() {
    burger.classList.add('open');
    burger.setAttribute('aria-expanded', 'true');
    mobileNav.style.display = 'flex';
    requestAnimationFrame(() => mobileNav.classList.add('open'));
    document.body.style.overflow = 'hidden';
  }
  function closeMenu() {
    burger.classList.remove('open');
    burger.setAttribute('aria-expanded', 'false');
    mobileNav.classList.remove('open');
    document.body.style.overflow = '';
    setTimeout(() => { mobileNav.style.display = 'none'; }, 260);
  }

  burger.addEventListener('click', () =>
    mobileNav.classList.contains('open') ? closeMenu() : openMenu()
  );
  if (closeBtn) closeBtn.addEventListener('click', closeMenu);
  mobileNav.querySelectorAll('a').forEach(l => l.addEventListener('click', closeMenu));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMenu(); });
}

/* ─── Bottom Nav (mobile, páginas públicas) ──────────── */
/* Desliza el pill de fondo hasta el tab con aria-current="page". Como acá
   no hay SPA (cada tab es una carga de página nueva), la animación se
   simula: se guarda el índice del tab activo en sessionStorage, y al
   entrar a la página siguiente el pill arranca en la posición vieja (sin
   transición) y en el frame siguiente se anima hacia la nueva. Así cada
   navegación se siente como un slide, no como un salto. */
function initBottomNav() {
  const nav = document.querySelector('.bottom-nav');
  if (!nav) return;
  const pill  = nav.querySelector('.bottom-nav__pill');
  const items = Array.from(nav.querySelectorAll('.bottom-nav__item'));
  const activeIndex = items.findIndex(a => a.getAttribute('aria-current') === 'page');
  if (!pill || activeIndex === -1) return;

  const place = (index, animate) => {
    const item = items[index];
    if (!item) return;
    pill.style.transition = animate ? '' : 'none';
    pill.style.width = (item.offsetWidth - 8) + 'px';
    pill.style.transform = `translateX(${item.offsetLeft + 4}px)`;
  };

  const STORAGE_KEY = 'tornalyx-bottomnav-idx';
  let prevIndex = -1;
  try { prevIndex = parseInt(sessionStorage.getItem(STORAGE_KEY), 10); } catch { /* privado / bloqueado */ }

  if (Number.isInteger(prevIndex) && prevIndex >= 0 && prevIndex !== activeIndex) {
    place(prevIndex, false);
    requestAnimationFrame(() => requestAnimationFrame(() => place(activeIndex, true)));
  } else {
    place(activeIndex, false);
  }

  try { sessionStorage.setItem(STORAGE_KEY, String(activeIndex)); } catch { /* privado / bloqueado */ }

  window.addEventListener('resize', () => place(activeIndex, false));

  initArrastreBottomNav(nav, items, pill, activeIndex, place);
}

/* Arrastrar el dedo (o el mouse) sobre la barra desliza el pill en tiempo
   real, tab por tab; soltar sobre un tab distinto navega ahí, soltar sobre
   el mismo o afuera lo devuelve a su lugar. Un tap simple (sin arrastre
   real, movimiento menor al umbral) no pasa por acá: el <a> navega solo,
   como siempre — por eso solo se llama preventDefault() una vez confirmado
   el arrastre, nunca en el pointerdown. */
function initArrastreBottomNav(nav, items, pill, activeIndex, place) {
  const UMBRAL = 10; // px de movimiento antes de considerarlo arrastre y no tap
  let inicio = null;
  let arrastrando = false;
  let idPuntero = null;
  let destino = activeIndex;
  let ultimoFueArrastre = false;

  const indiceBajoPuntero = (clientX) => {
    let mejor = destino;
    items.forEach((item, i) => {
      const r = item.getBoundingClientRect();
      if (clientX >= r.left && clientX < r.right) mejor = i;
    });
    return mejor;
  };

  nav.addEventListener('pointerdown', e => {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    inicio = { x: e.clientX, y: e.clientY };
    arrastrando = false;
    idPuntero = e.pointerId;
    destino = activeIndex;
  });

  nav.addEventListener('pointermove', e => {
    if (inicio === null || e.pointerId !== idPuntero) return;
    if (!arrastrando) {
      if (Math.abs(e.clientX - inicio.x) < UMBRAL && Math.abs(e.clientY - inicio.y) < UMBRAL) return;
      arrastrando = true;
    }
    e.preventDefault();
    destino = indiceBajoPuntero(e.clientX);
    place(destino, false);
    items.forEach((item, i) => item.classList.toggle('bottom-nav__item--targeted', i === destino));
  });

  const soltar = e => {
    if (inicio === null || e.pointerId !== idPuntero) return;
    ultimoFueArrastre = arrastrando;
    if (arrastrando) {
      items.forEach(item => item.classList.remove('bottom-nav__item--targeted'));
      if (destino !== activeIndex && items[destino] && items[destino].href) {
        window.location.href = items[destino].href;
      } else {
        place(activeIndex, true);
      }
    }
    inicio = null;
    arrastrando = false;
    idPuntero = null;
  };

  nav.addEventListener('pointerup', soltar);
  nav.addEventListener('pointercancel', soltar);

  // Sin esto, tras un arrastre real el navegador todavía dispara un
  // "click" sobre el tab donde arrancó el gesto (no donde se soltó),
  // navegando dos veces o al lugar equivocado.
  nav.addEventListener('click', e => {
    if (ultimoFueArrastre) { e.preventDefault(); ultimoFueArrastre = false; }
  });
}

/* ─── Scroll Reveal ─────────────────────────────────── */
function initScrollReveal() {
  document.querySelectorAll('[data-reveal]').forEach(el => el.classList.add('visible'));
}

/* ─── Tabs genéricos [role="tab"] ───────────────────── */
/* ─── Detección Apple / iOS / macOS para Liquid Glass ─── */
function detectAppleLiquidGlass() {
  try {
    const ua = navigator.userAgent || navigator.vendor || window.opera || '';
    const platform = navigator.platform || '';
    const isIOS = /iPad|iPhone|iPod/.test(ua) || (platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isMac = /Macintosh|MacIntel|MacPPC|Mac68K/.test(platform) || /Mac OS X/.test(ua);
    const isApple = isIOS || isMac || (/AppleWebKit/.test(ua) && /Safari/.test(ua) && !/Chrome|Chromium|Edg|OPR/.test(ua));

    if (isApple) {
      document.documentElement.classList.add('is-apple');
      document.documentElement.setAttribute('data-apple-device', isIOS ? 'ios' : 'mac');
    }
    if (isIOS) {
      document.documentElement.classList.add('is-ios');
      document.documentElement.classList.add('ios-liquid-glass');
    }
  } catch (err) {
    console.warn('[LiquidGlass] Error detectando iOS:', err);
  }
}

/* ─── Pestañas / Tabs (Documentación y General) ───────── */
function initTabs() {
  // Pestañas generales con role="tab"
  document.querySelectorAll('[role="tab"]').forEach(tab => {
    tab.addEventListener('click', function (e) {
      const targetId = this.dataset.tabTarget || ('tab-' + this.dataset.tab);
      const parent = this.closest('[role="tablist"]') || document;
      
      parent.querySelectorAll('[role="tab"]').forEach(t => {
        t.classList.remove('active');
        t.setAttribute('aria-selected', 'false');
      });
      
      document.querySelectorAll('.tab-content, .doc-tab-panel').forEach(c => {
        c.classList.remove('active');
        c.hidden = true;
      });

      this.classList.add('active');
      this.setAttribute('aria-selected', 'true');

      const panel = document.getElementById(targetId) || document.getElementById('tab-' + this.dataset.tab);
      if (panel) {
        panel.classList.add('active');
        panel.hidden = false;
      }

      // Sincronización en URL para apartado de documentación
      if (targetId === 'tabPresentaciones' || targetId === 'tabDocumentacion') {
        const url = new URL(window.location);
        url.searchParams.set('tab', targetId === 'tabPresentaciones' ? 'presentaciones' : 'documentacion');
        history.replaceState(null, '', url);
      }
    });
  });

  // Restaurar pestaña activa de documentación si viene por URL (?tab=presentaciones)
  const urlParams = new URLSearchParams(window.location.search);
  const tabQuery = urlParams.get('tab');
  if (tabQuery === 'presentaciones') {
    const presTabBtn = document.getElementById('tabBtnPres') || document.querySelector('[data-tab-target="tabPresentaciones"]');
    if (presTabBtn) presTabBtn.click();
  }
}

/* ─── Toast Notifications ────────────────────────────── */
const Toast = {
  container: null,

  init() {
    this.container = document.createElement('div');
    this.container.id = 'toast-container';
    Object.assign(this.container.style, {
      position: 'fixed', bottom: '1.5rem', right: '1.5rem',
      display: 'flex', flexDirection: 'column', gap: '0.5rem',
      zIndex: '9999', maxWidth: '360px'
    });
    document.body.appendChild(this.container);

    /* Los estilos inline no pueden declarar @keyframes; se inyecta una vez
       para la barra de cuenta regresiva de cada toast. */
    if (!document.getElementById('toast-keyframes')) {
      const style = document.createElement('style');
      style.id = 'toast-keyframes';
      style.textContent = '@keyframes toast-countdown { from { transform: scaleX(1); } to { transform: scaleX(0); } }';
      document.head.appendChild(style);
    }
  },

  show(message, type = 'info', duration = 4000) {
    if (!this.container) this.init();
    const colors = {
      success: { bg: 'rgba(5,150,105,.15)', border: '#059669', text: '#6ee7b7' },
      error:   { bg: 'rgba(220,38,38,.15)', border: 'var(--red)', text: '#fca5a5' },
      warning: { bg: 'rgba(217,119,6,.15)', border: '#d97706', text: '#fcd34d' },
      info:    { bg: 'rgba(37,99,235,.15)', border: '#2563eb', text: '#93c5fd' }
    };
    const c = colors[type] || colors.info;
    const toast = document.createElement('div');
    toast.setAttribute('role', 'alert');
    Object.assign(toast.style, {
      position: 'relative', overflow: 'hidden',
      padding: '0.75rem 1rem', borderRadius: '10px',
      border: `1px solid ${c.border}`, background: c.bg, color: c.text,
      fontSize: '0.875rem', fontWeight: '500',
      boxShadow: '0 4px 24px rgba(0,0,0,.4)',
      opacity: '0', transform: 'translateX(1rem)',
      transition: 'all 250ms ease', cursor: 'pointer'
    });
    toast.textContent = message;

    /* Barra de vigencia: se pausa (visual y realmente) si el mouse está
       encima, para no perderse un mensaje largo a mitad de lectura. */
    const bar = document.createElement('div');
    Object.assign(bar.style, {
      position: 'absolute', left: '0', bottom: '0', width: '100%', height: '2px',
      background: c.border, transformOrigin: 'left',
    });
    toast.appendChild(bar);

    this.container.appendChild(toast);
    requestAnimationFrame(() => {
      toast.style.opacity = '1';
      toast.style.transform = 'translateX(0)';
    });

    const remove = () => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateX(1rem)';
      setTimeout(() => toast.remove(), 300);
    };

    let restante = duration;
    let inicio = Date.now();
    let timer = null;
    const programar = (ms) => {
      inicio = Date.now();
      restante = ms;
      timer = setTimeout(remove, ms);
    };
    const pausar = () => {
      clearTimeout(timer);
      restante -= Date.now() - inicio;
      bar.style.animationPlayState = 'paused';
    };
    const reanudar = () => {
      bar.style.animationPlayState = 'running';
      programar(restante);
    };

    bar.style.animation = `toast-countdown ${duration}ms linear forwards`;
    toast.addEventListener('mouseenter', pausar);
    toast.addEventListener('mouseleave', reanudar);
    toast.addEventListener('click', remove);
    programar(duration);
  },

  success: (msg) => Toast.show(msg, 'success'),
  error:   (msg) => Toast.show(msg, 'error'),
  warning: (msg) => Toast.show(msg, 'warning'),
  info:    (msg) => Toast.show(msg, 'info')
};

/* ─── Utilidades ─────────────────────────────────────── */
const Utils = {
  /**
   * Formatea fecha ISO a español.
   * @param {string} isoDate
   * @returns {string}
   */
  formatDate(isoDate) {
    return new Date(isoDate).toLocaleDateString('es-UY', {
      day: '2-digit', month: 'long', year: 'numeric'
    });
  },

  /**
   * Trunca texto a N caracteres.
   * @param {string} text
   * @param {number} max
   * @returns {string}
   */
  truncate(text, max = 100) {
    return text.length > max ? text.slice(0, max) + '…' : text;
  },

  /**
   * Escapa HTML para prevenir XSS.
   * @param {string} str
   * @returns {string}
   */
  escapeHtml(str) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    return String(str).replace(/[&<>"']/g, m => map[m]);
  },

  /**
   * Tiempo relativo en español a partir de una fecha del servidor
   * ("hace 2h", "hace 3d", "recién"). Interpreta la fecha SQL como hora
   * local del servidor, que es como la genera MySQL con NOW().
   * @param {string} fechaSql  'YYYY-MM-DD HH:MM:SS'
   * @returns {string}
   */
  timeAgo(fechaSql) {
    if (!fechaSql) return '';
    const t = new Date(String(fechaSql).replace(' ', 'T'));
    if (isNaN(t)) return '';
    const seg = Math.max(0, Math.floor((Date.now() - t.getTime()) / 1000));
    if (seg < 60)     return 'recién';
    const min = Math.floor(seg / 60);
    if (min < 60)     return `hace ${min}m`;
    const hs = Math.floor(min / 60);
    if (hs < 24)      return `hace ${hs}h`;
    const dias = Math.floor(hs / 24);
    if (dias < 30)    return `hace ${dias}d`;
    const meses = Math.floor(dias / 30);
    if (meses < 12)   return `hace ${meses} mes${meses > 1 ? 'es' : ''}`;
    return `hace ${Math.floor(meses / 12)} año(s)`;
  },

  /**
   * Lee una cookie por nombre.
   * @param {string} name
   * @returns {string}
   */
  getCookie(name) {
    const match = document.cookie.match(
      new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)')
    );
    return match ? decodeURIComponent(match[1]) : '';
  },

  /**
   * Headers con el token CSRF para peticiones que cambian estado.
   * @returns {Object}
   */
  csrfHeaders() {
    return {
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-Token': this.getCookie('XSRF-TOKEN'),
    };
  },

  /**
   * Debounce: retrasa ejecución hasta que pase wait ms.
   * @param {Function} fn
   * @param {number} wait
   * @returns {Function}
   */
  debounce(fn, wait = 300) {
    let timer;
    return (...args) => {
      clearTimeout(timer);
      timer = setTimeout(() => fn(...args), wait);
    };
  },

  /**
   * Determina si el usuario/jugador corresponde a kratosduarte17 (easter egg).
   * @param {Object} u
   * @returns {boolean}
   */
  esPerfilKratos(u) {
    if (!u) return false;
    const normalize = str => String(str || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .trim();

    const nick = normalize(u.nickname);
    const nombre = normalize(u.nombre);
    const apellido = normalize(u.apellido);
    const full = `${nombre} ${apellido}`.trim();
    const email = normalize(u.email);
    const tag = String(u.tag || '').trim();

    return nick.includes('kratos') ||
           nick.includes('kratosduarte') ||
           full.includes('kratos') ||
           (full.includes('joaquin') && full.includes('duarte')) ||
           email.includes('kratos') ||
           (tag === '1891' && full.includes('joaquin'));
  },

  /**
   * Devuelve el HTML del Spider-Man pixel art invertido colgado de una telaraña.
   * @param {'normal'|'mini'} variant
   * @returns {string}
   */
  spidermanEasterEggHtml(variant = 'normal') {
    const isMini = variant === 'mini';
    const extraClass = isMini ? ' spiderman-easter-egg--mini' : '';
    const svgW = isMini ? 22 : 34;
    const svgH = isMini ? 34 : 52;

    return `
      <div class="spiderman-easter-egg${extraClass}" title="Spiderman No Home 🕷️" aria-label="Easter egg: Spiderman No Home" role="img">
        <div class="spiderman-web-thread"></div>
        <div class="spiderman-figure">
          <svg class="spiderman-pixel-svg" viewBox="0 0 17 26" width="${svgW}" height="${svgH}" shape-rendering="crispEdges" aria-hidden="true">
            <g fill="#ffffff">
              <rect x="8" y="0" width="1" height="10" />
              <rect x="6" y="17" width="1" height="2" />
              <rect x="7" y="18" width="1" height="2" />
              <rect x="10" y="17" width="1" height="2" />
              <rect x="9" y="18" width="1" height="2" />
            </g>
            <g fill="#1e6cd9">
              <rect x="5" y="8" width="2" height="1" />
              <rect x="10" y="8" width="2" height="1" />
              <rect x="4" y="9" width="2" height="1" />
              <rect x="11" y="9" width="2" height="1" />
              <rect x="4" y="10" width="1" height="1" />
              <rect x="12" y="10" width="1" height="1" />
              <rect x="5" y="11" width="1" height="2" />
              <rect x="11" y="11" width="1" height="2" />
            </g>
            <g fill="#e50914">
              <rect x="7" y="6" width="1" height="1" />
              <rect x="9" y="6" width="1" height="1" />
              <rect x="6" y="7" width="1" height="1" />
              <rect x="10" y="7" width="1" height="1" />
              <rect x="7" y="9" width="1" height="1" />
              <rect x="9" y="9" width="1" height="1" />
              <rect x="6" y="10" width="5" height="1" />
              <rect x="7" y="11" width="1" height="1" />
              <rect x="9" y="11" width="1" height="1" />
              <rect x="6" y="12" width="2" height="1" />
              <rect x="9" y="12" width="2" height="1" />
              <rect x="7" y="13" width="3" height="1" />
              <rect x="5" y="14" width="7" height="1" />
              <rect x="4" y="15" width="9" height="1" />
              <rect x="4" y="16" width="1" height="1" />
              <rect x="7" y="16" width="3" height="1" />
              <rect x="12" y="16" width="1" height="1" />
              <rect x="4" y="17" width="1" height="1" />
              <rect x="8" y="17" width="1" height="1" />
              <rect x="12" y="17" width="1" height="1" />
              <rect x="4" y="18" width="1" height="1" />
              <rect x="12" y="18" width="1" height="1" />
              <rect x="4" y="19" width="2" height="1" />
              <rect x="11" y="19" width="2" height="1" />
              <rect x="4" y="20" width="3" height="1" />
              <rect x="10" y="20" width="3" height="1" />
              <rect x="4" y="21" width="9" height="2" />
              <rect x="5" y="23" width="7" height="1" />
            </g>
            <g fill="#11141a">
              <rect x="6" y="6" width="1" height="1" />
              <rect x="10" y="6" width="1" height="1" />
              <rect x="5" y="7" width="1" height="1" />
              <rect x="7" y="7" width="1" height="1" />
              <rect x="9" y="7" width="1" height="1" />
              <rect x="11" y="7" width="1" height="1" />
              <rect x="4" y="8" width="1" height="1" />
              <rect x="7" y="8" width="1" height="1" />
              <rect x="9" y="8" width="1" height="1" />
              <rect x="12" y="8" width="1" height="1" />
              <rect x="3" y="9" width="1" height="1" />
              <rect x="6" y="9" width="1" height="1" />
              <rect x="10" y="9" width="1" height="1" />
              <rect x="13" y="9" width="1" height="1" />
              <rect x="3" y="10" width="1" height="1" />
              <rect x="5" y="10" width="1" height="1" />
              <rect x="11" y="10" width="1" height="1" />
              <rect x="13" y="10" width="1" height="1" />
              <rect x="4" y="11" width="1" height="1" />
              <rect x="6" y="11" width="1" height="1" />
              <rect x="8" y="11" width="1" height="1" />
              <rect x="10" y="11" width="1" height="1" />
              <rect x="12" y="11" width="1" height="1" />
              <rect x="4" y="12" width="1" height="1" />
              <rect x="8" y="12" width="1" height="1" />
              <rect x="12" y="12" width="1" height="1" />
              <rect x="5" y="13" width="2" height="1" />
              <rect x="10" y="13" width="2" height="1" />
              <rect x="4" y="14" width="1" height="1" />
              <rect x="12" y="14" width="1" height="1" />
              <rect x="3" y="15" width="1" height="1" />
              <rect x="13" y="15" width="1" height="1" />
              <rect x="3" y="16" width="1" height="1" />
              <rect x="5" y="16" width="2" height="1" />
              <rect x="10" y="16" width="2" height="1" />
              <rect x="13" y="16" width="1" height="1" />
              <rect x="3" y="17" width="1" height="1" />
              <rect x="5" y="17" width="1" height="1" />
              <rect x="7" y="17" width="1" height="1" />
              <rect x="9" y="17" width="1" height="1" />
              <rect x="11" y="17" width="1" height="1" />
              <rect x="13" y="17" width="1" height="1" />
              <rect x="3" y="18" width="1" height="1" />
              <rect x="5" y="18" width="1" height="1" />
              <rect x="8" y="18" width="1" height="1" />
              <rect x="11" y="18" width="1" height="1" />
              <rect x="13" y="18" width="1" height="1" />
              <rect x="3" y="19" width="1" height="1" />
              <rect x="6" y="19" width="1" height="1" />
              <rect x="8" y="19" width="1" height="1" />
              <rect x="10" y="19" width="1" height="1" />
              <rect x="13" y="19" width="1" height="1" />
              <rect x="3" y="20" width="1" height="1" />
              <rect x="7" y="20" width="3" height="1" />
              <rect x="13" y="20" width="1" height="1" />
              <rect x="3" y="21" width="1" height="2" />
              <rect x="13" y="21" width="1" height="2" />
              <rect x="4" y="23" width="1" height="1" />
              <rect x="12" y="23" width="1" height="1" />
              <rect x="5" y="24" width="7" height="1" />
            </g>
          </svg>
          <div class="spiderman-bubble">Spiderman No Home 🕷️</div>
        </div>
      </div>`;
  }
};

/* ─── Cliente del API JSON ───────────────────────────── */
/* Centraliza fetch + CSRF + manejo de errores para no repetirlo en cada
   pantalla. Todos los endpoints responden {success:true|false, ...}. */
const Api = {
  /**
   * Petición genérica al API.
   * @param {string} url
   * @param {RequestInit} options
   * @returns {Promise<Object>} Cuerpo JSON de una respuesta exitosa.
   * @throws {Error} Con .status y .data cuando el servidor rechaza la petición.
   */
  async request(url, options = {}) {
    let res;
    try {
      res = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: { 'X-Requested-With': 'XMLHttpRequest', ...(options.headers || {}) }
      });
    } catch {
      throw new Error('No se pudo conectar con el servidor. Revisá tu conexión.');
    }

    let data = null;
    try { data = await res.json(); } catch { /* respuesta vacía o no-JSON */ }

    if (!data) {
      throw new Error('El servidor respondió de forma inesperada.');
    }
    if (!res.ok || data.success === false) {
      /* Sesión vencida: avisamos y mandamos al login (el API no redirige
         para no romper el parseo JSON del cliente). */
      if (res.status === 401 || data.login) {
        Toast.error(data.error || 'Tu sesión expiró.');
        setTimeout(() => { window.location.href = '/login'; }, 1500);
      }
      const err = new Error(data.error || 'No se pudo completar la operación.');
      err.status = res.status;
      err.data = data;
      throw err;
    }
    return data;
  },

  /** GET de un endpoint JSON. */
  get(url) {
    return this.request(url);
  },

  /**
   * POST de un formulario (urlencoded) con token CSRF.
   * @param {string} url
   * @param {Object} fields Pares campo => valor.
   */
  post(url, fields = {}) {
    const body = new URLSearchParams();
    Object.entries(fields).forEach(([k, v]) => body.append(k, v ?? ''));
    return this.request(url, {
      method: 'POST',
      body,
      headers: {
        ...Utils.csrfHeaders(),
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      }
    });
  }
};

/* ─── Modal ──────────────────────────────────────────── */
const Modal = {
  open(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  },
  close(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  },
  init() {
    document.querySelectorAll('[data-modal-close]').forEach(btn => {
      btn.addEventListener('click', () => {
        const modal = btn.closest('.modal');
        if (modal) Modal.close(modal.id);
      });
    });
    document.querySelectorAll('.modal').forEach(modal => {
      modal.addEventListener('click', e => {
        if (e.target === modal) Modal.close(modal.id);
      });
    });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') {
        document.querySelectorAll('.modal[style*="flex"]').forEach(m => Modal.close(m.id));
      }
    });
  }
};

/* ─── Fallback de imágenes (reemplaza onerror inline) ──── */
/* Se hace por JS externo para cumplir la CSP (script-src 'self'). */
function initImageFallbacks() {
  document.querySelectorAll('img[data-fallback]').forEach(img => {
    const applyFallback = () => {
      switch (img.dataset.fallback) {
        case 'hide':
          img.style.display = 'none';
          break;
        case 'mark':
          img.outerHTML = '<div class="mark-fallback">TX</div>';
          break;
        case 'logo':
          img.outerHTML = '<div class="logo-mark-fallback">TX</div>';
          break;
      }
    };
    img.addEventListener('error', applyFallback);
    /* La imagen pudo fallar antes de registrar el listener. */
    if (img.complete && img.naturalWidth === 0) applyFallback();
  });
}

/* ─── Botones que activan un tab (reemplaza onclick inline) ─ */
function initTabShortcuts() {
  document.querySelectorAll('[data-goto-tab]').forEach(btn => {
    btn.addEventListener('click', () => {
      const target = document.querySelector(`[data-tab="${btn.dataset.gotoTab}"]`);
      if (target) target.click();
    });
  });
}

/* ─── Nav según sesión ────────────────────────────────── */
/* En las páginas públicas, si hay una sesión activa se reemplazan los
   accesos "Entrar / Crear cuenta" por "Mi perfil (o panel) / Cerrar
   sesión", y en los navs que no tienen accesos de cuenta (p. ej.
   /torneos) se insertan. Así siempre se puede volver al perfil. */
async function initAuthNav() {
  // Las pantallas de autenticación (login/registro) no llevan accesos de navegación
  if (document.querySelector('.auth-container') || document.querySelector('.auth-page')) return;

  // Las páginas privadas ya traen su propio nav con "Cerrar sesión".
  if (document.querySelector('.nav a[href="/logout"]')) return;

  let me;
  try {
    const res = await fetch('/api/me', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (!res.ok) return;                 // 401: no hay sesión, nav queda igual
    me = await res.json();
  } catch { return; }
  if (!me || !me.success) return;

  // "Organizador" ya no es un rol de cuenta: cualquier logueado entra a
  // "Mis torneos" (ahí puede crear el primero si todavía no organizó
  // ninguno). El administrador tiene, además, su panel de cuentas aparte.
  const panelUrl = me.rol === 'administrador' ? '/admin/dashboard' : '/organizador/dashboard';
  const panelTxt = me.rol === 'administrador' ? 'Mi panel' : 'Mis torneos';

  /* Avatar del usuario: su foto de perfil o, si no subió ninguna, sus
     iniciales sobre el rojo de marca. Al tocarlo redirige a /perfil. */
  const avatar = () => {
    const a = document.createElement('a');
    a.href = '/perfil';
    a.className = 'nav-avatar';
    a.title = 'Mi perfil';
    a.setAttribute('aria-label', 'Mi perfil');
    if (me.avatar_url) {
      a.style.backgroundImage = `url(${encodeURI(me.avatar_url)})`;
    } else {
      a.textContent = ((me.nombre || '')[0] || '') + ((me.apellido || '')[0] || '');
    }
    return a;
  };

  /* Tab "Cuenta" de la bottom nav: redirige a /perfil */
  document.querySelectorAll('.bottom-nav__account[href="/login"]').forEach(a => {
    a.href = '/perfil';
    a.title = 'Mi perfil';
    a.setAttribute('aria-label', 'Mi perfil');
    const icono = a.querySelector('.bottom-nav__icon');
    if (!icono) return;
    const av = document.createElement('span');
    av.className = 'bottom-nav__avatar';
    if (me.avatar_url) {
      av.style.backgroundImage = `url(${encodeURI(me.avatar_url)})`;
    } else {
      av.textContent = ((me.nombre || '')[0] || '') + ((me.apellido || '')[0] || '');
    }
    icono.replaceWith(av);
  });

  /* En desktop el acceso a la cuenta del nav pasa a ser la foto; y el registro pasa a ser Cerrar sesión */
  document.querySelectorAll('.nav-right a.nav-link-login, .nav-right a[href="/login"]').forEach(a => a.replaceWith(avatar()));
  document.querySelectorAll('.nav-right .btn-nav-register, .nav-right a[href="/registro"], .nav-right a[href^="/login?tab="]').forEach(a => {
    const salir = document.createElement('a');
    salir.href = '/logout';
    salir.className = 'nav-link-login';
    salir.textContent = 'Cerrar sesión';
    a.replaceWith(salir);
  });

  document.querySelectorAll('a[href="/login"]:not(.bottom-nav__item):not(.nav-link-login)').forEach(a => {
    a.href = panelUrl;
    a.textContent = panelTxt;
  });
  document.querySelectorAll('a[href="/registro"]:not(.btn-nav-register)').forEach(a => {
    a.href = '/logout';
    a.textContent = 'Cerrar sesión';
    a.classList.remove('btn-primary');
    if (a.classList.contains('btn')) a.classList.add('btn-ghost');
  });

  // Navs sin accesos de cuenta: insertarlos.
  document.querySelectorAll('.nav .nav-right').forEach(cont => {
    if (cont.querySelector('.nav-avatar, a[href="/logout"]')) return;
    const salir = document.createElement('a');
    salir.href = '/logout';
    salir.className = 'nav-link-login';
    salir.textContent = 'Cerrar sesión';
    cont.appendChild(avatar());
    cont.appendChild(salir);
  });
  document.querySelectorAll('.mobile-nav').forEach(cont => {
    if (cont.querySelector(`a[href="${panelUrl}"], a[href="/logout"]`)) return;
    const mk = (href, text) => {
      const a = document.createElement('a');
      a.href = href; a.textContent = text;
      return a;
    };
    cont.appendChild(mk(panelUrl, panelTxt));
    cont.appendChild(mk('/logout', 'Cerrar sesión'));
  });
}

/* ─── Panel de accesibilidad ──────────────────────────── */
/* Botón flotante presente en todas las páginas que cargan main.js.
   Preferencias: tamaño de texto, alto contraste, reducir animaciones y
   subrayar enlaces. Persisten en localStorage y se aplican como clases
   sobre <html> (los estilos viven en main.css). */
const A11Y_KEY = 'tornalyx-a11y';

function initAccessibility() {
  const root = document.documentElement;
  let prefs = {};
  try { prefs = JSON.parse(localStorage.getItem(A11Y_KEY) || '{}') || {}; } catch { /* corrupto */ }

  /* Skip-link para navegación por teclado. */
  const main = document.querySelector('main');
  if (main) {
    if (!main.id) main.id = 'contenido';
    if (!main.hasAttribute('tabindex')) main.setAttribute('tabindex', '-1');
    const skip = document.createElement('a');
    skip.className = 'skip-link';
    skip.href = '#' + main.id;
    skip.textContent = 'Saltar al contenido principal';
    document.body.prepend(skip);
  }

  /* El disparador es siempre un ícono (nunca la palabra "Accesibilidad"),
     dentro del navbar de la página. El navbar reparte sus utilidades en
     dos tiras complementarias: .nav-links se ve de 860px para arriba y
     .nav-utility de 860px para abajo. Acá va el widget principal, en la
     de escritorio; el clon de más abajo cubre la móvil. La topbar de los
     paneles queda de reserva, y sin ninguna barra cae al botón flotante. */
  const navBar = document.querySelector('.nav .nav-links')
    || document.querySelector('.nav .nav-right, .nav .nav-right-static')
    || document.getElementById('topbarActions');
  const widget = document.createElement('div');
  widget.className = navBar ? 'a11y-widget a11y-widget--nav' : 'a11y-widget';
  // El nombre accesible viaja por aria-label/title para lectores de pantalla.
  const A11Y_ICON_SVG = `
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <circle cx="12" cy="12" r="10"/>
      <circle cx="12" cy="7.2" r="1.3" fill="currentColor" stroke="none"/>
      <path d="M6.5 9.2c3.6 1.1 7.4 1.1 11 0"/>
      <path d="M12 10.4v4.1M12 14.5l-2.6 5M12 14.5l2.6 5"/>
    </svg>`;
  widget.innerHTML = `
    <button type="button" class="a11y-btn" id="a11yBtn" aria-expanded="false"
            aria-controls="a11yPanel" aria-label="Accesibilidad" title="Opciones de accesibilidad">
      ${A11Y_ICON_SVG}
    </button>`;

  const panel = document.createElement('div');
  panel.className = 'a11y-panel hidden';
  panel.id = 'a11yPanel';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', 'Opciones de accesibilidad');
  panel.innerHTML = `
      <h4>Accesibilidad</h4>
      <div class="a11y-row">
        <span id="a11yFontLabel">Tamaño del texto</span>
        <div class="a11y-fonts" role="group" aria-labelledby="a11yFontLabel">
          <button type="button" data-font=""   aria-label="Tamaño normal">A</button>
          <button type="button" data-font="lg" aria-label="Tamaño grande">A+</button>
          <button type="button" data-font="xl" aria-label="Tamaño muy grande">A++</button>
        </div>
      </div>
      <label class="a11y-row">
        <span>Alto contraste</span>
        <input type="checkbox" data-pref="contrast" />
      </label>
      <label class="a11y-row">
        <span>Reducir animaciones</span>
        <input type="checkbox" data-pref="motion" />
      </label>
      <label class="a11y-row">
        <span>Subrayar enlaces</span>
        <input type="checkbox" data-pref="links" />
      </label>`;

  document.body.appendChild(panel);
  if (navBar) {
    navBar.appendChild(widget);            // último ítem de esa barra
  } else {
    document.body.appendChild(widget);     // páginas sin ninguna barra: botón flotante
  }

  const btn = widget.querySelector('#a11yBtn');

  /* Mismo panel desde el menú móvil, donde .nav-links está oculto. Ícono
     también acá (no la palabra), con aria-label para lectores de pantalla. */
  const abrirDesdeMobile = document.createElement('a');
  abrirDesdeMobile.href = '#';
  abrirDesdeMobile.setAttribute('aria-label', 'Accesibilidad');
  abrirDesdeMobile.title = 'Opciones de accesibilidad';
  abrirDesdeMobile.style.display = 'inline-flex';
  abrirDesdeMobile.style.width = '22px';
  abrirDesdeMobile.style.height = '22px';
  abrirDesdeMobile.innerHTML = A11Y_ICON_SVG;
  document.querySelectorAll('.mobile-nav').forEach(nav => {
    const copia = abrirDesdeMobile.cloneNode(true);
    copia.addEventListener('click', e => {
      e.preventDefault();
      // Sin esto, el click sigue subiendo hasta el listener de "click
      // afuera cierra" de más abajo (.mobile-nav no es descendiente de
      // `widget` ni de `panel`) y cierra el panel en el mismo evento
      // que lo abre.
      e.stopPropagation();
      document.getElementById('mobileClose')?.click();
      togglePanel(true);
    });
    nav.insertBefore(copia, nav.querySelector('.theme-toggle'));
  });

  /* Mismo criterio en las páginas que reemplazaron el menú móvil por la
     bottom nav: el disparador vive en la tira de utilidades del header
     (#navUtility), no hay drawer que cerrar antes de abrir el panel.
     stopPropagation: #navUtility no es descendiente de `widget` ni de
     `panel`, así que sin esto el listener de "click afuera cierra" (más
     abajo) lo trataría como un click externo y cerraría el panel en el
     mismo evento que lo abre. */
  document.querySelectorAll('.nav-utility').forEach(host => {
    // El widget principal ya vive acá (es el primer destino que se elige):
    // agregar además el clon dejaría dos íconos iguales pegados.
    if (host.contains(widget)) { return; }
    const copia = abrirDesdeMobile.cloneNode(true);
    copia.addEventListener('click', e => {
      e.preventDefault();
      e.stopPropagation();
      togglePanel(true);
    });
    host.appendChild(copia);
  });

  const aplicar = () => {
    root.classList.toggle('a11y-font-lg',  prefs.font === 'lg');
    root.classList.toggle('a11y-font-xl',  prefs.font === 'xl');
    root.classList.toggle('a11y-contrast', !!prefs.contrast);
    root.classList.toggle('a11y-motion',   !!prefs.motion);
    root.classList.toggle('a11y-links',    !!prefs.links);
    panel.querySelectorAll('[data-font]').forEach(b => {
      b.classList.toggle('active', (prefs.font || '') === b.dataset.font);
    });
    panel.querySelectorAll('[data-pref]').forEach(chk => {
      chk.checked = !!prefs[chk.dataset.pref];
    });
  };

  function togglePanel(abrir) {
    panel.classList.toggle('hidden', !abrir);
    btn.setAttribute('aria-expanded', String(abrir));
  }
  const guardar = () => {
    try { localStorage.setItem(A11Y_KEY, JSON.stringify(prefs)); } catch { /* modo privado */ }
  };

  btn.addEventListener('click', () => togglePanel(panel.classList.contains('hidden')));
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && !panel.classList.contains('hidden')) {
      togglePanel(false);
      btn.focus();
    }
  });
  document.addEventListener('click', e => {
    const dentro = widget.contains(e.target) || panel.contains(e.target);
    if (!dentro && !panel.classList.contains('hidden')) {
      togglePanel(false);
    }
  });

  panel.querySelectorAll('[data-font]').forEach(b => {
    b.addEventListener('click', () => {
      prefs.font = b.dataset.font || undefined;
      guardar(); aplicar();
    });
  });
  panel.querySelectorAll('[data-pref]').forEach(chk => {
    chk.addEventListener('change', () => {
      prefs[chk.dataset.pref] = chk.checked || undefined;
      guardar(); aplicar();
    });
  });

  /* Todas las opciones arrancan desactivadas: el sitio se ve como está
     diseñado hasta que el usuario elija lo contrario, y su elección queda
     guardada para las próximas visitas. */
  aplicar();
}

/* ─── Tema Oscuro Permanente ─────────────────────────── */
function applyTheme() {
  document.documentElement.setAttribute('data-theme', 'dark');
  try { localStorage.removeItem('tornalyx-theme'); } catch { /* noop */ }
}

function initThemeToggle() {
  applyTheme();
}

/* ─── Usuario actual en paneles privados ─────────────── */
/* Rellena los datos del usuario logueado en los elementos marcados con
   data-user-*. Solo hace la petición si la página tiene esos hooks (así las
   páginas públicas no llaman a /api/me). */
async function initCurrentUser() {
  const hooks = '[data-user-name],[data-user-role],[data-user-avatar],' +
                '[data-user-welcome],[data-user-email],[data-user-nombre],[data-user-apellido]';
  if (!document.querySelector(hooks)) return;

  let me;
  try {
    const res = await fetch('/api/me', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    me = await res.json();
  } catch {
    return;
  }
  if (!me || !me.success) return;

  const nombre   = me.nombre   || '';
  const apellido = me.apellido || '';
  const full     = (nombre + ' ' + apellido).trim();
  const initials = ((nombre[0] || '') + (apellido[0] || '')).toUpperCase();

  /* textContent / value según el tipo de elemento (evita XSS). */
  const fill = (sel, val) => document.querySelectorAll(sel).forEach(el => {
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') el.value = val;
    else el.textContent = val;
  });

  fill('[data-user-name]',     full || nombre);
  fill('[data-user-nombre]',   nombre);
  fill('[data-user-apellido]', apellido);
  fill('[data-user-role]',     me.rol || '');
  fill('[data-user-email]',    me.email || '');

  /* Avatar: foto real si subió una (mismo criterio que perfil.js), si no
     las iniciales sobre el rojo de marca que ya trae el CSS de fondo. */
  document.querySelectorAll('[data-user-avatar]').forEach(el => {
    if (me.avatar_url) {
      el.textContent = '';
      el.style.backgroundImage = `url(${encodeURI(me.avatar_url)})`;
    } else {
      el.style.backgroundImage = '';
      el.textContent = initials || (nombre[0] || '').toUpperCase();
    }
  });
  document.querySelectorAll('[data-user-welcome]').forEach(el => {
    el.textContent = 'Bienvenido/a, ' + (nombre || full);
  });

  /* Si el usuario tiene rol administrador, mostrar acceso a Administración en el sidebar */
  if (me.rol === 'administrador') {
    document.querySelectorAll('#sidebarAdminLink, #sidebarAdminSection, [data-admin-only]').forEach(el => {
      el.style.display = (el.tagName === 'SPAN' || el.classList.contains('sidebar__section')) ? 'block' : 'flex';
      el.classList.remove('hidden');
    });
  }
}

/* ─── Menú de acciones (3 puntos) ─────────────────────── */
/* Delegado en document: abre/cierra sin importar si el .action-menu se
   agregó al DOM después (filas de tabla, tarjetas pintadas por JS). */
function initActionMenus() {
  const cerrarTodos = except => {
    document.querySelectorAll('.action-menu.is-open').forEach(m => {
      if (m === except) return;
      m.classList.remove('is-open');
      m.querySelector('.action-menu__trigger')?.setAttribute('aria-expanded', 'false');
    });
  };

  document.addEventListener('click', e => {
    const trigger = e.target.closest('.action-menu__trigger');
    if (!trigger) { cerrarTodos(); return; }

    const menu = trigger.closest('.action-menu');
    const abrir = !menu.classList.contains('is-open');
    cerrarTodos(abrir ? menu : null);
    menu.classList.toggle('is-open', abrir);
    trigger.setAttribute('aria-expanded', String(abrir));
  });

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') cerrarTodos();
  });
}

/* ─── Easter Egg: Spider-Man Interactivo ───────────────── */
function initSpidermanEasterEgg() {
  document.addEventListener('click', e => {
    const egg = e.target.closest('.spiderman-easter-egg');
    if (!egg) return;
    const fig = egg.querySelector('.spiderman-figure');
    if (fig) {
      fig.classList.remove('flip');
      void fig.offsetWidth; // trigger reflow
      fig.classList.add('flip');
      setTimeout(() => fig.classList.remove('flip'), 750);
    }
    try {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (AudioCtx) {
        const ctx = new AudioCtx();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'triangle';
        osc.frequency.setValueAtTime(460, ctx.currentTime);
        osc.frequency.exponentialRampToValueAtTime(880, ctx.currentTime + 0.08);
        osc.frequency.exponentialRampToValueAtTime(240, ctx.currentTime + 0.18);
        gain.gain.setValueAtTime(0.15, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.18);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.18);
      }
    } catch { /* AudioContext bloqueado o no soportado */ }
  });
}

/* ─── Init global ────────────────────────────────────── */
detectAppleLiquidGlass();

document.addEventListener('DOMContentLoaded', () => {
  detectAppleLiquidGlass();
  initMobileNav();
  initBottomNav();
  initScrollReveal();
  initTabs();
  initImageFallbacks();
  initTabShortcuts();
  initThemeToggle();
  initAccessibility();
  initActionMenus();
  initSpidermanEasterEgg();
  initAuthNav();
  Toast.init();
  Modal.init();
  initCurrentUser();
});

window.Tornalyx = { Toast, Utils, Modal, Api };
