<?php
require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../models/Torneo.php';
require_once __DIR__ . '/../models/Partido.php';
require_once __DIR__ . '/../models/Posicion.php';
require_once __DIR__ . '/../shared/Session.php';

/**
 * Controlador del perfil del usuario autenticado.
 * Expone los datos reales del perfil (sin seeds) y permite editarlos:
 * datos personales, biografía, ubicación, contraseña y foto de perfil.
 */
class PerfilController extends Controller {

    /** Límite de la biografía, en palabras. */
    private const BIO_MAX_PALABRAS = 500;

    /** Tamaño máximo del avatar en bytes (2 MB). */
    private const AVATAR_MAX_BYTES = 2 * 1024 * 1024;

    /** MIME reales admitidos para el avatar y su extensión de guardado. */
    private const AVATAR_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /** Ruta pública donde se sirve el avatar de un usuario (ver servirAvatar). */
    private const AVATAR_URL = '/api/perfil/avatar';

    private Usuario $usuarioModel;
    private Torneo  $torneoModel;

    public function __construct() {
        parent::__construct();
        $this->usuarioModel = new Usuario();
        $this->torneoModel  = new Torneo();
    }

    /**
     * Datos completos del perfil (GET /api/perfil): usuario + historial de
     * torneos + equipos + estadísticas agregadas, todo desde la base.
     */
    public function datos(): void {
        if (!$this->requireApiLogin()) {
            return;
        }
        $userId  = (int) Session::getUserId();
        $usuario = $this->usuarioModel->findById($userId);
        if (!$usuario) {
            $this->jsonError('No autenticado.', [], 401);
            return;
        }

        $torneos = $this->torneoModel->listarDeParticipante($userId);
        $equipos = $this->torneoModel->equiposDeUsuario($userId);

        $activos = count(array_filter($torneos, static fn($t) =>
            in_array($t['estado'], ['inscripcion', 'en_curso'], true)
            && $t['inscripcion_estado'] === 'aprobada'
        ));
        $finalizados = count(array_filter($torneos, static fn($t) => $t['estado'] === 'finalizado'));

        $this->jsonSuccess([
            'usuario' => $this->usuarioPublico($usuario),
            'torneos' => $torneos,
            'equipos' => $equipos,
            'stats'   => [
                'torneos'     => count($torneos),
                'equipos'     => count($equipos),
                'activos'     => $activos,
                'finalizados' => $finalizados,
            ],
        ]);
    }

    /**
     * Actualiza datos personales, bio y ubicación (POST /api/perfil/actualizar).
     */
    public function actualizar(): void {
        if (!$this->requireApiLogin()) {
            return;
        }
        $userId = (int) Session::getUserId();

        $nombre    = trim((string) (filter_input(INPUT_POST, 'nombre',    FILTER_DEFAULT) ?? ''));
        $apellido  = trim((string) (filter_input(INPUT_POST, 'apellido',  FILTER_DEFAULT) ?? ''));
        $nickname  = trim((string) (filter_input(INPUT_POST, 'nickname',  FILTER_DEFAULT) ?? ''));
        $tag       = trim((string) (filter_input(INPUT_POST, 'tag',       FILTER_DEFAULT) ?? ''));
        $tag       = ltrim($tag, '#');
        $email     = (string) (filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');
        $fechaNac  = trim((string) (filter_input(INPUT_POST, 'fecha_nac', FILTER_DEFAULT) ?? ''));
        $ubicacion = trim((string) (filter_input(INPUT_POST, 'ubicacion', FILTER_DEFAULT) ?? ''));
        $bio       = trim((string) (filter_input(INPUT_POST, 'bio',       FILTER_DEFAULT) ?? ''));
        $twitter   = trim((string) (filter_input(INPUT_POST, 'twitter_url',   FILTER_DEFAULT) ?? ''));
        $facebook  = trim((string) (filter_input(INPUT_POST, 'facebook_url',  FILTER_DEFAULT) ?? ''));
        $instagram = trim((string) (filter_input(INPUT_POST, 'instagram_url', FILTER_DEFAULT) ?? ''));
        $youtube   = trim((string) (filter_input(INPUT_POST, 'youtube_url',   FILTER_DEFAULT) ?? ''));
        $tiktok    = trim((string) (filter_input(INPUT_POST, 'tiktok_url',    FILTER_DEFAULT) ?? ''));
        $kick      = trim((string) (filter_input(INPUT_POST, 'kick_url',      FILTER_DEFAULT) ?? ''));
        $twitch    = trim((string) (filter_input(INPUT_POST, 'twitch_url',    FILTER_DEFAULT) ?? ''));

        if ($nombre === '' || $apellido === '' || $email === '' || $fechaNac === '') {
            $this->jsonError('Nombre, apellido, correo y fecha de nacimiento son obligatorios.');
            return;
        }
        if (mb_strlen($nombre) > 60 || mb_strlen($apellido) > 60) {
            $this->jsonError('Nombre y apellido no pueden superar los 60 caracteres.');
            return;
        }
        if ($nickname !== '' && mb_strlen($nickname) > 30) {
            $this->jsonError('El nickname no puede superar los 30 caracteres.');
            return;
        }
        if ($tag !== '') {
            if (mb_strlen($tag) > 5) {
                $this->jsonError('El hashtag no puede superar los 5 caracteres.');
                return;
            }
            if (!preg_match('/^[A-Za-z0-9]{1,5}$/', $tag)) {
                $this->jsonError('El hashtag solo puede contener números y letras (máximo 5 caracteres).');
                return;
            }
            $tag = strtoupper($tag);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) {
            $this->jsonError('Correo electrónico inválido.');
            return;
        }
        if (!$this->esFechaNacValida($fechaNac)) {
            $this->jsonError('La fecha de nacimiento no es válida.');
            return;
        }
        if (mb_strlen($ubicacion) > 120) {
            $this->jsonError('La ubicación no puede superar los 120 caracteres.');
            return;
        }
        $palabras = $bio === '' ? 0 : count(preg_split('/\s+/u', $bio, -1, PREG_SPLIT_NO_EMPTY));
        if ($palabras > self::BIO_MAX_PALABRAS) {
            $this->jsonError(sprintf(
                'La descripción no puede superar las %d palabras (tiene %d).',
                self::BIO_MAX_PALABRAS, $palabras
            ));
            return;
        }
        if ($this->usuarioModel->emailExiste($email, $userId)) {
            $this->jsonError('Ese correo ya está en uso por otra cuenta.', [], 409);
            return;
        }

        $redesProcesadas = [];
        $redes = [
            'twitter_url'   => $twitter,   'facebook_url' => $facebook, 'instagram_url' => $instagram,
            'youtube_url'   => $youtube,   'tiktok_url'   => $tiktok,   'kick_url'      => $kick,
            'twitch_url'    => $twitch,
        ];
        foreach ($redes as $campo => $valor) {
            if ($valor !== '') {
                $norm = $this->normalizarRedSocial($campo, $valor);
                if ($norm === null) {
                    $nombreAmigable = match($campo) {
                        'twitter_url'   => 'X (Twitter)',
                        'facebook_url'  => 'Facebook',
                        'instagram_url' => 'Instagram',
                        'youtube_url'   => 'YouTube',
                        'tiktok_url'    => 'TikTok',
                        'kick_url'      => 'Kick',
                        'twitch_url'    => 'Twitch',
                        default         => $campo,
                    };
                    $this->jsonError("El enlace o usuario de $nombreAmigable no es válido.");
                    return;
                }
                $redesProcesadas[$campo] = $norm;
            } else {
                $redesProcesadas[$campo] = null;
            }
        }

        $this->usuarioModel->actualizar($userId, [
            'nombre'        => $nombre,
            'apellido'      => $apellido,
            'nickname'      => $nickname !== '' ? $nickname : null,
            'tag'           => $tag !== '' ? $tag : null,
            'email'         => $email,
            'fecha_nac'     => $fechaNac,
            'ubicacion'     => $ubicacion !== '' ? $ubicacion : null,
            'bio'           => $bio !== '' ? $bio : null,
            'twitter_url'   => $redesProcesadas['twitter_url'],
            'facebook_url'  => $redesProcesadas['facebook_url'],
            'instagram_url' => $redesProcesadas['instagram_url'],
            'youtube_url'   => $redesProcesadas['youtube_url'],
            'tiktok_url'    => $redesProcesadas['tiktok_url'],
            'kick_url'      => $redesProcesadas['kick_url'],
            'twitch_url'    => $redesProcesadas['twitch_url'],
        ]);

        // El nav y los saludos usan el nombre guardado en sesión.
        $_SESSION['usuario_nombre'] = $nombre;

        $usuario = $this->usuarioModel->findById($userId);
        $this->jsonSuccess(['usuario' => $this->usuarioPublico($usuario ?? [])]);
    }

    /**
     * Cambia la contraseña verificando la actual (POST /api/perfil/password).
     */
    public function password(): void {
        if (!$this->requireApiLogin()) {
            return;
        }
        $userId  = (int) Session::getUserId();
        $actual  = (string) (filter_input(INPUT_POST, 'actual', FILTER_DEFAULT) ?? '');
        $nueva   = (string) (filter_input(INPUT_POST, 'nueva',  FILTER_DEFAULT) ?? '');

        $usuario = $this->usuarioModel->findById($userId);
        if (!$usuario || !password_verify($actual, (string) $usuario['password'])) {
            $this->jsonError('La contraseña actual no es correcta.', [], 401);
            return;
        }
        $esFuerte = strlen($nueva) >= 8
            && preg_match('/[A-Z]/', $nueva)
            && preg_match('/[a-z]/', $nueva)
            && preg_match('/[0-9]/', $nueva);
        if (!$esFuerte) {
            $this->jsonError('La nueva contraseña debe tener al menos 8 caracteres e incluir mayúsculas, minúsculas y números.');
            return;
        }

        $this->usuarioModel->actualizar($userId, ['password' => $nueva]);
        $this->jsonSuccess();
    }

    /**
     * Sube o reemplaza la foto de perfil (POST /api/perfil/avatar).
     * Valida tipo real (finfo + getimagesize) y tamaño; se guarda como BLOB
     * en la propia fila del usuario (no en disco: Render free no tiene
     * almacenamiento persistente, cualquier archivo escrito en frontend/
     * desaparece en el próximo redeploy) y se sirve vía servirAvatar().
     */
    public function avatar(): void {
        if (!$this->requireApiLogin()) {
            return;
        }
        $userId = (int) Session::getUserId();

        $file = $_FILES['avatar'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->jsonError('No se recibió ninguna imagen.');
            return;
        }
        if ((int) $file['size'] > self::AVATAR_MAX_BYTES) {
            $this->jsonError('La imagen no puede superar los 2 MB.');
            return;
        }

        // Tipo real del archivo, no el que declara el cliente.
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::AVATAR_MIMES[$mime]) || getimagesize($file['tmp_name']) === false) {
            $this->jsonError('Formato no admitido. Usá JPG, PNG o WebP.');
            return;
        }

        $datos = file_get_contents($file['tmp_name']);
        if ($datos === false) {
            $this->jsonError('No se pudo leer la imagen. Intentá más tarde.', [], 500);
            return;
        }

        // La URL cambia con cada subida (cache-busting): el navegador
        // cachea agresivo las imágenes, y sin esto seguiría mostrando la
        // vieja aunque la fila ya tenga la nueva.
        $url = self::AVATAR_URL . '/' . $userId . '?v=' . time();
        $this->usuarioModel->actualizar($userId, [
            'avatar_data' => $datos,
            'avatar_mime' => $mime,
            'avatar_url'  => $url,
        ]);
        $this->jsonSuccess(['avatar_url' => $url]);
    }

    /**
     * Sirve el avatar de un usuario guardado en la base (GET
     * /api/perfil/avatar/{id}). Pública y sin autenticación: es la misma
     * imagen que ya se ve en la ficha pública del jugador y en los
     * resultados de búsqueda, no hay nada que proteger.
     */
    public function servirAvatar(int $id): void {
        $usuario = $this->usuarioModel->findById($id);
        if (!$usuario || $usuario['avatar_data'] === null || $usuario['avatar_mime'] === null) {
            http_response_code(404);
            return;
        }
        header('Content-Type: ' . $usuario['avatar_mime']);
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . strlen($usuario['avatar_data']));
        echo $usuario['avatar_data'];
    }

    /**
     * Busca jugadores por nombre o apellido (GET /api/jugadores?q=).
     * Público y acotado: solo datos básicos, nunca el correo ni la fecha de
     * nacimiento de terceros.
     */
    public function buscar(): void {
        $q = trim((string) (filter_input(INPUT_GET, 'q', FILTER_DEFAULT) ?? ''));
        if (mb_strlen($q) < 2) {
            $this->jsonSuccess(['jugadores' => []]);
            return;
        }
        $this->jsonSuccess(['jugadores' => $this->usuarioModel->buscar($q)]);
    }

    /**
     * Ficha pública de un jugador (GET /api/jugador/{id}): su perfil visible,
     * los torneos en los que participa, su rendimiento acumulado y sus
     * próximos enfrentamientos con rival, fecha y lugar.
     */
    public function publico(int $id): void {
        $u = $this->usuarioModel->findById($id);
        if (!$u || $u['estado'] === 'suspendido') {
            $this->jsonError('Jugador no encontrado.', [], 404);
            return;
        }

        $this->jsonSuccess([
            'jugador' => [
                'id'            => (int) $u['id'],
                'nombre'        => $u['nombre'],
                'apellido'      => $u['apellido'],
                'nickname'      => $u['nickname']      ?? null,
                'tag'           => $u['tag']           ?? null,
                'rol'           => $u['rol'],
                'bio'           => $u['bio']           ?? null,
                'ubicacion'     => $u['ubicacion']     ?? null,
                'avatar_url'    => $u['avatar_url']    ?? null,
                'twitter_url'   => $u['twitter_url']   ?? null,
                'facebook_url'  => $u['facebook_url']  ?? null,
                'instagram_url' => $u['instagram_url'] ?? null,
                'youtube_url'   => $u['youtube_url']   ?? null,
                'tiktok_url'    => $u['tiktok_url']    ?? null,
                'kick_url'      => $u['kick_url']      ?? null,
                'twitch_url'    => $u['twitch_url']    ?? null,
                'created_at'    => $u['created_at']    ?? null,
            ],
            'torneos'   => $this->torneoModel->listarDeParticipante($id),
            'equipos'   => $this->torneoModel->equiposDeUsuario($id),
            'resumen'   => (new Posicion())->resumenDeContendiente($id),
            'proximos'  => (new Partido())->proximosDeUsuario($id),
        ]);
    }

    /**
     * Subconjunto del usuario que es seguro exponer al cliente.
     */
    private function usuarioPublico(array $u): array {
        return [
            'id'            => (int) ($u['id'] ?? 0),
            'nombre'        => $u['nombre']        ?? '',
            'apellido'      => $u['apellido']      ?? '',
            'nickname'      => $u['nickname']      ?? null,
            'tag'           => $u['tag']           ?? null,
            'email'         => $u['email']         ?? '',
            'rol'           => $u['rol']           ?? '',
            'fecha_nac'     => $u['fecha_nac']     ?? null,
            'ubicacion'     => $u['ubicacion']     ?? null,
            'bio'           => $u['bio']           ?? null,
            'avatar_url'    => $u['avatar_url']    ?? null,
            'twitter_url'   => $u['twitter_url']   ?? null,
            'facebook_url'  => $u['facebook_url']  ?? null,
            'instagram_url' => $u['instagram_url'] ?? null,
            'youtube_url'   => $u['youtube_url']   ?? null,
            'tiktok_url'    => $u['tiktok_url']    ?? null,
            'kick_url'      => $u['kick_url']      ?? null,
            'twitch_url'    => $u['twitch_url']    ?? null,
            'created_at'    => $u['created_at']    ?? null,
        ];
    }

    /**
     * Normaliza un input de red social para aceptar tanto URLs completas
     * (https://instagram.com/usuario), URLs sin protocolo (instagram.com/usuario),
     * usuarios con arroba (@usuario) o simples nombres de usuario (usuario).
     */
    private function normalizarRedSocial(string $tipo, string $valor): ?string {
        $v = trim($valor);
        if ($v === '') {
            return null;
        }
        // Quitar arroba inicial si el usuario puso @usuario
        $v = ltrim($v, '@');

        // Si ya empieza con http:// o https://
        if (preg_match('#^https?://#i', $v)) {
            return $this->esUrlValida($v) ? $v : null;
        }

        // Si empieza con www. o el dominio de la red social
        if (preg_match('#^(?:www\.)?(?:twitter\.com|x\.com|facebook\.com|fb\.com|instagram\.com|instagr\.am|youtube\.com|youtu\.be|tiktok\.com|kick\.com|twitch\.tv)/#i', $v)) {
            $url = 'https://' . ltrim($v, '/');
            return $this->esUrlValida($url) ? $url : null;
        }

        // Si es un nombre de usuario alfanumérico (letras, números, puntos, guiones)
        if (preg_match('/^[A-Za-z0-9_.-]{1,60}$/', $v)) {
            $base = match ($tipo) {
                'twitter_url'   => 'https://x.com/',
                'facebook_url'  => 'https://facebook.com/',
                'instagram_url' => 'https://instagram.com/',
                'youtube_url'   => 'https://youtube.com/@',
                'tiktok_url'    => 'https://tiktok.com/@',
                'kick_url'      => 'https://kick.com/',
                'twitch_url'    => 'https://twitch.tv/',
                default         => ''
            };
            if ($base !== '') {
                return $base . $v;
            }
        }

        // Intento genérico agregando https://
        $url = 'https://' . $v;
        return $this->esUrlValida($url) ? $url : null;
    }

    /**
     * True si $url es una URL http(s) sintácticamente válida. Mismo criterio
     * que discord_url en TorneoController::validarCampos(): FILTER_VALIDATE_URL
     * por sí solo acepta esquemas como "javascript:" o "data:" como válidos,
     * así que sin el chequeo de esquema alguien podría guardar
     * "javascript:alert(1)" como red social y el front lo volcaría tal cual
     * en el href del ícono.
     */
    private function esUrlValida(string $url): bool {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && preg_match('#^https?://#i', $url) === 1;
    }
}
