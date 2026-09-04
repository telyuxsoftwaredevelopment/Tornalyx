/* =====================================================
   TORNALYX – perfil.js
   Página /perfil: carga los datos reales del usuario
   (GET /api/perfil) y maneja la edición de datos, bio,
   contraseña y foto de perfil. Sin datos de ejemplo.
   ===================================================== */

'use strict';

(function () {

  const { Api, Toast, Utils } = window.Tornalyx || {};
  if (!Api) return;

  const BIO_MAX_PALABRAS = 500;

  /* Estado del usuario cargado, para "Restablecer" y para re-render. */
  let usuarioActual = null;

  const $ = (id) => document.getElementById(id);

  const ESTADO_TORNEO = {
    borrador:    ['Borrador',      'badge-gray'],
    inscripcion: ['Inscripciones', 'badge-green'],
    en_curso:    ['En curso',      'badge-green'],
    finalizado:  ['Finalizado',    'badge-gray'],
    cancelado:   ['Cancelado',     'badge-gray'],
  };
  const ESTADO_INSCRIPCION = {
    pendiente: ['Pendiente', 'badge-gray'],
    aprobada:  ['Aprobada',  'badge-green'],
    rechazada: ['Rechazada', 'badge-gray'],
  };

  /* ─── Render del hero y avatar ─────────────────────── */

  function iniciales(u) {
    return (((u.nombre || '')[0] || '') + ((u.apellido || '')[0] || '')).toUpperCase() || '?';
  }

  function pintarAvatar(el, u) {
    if (!el) return;
    if (u.avatar_url) {
      el.textContent = '';
      el.style.backgroundImage = `url(${encodeURI(u.avatar_url)})`;
    } else {
      el.style.backgroundImage = '';
      el.textContent = iniciales(u);
    }
  }

  /* ─── Redes sociales ───────────────────────────────── */
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

  function redesHtml(u) {
    return Object.keys(REDES_ICONS)
      .filter(k => u[k])
      .map(k => `<a href="${Utils.escapeHtml(u[k])}" target="_blank" rel="noopener noreferrer" aria-label="${REDES_LABELS[k]}" title="${REDES_LABELS[k]}">${REDES_ICONS[k]}</a>`)
      .join('');
  }

  function pintarHero(u) {
    if ($('heroNombre')) {
      if (u.nickname) {
        const tagHtml = u.tag ? `<span class="profile-hero-tag">#${Utils.escapeHtml(u.tag)}</span>` : '';
        $('heroNombre').innerHTML = `${Utils.escapeHtml(u.nickname)} ${tagHtml}`;
      } else {
        $('heroNombre').textContent = `${u.nombre} ${u.apellido}`.trim();
      }
    }

    if ($('heroSpidermanEgg')) {
      $('heroSpidermanEgg').innerHTML = Utils.esPerfilKratos(u) ? Utils.spidermanEasterEggHtml() : '';
    } else if (Utils.esPerfilKratos(u) && $('heroNombre') && !$('spiderman-hero-egg')) {
      const spEl = document.createElement('span');
      spEl.id = 'spiderman-hero-egg';
      spEl.innerHTML = Utils.spidermanEasterEggHtml();
      $('heroNombre').parentNode.appendChild(spEl);
    }

    const meta = [];
    if (u.rol) {
      const esAdmin = u.rol === 'administrador';
      meta.push(`<span class="${esAdmin ? 'badge-role-admin' : 'badge-role-user'}">${esAdmin ? 'ADMINISTRADOR' : 'PARTICIPANTE'}</span>`);
    }
    if (u.nickname) {
      meta.push('<span>👤 ' + Utils.escapeHtml(`${u.nombre} ${u.apellido}`.trim()) + '</span>');
    }
    if (u.email) {
      meta.push('<span>✉️ ' + Utils.escapeHtml(u.email) + '</span>');
    }
    if (u.ubicacion) {
      meta.push('<span>📍 ' + Utils.escapeHtml(u.ubicacion) + '</span>');
    }
    if ($('heroMeta')) $('heroMeta').innerHTML = meta.join('');
    if ($('heroRedes')) $('heroRedes').innerHTML = redesHtml(u);

    pintarAvatar($('heroAvatar'), u);
    if ($('editAvatar')) pintarAvatar($('editAvatar'), u);
  }

  /* ─── Render de stats, torneos y equipos ───────────── */

  function pintarStats(s) {
    $('statTorneos').textContent     = s.torneos;
    $('statEquipos').textContent     = s.equipos;
    $('statActivos').textContent     = s.activos;
    $('statFinalizados').textContent = s.finalizados;
  }

  function badge(mapa, clave) {
    const [texto, clase] = mapa[clave] || [clave, 'badge-gray'];
    return `<span class="badge ${clase}">${Utils.escapeHtml(texto)}</span>`;
  }

  function pintarTorneos(torneos) {
    const body  = $('torneosBody');
    const empty = $('torneosEmpty');
    if (!torneos.length) {
      body.innerHTML = '';
      empty.classList.remove('hidden');
      return;
    }
    empty.classList.add('hidden');
    body.innerHTML = torneos.map(t => `
      <tr>
        <td>${Utils.escapeHtml(t.nombre)}</td>
        <td>${Utils.escapeHtml(t.disciplina)}</td>
        <td>${Utils.escapeHtml(t.equipo || 'Individual')}</td>
        <td>${badge(ESTADO_INSCRIPCION, t.inscripcion_estado)}</td>
        <td>${badge(ESTADO_TORNEO, t.estado)}</td>
      </tr>`).join('');
  }

  function pintarEquipos(equipos) {
    const grid  = $('equiposGrid');
    const empty = $('equiposEmpty');
    if (!equipos.length) {
      grid.innerHTML = '';
      empty.classList.remove('hidden');
      return;
    }
    empty.classList.add('hidden');
    grid.innerHTML = equipos.map(e => `
      <div class="team-card">
        <div class="team-card__name">${Utils.escapeHtml(e.nombre)}</div>
        <div class="team-card__meta">
          ${Utils.escapeHtml(e.disciplina)} · ${Utils.escapeHtml(e.torneo)}
          ${Number(e.es_capitan) ? ' · Capitán' : ''}
        </div>
        <div style="margin-top:var(--space-3)">
          ${badge(ESTADO_TORNEO, e.torneo_estado)}
        </div>
      </div>`).join('');
  }

  /* ─── Formulario de datos ──────────────────────────── */

  function llenarFormulario(u) {
    if ($('pNickname'))  $('pNickname').value  = u.nickname  || '';
    if ($('pTag'))       $('pTag').value       = u.tag       || '';
    $('pNombre').value    = u.nombre    || '';
    $('pApellido').value  = u.apellido  || '';
    $('pEmail').value     = u.email     || '';
    $('pFecha').value     = u.fecha_nac || '';
    $('pUbicacion').value = u.ubicacion || '';
    $('pBio').value       = u.bio       || '';
    $('pTwitter').value   = u.twitter_url   || '';
    $('pFacebook').value  = u.facebook_url  || '';
    $('pInstagram').value = u.instagram_url || '';
    $('pYoutube').value   = u.youtube_url   || '';
    $('pTiktok').value    = u.tiktok_url    || '';
    $('pKick').value      = u.kick_url      || '';
    $('pTwitch').value    = u.twitch_url    || '';
    actualizarContadorBio();
  }

  function contarPalabras(texto) {
    const limpio = texto.trim();
    return limpio === '' ? 0 : limpio.split(/\s+/).length;
  }

  function actualizarContadorBio() {
    const contador = $('bioCounter');
    const palabras = contarPalabras($('pBio').value);
    contador.textContent = `${palabras} / ${BIO_MAX_PALABRAS} palabras`;
    contador.classList.toggle('limit', palabras > BIO_MAX_PALABRAS);
  }

  function initFormularios() {
    $('pBio').addEventListener('input', actualizarContadorBio);

    if ($('pTag')) {
      $('pTag').addEventListener('input', (e) => {
        let val = e.target.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
        if (val.length > 5) val = val.slice(0, 5);
        e.target.value = val;
      });
    }

    /* "Restablecer" vuelve a los valores guardados, no a campos vacíos. */
    $('perfilForm').addEventListener('reset', (e) => {
      e.preventDefault();
      if (usuarioActual) llenarFormulario(usuarioActual);
    });

    $('perfilForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const palabras = contarPalabras($('pBio').value);
      if (palabras > BIO_MAX_PALABRAS) {
        Toast.error(`La descripción supera las ${BIO_MAX_PALABRAS} palabras (tiene ${palabras}).`);
        return;
      }
      const nickVal = $('pNickname') ? $('pNickname').value.trim() : '';
      if (nickVal.length > 30) {
        Toast.error('El nickname no puede superar los 30 caracteres.');
        return;
      }
      const tagVal = $('pTag') ? $('pTag').value.trim().replace(/^#/, '').toUpperCase() : '';
      if (tagVal !== '' && !/^[A-Z0-9]{1,5}$/.test(tagVal)) {
        Toast.error('El hashtag solo puede tener hasta 5 letras y números (ej: UY1, 1234).');
        return;
      }
      try {
        const res = await Api.post('/api/perfil/actualizar', {
          nickname:       nickVal,
          tag:            tagVal,
          nombre:         $('pNombre').value.trim(),
          apellido:       $('pApellido').value.trim(),
          email:          $('pEmail').value.trim(),
          fecha_nac:      $('pFecha').value,
          ubicacion:      $('pUbicacion').value.trim(),
          bio:            $('pBio').value.trim(),
          twitter_url:    $('pTwitter').value.trim(),
          facebook_url:   $('pFacebook').value.trim(),
          instagram_url:  $('pInstagram').value.trim(),
          youtube_url:    $('pYoutube').value.trim(),
          tiktok_url:     $('pTiktok').value.trim(),
          kick_url:       $('pKick').value.trim(),
          twitch_url:     $('pTwitch').value.trim(),
        });
        usuarioActual = res.usuario;
        pintarHero(usuarioActual);
        llenarFormulario(usuarioActual);
        Toast.success('Perfil actualizado.');
      } catch (err) {
        Toast.error(err.message);
      }
    });

    $('passForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const nueva = $('passNueva').value;
      if (nueva !== $('passConfirma').value) {
        Toast.error('Las contraseñas no coinciden.');
        return;
      }
      try {
        await Api.post('/api/perfil/password', {
          actual: $('passActual').value,
          nueva,
        });
        e.target.reset();
        Toast.success('Contraseña actualizada.');
      } catch (err) {
        Toast.error(err.message);
      }
    });
  }

  /* ─── Foto de perfil ───────────────────────────────── */

  function initAvatar() {
    const input = $('avatarInput');
    if (!input) return;
    const triggers = [$('avatarBtn'), $('heroAvatar')].filter(Boolean);
    triggers.forEach(el => el.addEventListener('click', () => input.click()));

    input.addEventListener('change', async () => {
      const file = input.files && input.files[0];
      input.value = '';
      if (!file) return;

      if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        Toast.error('Formato no admitido. Usá JPG, PNG o WebP.');
        return;
      }
      if (file.size > 2 * 1024 * 1024) {
        Toast.error('La imagen no puede superar los 2 MB.');
        return;
      }

      const data = new FormData();
      data.append('avatar', file);
      try {
        const res = await fetch('/api/perfil/avatar', {
          method: 'POST',
          body: data,
          credentials: 'same-origin',
          headers: Utils.csrfHeaders(),
        });
        const json = await res.json();
        if (!json.success) throw new Error(json.error || 'No se pudo subir la imagen.');
        usuarioActual.avatar_url = json.avatar_url;
        pintarAvatar($('heroAvatar'), usuarioActual);
        if ($('editAvatar')) pintarAvatar($('editAvatar'), usuarioActual);
        Toast.success('Foto de perfil actualizada.');
      } catch (err) {
        Toast.error(err.message);
      }
    });
  }

  /* ─── Carga inicial ────────────────────────────────── */

  function verificarRolAdmin(u) {
    const adminLink = $('sidebarAdminLink');
    const adminSec  = $('sidebarAdminSection');
    const esAdmin   = (u && u.rol === 'administrador');
    if (adminLink) adminLink.style.display = esAdmin ? 'flex' : 'none';
    if (adminSec)  adminSec.style.display  = esAdmin ? 'block' : 'none';
  }

  async function cargar() {
    try {
      const res = await Api.get('/api/perfil');
      usuarioActual = res.usuario;
      verificarRolAdmin(res.usuario);
      pintarHero(res.usuario);
      pintarStats(res.stats);
      pintarTorneos(res.torneos);
      pintarEquipos(res.equipos);
      llenarFormulario(res.usuario);
    } catch (err) {
      /* Api.get ya redirige al login si la sesión expiró (401). */
      Toast.error(err.message);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    initFormularios();
    initAvatar();
    cargar();
  });

})();
