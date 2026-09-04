/* =====================================================
   TORNALYX – admin.js
   Panel de administración: llena las tarjetas KPI y el
   feed de actividad reciente con GET /api/admin/stats.
   ===================================================== */

'use strict';

/* IIFE: evita colisiones de `const` de nivel superior con organizador.js,
   que se carga en la misma página (pestaña Torneos del admin). */
(function () {

const { Api, Utils, Toast, Modal } = window.Tornalyx;

/* Punto y color del feed según el tipo de evento. */
const DOT_POR_TIPO = {
  torneo:    'dot-green',
  resultado: 'dot-yellow',
  usuario:   'dot-muted'
};

/* Color del badge y etiqueta legible de cada rol. */
const ROL_BADGE = {
  participante:  { badge: 'badge-blue', label: 'Participante'  },
  administrador: { badge: 'badge-red',  label: 'Administrador' }
};

/* Punto y etiqueta de cada estado de usuario. */
const ESTADO_USUARIO = {
  activo:     { dot: 'dot-green',  label: 'Activo'     },
  suspendido: { dot: 'dot-muted',  label: 'Suspendido' },
  pendiente:  { dot: 'dot-yellow', label: 'Pendiente'  }
};

/* Todos los usuarios cargados (se filtran en el cliente). */
let usuarios = [];

/**
 * Escribe un número en una tarjeta KPI, formateado con separador de miles.
 * @param {string} id
 * @param {number} valor
 */
function setKpi(id, valor) {
  const el = document.getElementById(id);
  if (el) el.textContent = Number(valor || 0).toLocaleString('es-UY');
}

/**
 * Escribe el texto secundario (delta) de una tarjeta KPI.
 * @param {string} id
 * @param {string} texto
 */
function setDelta(id, texto) {
  const el = document.getElementById(id);
  if (el) el.textContent = texto;
}

/** Vuelca los contadores en las tarjetas KPI. */
function renderKpis(stats) {
  setKpi('kpiUsuarios', stats.usuarios);
  const nuevos = Number(stats.usuarios_nuevos_semana || 0);
  setDelta('kpiUsuariosDelta', nuevos > 0 ? `+${nuevos} esta semana` : 'Sin altas esta semana');

  setKpi('kpiTorneos', stats.torneos_activos);
  setDelta('kpiTorneosDelta', `${Number(stats.torneos_total || 0)} en total`);

  setKpi('kpiEquipos', stats.equipos);
  setDelta('kpiEquiposDelta', 'Equipos aprobados');

  setKpi('kpiPartidos', stats.partidos);
  const semana = Number(stats.partidos_semana || 0);
  setDelta('kpiPartidosDelta', semana > 0 ? `+${semana} esta semana` : 'Sin partidos esta semana');
}

/**
 * HTML de una fila del feed de actividad.
 * @param {Object} ev Evento de /api/admin/stats.
 * @returns {string}
 */
function actividadRowHtml(ev) {
  const esc = Utils.escapeHtml;
  const dot = DOT_POR_TIPO[ev.tipo] || 'dot-muted';
  return `
    <div style="display:flex;align-items:center;gap:var(--space-3);font-size:var(--font-size-sm)">
      <span class="dot ${dot}"></span>
      <span style="color:var(--muted)">${esc(ev.texto)}:</span>
      <span style="color:var(--ink);font-weight:500">${esc(ev.detalle)}</span>
      <span style="margin-left:auto;color:var(--muted-2);font-family:var(--mono);font-size:11px">${esc(Utils.timeAgo(ev.created_at))}</span>
    </div>`;
}

/** Dibuja el feed de actividad reciente. */
function renderActividad(actividad) {
  const cont = document.getElementById('actividadReciente');
  if (!cont) return;
  cont.innerHTML = actividad.length
    ? actividad.map(actividadRowHtml).join('')
    : '<div style="color:var(--muted);font-size:var(--font-size-sm)">Sin actividad reciente.</div>';
}

/**
 * Filas placeholder para una tabla mientras se espera al API.
 * @param {number} cols Cantidad de columnas (debe matchear el colspan real).
 * @param {number} [filas]
 * @returns {string}
 */
function skeletonRowsHtml(cols, filas = 4) {
  const celda = '<td><span class="skeleton" style="display:block;height:14px;width:70%"></span></td>';
  return Array.from({ length: filas }, () => `<tr aria-hidden="true">${celda.repeat(cols)}</tr>`).join('');
}

/**
 * Formatea la fecha de registro (created_at SQL) como dd/mm/aaaa.
 * @param {string} fechaSql
 * @returns {string}
 */
function fechaRegistro(fechaSql) {
  if (!fechaSql) return '—';
  const d = new Date(String(fechaSql).replace(' ', 'T'));
  return isNaN(d) ? '—' : d.toLocaleDateString('es-UY', {
    day: '2-digit', month: '2-digit', year: 'numeric'
  });
}

/**
 * HTML de una fila de la tabla de usuarios.
 * @param {Object} u
 * @returns {string}
 */
function usuarioRowHtml(u) {
  const esc    = Utils.escapeHtml;
  const rol    = ROL_BADGE[u.rol] || { badge: 'badge-muted', label: u.rol || '—' };
  const estado = ESTADO_USUARIO[u.estado] || { dot: 'dot-muted', label: u.estado || '—' };
  const nombre = [u.nombre, u.apellido].filter(Boolean).join(' ');
  const suspendido   = u.estado === 'suspendido';
  const txtToggle    = suspendido ? 'Activar cuenta' : 'Suspender cuenta';
  const iconoToggle  = suspendido
    ? '<svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>'
    : '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/></svg>';

  return `
    <tr>
      <td>${esc(nombre)}</td>
      <td style="color:var(--muted-2)">${esc(u.email)}</td>
      <td><span class="badge ${rol.badge}">${esc(rol.label)}</span></td>
      <td><span class="dot ${estado.dot}"></span>${esc(estado.label)}</td>
      <td style="font-family:var(--mono);font-size:12px">${esc(fechaRegistro(u.created_at))}</td>
      <td>
        <div class="action-menu">
          <button type="button" class="action-menu__trigger" aria-haspopup="true" aria-expanded="false" aria-label="Más acciones para ${esc(nombre)}">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>
          </button>
          <div class="action-menu__list" role="menu">
            <button type="button" class="action-menu__item" role="menuitem" data-editar="${u.id}">
              <svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
              Editar
            </button>
            <button type="button" class="action-menu__item ${suspendido ? '' : 'action-menu__item--danger'}" role="menuitem" data-toggle-estado="${u.id}">
              ${iconoToggle}
              ${txtToggle}
            </button>
          </div>
        </div>
      </td>
    </tr>`;
}

/** Aplica el filtro de rol + búsqueda y redibuja la tabla de usuarios. */
function renderUsuarios() {
  const body = document.getElementById('usuariosBody');
  if (!body) return;

  const rol = (document.getElementById('usuariosRol')?.value || '');
  const q   = (document.getElementById('usuariosBuscar')?.value || '').toLowerCase().trim();

  const filtrados = usuarios.filter(u => {
    if (rol && u.rol !== rol) return false;
    if (!q) return true;
    const texto = `${u.nombre} ${u.apellido} ${u.email}`.toLowerCase();
    return texto.includes(q);
  });

  body.innerHTML = filtrados.length
    ? filtrados.map(usuarioRowHtml).join('')
    : '<tr><td colspan="6" style="text-align:center;color:var(--muted)">No hay usuarios que coincidan.</td></tr>';

  /* "Editar" abre el modal con los datos de ese usuario. */
  body.querySelectorAll('[data-editar]').forEach(b =>
    b.addEventListener('click', () => {
      abrirModalUsuario(usuarios.find(u => u.id === Number(b.dataset.editar)));
    })
  );
  /* "Suspender/Activar cuenta": reenvía los mismos datos del usuario con
     el estado invertido, sin abrir el modal (mismo endpoint que editar). */
  body.querySelectorAll('[data-toggle-estado]').forEach(b =>
    b.addEventListener('click', () => toggleEstadoUsuario(Number(b.dataset.toggleEstado)))
  );

  const footer = document.getElementById('usuariosFooter');
  if (footer) {
    footer.textContent = filtrados.length === usuarios.length
      ? `${usuarios.length} usuario(s)`
      : `Mostrando ${filtrados.length} de ${usuarios.length} usuario(s)`;
  }
}

/** Carga la lista de usuarios y engancha los controles de filtrado. */
async function cargarUsuarios() {
  const body = document.getElementById('usuariosBody');
  if (!body) return;

  body.innerHTML = skeletonRowsHtml(6);
  try {
    const data = await Api.get('/api/admin/usuarios');
    usuarios = data.usuarios || [];
    renderUsuarios();
  } catch (err) {
    body.innerHTML = `<tr><td colspan="6" style="color:var(--muted)">${Utils.escapeHtml(err.message)}</td></tr>`;
    const footer = document.getElementById('usuariosFooter');
    if (footer) footer.textContent = '';
  }
}

/**
 * Abre el modal de usuario. Sin argumento → modo alta; con un usuario → modo
 * edición, con el formulario precargado.
 * @param {Object} [u]
 */
function abrirModalUsuario(u) {
  const form = document.getElementById('usuarioForm');
  if (!form) return;
  form.reset();
  limpiarErroresUsuario();

  const editando = Boolean(u && u.id);
  document.getElementById('usuarioModalTitle').textContent = editando ? 'Editar usuario' : 'Nuevo usuario';
  document.getElementById('usuarioSubmit').textContent     = editando ? 'Guardar cambios' : 'Crear usuario';
  document.getElementById('usuarioPasswordHint').textContent = editando
    ? 'Dejala en blanco para conservar la contraseña actual.'
    : 'Mínimo 8 caracteres, con mayúsculas, minúsculas y números.';

  document.getElementById('usuarioId').value       = editando ? u.id : '';
  if (editando) {
    document.getElementById('usuarioNombre').value   = u.nombre || '';
    document.getElementById('usuarioApellido').value = u.apellido || '';
    document.getElementById('usuarioEmail').value    = u.email || '';
    document.getElementById('usuarioRolCampo').value = u.rol || 'participante';
    document.getElementById('usuarioEstado').value   = u.estado || 'activo';
    /* fecha_nac viene como 'YYYY-MM-DD'; el input date la toma directo. */
    document.getElementById('usuarioFechaNac').value = (u.fecha_nac || '').slice(0, 10);
  }

  Modal.open('usuarioModal');
  document.getElementById('usuarioNombre').focus();
}

/** Quita las marcas de error de todos los campos del formulario de usuario. */
function limpiarErroresUsuario() {
  document.querySelectorAll('#usuarioForm .form-control').forEach(el => { el.style.borderColor = ''; });
}

/**
 * Marca un campo del formulario de usuario como erróneo.
 * @param {string} campo
 */
function marcarErrorUsuario(campo) {
  const el = document.querySelector(`#usuarioForm [name="${campo}"]`);
  if (!el) return;
  el.style.borderColor = 'var(--red)';
  el.focus();
  el.addEventListener('input', () => { el.style.borderColor = ''; }, { once: true });
}

/** Inserta o actualiza un usuario en la lista en memoria y redibuja. */
function upsertUsuario(u) {
  const i = usuarios.findIndex(x => x.id === u.id);
  if (i >= 0) usuarios[i] = u;
  else        usuarios.unshift(u);
  renderUsuarios();
}

/**
 * Suspende o reactiva una cuenta desde el menú de acciones de la fila,
 * sin pasar por el modal. Reenvía los datos actuales del usuario con el
 * estado invertido al mismo endpoint que usa "Editar" (que exige todos
 * los campos, no solo el que cambió).
 * @param {number} id
 */
async function toggleEstadoUsuario(id) {
  const u = usuarios.find(x => x.id === id);
  if (!u) return;
  const nuevoEstado = u.estado === 'suspendido' ? 'activo' : 'suspendido';

  try {
    const data = await Api.post('/api/admin/usuario/actualizar', {
      id: u.id,
      nombre: u.nombre,
      apellido: u.apellido,
      email: u.email,
      rol: u.rol,
      estado: nuevoEstado,
      fecha_nac: (u.fecha_nac || '').slice(0, 10),
    });
    Toast.success(data.mensaje || 'Listo.');
    if (data.usuario) upsertUsuario(data.usuario);
    else await cargarUsuarios();
  } catch (err) {
    Toast.error(err.message);
  }
}

/** Envía el formulario del modal a crear o actualizar según el modo. */
function initUsuarioForm() {
  const form = document.getElementById('usuarioForm');
  if (!form) return;

  form.addEventListener('submit', async e => {
    e.preventDefault();
    limpiarErroresUsuario();

    const editando = document.getElementById('usuarioId').value !== '';
    const url = editando ? '/api/admin/usuario/actualizar' : '/api/admin/usuario/crear';
    const campos = Object.fromEntries(new FormData(form).entries());

    const submit = document.getElementById('usuarioSubmit');
    const original = submit.textContent;
    submit.disabled = true;
    submit.textContent = editando ? 'Guardando…' : 'Creando…';

    try {
      const data = await Api.post(url, campos);
      Toast.success(data.mensaje || 'Listo.');
      if (data.usuario) upsertUsuario(data.usuario);
      else await cargarUsuarios();
      Modal.close('usuarioModal');
    } catch (err) {
      Toast.error(err.message);
      if (err.data && err.data.campo) marcarErrorUsuario(err.data.campo);
    } finally {
      submit.disabled = false;
      submit.textContent = original;
    }
  });
}

/* =====================================================
   Solicitudes de acceso a documentación restringida
   ===================================================== */

/* Nombre legible de cada materia restringida. */
const MATERIA_LABEL = { ciberseguridad: 'Ciberseguridad' };

/* Punto y etiqueta de cada estado de solicitud. */
const SOLICITUD_ESTADO = {
  pendiente: { dot: 'dot-yellow', label: 'Pendiente' },
  aprobado:  { dot: 'dot-green',  label: 'Aprobado'  },
  rechazado: { dot: 'dot-muted',  label: 'Rechazado' }
};

/* Solicitudes cargadas en memoria. */
let solicitudes = [];

/**
 * HTML de una fila de la tabla de solicitudes de acceso.
 * @param {Object} s
 * @returns {string}
 */
function solicitudRowHtml(s) {
  const esc    = Utils.escapeHtml;
  const nombre = [s.nombre, s.apellido].filter(Boolean).join(' ');
  const est    = SOLICITUD_ESTADO[s.estado] || { dot: 'dot-muted', label: s.estado || '—' };
  const mat    = MATERIA_LABEL[s.materia] || s.materia || '—';

  /* Según el estado, se ofrece aprobar y/o rechazar (revocar). */
  const aprobar  = `<a href="#" data-doc-accion="aprobar" data-id="${s.id}" style="color:#34d399;font-size:12px;margin-right:12px">Aprobar</a>`;
  const rechazar = `<a href="#" data-doc-accion="rechazar" data-id="${s.id}" style="color:var(--red-bright);font-size:12px">${s.estado === 'aprobado' ? 'Revocar' : 'Rechazar'}</a>`;
  let acciones;
  if (s.estado === 'pendiente')      acciones = aprobar + rechazar;
  else if (s.estado === 'aprobado')  acciones = rechazar;
  else                               acciones = aprobar;

  return `
    <tr>
      <td>${esc(nombre)}</td>
      <td style="color:var(--muted-2)">${esc(s.email)}</td>
      <td>${esc(mat)}</td>
      <td><span class="dot ${est.dot}"></span>${esc(est.label)}</td>
      <td style="font-family:var(--mono);font-size:12px">${esc(fechaRegistro(s.solicitado_at))}</td>
      <td>${acciones}</td>
    </tr>`;
}

/** Redibuja la tabla de solicitudes y engancha los botones de acción. */
function renderSolicitudes() {
  const body = document.getElementById('solicitudesBody');
  if (!body) return;

  body.innerHTML = solicitudes.length
    ? solicitudes.map(solicitudRowHtml).join('')
    : '<tr><td colspan="6" style="text-align:center;color:var(--muted)">No hay solicitudes.</td></tr>';

  body.querySelectorAll('[data-doc-accion]').forEach(a =>
    a.addEventListener('click', e => {
      e.preventDefault();
      resolverSolicitud(Number(a.dataset.id), a.dataset.docAccion);
    })
  );

  const footer = document.getElementById('solicitudesFooter');
  if (footer) {
    const pend = solicitudes.filter(s => s.estado === 'pendiente').length;
    footer.textContent = `${solicitudes.length} solicitud(es) · ${pend} pendiente(s)`;
  }
}

/** Carga las solicitudes de acceso desde el backend. */
async function cargarSolicitudes() {
  const body = document.getElementById('solicitudesBody');
  if (!body) return;

  body.innerHTML = skeletonRowsHtml(6);
  try {
    const data = await Api.get('/api/admin/doc-solicitudes');
    solicitudes = data.solicitudes || [];
    renderSolicitudes();
  } catch (err) {
    body.innerHTML = `<tr><td colspan="6" style="color:var(--muted)">${Utils.escapeHtml(err.message)}</td></tr>`;
    const footer = document.getElementById('solicitudesFooter');
    if (footer) footer.textContent = '';
  }
}

/**
 * Aprueba o rechaza una solicitud y recarga la lista.
 * @param {number} id
 * @param {string} accion 'aprobar' | 'rechazar'
 */
async function resolverSolicitud(id, accion) {
  if (!id || !accion) return;
  try {
    const data = await Api.post('/api/admin/doc-solicitud/resolver', { id, accion });
    Toast.success(data.mensaje || 'Listo.');
    await cargarSolicitudes();
  } catch (err) {
    Toast.error(err.message);
  }
}

/** Carga las estadísticas del sistema y pinta KPIs + actividad. */
async function cargarStats() {
  try {
    const data = await Api.get('/api/admin/stats');
    renderKpis(data.stats || {});
    renderActividad(data.actividad || []);
  } catch (err) {
    const cont = document.getElementById('actividadReciente');
    if (cont) {
      cont.innerHTML = `<div style="color:var(--muted);font-size:var(--font-size-sm)">${Utils.escapeHtml(err.message)}</div>`;
    }
  }
}

/** Avisa en el panel si falta correr alguna migración .sql en esta base. */
async function chequearSalud() {
  const banner = document.getElementById('saludBanner');
  if (!banner) return;
  try {
    const data = await Api.get('/api/admin/salud');
    const faltantes = data.migraciones_faltantes || [];
    if (!faltantes.length) {
      banner.classList.add('hidden');
      return;
    }
    banner.innerHTML = '⚠️ Falta correr en la base: <strong>' +
      faltantes.map(Utils.escapeHtml).join(', ') + '</strong>.';
    banner.classList.remove('hidden');
  } catch {
    /* Si /api/admin/salud falla, no tapamos el resto del panel con esto. */
  }
}

document.addEventListener('DOMContentLoaded', () => {
  /* Solo corre en la vista del panel de administración. */
  if (document.getElementById('kpiUsuarios')) {
    cargarStats();
    chequearSalud();
  }

  if (document.getElementById('usuariosBody')) {
    cargarUsuarios();
    initUsuarioForm();
    document.getElementById('usuariosRol')?.addEventListener('change', renderUsuarios);
    document.getElementById('nuevoUsuarioBtn')?.addEventListener('click', () => abrirModalUsuario());
    const buscar = document.getElementById('usuariosBuscar');
    if (buscar) buscar.addEventListener('input', Utils.debounce(renderUsuarios, 200));
  }

  if (document.getElementById('solicitudesBody')) {
    cargarSolicitudes();
    document.getElementById('solicitudesRefrescar')?.addEventListener('click', cargarSolicitudes);
  }
});

})();
