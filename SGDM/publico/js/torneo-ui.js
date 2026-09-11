/* =====================================================
   TORNALYX – torneo-ui.js
   Vocabulario compartido para mostrar torneos:
   etiquetas, badges, fechas y progreso.
   Lo usan el panel del organizador, el listado público
   y la página de detalle, para que un mismo torneo se
   vea igual en todas las pantallas.
   ===================================================== */

'use strict';

const TorneoUI = {

  /* Etiqueta legible de cada valor del ENUM `formato`. */
  FORMATOS: {
    liga:                'Liga',
    eliminacion_directa: 'Eliminación directa',
    suizo:               'Sistema suizo',
    grupos_playoff:      'Grupos + playoffs'
  },

  /* Etiqueta y color de cada valor del ENUM `estado`. */
  ESTADOS: {
    borrador:    { label: 'Borrador',              badge: 'badge-muted'  },
    inscripcion: { label: 'Inscripciones abiertas', badge: 'badge-yellow' },
    en_curso:    { label: 'En curso',              badge: 'badge-green'  },
    finalizado:  { label: 'Finalizado',            badge: 'badge-muted'  },
    cancelado:   { label: 'Cancelado',             badge: 'badge-red'    }
  },

  /* Ícono por disciplina; se compara en minúsculas y sin acentos. */
  ICONOS: {
    futbol: '⚽', basquetbol: '🏀', baloncesto: '🏀', basket: '🏀',
    tenis: '🎾', 'tenis de mesa': '🏓', voley: '🏐', voleibol: '🏐',
    ajedrez: '♟️', esports: '🎮', running: '🏃', natacion: '🏊'
  },

  /**
   * Nombre legible del formato.
   * @param {string} formato
   * @returns {string}
   */
  formato(formato) {
    return this.FORMATOS[formato] || formato || '—';
  },

  /**
   * Etiqueta y clase de badge del estado.
   * @param {string} estado
   * @returns {{label:string, badge:string}}
   */
  estado(estado) {
    return this.ESTADOS[estado] || { label: estado || '—', badge: 'badge-muted' };
  },

  /**
   * Ícono representativo de la disciplina.
   * @param {string} disciplina
   * @returns {string}
   */
  icono(disciplina) {
    const key = String(disciplina || '')
      .toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    return this.ICONOS[key] || '🏆';
  },

  /**
   * Formatea una fecha `YYYY-MM-DD` de la BD a formato corto en español.
   * Se construye con componentes locales para que no se corra un día por
   * la conversión a UTC que hace `new Date('2026-03-10')`.
   * @param {string|null} iso
   * @returns {string} Cadena vacía si no hay fecha.
   */
  fecha(iso) {
    if (!iso) return '';
    const [y, m, d] = String(iso).slice(0, 10).split('-').map(Number);
    if (!y || !m || !d) return '';
    return new Date(y, m - 1, d).toLocaleDateString('es-UY', {
      day: '2-digit', month: 'short', year: 'numeric'
    });
  },

  /**
   * Porcentaje de avance del torneo (partidos finalizados sobre el total).
   * @param {Object} t Torneo del API.
   * @returns {number} 0-100
   */
  progreso(t) {
    const total = Number(t.partidos) || 0;
    if (!total) return 0;
    return Math.round((Number(t.partidos_jugados) || 0) / total * 100);
  },

  /**
   * Cantidad de contendientes confirmados: los equipos aprobados si el torneo
   * es por equipos, o las inscripciones aprobadas si es individual.
   * @param {Object} t
   * @returns {number}
   */
  participantes(t) {
    return Math.max(Number(t.equipos) || 0, Number(t.inscriptos) || 0);
  },

  /**
   * Línea de metadatos "Formato · N/M participantes · Inicio dd mmm".
   * @param {Object} t
   * @returns {string}
   */
  resumen(t) {
    const partes = [this.formato(t.formato)];
    partes.push(`${this.participantes(t)}/${t.max_participantes} participantes`);
    const inicio = this.fecha(t.fecha_inicio);
    if (inicio) partes.push(`Inicio ${inicio}`);
    return partes.join(' · ');
  }
};

window.TorneoUI = TorneoUI;
