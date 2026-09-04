<?php
// Asegura que las variables SMTP_* del archivo .env estén cargadas en el
// entorno aunque todavía no se haya tocado la base de datos. Requerir este
// archivo ejecuta loadDotEnv() pero NO abre conexión (getDB es perezoso).
require_once __DIR__ . '/../config/database.php';

/**
 * Cliente de correo para mensajes transaccionales (p. ej. el código de
 * verificación de doble factor). Soporta dos transportes:
 *
 *   1. API HTTP de Brevo (si BREVO_API_KEY está definida): envía por HTTPS,
 *      necesario en plataformas que bloquean el SMTP saliente (Render free
 *      bloquea los puertos 25/465/587). Es el transporte preferido si está.
 *   2. SMTP puro con STARTTLS (587) o TLS implícito (465) y AUTH LOGIN, que
 *      cubre Gmail y la mayoría de proveedores. Sirve en desarrollo local.
 *
 * Configuración por variables de entorno (.env):
 *   BREVO_API_KEY                                    (activa el transporte HTTP)
 *   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS       (transporte SMTP)
 *   SMTP_FROM, SMTP_FROM_NAME                         (remitente, ambos transportes)
 *
 * Nota de entregabilidad: si el remitente (SMTP_FROM) es un dominio que no
 * controlás (p. ej. @gmail.com), el correo se entrega igual pero puede caer en
 * spam por falta de alineación SPF/DKIM. Para inbox confiable, verificá en
 * Brevo un dominio propio y usalo como SMTP_FROM.
 *
 * Diseño deliberado: ante cualquier fallo se lanza una excepción. El llamador
 * NUNCA debe asumir que el código llegó si no hubo envío exitoso (en 2FA
 * obligatorio, un fallo silencioso dejaría a la persona sin poder entrar).
 */
class Mailer {

    private string $apiKey;
    private string $host;
    private int    $port;
    private string $user;
    private string $pass;
    private string $from;
    private string $fromName;
    private int    $timeout = 15;

    public function __construct() {
        $this->apiKey   = (string) (getenv('BREVO_API_KEY') ?: '');
        $this->host     = (string) (getenv('SMTP_HOST') ?: '');
        $this->port     = (int)    (getenv('SMTP_PORT') ?: 587);
        $this->user     = (string) (getenv('SMTP_USER') ?: '');
        $this->pass     = (string) (getenv('SMTP_PASS') ?: '');
        $this->from     = (string) (getenv('SMTP_FROM') ?: $this->user);
        $this->fromName = (string) (getenv('SMTP_FROM_NAME') ?: 'Tornalyx');
    }

    /**
     * Indica si hay configuración suficiente para intentar un envío, por
     * cualquiera de los dos transportes (API HTTP o SMTP).
     */
    public function isConfigured(): bool {
        $apiOk  = $this->apiKey !== '' && $this->from !== '';
        $smtpOk = $this->host !== '' && $this->user !== '' && $this->pass !== '';
        return $apiOk || $smtpOk;
    }

    /**
     * Envía un correo multipart (texto + HTML) por el transporte disponible:
     * prioriza la API HTTP de Brevo y cae a SMTP si no hay API key.
     *
     * @throws RuntimeException si el envío falla.
     */
    public function send(string $toEmail, string $toName, string $subject, string $html, string $text): void {
        if ($this->apiKey !== '') {
            $this->sendViaBrevo($toEmail, $toName, $subject, $html, $text);
            return;
        }
        $this->sendViaSmtp($toEmail, $toName, $subject, $html, $text);
    }

    /**
     * Envía por la API HTTP de Brevo (POST /v3/smtp/email). Va por HTTPS, así
     * que funciona donde el SMTP saliente está bloqueado.
     *
     * @throws RuntimeException si la API responde un código != 2xx o curl falla.
     */
    private function sendViaBrevo(string $toEmail, string $toName, string $subject, string $html, string $text): void {
        if ($this->from === '') {
            throw new RuntimeException('Falta SMTP_FROM (remitente) para enviar por la API de Brevo.');
        }

        $to = ['email' => $toEmail];
        if ($toName !== '') {
            $to['name'] = $toName;
        }
        $payload = [
            'sender'      => ['name' => $this->fromName, 'email' => $this->from],
            'to'          => [$to],
            'subject'     => $subject,
            'htmlContent' => $html,
            'textContent' => $text,
        ];

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'content-type: application/json',
                'api-key: ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('No se pudo contactar la API de Brevo: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Brevo devuelve 201 (Created) en el envío exitoso.
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("La API de Brevo respondió HTTP {$code}: " . trim((string) $response));
        }
    }

    /**
     * Envía por SMTP puro (STARTTLS/TLS implícito + AUTH LOGIN).
     *
     * @throws RuntimeException si la conexión, autenticación o entrega fallan.
     */
    private function sendViaSmtp(string $toEmail, string $toName, string $subject, string $html, string $text): void {
        if (!($this->host !== '' && $this->user !== '' && $this->pass !== '')) {
            throw new RuntimeException('SMTP no configurado (faltan SMTP_HOST/SMTP_USER/SMTP_PASS).');
        }

        $implicitTls = ($this->port === 465);
        $transport   = $implicitTls ? 'ssl://' : 'tcp://';

        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'SNI_enabled'       => true,
            ],
        ]);

        $errno  = 0;
        $errstr = '';
        $conn = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if ($conn === false) {
            throw new RuntimeException("No se pudo conectar al servidor SMTP: {$errstr} ({$errno})");
        }
        stream_set_timeout($conn, $this->timeout);

        try {
            $this->expect($conn, 220);

            $ehlo = $this->ehloName();
            $this->cmd($conn, "EHLO {$ehlo}", 250);

            if (!$implicitTls) {
                $this->cmd($conn, 'STARTTLS', 220);
                $ok = stream_socket_enable_crypto(
                    $conn,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                );
                if ($ok !== true) {
                    throw new RuntimeException('No se pudo establecer TLS (STARTTLS) con el servidor SMTP.');
                }
                // El estándar exige re-emitir EHLO tras activar TLS.
                $this->cmd($conn, "EHLO {$ehlo}", 250);
            }

            // Autenticación AUTH LOGIN (usuario y clave en base64).
            $this->cmd($conn, 'AUTH LOGIN', 334);
            $this->cmd($conn, base64_encode($this->user), 334);
            $this->cmd($conn, base64_encode($this->pass), 235);

            // Sobre del mensaje.
            $this->cmd($conn, 'MAIL FROM:<' . $this->from . '>', 250);
            $this->cmd($conn, 'RCPT TO:<' . $toEmail . '>', 250);
            $this->cmd($conn, 'DATA', 354);

            $message = $this->buildMessage($toEmail, $toName, $subject, $html, $text);
            fwrite($conn, $message . "\r\n.\r\n");
            $this->expect($conn, 250);

            // Cierre cordial; si el servidor no responde 221 no es un fallo de
            // entrega (el mensaje ya fue aceptado con el 250 anterior).
            @fwrite($conn, "QUIT\r\n");
        } finally {
            @fclose($conn);
        }
    }

    // ──────────────────────────────────────────────────────
    // Internos
    // ──────────────────────────────────────────────────────

    /**
     * Envía un comando y verifica el código de respuesta esperado.
     */
    private function cmd($conn, string $command, int $expectedCode): string {
        fwrite($conn, $command . "\r\n");
        return $this->expect($conn, $expectedCode);
    }

    /**
     * Lee la respuesta del servidor y verifica su código.
     *
     * @throws RuntimeException si el código no coincide.
     */
    private function expect($conn, int $code): string {
        $response = $this->readResponse($conn);
        $actual   = (int) substr($response, 0, 3);
        if ($actual !== $code) {
            throw new RuntimeException("SMTP: se esperaba {$code} pero llegó: " . trim($response));
        }
        return $response;
    }

    /**
     * Lee una respuesta SMTP completa (soporta respuestas multilínea como
     * "250-...\r\n250 ...\r\n").
     */
    private function readResponse($conn): string {
        $data = '';
        while (($line = fgets($conn, 515)) !== false) {
            $data .= $line;
            // En una respuesta multilínea, la última línea tiene un espacio en
            // la 4.ª posición ("250 ..."); las intermedias, un guion ("250-...").
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
            $meta = stream_get_meta_data($conn);
            if (!empty($meta['timed_out'])) {
                throw new RuntimeException('SMTP: tiempo de espera agotado al leer la respuesta.');
            }
        }
        if ($data === '') {
            throw new RuntimeException('SMTP: el servidor no respondió.');
        }
        return $data;
    }

    /**
     * Arma el mensaje RFC 5322 multipart/alternative (texto + HTML).
     */
    private function buildMessage(string $toEmail, string $toName, string $subject, string $html, string $text): string {
        $boundary  = 'b_' . bin2hex(random_bytes(12));
        $fromValue = $this->encodeHeader($this->fromName) . ' <' . $this->from . '>';
        $toValue   = ($toName !== '' ? $this->encodeHeader($toName) . ' ' : '') . '<' . $toEmail . '>';
        $messageId = '<' . bin2hex(random_bytes(16)) . '@' . $this->ehloName() . '>';

        $headers = [
            'Date: '         . date('r'),
            'From: '         . $fromValue,
            'To: '           . $toValue,
            'Subject: '      . $this->encodeHeader($subject),
            'Message-ID: '   . $messageId,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $body = "--{$boundary}\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($text)) . "\r\n"
              . "--{$boundary}\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($html)) . "\r\n"
              . "--{$boundary}--\r\n";

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;

        // Dot-stuffing: una línea que empiece con "." debe duplicar el punto
        // para no confundirse con el terminador de DATA ("\r\n.\r\n").
        return preg_replace('/^\./m', '..', $message);
    }

    /**
     * Codifica un encabezado con caracteres no ASCII (acentos, etc.).
     */
    private function encodeHeader(string $value): string {
        if (preg_match('/[^\x20-\x7e]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    /**
     * Nombre de host válido para el comando EHLO y el Message-ID.
     */
    private function ehloName(): string {
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
        return preg_match('/^[A-Za-z0-9.\-]+$/', (string) $host) ? (string) $host : 'localhost';
    }
}
