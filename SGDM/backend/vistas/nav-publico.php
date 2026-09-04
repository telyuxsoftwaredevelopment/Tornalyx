<?php
/**
 * Parcial: navbar pública reutilizable (home, torneos, jugadores, docs).
 * Recibe $activo con la sección en la que está parado el usuario
 * ('inicio', 'torneos', 'jugadores' o 'documentacion') para resaltar
 * ese enlace; si no se pasa, ninguno queda marcado.
 */
$activo = $activo ?? '';

/** Resalta el enlace de la sección actual y lo anuncia a los lectores de pantalla. */
$marca = static fn(string $seccion): string =>
    $seccion === $activo ? ' style="color:var(--ink)" aria-current="page"' : '';

/** Igual que $marca pero sin el color inline: la bottom nav ya resuelve el
 * color del tab activo por CSS ([aria-current="page"]); un style inline acá
 * lo pisaría. */
$marcaBN = static fn(string $seccion): string =>
    $seccion === $activo ? ' aria-current="page"' : '';
?>
<header class="nav">
  <div class="wrap nav-in">
    <a class="brand" href="/">
      <span class="badge badge-crop" style="background-image:url(../assets/ICONO.png)" role="img" aria-label="Tornalyx"></span>
      Tornalyx
    </a>
    <nav class="nav-links" aria-label="Navegación principal">
      <a href="/"<?= $marca('inicio') ?>>Inicio</a>
      <a href="/torneos"<?= $marca('torneos') ?>>Torneos</a>
      <a href="/jugadores"<?= $marca('jugadores') ?>>Jugadores</a>
      <a href="/documentacion"<?= $marca('documentacion') ?>>Documentación</a>
    </nav>
    <div class="nav-right">
      <a href="/login" class="nav-link-login">Iniciar Sesión</a>
      <a href="/login?tab=registro" class="btn btn-nav-register">Registrarse</a>
    </div>
    <div class="nav-utility" id="navUtility">
    </div>
  </div>
</header>

<!-- Navegación móvil: pill líquido sobre el tab activo (main.js:initBottomNav) -->
<nav class="bottom-nav" aria-label="Navegación principal">
  <span class="bottom-nav__pill" aria-hidden="true"></span>
  <a href="/"<?= $marcaBN('inicio') ?> class="bottom-nav__item">
    <span class="bottom-nav__icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M4 11.5 12 4l8 7.5"/>
        <path d="M6 10.5V19a1 1 0 0 0 1 1h3v-5.5h4V20h3a1 1 0 0 0 1-1v-8.5"/>
      </svg>
    </span>
    <span class="bottom-nav__label">Inicio</span>
  </a>
  <a href="/torneos"<?= $marcaBN('torneos') ?> class="bottom-nav__item">
    <span class="bottom-nav__icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M7 4h10v3.5a5 5 0 0 1-10 0V4Z"/>
        <path d="M7 5.2H5.2a2 2 0 0 0 0 4H7"/>
        <path d="M17 5.2h1.8a2 2 0 0 1 0 4H17"/>
        <path d="M12 12.5V16"/>
        <path d="M9 20h6"/>
        <path d="M9.5 16h5l.6 4h-6.2l.6-4Z"/>
      </svg>
    </span>
    <span class="bottom-nav__label">Torneos</span>
  </a>
  <a href="/jugadores"<?= $marcaBN('jugadores') ?> class="bottom-nav__item">
    <span class="bottom-nav__icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="9" cy="8.2" r="3.2"/>
        <path d="M3.5 20c0-3.6 2.5-6.3 5.5-6.3s5.5 2.7 5.5 6.3"/>
        <circle cx="16.8" cy="9" r="2.3"/>
        <path d="M15 14.2c2.4.5 4.2 2.7 4.5 5.8"/>
      </svg>
    </span>
    <span class="bottom-nav__label">Jugadores</span>
  </a>
  <a href="/documentacion"<?= $marcaBN('documentacion') ?> class="bottom-nav__item">
    <span class="bottom-nav__icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M7.5 3h6l4 4v12.5a1 1 0 0 1-1 1h-9a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/>
        <path d="M13.5 3v4h4"/>
        <path d="M9 13h6M9 16.5h6M9 9.5h2"/>
      </svg>
    </span>
    <span class="bottom-nav__label">Documentación</span>
  </a>
  <a href="/login" class="bottom-nav__item bottom-nav__account" aria-label="Entrar" title="Entrar">
    <span class="bottom-nav__icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="8.2" r="3.4"/>
        <path d="M4.8 20c0-4 3.2-7.2 7.2-7.2s7.2 3.2 7.2 7.2"/>
      </svg>
    </span>
    <span class="bottom-nav__label">Cuenta</span>
  </a>
</nav>
