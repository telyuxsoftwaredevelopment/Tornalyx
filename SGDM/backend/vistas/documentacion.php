<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Documentación y presentaciones del proyecto Tornalyx · SGDM, organizadas por materia y diapositivas interactivas." />
  <script>
    document.documentElement.setAttribute('data-theme','dark');
    // Detección inmediata de Apple / iOS / macOS para Liquid Glass
    (function(){
      var ua = navigator.userAgent || navigator.vendor || window.opera || '';
      var platform = navigator.platform || '';
      var isIOS = /iPad|iPhone|iPod/.test(ua) || (platform === 'MacIntel' && navigator.maxTouchPoints > 1);
      var isMac = /Macintosh|MacIntel|MacPPC|Mac68K/.test(platform) || /Mac OS X/.test(ua);
      var isApple = isIOS || isMac || (/AppleWebKit/.test(ua) && /Safari/.test(ua) && !/Chrome|Chromium|Edg|OPR/.test(ua));
      if (isApple) {
        document.documentElement.classList.add('is-apple');
        document.documentElement.setAttribute('data-apple-device', isIOS ? 'ios' : 'mac');
      }
      if (isIOS) {
        document.documentElement.classList.add('is-ios');
      }
    })();
  </script>
  <title><?= e($title ?? 'Tornalyx | Documentación') ?></title>

  <link rel="icon" href="../assets/favicon.ico" type="image/x-icon" />
  <link rel="shortcut icon" href="../assets/favicon.ico" />
  <link rel="stylesheet" href="../css/variables.css?v=16" />
  <link rel="stylesheet" href="../css/main.css?v=16" />
  <link rel="stylesheet" href="../css/components.css?v=16" />
  <style>
    .doc-page { padding:var(--space-10) var(--space-4) var(--space-16); }
    .doc-head { text-align:center; margin-bottom:var(--space-8); }
    .doc-head h1 { font-family:var(--head); font-size:var(--font-size-3xl); color:var(--ink); margin-top:6px; }
    .doc-head .doc-sub { color:var(--muted); font-size:var(--font-size-sm); margin-top:8px; }

    /* ─── Control Segmentado / Pestañas de Documentación ─── */
    .doc-segmented-wrap {
      display: flex;
      justify-content: center;
      margin-bottom: var(--space-8);
    }
    .doc-tabs-control {
      display: inline-flex;
      background: rgba(18, 22, 34, 0.75);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid var(--line);
      border-radius: 999px;
      padding: 5px;
      gap: 6px;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
    }
    .doc-tab-btn {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 10px 22px;
      border-radius: 999px;
      font-family: var(--head);
      font-size: 14px;
      font-weight: 700;
      color: var(--muted);
      cursor: pointer;
      border: none;
      background: transparent;
      transition: all 0.25s cubic-bezier(0.22, 1, 0.36, 1);
    }
    .doc-tab-btn:hover {
      color: var(--ink);
    }
    .doc-tab-btn.active {
      background: linear-gradient(145deg, #ff4655, #cc2b37);
      color: #ffffff;
      box-shadow: 0 4px 16px rgba(255, 70, 85, 0.4), inset 0 1px 1px rgba(255, 255, 255, 0.4);
    }
    .doc-tab-badge {
      font-size: 11px;
      padding: 2px 7px;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.15);
      color: inherit;
      font-weight: 800;
    }

    /* ─── Paneles de Contenido ─── */
    .doc-tab-panel {
      display: none;
      animation: doc-fade-in 0.35s ease both;
    }
    .doc-tab-panel.active {
      display: block;
    }
    @keyframes doc-fade-in {
      from { opacity: 0; transform: translateY(8px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    /* ── Grilla de materias y presentaciones ────────────── */
    .doc-materias { display:grid; grid-template-columns:1fr; gap:var(--space-4); max-width:960px; margin:0 auto; }
    .doc-materia {
      display:flex; flex-direction:column; gap:8px;
      justify-content:space-between;
      padding:var(--space-6); background:var(--bg-card);
      border:1px solid var(--line-soft); border-radius:18px;
      text-decoration:none; transition:border-color var(--transition-fast), transform var(--transition-fast), box-shadow var(--transition-fast);
      position: relative;
      overflow: hidden;
    }
    .doc-materia:hover {
      border-color:var(--red);
      transform:translateY(-3px);
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.5), 0 0 20px rgba(255, 70, 85, 0.15);
    }
    .doc-materia__top {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
    }
    .doc-materia__ic { width:42px; height:42px; color:var(--red-bright); }
    .doc-materia__ic svg { width:100%; height:100%; fill:none; stroke:currentColor; stroke-width:1.6; stroke-linecap:round; stroke-linejoin:round; }
    .doc-tag {
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      padding: 4px 10px;
      border-radius: 999px;
      background: rgba(255, 70, 85, 0.12);
      color: var(--red-bright);
      border: 1px solid rgba(255, 70, 85, 0.25);
    }
    .doc-tag--pres {
      background: rgba(56, 189, 248, 0.12);
      color: #38bdf8;
      border-color: rgba(56, 189, 248, 0.25);
    }
    .doc-materia__nombre { font-family:var(--head); font-size:var(--font-size-lg); font-weight:700; color:var(--ink); }
    .doc-materia__desc { color:var(--muted); font-size:var(--font-size-sm); line-height:1.6; }
    .doc-materia__go { color:var(--red-bright); font-size:13px; font-weight:700; margin-top:var(--space-2); display: inline-flex; align-items: center; gap: 4px; }
    .doc-materia__go--blue { color: #38bdf8; }

    /* ── Cabecera de materia ────────────────────────────── */
    .doc-back { display:inline-flex; align-items:center; gap:8px; margin-bottom:var(--space-6); color:var(--red-bright); font-size:var(--font-size-sm); text-decoration:none; font-weight: 600; }
    .doc-back:hover { color:var(--red); }
    .doc-materia-head { display:flex; flex-wrap:wrap; align-items:flex-end; justify-content:space-between; gap:var(--space-4); margin-bottom:var(--space-6); padding-bottom:var(--space-5); border-bottom:1px solid var(--line); }
    .doc-materia-head h1 { font-family:var(--head); font-size:var(--font-size-2xl); color:var(--ink); margin-top:4px; }
    .doc-meta { display:flex; flex-direction:column; align-items:flex-end; gap:4px; font-size:12px; color:var(--muted-2); }
    .doc-meta a { color:var(--red-bright); text-decoration:none; }
    .doc-meta a:hover { color:var(--red); }
    .doc-live { display:inline-flex; align-items:center; gap:6px; }
    .doc-live .status-dot { width:7px; height:7px; }

    /* ── Contenido renderizado desde Google Docs ────────── */
    .doc-content { max-width:900px; margin:0 auto; }
    .doc-error { max-width:900px; margin:0 auto; }
    .markdown { color:var(--muted); font-size:var(--font-size-base); line-height:1.75; overflow-wrap:break-word; }
    .markdown > :first-child { margin-top:0; }
    .markdown h1, .markdown h2, .markdown h3, .markdown h4, .markdown h5, .markdown h6 { font-family:var(--head); color:var(--ink); line-height:1.3; }
    .markdown h1 { font-size:var(--font-size-2xl); margin:var(--space-8) 0 var(--space-4); }
    .markdown h2 { font-size:var(--font-size-xl); margin:var(--space-8) 0 var(--space-3); padding-top:var(--space-5); border-top:1px solid var(--line); }
    .markdown h3 { font-size:var(--font-size-lg); color:var(--red-bright); margin:var(--space-5) 0 var(--space-2); }
    .markdown h4, .markdown h5, .markdown h6 { font-size:var(--font-size-base); margin:var(--space-4) 0 var(--space-2); }
    .markdown p { margin:0 0 var(--space-4); }
    .markdown a { color:var(--red-bright); text-decoration:underline; text-underline-offset:2px; }
    .markdown a:hover { color:var(--red); }
    .markdown ul, .markdown ol { margin:0 0 var(--space-4) var(--space-6); display:flex; flex-direction:column; gap:6px; }
    .markdown li { color:var(--muted); }
    .markdown ul li { list-style:disc; }
    .markdown ol li { list-style:decimal; }
    .markdown strong, .markdown b { color:var(--ink); font-weight:600; }
    .markdown blockquote { margin:0 0 var(--space-4); padding:var(--space-3) var(--space-4); border-left:3px solid var(--red); background:rgba(236,28,36,.06); border-radius:0 8px 8px 0; color:var(--muted); }
    .markdown hr { border:none; border-top:1px solid var(--line); margin:var(--space-6) 0; }
    .markdown code { font-family:var(--mono); font-size:.9em; background:var(--bg-2); border:1px solid var(--line-soft); border-radius:6px; padding:2px 6px; color:var(--ink); }
    .markdown pre { margin:0 0 var(--space-4); padding:var(--space-4); background:var(--bg); border:1px solid var(--line); border-radius:10px; overflow-x:auto; }
    .markdown pre code { background:none; border:none; padding:0; font-size:13px; line-height:1.6; color:var(--muted); white-space:pre; }
    .markdown img { max-width:100%; height:auto; border-radius:8px; margin:var(--space-2) 0; }
    .markdown table { width:100%; border-collapse:collapse; font-size:var(--font-size-sm); margin:0 0 var(--space-4); display:block; overflow-x:auto; }
    .markdown th, .markdown td { padding:10px 14px; border:1px solid var(--line); text-align:left; vertical-align:top; min-width:80px; }
    .markdown th { font-family:var(--head); color:var(--ink); background:rgba(255,255,255,.03); }
    .markdown td { color:var(--muted); }

    /* ── Pantalla de acceso restringido (gate) ──────────── */
    .doc-gate { max-width:520px; margin:var(--space-8) auto 0; }
    .doc-gate__card { background:var(--bg-card); border:1px solid var(--line-soft); border-radius:16px; padding:var(--space-8); text-align:center; }
    .doc-gate__ic { width:52px; height:52px; margin:0 auto var(--space-4); color:var(--red-bright); }
    .doc-gate__ic svg { width:100%; height:100%; fill:none; stroke:currentColor; stroke-width:1.5; stroke-linecap:round; stroke-linejoin:round; }
    .doc-gate__card h2 { font-family:var(--head); font-size:var(--font-size-xl); color:var(--ink); margin-bottom:var(--space-3); }
    .doc-gate__card p { color:var(--muted); font-size:var(--font-size-sm); line-height:1.7; margin-bottom:var(--space-5); }
    .doc-gate__card p strong { color:var(--ink); }
    .doc-gate form { display:flex; flex-direction:column; align-items:center; gap:var(--space-3); }
    .doc-code-input { width:200px; text-align:center; font-family:var(--mono); font-size:1.4rem; letter-spacing:8px; padding:12px; }
    .doc-resend { margin-top:var(--space-3); }
    .doc-flash { max-width:520px; margin:0 auto var(--space-4); }

    @media (min-width:768px) {
      .doc-materias { grid-template-columns:repeat(2, 1fr); }
    }
  </style>
</head>
<body>

  <?= $partial('nav-publico', ['activo' => 'documentacion']) ?>

  <main class="doc-page">
    <div class="wrap">

<?php if (($materia ?? null) === null): ?>
      <!-- ── Estado 1 · Dos Apartados: Documentación y Presentaciones ── -->
      <?php
        $iconos = [
          'ciberseguridad'      => '<svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><circle cx="12" cy="15" r="1.3"/></svg>',
          'ingenieria-software' => '<svg viewBox="0 0 24 24"><path d="M12 3l7 4v5c0 4.4-3 7.6-7 9-4-1.4-7-4.6-7-9V7l7-4Z"/><path d="M9.5 12l1.8 1.8L15 10"/></svg>',
          'sistemas-operativos' => '<svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
          'tutoria'             => '<svg viewBox="0 0 24 24"><path d="M3 7l9-4 9 4-9 4-9-4Z"/><path d="M7 9v5c0 1.5 2.2 3 5 3s5-1.5 5-3V9"/><path d="M21 7v6"/></svg>',
          'presentacion'        => '<svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/><path d="m10 9 5 3-5 3z"/></svg>',
          'pitch-deck'          => '<svg viewBox="0 0 24 24"><path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M7 21h10"/><path d="M12 3v18"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/></svg>'
        ];
      ?>
      <div class="doc-head" data-reveal>
        <p class="eyebrow eyebrow-center">Centro de Conocimiento</p>
        <h1>Documentación y Presentaciones</h1>
        <p class="doc-sub">Explorá los documentos técnicos del sistema y las presentaciones interactivas del proyecto.</p>
      </div>

      <!-- Segmented Control de Dos Apartados -->
      <div class="doc-segmented-wrap" data-reveal>
        <div class="doc-tabs-control" role="tablist" aria-label="Secciones de documentación">
          <button type="button" class="doc-tab-btn active" id="tabBtnDocs" data-tab-target="tabDocumentacion" role="tab" aria-selected="true" aria-controls="tabDocumentacion">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="M6 6h10"/><path d="M6 10h10"/></svg>
            <span>Documentación</span>
            <span class="doc-tab-badge"><?= count($materias) ?></span>
          </button>
          <button type="button" class="doc-tab-btn" id="tabBtnPres" data-tab-target="tabPresentaciones" role="tab" aria-selected="false" aria-controls="tabPresentaciones">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/><path d="m10 9 5 3-5 3z"/></svg>
            <span>Presentaciones</span>
            <span class="doc-tab-badge"><?= count($enlaces ?? []) ?></span>
          </button>
        </div>
      </div>

      <!-- Apartado 1: Documentación Técnica -->
      <div class="doc-tab-panel active" id="tabDocumentacion" role="tabpanel" aria-labelledby="tabBtnDocs">
        <div class="doc-materias">
          <?php foreach ($materias as $slug => $m): ?>
            <a class="doc-materia" href="/documentacion?materia=<?= e($slug) ?>" data-reveal>
              <div class="doc-materia__top">
                <span class="doc-materia__ic"><?= $iconos[$slug] ?? '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>' ?></span>
                <span class="doc-tag">Google Docs</span>
              </div>
              <span class="doc-materia__nombre"><?= e($m['nombre']) ?></span>
              <span class="doc-materia__desc"><?= e($m['desc']) ?></span>
              <span class="doc-materia__go">Ver documento técnico →</span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Apartado 2: Presentaciones -->
      <div class="doc-tab-panel" id="tabPresentaciones" role="tabpanel" aria-labelledby="tabBtnPres">
        <div class="doc-materias">
          <?php if (empty($enlaces)): ?>
            <p class="text-center" style="color:var(--muted);grid-column:1/-1;padding:var(--space-8)">No hay presentaciones disponibles por el momento.</p>
          <?php else: ?>
            <?php foreach (($enlaces ?? []) as $slug => $en): ?>
              <a class="doc-materia" href="/documentacion?enlace=<?= e($slug) ?>" target="_blank" rel="noopener" data-reveal>
                <div class="doc-materia__top">
                  <span class="doc-materia__ic" style="color:#38bdf8"><?= $iconos[$slug] ?? '<svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/><path d="m10 9 5 3-5 3z"/></svg>' ?></span>
                  <span class="doc-tag doc-tag--pres">Web Slides</span>
                </div>
                <span class="doc-materia__nombre"><?= e($en['nombre']) ?></span>
                <span class="doc-materia__desc"><?= e($en['desc']) ?></span>
                <span class="doc-materia__go doc-materia__go--blue">Abrir presentación interactiva ↗</span>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

<?php else: ?>
      <?php $gate = $gate ?? null; ?>
      <!-- ── Estado 2 · Documento de la materia (en vivo) ── -->
      <a class="doc-back" href="/documentacion">
        <img src="../assets/icon-volver.svg" width="17" height="17" alt="">Todas las materias
      </a>
      <div class="doc-materia-head">
        <div>
          <p class="eyebrow">Documentación</p>
          <h1><?= e($materia['nombre']) ?></h1>
        </div>
        <?php if ($gate === null): ?>
        <div class="doc-meta">
          <span class="doc-live"><span class="status-dot status-dot--green"></span>Se actualiza automáticamente</span>
          <a href="<?= e($materia['url']) ?>" target="_blank" rel="noopener">Abrir original en Google Docs ↗</a>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($gate !== null): ?>
        <!-- Acceso restringido: registrado + aprobado + código por email -->
        <?php if (!empty($flash)): ?>
          <div class="doc-flash"><div class="alert alert-info"><?= e($flash) ?></div></div>
        <?php endif; ?>
        <div class="doc-gate">
          <div class="doc-gate__card">
            <div class="doc-gate__ic">
              <svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/><circle cx="12" cy="15.5" r="1.2"/></svg>
            </div>

            <?php if ($gate['estado'] === 'login'): ?>
              <h2>Documentación restringida</h2>
              <p>Este documento solo está disponible para usuarios registrados y autorizados. Iniciá sesión para poder solicitar acceso.</p>
              <a class="btn btn-primary" href="/login">Iniciar sesión</a>

            <?php elseif ($gate['estado'] === 'solicitar'): ?>
              <h2>Acceso restringido</h2>
              <p>Para ver la documentación de <strong><?= e($materia['nombre']) ?></strong> necesitás autorización. Solicitá acceso y un administrador lo revisará.</p>
              <form method="post" action="/documentacion/solicitar">
                <input type="hidden" name="csrf_token" value="<?= e($csrf ?? '') ?>">
                <input type="hidden" name="materia" value="<?= e($materiaSlug) ?>">
                <button class="btn btn-primary" type="submit">Solicitar acceso</button>
              </form>

            <?php elseif ($gate['estado'] === 'pendiente'): ?>
              <h2>Solicitud pendiente</h2>
              <p>Tu solicitud de acceso está <strong>pendiente de aprobación</strong>. Te avisaremos por correo cuando un administrador la resuelva.</p>

            <?php elseif ($gate['estado'] === 'rechazado'): ?>
              <h2>Acceso rechazado</h2>
              <p>Tu solicitud de acceso fue rechazada por el administrador. Si considerás que es un error, podés solicitarlo nuevamente.</p>
              <form method="post" action="/documentacion/solicitar">
                <input type="hidden" name="csrf_token" value="<?= e($csrf ?? '') ?>">
                <input type="hidden" name="materia" value="<?= e($materiaSlug) ?>">
                <button class="btn btn-primary" type="submit">Solicitar de nuevo</button>
              </form>
            <?php endif; ?>
          </div>
        </div>

      <?php elseif (($error ?? null) !== null): ?>
        <div class="doc-error">
          <div class="alert alert-error"><?= e($error) ?></div>
        </div>
      <?php else: ?>
        <article class="doc-content markdown">
          <?= $contenido /* HTML ya saneado en el servidor (allowlist de tags/atributos) */ ?>
        </article>
      <?php endif; ?>

<?php endif; ?>

    </div>
  </main>

  <!-- ── FOOTER ─────────────────────────────────────────── -->
  <footer class="footer-wrap">
    <div class="wrap">
      <div class="foot-grid">
        <div class="foot-brand">
          <a class="brand" href="/">
            <span class="badge badge-crop" style="background-image:url(../assets/ICONO.png)" role="img" aria-label="Tornalyx"></span>
            Tornalyx
          </a>
          <p>Llaves, calendarios y resultados en vivo — todo en un solo lugar.</p>
        </div>
        <div class="foot-col">
          <h5>Producto</h5>
          <a href="/torneos">Torneos</a>
          <a href="/jugadores">Jugadores</a>
          <a href="/documentacion">Documentación</a>
        </div>
        <div class="foot-col">
          <h5>Formatos</h5>
          <a href="/torneos">Liga</a>
          <a href="/torneos">Eliminación directa</a>
          <a href="/torneos">Sistema suizo</a>
        </div>
        <div class="foot-col">
          <h5>Conéctate</h5>
          <div class="foot-socials">
            <a href="https://x.com/Tornalyx" target="_blank" rel="noopener noreferrer" class="foot-social-link" aria-label="X (Twitter)">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
              </svg>
            </a>
            <a href="https://youtube.com/@Tornalyx" target="_blank" rel="noopener noreferrer" class="foot-social-link" aria-label="YouTube">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
              </svg>
            </a>
            <a href="https://instagram.com/telyuxsoftwaredevelopment" target="_blank" rel="noopener noreferrer" class="foot-social-link" aria-label="Instagram">
              <svg width="19" height="19" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
              </svg>
            </a>
          </div>
        </div>
      </div>
      <div class="foot-bottom">
        <div class="foot-legal-links">
          <span>© 2026 Tornalyx</span>
          <span class="foot-sep" aria-hidden="true">·</span>
          <a href="/privacidad">Política de Privacidad</a>
          <span class="foot-sep" aria-hidden="true">·</span>
          <a href="/terminos">Términos y Condiciones</a>
          <span class="foot-sep" aria-hidden="true">·</span>
          <span>Hecho con ♡</span>
        </div>
      </div>
    </div>
  </footer>

  <script src="../js/main.js?v=16"></script>
</body>
</html>
