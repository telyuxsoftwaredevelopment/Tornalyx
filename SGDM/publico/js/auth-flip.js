/* =====================================================
   TORNALYX – auth-flip.js
   - Desktop (PC): Panel Horizontal Deslizante 50/50 (Sliding Overlay)
   - Celular (Mobile): Banner Curvo Expansivo Vertical (TikTok Prosper Samuel)
   ===================================================== */

'use strict';

(function () {
  const container = document.getElementById('authContainer');
  const signInBtn = document.getElementById('signInBtn');
  const signUpBtn = document.getElementById('signUpBtn');
  const mobileToSignupBtn = document.getElementById('mobileToSignupBtn');
  const mobileToLoginBtn = document.getElementById('mobileToLoginBtn');
  const signInPanel = document.getElementById('authFaceLogin');
  const signUpPanel = document.getElementById('authFaceSignup');

  if (!container) return;

  const ROUTES = { login: '/login', signup: '/registro' };
  let isAnimating = false;

  function isMobile() {
    return window.innerWidth <= 820;
  }

  function modeFromPath(path) {
    const clean = (path || '').toLowerCase().replace(/\/+$/, '');
    return clean.endsWith('/registro') ? 'signup' : 'login';
  }

  function focusFirstField(panel) {
    if (!panel) return;
    const field = panel.querySelector('input:not([type="hidden"]):not([disabled])');
    if (field) field.focus({ preventScroll: true });
  }

  function setMode(mode, { push = false, focus = false, animate = true } = {}) {
    const isSignUp = mode === 'signup';
    const currentMode = container.classList.contains('right-panel-active') ? 'signup' : 'login';

    if (currentMode === mode && !container.classList.contains('expanding')) {
      return;
    }

    if (push) {
      const url = ROUTES[mode];
      if (location.pathname.replace(/\/+$/, '') !== url) {
        history.pushState({ authMode: mode }, '', url);
      }
    }

    if (!animate) {
      container.classList.toggle('right-panel-active', isSignUp);
      container.dataset.mode = mode;
      if (focus) focusFirstField(isSignUp ? signUpPanel : signInPanel);
      return;
    }

    if (isMobile()) {
      // ─── MODO CELULAR: Banner Curvo Expansivo (TikTok Prosper Samuel) ───
      if (isAnimating) return;
      isAnimating = true;

      container.classList.add('expanding');

      // Cúspide de la cobertura del banner: cambiar modo y panel
      setTimeout(() => {
        container.dataset.mode = mode;
        container.classList.toggle('right-panel-active', isSignUp);
      }, 420);

      // Contraer banner en la posición opuesta
      setTimeout(() => {
        container.classList.remove('expanding');
      }, 440);

      setTimeout(() => {
        isAnimating = false;
        if (focus) focusFirstField(isSignUp ? signUpPanel : signInPanel);
      }, 440 + 420);

    } else {
      // ─── MODO PC: Transición Horizontal Deslizante 50/50 ───
      container.dataset.mode = mode;
      container.classList.toggle('right-panel-active', isSignUp);

      if (focus) {
        setTimeout(() => {
          focusFirstField(isSignUp ? signUpPanel : signInPanel);
        }, 350);
      }
    }
  }

  // Eventos de botones Desktop Overlay
  signUpBtn?.addEventListener('click', () => setMode('signup', { push: true, focus: true }));
  signInBtn?.addEventListener('click', () => setMode('login', { push: true, focus: true }));

  // Eventos de botones Mobile Banner
  mobileToSignupBtn?.addEventListener('click', () => setMode('signup', { push: true, focus: true }));
  mobileToLoginBtn?.addEventListener('click', () => setMode('login', { push: true, focus: true }));

  // Disparadores genéricos data-auth-toggle (navbars, links, etc.)
  document.querySelectorAll('[data-auth-toggle]').forEach(el => {
    el.addEventListener('click', (e) => {
      const targetMode = el.dataset.authToggle;
      if (targetMode) {
        e.preventDefault();
        setMode(targetMode, { push: true, focus: true });
      }
    });
  });

  // Navegación con historial (Atrás / Adelante del navegador)
  window.addEventListener('popstate', () => {
    setMode(modeFromPath(location.pathname), { push: false });
  });

  // Inicializar estado según la ruta actual o parámetro ?tab=registro sin animación
  const urlParams = new URLSearchParams(window.location.search);
  const initialMode = modeFromPath(location.pathname) || (urlParams.get('tab') === 'registro' ? 'signup' : 'login');
  setMode(initialMode, { push: false, animate: false });
})();
