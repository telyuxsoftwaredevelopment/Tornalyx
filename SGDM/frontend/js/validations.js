/* =====================================================
   TORNALYX – validations.js
   Validaciones de formularios en el cliente
   Login, Registro, campos en tiempo real
   ===================================================== */

'use strict';

/* ─── Helpers de autenticación (AJAX) ────────────────── */
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/* Redirige al panel según el rol devuelto por el backend. "Organizador" ya
   no es un rol de cuenta: cualquier usuario logueado entra a "Mis torneos". */
function redirectByRole(rol) {
  window.location.href = rol === 'administrador' ? '/admin/dashboard' : '/organizador/dashboard';
}

/* Muestra un mensaje de error (toast si existe, alert como fallback). */
function authError(msg) {
  if (window.Tornalyx?.Toast) window.Tornalyx.Toast.error(msg);
  else alert(msg);
}

/* Lee el valor de una cookie por nombre (devuelve '' si no existe). */
function getCookie(name) {
  const match = document.cookie.match(
    new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)')
  );
  return match ? decodeURIComponent(match[1]) : '';
}

/* POST de un FormData con token CSRF y parseo de la respuesta JSON. */
async function postForm(url, data) {
  const res = await fetch(url, {
    method: 'POST',
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-Token': getCookie('XSRF-TOKEN'),
    },
    body: data,
  });
  return res.json();
}

/* Estado de carga en un botón de submit: cambia el texto y lo deshabilita
   mientras se espera la respuesta del servidor. */
function setBtnLoading(btn, loading) {
  if (!btn) return;
  if (loading) {
    btn.dataset.originalText = btn.dataset.originalText || btn.textContent;
    btn.textContent = btn.dataset.loadingText || 'Un momento…';
    btn.classList.add('is-loading');
    btn.disabled = true;
  } else {
    btn.textContent = btn.dataset.originalText || btn.textContent;
    btn.classList.remove('is-loading');
    btn.disabled = false;
  }
}

/* Sacude un elemento para reforzar visualmente un error (p. ej. login
   fallido). Se saca y se vuelve a poner la clase para poder re-disparar
   la animación en fallos consecutivos. */
function shakeEl(el) {
  if (!el) return;
  el.classList.remove('auth-shake');
  void el.offsetWidth; // fuerza reflow
  el.classList.add('auth-shake');
}

/* ─── Toggle visibilidad de contraseña ──────────────── */
/* El ícono es siempre el ojo; el estado "visible" se marca con la clase
   .is-visible (estilo en CSS) y el aria-label, sin cambiar de emoji. */
function togglePassVisibility(btn, input) {
  if (!input) return;
  const mostrar = input.type === 'password';
  input.type = mostrar ? 'text' : 'password';
  btn.classList.toggle('is-visible', mostrar);
  btn.setAttribute('aria-label', mostrar ? 'Ocultar contraseña' : 'Mostrar contraseña');
}

function initPasswordToggle() {
  document.querySelectorAll('[data-toggle-pass]').forEach(btn => {
    btn.addEventListener('click', function () {
      togglePassVisibility(this, document.getElementById(this.dataset.togglePass || 'password'));
    });
  });

  /* Fallback para el botón con id fijo en login.html */
  const legacyBtn = document.getElementById('togglePassword');
  if (legacyBtn && !legacyBtn.dataset.togglePass) {
    legacyBtn.addEventListener('click', function () {
      togglePassVisibility(this, document.getElementById('password'));
    });
  }
}

/* ─── Validación del formulario de Login ─────────────── */
function initLoginValidation() {
  const form = document.getElementById('loginForm');
  if (!form) return;

  const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  /* Validación en tiempo real */
  form.querySelectorAll('input').forEach(input => {
    input.addEventListener('blur', () => validateField(input));
    input.addEventListener('input', () => {
      const errEl = document.getElementById(input.id + 'Error');
      if (errEl) errEl.classList.add('hidden');
      input.classList.remove('input-error');
    });
  });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    let valid = true;

    const email    = document.getElementById('email');
    const password = document.getElementById('password');
    const emailErr = document.getElementById('emailError');
    const passErr  = document.getElementById('passwordError');

    if (emailErr) emailErr.classList.add('hidden');
    if (passErr)  passErr.classList.add('hidden');

    if (!email?.value || !EMAIL_RE.test(email.value)) {
      if (emailErr) emailErr.classList.remove('hidden');
      email?.classList.add('input-error');
      if (valid) email?.focus();
      valid = false;
    }
    if (!password?.value) {
      if (passErr) passErr.classList.remove('hidden');
      password?.classList.add('input-error');
      if (valid) password?.focus();
      valid = false;
    }
    if (!valid) return;

    const submitBtn = document.getElementById('loginBtn');
    setBtnLoading(submitBtn, true);
    try {
      const json = await postForm('/login', new FormData(form));
      if (json.success && json.twofa) {
        showOtpStep(json.target_masked);
      } else if (json.success) {
        redirectByRole(json.rol);
      } else {
        let msg = json.error || 'No se pudo iniciar sesión.';
        if (typeof json.intentos_restantes === 'number') {
          msg += ` Intentos restantes: ${json.intentos_restantes}.`;
        }
        authError(msg);
        shakeEl(document.getElementById('credCard'));
      }
    } catch {
      authError('Error de conexión. Intentá de nuevo.');
    } finally {
      setBtnLoading(submitBtn, false);
    }
  });

  function validateField(input) {
    const errEl = document.getElementById(input.id + 'Error');
    if (!errEl) return;
    const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    let invalid = false;
    if (input.type === 'email')    invalid = !EMAIL_RE.test(input.value);
    if (input.required)            invalid = invalid || !input.value.trim();
    errEl.classList.toggle('hidden', !invalid);
    input.classList.toggle('input-error', invalid);
  }
}

/* ─── MFA: verificar código (2FA por email) con Animación OTP Orbital v3 ─── */

/* Oculta los pasos previos, posiciona los slots en órbita y ejecuta la animación de entrada */
function showOtpStep(targetMasked) {
  const otp = document.getElementById('otpCard');
  if (!otp) return;
  document.getElementById('credCard')?.classList.add('hidden');
  otp.classList.remove('hidden');
  const t = document.getElementById('otpTarget');
  if (t && targetMasked) t.textContent = targetMasked;
  
  if (window.animateOtpOrbit) {
    window.animateOtpOrbit();
  }
  
  setTimeout(() => {
    document.querySelector('.orbit__slot-input')?.focus();
  }, 400);

  startOtpCountdown();
}

const OTP_TTL_SECONDS = 600;
let otpCountdownId = null;

function startOtpCountdown() {
  stopOtpCountdown();
  const label = document.getElementById('otpTimerLabel');
  const dot   = document.getElementById('otpTimerDot');
  if (!label) return;
  const deadline = Date.now() + OTP_TTL_SECONDS * 1000;

  function tick() {
    const remaining = Math.max(0, Math.round((deadline - Date.now()) / 1000));
    const m = String(Math.floor(remaining / 60)).padStart(2, '0');
    const s = String(remaining % 60).padStart(2, '0');
    
    if (dot) {
      dot.className = 'status-dot ' + (remaining <= 30 ? 'status-dot--red' : (remaining <= 120 ? 'status-dot--yellow' : 'status-dot--green'));
    }

    label.textContent = remaining > 0
      ? `Código válido por ${m}:${s}`
      : 'Código vencido. Solicitá uno nuevo.';
    if (remaining <= 0) stopOtpCountdown();
  }
  tick();
  otpCountdownId = setInterval(tick, 1000);
}

function stopOtpCountdown() {
  if (otpCountdownId) { clearInterval(otpCountdownId); otpCountdownId = null; }
}

function initOtpFlow() {
  if (!document.getElementById('otpForm')) return;

  const orbitEl = document.getElementById('otpOrbit');
  const hubEl   = document.getElementById('orbitHub');
  const slots   = Array.from(document.querySelectorAll('.orbit__slot'));
  const inputs  = Array.from(document.querySelectorAll('.orbit__slot-input'));
  const hidden  = document.getElementById('codigo');
  const errEl   = document.getElementById('otpError');

  // Posicionamiento geométrico de los slots en órbita circular
  const ORBIT_RADIUS = 92;
  const count = slots.length;

  function setupOrbitLayout(animate = false) {
    slots.forEach((slot, i) => {
      const angle = (360 / count) * i - 90;
      const rad = angle * (Math.PI / 180);
      const dx = Math.round(ORBIT_RADIUS * Math.cos(rad));
      const dy = Math.round(ORBIT_RADIUS * Math.sin(rad));

      slot.style.transform = `translate(${dx}px, ${dy}px)`;

      if (animate && slot.animate) {
        slot.animate([
          { transform: `rotate(-200deg) translate(${Math.round(dx * 0.3)}px, ${Math.round(dy * 0.3)}px) scale(0.2)`, opacity: 0 },
          { transform: `rotate(0deg) translate(${dx}px, ${dy}px) scale(1)`, opacity: 1 }
        ], {
          duration: 700 + i * 50,
          easing: 'cubic-bezier(.34, 1.56, .64, 1)',
          fill: 'forwards'
        });
      }
    });
  }

  window.animateOtpOrbit = () => setupOrbitLayout(true);
  setupOrbitLayout(false);

  const showErr = (m) => {
    if (errEl) { errEl.textContent = m; errEl.classList.remove('hidden'); }
    else authError(m);
    orbitEl?.classList.add('is-error');
    if (hubEl) hubEl.textContent = '✖';
  };

  const hideErr = () => {
    errEl?.classList.add('hidden');
    orbitEl?.classList.remove('is-error', 'is-ok');
    if (hubEl) hubEl.textContent = '🔒';
  };

  function syncOtp() {
    if (!hidden) return;
    const value = inputs.map(inp => inp.value).join('');
    hidden.value = value;

    slots.forEach((slot, idx) => {
      const isFilled = !!inputs[idx].value;
      slot.classList.toggle('is-filled', isFilled);
    });

    orbitEl?.classList.toggle('is-active', value.length > 0);
  }

  function clearOtp() {
    inputs.forEach(inp => inp.value = '');
    syncOtp();
    orbitEl?.classList.remove('is-error', 'is-ok');
  }

  inputs.forEach((input, i) => {
    input.addEventListener('focus', () => {
      orbitEl?.classList.add('is-active');
    });

    input.addEventListener('blur', () => {
      if (!inputs.some(inp => inp.value)) {
        orbitEl?.classList.remove('is-active');
      }
    });

    input.addEventListener('input', () => {
      input.value = input.value.replace(/[^0-9]/g, '').slice(-1);
      syncOtp();
      if (input.value && inputs[i + 1]) {
        inputs[i + 1].focus();
      } else if (input.value && i === count - 1) {
        // Al completar todos los dígitos, auto-enviar el formulario
        document.getElementById('otpBtn')?.click();
      }
    });

    input.addEventListener('keydown', (e) => {
      if (e.key === 'Backspace' && !input.value && inputs[i - 1]) {
        inputs[i - 1].focus();
      } else if (e.key === 'ArrowRight' && inputs[i + 1]) {
        inputs[i + 1].focus();
      } else if (e.key === 'ArrowLeft' && inputs[i - 1]) {
        inputs[i - 1].focus();
      }
    });

    input.addEventListener('paste', (e) => {
      const text = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
      if (!text) return;
      e.preventDefault();
      text.slice(0, count).split('').forEach((d, j) => {
        if (inputs[j]) inputs[j].value = d;
      });
      syncOtp();
      const nextIdx = Math.min(text.length, count - 1);
      inputs[nextIdx]?.focus();
      if (text.length >= count) {
        document.getElementById('otpBtn')?.click();
      }
    });
  });

  // Consultar al cargar si hay un desafío pendiente
  (async function checkPending() {
    try {
      const res = await fetch('/api/2fa/estado', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const json = await res.json();
      if (!json || !json.pending) return;
      showOtpStep(json.target_masked);
    } catch { /* login normal */ }
  })();

  // Envío del formulario OTP
  document.getElementById('otpForm')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    hideErr();
    const codigo = (hidden?.value || '').trim();
    if (!/^[0-9]{6}$/.test(codigo)) {
      showErr('Ingresá el código de 6 dígitos.');
      return;
    }

    const data = new FormData();
    data.append('codigo', codigo);
    const submitBtn = document.getElementById('otpBtn');
    setBtnLoading(submitBtn, true);

    try {
      const json = await postForm('/login/verificar', data);
      if (json.success) {
        orbitEl?.classList.remove('is-error');
        orbitEl?.classList.add('is-ok');
        if (hubEl) hubEl.textContent = '✓';
        setTimeout(() => redirectByRole(json.rol), 400);
      } else {
        showErr(json.error || 'Código incorrecto.');
        shakeEl(orbitEl);
        clearOtp();
        inputs[0]?.focus();
      }
    } catch {
      showErr('Error de conexión. Intentá de nuevo.');
    } finally {
      setBtnLoading(submitBtn, false);
    }
  });

  // Reenviar código
  document.getElementById('resendBtn')?.addEventListener('click', async function (e) {
    e.preventDefault();
    if (this.style.pointerEvents === 'none') return;
    hideErr();
    try {
      const json = await postForm('/login/reenviar', new FormData());
      if (json.success) {
        if (window.Tornalyx?.Toast) window.Tornalyx.Toast.success('Código reenviado.');
        clearOtp();
        if (json.target_masked) showOtpStep(json.target_masked);
        startResendCooldown(60);
      } else {
        showErr(json.error || 'No se pudo reenviar el código.');
      }
    } catch {
      showErr('Error de conexión. Intentá de nuevo.');
    }
  });

  // Volver al login normal
  document.getElementById('backToLogin')?.addEventListener('click', function (e) {
    e.preventDefault();
    document.getElementById('otpCard')?.classList.add('hidden');
    document.getElementById('credCard')?.classList.remove('hidden');
    clearOtp();
    hideErr();
    stopOtpCountdown();
  });

  syncOtp();
}

/* Deshabilita el enlace "Reenviar" durante unos segundos. */
function startResendCooldown(seconds) {
  const link = document.getElementById('resendBtn');
  const span = document.getElementById('resendCooldown');
  if (!link) return;
  let s = seconds;
  link.style.pointerEvents = 'none';
  link.style.opacity = '.5';
  (function tick() {
    if (span) span.textContent = s > 0 ? ` (${s}s)` : '';
    if (s <= 0) {
      link.style.pointerEvents = '';
      link.style.opacity = '';
      return;
    }
    s--;
    setTimeout(tick, 1000);
  })();
}

/* ─── Medidor de fortaleza de contraseña ─────────────── */
function initPasswordStrength() {
  const passInput = document.getElementById('passReg');
  if (!passInput) return;

  const card  = document.getElementById('strengthCard');
  const icon  = document.getElementById('strengthIcon');
  const fill  = document.getElementById('strengthFill');
  const label = document.getElementById('strengthLabel');
  if (!fill || !label) return;

  const levels = [
    { w: '0%',   tier: 'var(--muted-2)',                icon: '🔓', text: 'Ingresa una contraseña' },
    { w: '25%',  tier: 'var(--red-bright)',              icon: '📎', text: 'Débil — se descifra al toque' },
    { w: '50%',  tier: 'var(--color-warning,#f59e0b)',   icon: '🔑', text: 'Regular' },
    { w: '75%',  tier: '#eab308',                        icon: '🔒', text: 'Buena' },
    { w: '100%', tier: '#22c55e',                        icon: '🛡️', text: 'Fuerte' }
  ];

  passInput.addEventListener('input', function () {
    const val = this.value;
    let score = 0;
    if (val.length >= 8)          score++;
    if (/[A-Z]/.test(val))        score++;
    if (/[0-9]/.test(val))        score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const lv = levels[score] || levels[0];
    fill.style.width      = lv.w;
    fill.style.background = lv.tier;
    label.textContent     = lv.text;
    if (icon) icon.textContent = lv.icon;
    if (card) {
      card.style.setProperty('--tier', lv.tier);
      card.toggleAttribute('data-tier', !!val);
    }
  });
}

/* ─── Requisitos de contraseña (checklist en vivo) ───── */
/* Refleja exactamente lo que valida el backend (AuthController::passwordEsFuerte):
   8+ caracteres, mayúscula, minúscula y número. Visible desde el inicio. */
function initPasswordRequirements() {
  const input = document.getElementById('passReg');
  const list  = document.getElementById('passReqs');
  if (!input || !list) return;

  const rules = {
    length: v => v.length >= 8,
    upper:  v => /[A-Z]/.test(v),
    lower:  v => /[a-z]/.test(v),
    number: v => /[0-9]/.test(v),
  };

  const evaluate = () => {
    const v = input.value;
    list.querySelectorAll('li[data-req]').forEach(li => {
      const rule = rules[li.dataset.req];
      li.classList.toggle('met', !!(rule && rule(v)));
    });
  };

  input.addEventListener('input', evaluate);
  evaluate(); // estado inicial
}

/* ─── Sugerencias de dominio de email ─────────────────── */
const EMAIL_DOMAINS = ['gmail.com', 'hotmail.com', 'outlook.com', 'yahoo.com', 'icloud.com'];

function initEmailDomainSuggest() {
  document.querySelectorAll('input[type="email"]').forEach(input => {
    const box = document.getElementById(input.id + 'Suggest');
    if (!box) return;

    function render() {
      const val = input.value;
      const at  = val.indexOf('@');
      if (at === -1) { box.classList.add('hidden'); box.innerHTML = ''; return; }
      const typed   = val.slice(at + 1).toLowerCase();
      const opciones = EMAIL_DOMAINS.filter(d => d.startsWith(typed) && d !== typed);
      if (!opciones.length) { box.classList.add('hidden'); box.innerHTML = ''; return; }
      box.innerHTML = opciones.map(d => `<button type="button">${val.slice(0, at + 1)}${d}</button>`).join('');
      box.classList.remove('hidden');
    }

    input.addEventListener('input', render);
    input.addEventListener('blur', () => setTimeout(() => box.classList.add('hidden'), 150));
    box.addEventListener('click', (e) => {
      const btn = e.target.closest('button');
      if (!btn) return;
      input.value = btn.textContent;
      box.classList.add('hidden');
      input.dispatchEvent(new Event('input'));
      input.focus();
    });
  });
}

/* ─── Avatar con iniciales en vivo (panel promo de registro) ── */
function initPromoAvatar() {
  const nombre   = document.getElementById('nombre');
  const apellido = document.getElementById('apellido');
  const avatar   = document.getElementById('promoAvatar');
  if (!nombre || !apellido || !avatar) return;

  const update = () => {
    const iniciales = ((nombre.value.trim()[0] || '') + (apellido.value.trim()[0] || '')).toUpperCase();
    avatar.textContent = iniciales || '?';
  };
  nombre.addEventListener('input', update);
  apellido.addEventListener('input', update);
}

/* ─── Check en vivo: "confirmar contraseña" coincide ──── */
function initPasswordConfirmCheck() {
  const pass    = document.getElementById('passReg');
  const confirm = document.getElementById('passConfirm');
  const hint    = document.getElementById('confirmHint');
  if (!pass || !confirm || !hint) return;

  const evaluate = () => {
    if (!confirm.value) { hint.textContent = ''; hint.className = 'confirm-hint'; return; }
    const coincide = confirm.value === pass.value;
    hint.textContent = coincide ? '✓ Coinciden' : 'Todavía no coinciden';
    hint.className = 'confirm-hint ' + (coincide ? 'ok' : 'no');
  };
  confirm.addEventListener('input', evaluate);
  pass.addEventListener('input', evaluate);
}

/* ─── Envío del formulario de Registro (AJAX) ────────── */
function initRegistroSubmit() {
  const form = document.getElementById('registroForm');
  if (!form) return;

  form.addEventListener('submit', async function (e) {
    e.preventDefault();

    const nombre      = document.getElementById('nombre');
    const email       = document.getElementById('emailReg');
    const fecha       = document.getElementById('fechaNac');
    const pass        = document.getElementById('passReg');
    const passConfirm = document.getElementById('passConfirm');
    const terminos    = document.getElementById('terminos');

    /* Como ahora todo está en una sola pantalla (sin el paso "Continuar" que
       antes validaba estos campos), los chequeamos acá antes de enviar. */
    if (!nombre?.value.trim()) {
      authError('Ingresá tu nombre.');
      nombre?.focus();
      return;
    }
    if (!email?.value || !EMAIL_RE.test(email.value)) {
      authError('Ingresá un correo electrónico válido.');
      email?.focus();
      return;
    }
    const pv = pass?.value || '';
    if (pv.length < 8 || !/[A-Z]/.test(pv) || !/[a-z]/.test(pv) || !/[0-9]/.test(pv)) {
      authError('La contraseña no cumple los requisitos: 8+ caracteres, mayúscula, minúscula y número.');
      pass?.focus();
      return;
    }
    if (!fecha?.value) {
      authError('Ingresá tu fecha de nacimiento.');
      fecha?.focus();
      return;
    }
    // El año de <input type="date"> no está acotado en todos los navegadores
    // (se puede escribir "20001-07-13" a mano): sin este chequeo el backend
    // lo rechaza recién después del viaje al servidor. El regex exige año de
    // EXACTAMENTE 4 dígitos: con eso, comparar como string contra otra fecha
    // ISO de 4 dígitos es válido (mismo largo, mismo formato).
    if (!/^\d{4}-\d{2}-\d{2}$/.test(fecha.value)
        || fecha.value < '1900-01-01'
        || fecha.value > new Date().toISOString().slice(0, 10)) {
      authError('La fecha de nacimiento no es válida.');
      fecha.focus();
      return;
    }
    /* Validaciones de cliente que el backend no puede inferir */
    if ((pass?.value || '') !== (passConfirm?.value || '')) {
      authError('Las contraseñas no coinciden.');
      return;
    }
    if (terminos && !terminos.checked) {
      authError('Debés aceptar los términos de uso.');
      return;
    }

    /* Los campos viven fuera del <form>; se recogen por name, buscando
       dentro de la cara de registro. Buscar en document sin acotar agarraba
       el campo equivocado: "email" y "password" existen en las DOS caras
       de la tarjeta (login y registro), y al estar el login primero en el
       DOM, document.querySelector devolvía sus inputs vacíos en vez de los
       que el usuario completó acá, mandando el registro con email/password
       en blanco (de ahí el "Todos los campos son obligatorios"). */
    const scope = document.getElementById('authFaceSignup') || document;
    const data = new FormData();
    ['nombre', 'apellido', 'email', 'password', 'fecha_nacimiento', 'rol']
      .forEach(name => {
        const el = scope.querySelector(`[name="${name}"]`);
        if (el) data.append(name, el.value);
      });

    const submitBtn = document.getElementById('registroBtn');
    setBtnLoading(submitBtn, true);
    try {
      const json = await postForm('/registro', data);
      if (json.success && json.twofa) {
        // 2FA obligatorio: la cuenta se creó pero falta verificar el código.
        // Vamos al login, que mostrará el paso del código (desafío en sesión).
        if (window.Tornalyx?.Toast) window.Tornalyx.Toast.success('¡Cuenta creada! Te enviamos un código.');
        window.location.href = '/login';
      } else if (json.success) {
        if (window.Tornalyx?.Toast) window.Tornalyx.Toast.success('¡Cuenta creada!');
        redirectByRole(json.rol);
      } else {
        authError(json.error || 'No se pudo crear la cuenta.');
        /* No se sacude .auth-face--back directamente: ya tiene su propio
           transform:rotateY(180deg) en desktop, y la animación de shake
           (que solo anima translateX) lo pisaría y "desflipearía" la
           tarjeta por un instante. El panel interno no tiene transform
           propio, así que es seguro sacudirlo a él. */
        shakeEl(document.querySelector('#authFaceSignup .auth-face__inner'));
      }
    } catch {
      authError('Error de conexión. Intentá de nuevo.');
    } finally {
      setBtnLoading(submitBtn, false);
    }
  });
}

/* ─── Validación en tiempo real genérica ─────────────── */
function initRealtimeValidation() {
  const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  document.querySelectorAll('input[type="email"]').forEach(input => {
    input.addEventListener('blur', function () {
      const ok = EMAIL_RE.test(this.value);
      this.classList.toggle('input-error', this.value && !ok);
    });
  });
  document.querySelectorAll('input[required]').forEach(input => {
    input.addEventListener('blur', function () {
      this.classList.toggle('input-error', !this.value.trim());
    });
    input.addEventListener('input', function () {
      if (this.value.trim()) this.classList.remove('input-error');
    });
  });
}

/* ─── Init ───────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  initPasswordToggle();
  initLoginValidation();
  initOtpFlow();
  initPasswordStrength();
  initPasswordRequirements();
  initPasswordConfirmCheck();
  initEmailDomainSuggest();
  initPromoAvatar();
  initRegistroSubmit();
  initRealtimeValidation();
});
