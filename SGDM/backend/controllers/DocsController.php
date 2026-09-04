<?php
require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../shared/Session.php';
require_once __DIR__ . '/../shared/Mailer.php';
require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../models/DocAcceso.php';
require_once __DIR__ . '/../models/DocOtp.php';

/**
 * Controlador de la documentación del proyecto (capa C del patrón MVC).
 *
 * La documentación son Google Docs nativos que el equipo actualiza de forma
 * continua. Cada materia se corresponde con UN documento "publicado en la web"
 * (Archivo → Compartir → Publicar en la Web). Este controlador:
 *   1. descarga el HTML publicado (que Google refresca cada ~5 min),
 *   2. extrae solo el contenido (#contents) y lo SANEA con una lista blanca de
 *      etiquetas y atributos (defensa anti-XSS ante HTML externo),
 *   3. lo cachea en disco unos minutos (rendimiento y resiliencia),
 * y la vista lo pinta con los estilos de Tornalyx. Así queda "siempre al día"
 * conservando tablas, índices y encabezados, sin redirigir a Google.
 *
 * Navegación (100% en servidor; la CSP prohíbe scripts inline):
 *   - /documentacion                 → grilla de materias
 *   - /documentacion?materia=<slug>  → documento de esa materia
 *
 * Para agregar una materia nueva: publicá su Doc en la web y sumá una entrada a
 * MATERIAS con su slug, nombre, descripción y la URL '.../pub'.
 */
class DocsController extends Controller {

    /** Vida de la caché en segundos (Google republica el pub cada ~5 min). */
    private const CACHE_TTL = 300;

    /** Timeout de descarga en segundos. */
    private const FETCH_TIMEOUT = 8;

    /**
     * Acceso restringido: TODA la documentación exige sesión + aprobación (por el
     * enlace del correo al aprobador) + verificación por código enviado al correo.
     * Al ser "todas", cualquier materia nueva que se agregue a MATERIAS (o
     * enlace nuevo a ENLACES_EXTERNOS) queda protegida automáticamente, sin
     * tener que mantener una lista aparte.
     */
    private function esRestringida(string $slug): bool {
        return isset(self::MATERIAS[$slug]) || isset(self::ENLACES_EXTERNOS[$slug]);
    }

    /** Minutos que dura el acceso verificado por código antes de re-pedirlo. */
    private const VERIF_TTL = 1800;

    /**
     * Lista blanca de materias: slug público → nombre, descripción y URL del
     * Google Doc publicado. El orden es el que se muestra en la grilla.
     */
    private const MATERIAS = [
        'ciberseguridad' => [
            'nombre' => 'Ciberseguridad',
            'desc'   => 'Identificación de amenazas, vulnerabilidades de infraestructura, matriz de control de acceso, política de contraseñas y medidas de seguridad.',
            'url'    => 'https://docs.google.com/document/d/e/2PACX-1vQdP4ZXer6vjoQ26e8R1-eEcmss2anrn3YXf4IhZPvg4F__gTTa6kgyLIcC7geuGfg75S923FNhQIGU/pub',
        ],
        'ingenieria-software' => [
            'nombre' => 'Ingeniería de Software',
            'desc'   => 'Documentación de inicio, organización del equipo, ciclo de vida Scrum, relevamiento de requisitos y especificación ESRE (IEEE 29148).',
            'url'    => 'https://docs.google.com/document/d/e/2PACX-1vRNPU_Vc_0dfoxOP5JWTOqqwBDzRezTEvIDMLOXt1QYw0drRfw_Zq-1gB4KhQHb4K7xcn5syj28VJ5f/pub',
        ],
        'sistemas-operativos' => [
            'nombre' => 'Sistemas Operativos',
            'desc'   => 'Estudio comparativo cliente/servidor, pila LAMP en AlmaLinux 8.10, usuarios del sistema y scripts de gestión en Bash.',
            'url'    => '',
        ],
        'tutoria' => [
            'nombre' => 'Tutoría de Proyecto UTULAB',
            'desc'   => 'Identidad visual y significado de Telyux y Tornalyx, técnica S.C.A.M.P.E.R, 7 actas de reunión y decisiones de diseño.',
            'url'    => 'https://docs.google.com/document/d/e/2PACX-1vS98n_oJKtySAZNbLRjHq3VANtgaDZU0mIyjxhuomDXjs_k-n59_8cyMiZIaEzQ6OO6xvjPNX74wasi/pub',
        ],
    ];

    /**
     * Materias cuya documentación vive en un sitio externo (no un Google Doc
     * publicado), por ejemplo una presentación. A diferencia de MATERIAS, acá
     * no hay fetch/saneado ni caché: pasan por el mismo gate de acceso
     * restringido (login + aprobación + código) y, una vez autorizado, el
     * controlador redirige (302) directo al sitio externo — ver el bloque
     * `enlace` en show().
     */
    private const ENLACES_EXTERNOS = [
        'sistemas' => [
            'nombre' => 'Sistemas',
            'desc'   => 'Presentación del proyecto para la materia Sistemas.',
            'url'    => 'https://sistemas-presentacion.onrender.com/#1',
        ],
    ];

    /** Etiquetas HTML permitidas en el contenido saneado. */
    private const TAGS_OK = [
        'p', 'br', 'hr', 'span', 'div', 'a', 'img',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th',
        'strong', 'b', 'em', 'i', 'u', 'sup', 'sub', 'blockquote', 'code', 'pre',
    ];

    /** Etiquetas que se eliminan por completo (con su contenido). */
    private const TAGS_KILL = [
        'style', 'script', 'link', 'meta', 'iframe', 'object',
        'embed', 'form', 'input', 'button', 'noscript', 'svg',
    ];

    /** Atributos permitidos por etiqueta (además de 'id', global para el índice). */
    private const ATTRS_OK = [
        'a'   => ['href', 'title', 'rel', 'target'],
        'img' => ['src', 'alt'],
        'td'  => ['colspan', 'rowspan'],
        'th'  => ['colspan', 'rowspan'],
    ];

    /**
     * GET /documentacion. Según los parámetros, muestra la grilla de materias o
     * el documento de una materia concreta.
     */
    public function show(): void {
        $slug   = isset($_GET['materia']) ? (string) $_GET['materia'] : '';
        $enlace = isset($_GET['enlace'])  ? (string) $_GET['enlace']  : '';

        // Estado 1 · sin materia ni enlace válido → grilla de materias.
        if (!isset(self::MATERIAS[$slug]) && !isset(self::ENLACES_EXTERNOS[$enlace])) {
            $this->render('documentacion', [
                'title'    => 'Tornalyx | Documentación',
                'materias' => self::MATERIAS,
                'enlaces'  => self::ENLACES_EXTERNOS,
                'materia'  => null,
            ]);
            return;
        }

        // Estado 2b · enlace externo: mismo gate que una materia; autorizado,
        // se redirige directo al sitio externo (no es un Google Doc, no hay
        // nada que cachear ni sanear acá).
        if ($enlace !== '' && isset(self::ENLACES_EXTERNOS[$enlace])) {
            $externo = self::ENLACES_EXTERNOS[$enlace];
            $gate = $this->evaluarAcceso($enlace);
            if ($gate !== null) {
                $this->render('documentacion', [
                    'title'       => 'Tornalyx | ' . $externo['nombre'],
                    'materias'    => self::MATERIAS,
                    'materia'     => $externo,
                    'materiaSlug' => $enlace,
                    'gate'        => $gate,
                    'csrf'        => Session::csrfToken(),
                    'flash'       => $this->tomarFlash(),
                ]);
                return;
            }
            header('Location: ' . $externo['url']);
            exit;
        }

        // Estado 2 · materia elegida.
        $materia = self::MATERIAS[$slug];

        // Gate de las materias restringidas: si el usuario todavía no está
        // registrado + aprobado + verificado por código, mostramos la pantalla
        // de acceso en lugar del documento.
        if ($this->esRestringida($slug)) {
            $gate = $this->evaluarAcceso($slug);
            if ($gate !== null) {
                $this->render('documentacion', [
                    'title'       => 'Tornalyx | ' . $materia['nombre'],
                    'materias'    => self::MATERIAS,
                    'materia'     => $materia,
                    'materiaSlug' => $slug,
                    'gate'        => $gate,
                    'csrf'        => Session::csrfToken(),
                    'flash'       => $this->tomarFlash(),
                ]);
                return;
            }
        }

        // Autorizado (o materia pública): render del documento en vivo.
        [$contenido, $error] = $this->obtenerContenido($slug, $materia['url']);

        $this->render('documentacion', [
            'title'       => 'Tornalyx | ' . $materia['nombre'],
            'materias'    => self::MATERIAS,
            'materia'     => $materia,
            'materiaSlug' => $slug,
            'contenido'   => $contenido,
            'error'       => $error,
        ]);
    }

    // ──────────────────────────────────────────────────────────
    // Acceso restringido: solicitud, aprobación y código por email
    // ──────────────────────────────────────────────────────────

    /**
     * Evalúa si el usuario puede ver una materia restringida. Devuelve null si
     * el acceso está concedido (registrado + aprobado + verificado en la
     * sesión); en caso contrario, un array que describe qué pantalla mostrar.
     *
     * @return array<string,mixed>|null
     */
    private function evaluarAcceso(string $slug): ?array {
        Session::start();

        if (!Session::isLoggedIn()) {
            return ['estado' => 'login'];
        }

        // Los administradores tienen acceso total directo a la documentación
        if (Session::getUserRole() === 'administrador') {
            return null; // acceso concedido
        }

        $usuarioId = (int) Session::getUserId();
        $estado    = (new DocAcceso())->estado($usuarioId, $slug);

        if ($estado === null)        return ['estado' => 'solicitar'];
        if ($estado === 'pendiente') return ['estado' => 'pendiente'];
        if ($estado === 'rechazado') return ['estado' => 'rechazado'];
        if ($estado === 'aprobado')  return null; // acceso concedido directamente por aprobación del admin

        return ['estado' => 'solicitar'];
    }

    /**
     * POST /documentacion/solicitar — el usuario pide acceso a una materia
     * restringida. Queda pendiente y se envía un correo al aprobador con un
     * enlace (token de un solo uso) para aprobarla o rechazarla, al estilo de las
     * solicitudes de acceso de Google Docs.
     */
    public function solicitarAcceso(): void {
        Session::start();
        $slug = $this->slugSensibleDePost();
        if ($slug === null) {
            $this->volver('');
        }
        if (!Session::isLoggedIn()) {
            header('Location: /login');
            exit;
        }

        $usuarioId = (int) Session::getUserId();
        $token     = (new DocAcceso())->solicitar($usuarioId, $slug);

        // $token === null significa que ya estaba aprobado (nada que notificar).
        if ($token !== null) {
            try {
                $this->enviarSolicitudEmail($usuarioId, $slug, $token);
            } catch (Throwable $e) {
                // El aviso al aprobador es best-effort: si falla el correo, la
                // solicitud igual queda registrada y se puede resolver por panel.
                error_log('Fallo al enviar email de solicitud de acceso a documentación: ' . $e->getMessage());
            }
        }

        $this->flash('Tu solicitud de acceso fue enviada. Recibirás un correo cuando la aprueben.');
        $this->volver($slug);
    }

    /**
     * GET /documentacion/aprobar?token=… — pantalla de aprobación que abre el
     * aprobador desde el enlace del correo. Muestra quién pide acceso y a qué
     * materia, con botones para Aprobar o Rechazar (POST). Es una página
     * intermedia a propósito: así los escáneres de correo que precargan enlaces
     * (GET) no resuelven la solicitud por sí solos.
     */
    public function revisarSolicitud(): void {
        // Arrancamos sesión (aunque el aprobador no esté logueado) para tener un
        // token CSRF: la protección global exige csrf_token en el POST siguiente.
        Session::start();

        $token = (string) ($_GET['token'] ?? '');
        $sol   = (new DocAcceso())->buscarPorToken($token);

        if ($sol === null) {
            $this->render('doc-aprobacion', [
                'title'  => 'Tornalyx | Solicitud de acceso',
                'estado' => 'invalido',
            ]);
            return;
        }

        $this->render('doc-aprobacion', [
            'title'         => 'Tornalyx | Solicitud de acceso',
            'estado'        => 'confirmar',
            'token'         => $token,
            'csrf'          => Session::csrfToken(),
            'solicitante'   => trim(($sol['nombre'] ?? '') . ' ' . ($sol['apellido'] ?? '')),
            'email'         => (string) ($sol['email'] ?? ''),
            'materiaNombre' => self::MATERIAS[$sol['materia']]['nombre'] ?? (string) $sol['materia'],
        ]);
    }

    /**
     * POST /documentacion/resolver — aplica la decisión (aprobar/rechazar) usando
     * el token del enlace. El token es el secreto que autoriza la acción (magic
     * link de un solo uso), por eso no exige sesión de administrador.
     */
    public function resolverSolicitud(): void {
        $token  = (string) ($_POST['token'] ?? '');
        $accion = (string) ($_POST['accion'] ?? '');
        $sol    = (new DocAcceso())->resolverPorToken($token, $accion);

        if ($sol === null) {
            $this->render('doc-aprobacion', [
                'title'  => 'Tornalyx | Solicitud de acceso',
                'estado' => 'invalido',
            ]);
            return;
        }

        $this->render('doc-aprobacion', [
            'title'         => 'Tornalyx | Solicitud de acceso',
            'estado'        => 'resuelto',
            'resultado'     => $sol['estado'], // 'aprobado' | 'rechazado'
            'solicitante'   => trim(($sol['nombre'] ?? '') . ' ' . ($sol['apellido'] ?? '')),
            'materiaNombre' => self::MATERIAS[$sol['materia']]['nombre'] ?? (string) $sol['materia'],
        ]);
    }

    /** Correo del aprobador de solicitudes (configurable por entorno). */
    private function approverEmail(): string {
        return (string) (getenv('DOC_APPROVER_EMAIL') ?: 'telyuxsoftwaredevelopment67@gmail.com');
    }

    /** Base absoluta (esquema + host) para armar enlaces en los correos. */
    private function baseUrl(): string {
        $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                  || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
        $scheme = $https ? 'https' : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host;
    }

    /** Envía al aprobador el correo con el enlace para resolver la solicitud. */
    private function enviarSolicitudEmail(int $usuarioId, string $slug, string $token): void {
        $usuario  = (new Usuario())->findById($usuarioId);
        $nombreU  = trim((string) (($usuario['nombre'] ?? '') . ' ' . ($usuario['apellido'] ?? '')));
        $emailU   = (string) ($usuario['email'] ?? '');
        $materia  = self::MATERIAS[$slug]['nombre'] ?? $slug;
        $link     = $this->baseUrl() . '/documentacion/aprobar?token=' . urlencode($token);

        $subject = "Tornalyx · Solicitud de acceso a {$materia}";
        $text = "Nueva solicitud de acceso a la documentación de {$materia}.\n\n"
              . "Solicitante: {$nombreU} ({$emailU})\n\n"
              . "Para aprobar o rechazar, abrí este enlace:\n{$link}\n\n"
              . "El enlace es de un solo uso y vence en 7 días.\n\nTornalyx";
        $safeNombre  = htmlspecialchars($nombreU, ENT_QUOTES, 'UTF-8');
        $safeEmail   = htmlspecialchars($emailU, ENT_QUOTES, 'UTF-8');
        $safeMateria = htmlspecialchars($materia, ENT_QUOTES, 'UTF-8');
        $safeLink    = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
        $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto">'
              . '<h2 style="color:#ec1c24">Tornalyx</h2>'
              . '<p>Nueva solicitud de acceso a la documentación de <strong>' . $safeMateria . '</strong>.</p>'
              . '<p style="color:#333"><strong>' . $safeNombre . '</strong><br>'
              . '<span style="color:#666">' . $safeEmail . '</span></p>'
              . '<p style="margin:24px 0">'
              . '<a href="' . $safeLink . '" style="background:#ec1c24;color:#fff;text-decoration:none;'
              . 'padding:12px 24px;border-radius:8px;font-weight:bold;display:inline-block">Revisar solicitud</a></p>'
              . '<p style="color:#666;font-size:13px">El enlace es de un solo uso y vence en 7 días. '
              . 'En la página vas a poder aprobar o rechazar el acceso.</p>'
              . '</div>';

        (new Mailer())->send($this->approverEmail(), 'Aprobador Tornalyx', $subject, $html, $text);
    }

    /**
     * POST /documentacion/codigo — envía (o reenvía) el código de verificación
     * al correo del usuario aprobado, respetando el enfriamiento.
     */
    public function enviarCodigo(): void {
        Session::start();
        $slug = $this->slugSensibleDePost();
        if ($slug === null || !Session::isLoggedIn()) {
            header('Location: /login');
            exit;
        }
        $usuarioId = (int) Session::getUserId();

        if ((new DocAcceso())->estado($usuarioId, $slug) !== 'aprobado') {
            $this->flash('Tu acceso todavía no fue aprobado.');
            $this->volver($slug);
        }

        $otp      = new DocOtp();
        $cooldown = $otp->cooldownRestante($usuarioId, $slug);
        if ($cooldown > 0) {
            $this->flash("Esperá {$cooldown} segundos para pedir otro código.");
            $this->volver($slug);
        }

        $usuario = (new Usuario())->findById($usuarioId);
        if (!$usuario) {
            $this->flash('No pudimos enviar el código. Iniciá sesión de nuevo.');
            $this->volver($slug);
        }

        $codigo = $otp->generar($usuarioId, $slug);
        try {
            $this->enviarCodigoEmail($usuario, $slug, $codigo);
        } catch (Throwable $e) {
            error_log('Fallo al enviar código de acceso a documentación: ' . $e->getMessage());
            $otp->invalidar($usuarioId, $slug);
            $this->flash('No pudimos enviar el código. Probá de nuevo en unos minutos.');
            $this->volver($slug);
        }

        $this->flash('Te enviamos un código de 6 dígitos a tu correo.');
        $this->volver($slug);
    }

    /**
     * POST /documentacion/verificar — valida el código; si es correcto, habilita
     * el documento durante esta sesión (VERIF_TTL).
     */
    public function verificarCodigo(): void {
        Session::start();
        $slug = $this->slugSensibleDePost();
        if ($slug === null || !Session::isLoggedIn()) {
            header('Location: /login');
            exit;
        }
        $usuarioId = (int) Session::getUserId();

        if ((new DocAcceso())->estado($usuarioId, $slug) !== 'aprobado') {
            $this->flash('Tu acceso todavía no fue aprobado.');
            $this->volver($slug);
        }

        $codigo = preg_replace('/\D/', '', (string) ($_POST['codigo'] ?? ''));
        if (strlen($codigo) !== 6) {
            $this->flash('Ingresá el código de 6 dígitos.');
            $this->volver($slug);
        }

        $error = null;
        if (!(new DocOtp())->verificar($usuarioId, $slug, $codigo, $error)) {
            $this->flash($error ?? 'Código incorrecto.');
            $this->volver($slug);
        }

        // Verificado: habilitar el documento por un rato en esta sesión.
        $_SESSION['doc_ok'][$slug] = time() + self::VERIF_TTL;
        $this->volver($slug);
    }

    /** Lee y valida el slug de materia restringida del POST; null si no es válido. */
    private function slugSensibleDePost(): ?string {
        $slug = (string) ($_POST['materia'] ?? '');
        return $this->esRestringida($slug) ? $slug : null;
    }

    /** Guarda un mensaje flash para mostrar tras la redirección (patrón PRG). */
    private function flash(string $mensaje): void {
        $_SESSION['doc_flash'] = $mensaje;
    }

    /** Recupera y limpia el mensaje flash. */
    private function tomarFlash(): ?string {
        $f = $_SESSION['doc_flash'] ?? null;
        unset($_SESSION['doc_flash']);
        return is_string($f) ? $f : null;
    }

    /** Redirige de vuelta a la materia (o a la grilla si no hay slug) y corta. */
    private function volver(string $slug): void {
        $destino = $slug !== '' ? '/documentacion?materia=' . urlencode($slug) : '/documentacion';
        header('Location: ' . $destino);
        exit;
    }

    /** Envía el código de acceso por correo (mismo transporte que el 2FA). */
    private function enviarCodigoEmail(array $usuario, string $slug, string $codigo): void {
        $nombreMateria = self::MATERIAS[$slug]['nombre'] ?? $slug;
        $nombre = trim((string) ($usuario['nombre'] ?? ''));
        $email  = (string) ($usuario['email'] ?? '');

        $subject = "Tornalyx · Código de acceso a {$nombreMateria}";
        $text = "Hola {$nombre},\n\n"
              . "Tu código para ver la documentación de {$nombreMateria} es: {$codigo}\n"
              . "Vence en 10 minutos y es de un solo uso.\n\n"
              . "Si no fuiste vos, ignorá este correo.\n\nTornalyx";
        $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto">'
              . '<h2 style="color:#ec1c24">Tornalyx</h2>'
              . '<p>Hola ' . htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') . ',</p>'
              . '<p>Tu código para ver la documentación de <strong>' . htmlspecialchars($nombreMateria, ENT_QUOTES, 'UTF-8') . '</strong> es:</p>'
              . '<p style="font-size:30px;font-weight:bold;letter-spacing:6px;color:#111">' . htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8') . '</p>'
              . '<p style="color:#666">Vence en 10 minutos y es de un solo uso. Si no fuiste vos, ignorá este correo.</p>'
              . '</div>';

        (new Mailer())->send($email, $nombre, $subject, $html, $text);
    }

    /** Enmascara el correo para mostrarlo sin revelarlo del todo. */
    private function maskEmail(string $email): string {
        $partes = explode('@', $email);
        if (count($partes) !== 2 || $partes[0] === '') {
            return '***';
        }
        $u    = $partes[0];
        $len  = mb_strlen($u);
        $ini  = mb_substr($u, 0, 1);
        $fin  = $len > 1 ? mb_substr($u, -1) : '';
        return $ini . str_repeat('*', max(1, $len - 2)) . $fin . '@' . $partes[1];
    }

    /**
     * Devuelve [htmlContenido, mensajeError|null]. Sirve de la caché si sigue
     * fresca; si venció, descarga y reprocesa; si la descarga falla, cae a la
     * copia en caché aunque esté vencida (resiliencia ante caídas de red).
     *
     * @return array{0:string,1:?string}
     */
    private function obtenerContenido(string $slug, string $url): array {
        $dirLocal = __DIR__ . '/../docs';
        $fileLocal = $dirLocal . '/' . $slug . '.html';

        $dirCache = __DIR__ . '/../storage/docs-cache';
        $fileCache = $dirCache . '/' . $slug . '.html';

        // Si es un documento nativo local (sin URL de Google Docs)
        if ($url === '') {
            if (is_file($fileLocal)) {
                return [(string) file_get_contents($fileLocal), null];
            }
            if (is_file($fileCache)) {
                return [(string) file_get_contents($fileCache), null];
            }
            return ['', 'El documento solicitado se encuentra en preparación.'];
        }

        // 1 · Caché fresca de Google Docs.
        if (is_file($fileCache) && (time() - filemtime($fileCache)) < self::CACHE_TTL) {
            return [(string) file_get_contents($fileCache), null];
        }

        // 2 · Descargar y procesar desde Google Docs.
        $html = $this->descargar($url);
        if ($html !== null) {
            $contenido = $this->extraer($html);
            if ($contenido !== '') {
                if (!is_dir($dirCache)) {
                    @mkdir($dirCache, 0775, true);
                }
                @file_put_contents($fileCache, $contenido, LOCK_EX);
                return [$contenido, null];
            }
        }

        // 3 · Fallback 1: documento local versionado en backend/docs/
        if (is_file($fileLocal)) {
            return [(string) file_get_contents($fileLocal), null];
        }

        // 4 · Fallback 2: caché vencida existente
        if (is_file($fileCache)) {
            return [(string) file_get_contents($fileCache), null];
        }

        return ['', 'No pudimos cargar la documentación en este momento. Probá de nuevo en unos minutos.'];
    }

    /** Descarga el HTML del Doc publicado (curl si está; si no, file_get_contents). */
    private function descargar(string $url): ?string {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => self::FETCH_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_USERAGENT      => 'Tornalyx-Docs/1.0',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($body !== false && $code >= 200 && $code < 300) ? (string) $body : null;
        }

        $ctx  = stream_context_create(['http' => [
            'timeout'    => self::FETCH_TIMEOUT,
            'user_agent' => 'Tornalyx-Docs/1.0',
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }

    /**
     * Extrae el nodo #contents del HTML publicado y devuelve su interior ya
     * saneado. Si no encuentra el contenedor, devuelve cadena vacía.
     */
    private function extraer(string $html): string {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        // El prefijo XML fuerza UTF-8 para que no se rompan los acentos.
        $doc->loadHTML('<?xml encoding="UTF-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $xpath    = new DOMXPath($doc);
        $contents = $xpath->query('//*[@id="contents"]')->item(0);
        if (!$contents instanceof DOMElement) {
            return '';
        }

        $this->sanear($contents);

        $out = '';
        foreach (iterator_to_array($contents->childNodes) as $node) {
            $out .= $doc->saveHTML($node);
        }
        return trim($out);
    }

    /**
     * Limpieza recursiva del árbol: elimina etiquetas peligrosas (con su
     * contenido), desenvuelve las desconocidas, quita atributos no permitidos
     * (class, style, on*, etc.), normaliza los enlaces envueltos por Google y
     * descarta encabezados/párrafos vacíos.
     */
    private function sanear(DOMNode $nodo): void {
        // Copia estática: el árbol se muta durante la iteración.
        foreach (iterator_to_array($nodo->childNodes) as $hijo) {
            if ($hijo->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            /** @var DOMElement $hijo */
            $tag = strtolower($hijo->nodeName);

            // Etiquetas peligrosas: fuera por completo.
            if (in_array($tag, self::TAGS_KILL, true)) {
                $nodo->removeChild($hijo);
                continue;
            }

            // Etiqueta desconocida: desenvolver (conservar sus hijos).
            if (!in_array($tag, self::TAGS_OK, true)) {
                $this->sanear($hijo);
                while ($hijo->firstChild) {
                    $nodo->insertBefore($hijo->firstChild, $hijo);
                }
                $nodo->removeChild($hijo);
                continue;
            }

            // Depurar atributos (se permite 'id' global para el índice/anclas).
            $permitidos = array_merge(['id'], self::ATTRS_OK[$tag] ?? []);
            foreach (iterator_to_array($hijo->attributes) as $attr) {
                if (!in_array(strtolower($attr->name), $permitidos, true)) {
                    $hijo->removeAttribute($attr->name);
                }
            }

            // Normalizar enlaces (Google envuelve externos en /url?q=REAL).
            if ($tag === 'a') {
                $href = $hijo->hasAttribute('href') ? $this->normalizarHref($hijo->getAttribute('href')) : '';
                if ($href === '') {
                    $hijo->removeAttribute('href');
                } else {
                    $hijo->setAttribute('href', $href);
                    // Los enlaces externos se abren en pestaña nueva y sin fugas de referrer.
                    if ($href[0] !== '#') {
                        $hijo->setAttribute('rel', 'noopener nofollow');
                        $hijo->setAttribute('target', '_blank');
                    }
                }
            }

            // Recursión en el subárbol ya validado.
            $this->sanear($hijo);

            // Descartar encabezados/párrafos que quedaron vacíos (Google mete varios).
            if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p'], true)
                && trim($hijo->textContent) === ''
                && $hijo->getElementsByTagName('img')->length === 0) {
                $nodo->removeChild($hijo);
            }
        }
    }

    /** Desenvuelve el redirector de Google y solo deja esquemas seguros. */
    private function normalizarHref(string $href): string {
        $href = trim($href);
        if ($href === '') {
            return '';
        }
        if (preg_match('#^https?://www\.google\.com/url\?#i', $href)) {
            parse_str((string) parse_url($href, PHP_URL_QUERY), $q);
            if (!empty($q['q']) && is_string($q['q'])) {
                $href = $q['q'];
            }
        }
        // Anclas internas (índice), http(s) y mailto; nada de javascript:, data:, etc.
        return preg_match('~^(https?://|mailto:|#)~i', $href) ? $href : '';
    }
}
