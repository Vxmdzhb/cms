<?php
declare(strict_types=1);

namespace AstrX\User\Zkp;

use AstrX\Config\InjectConfig;
use AstrX\Config\ConfigDomain;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use AstrX\Template\TemplateVars;
use AstrX\User\Diagnostic\UserLoginFailedDiagnostic;

/** Optional credential proof; account policy and session grants stay in UserService. */
#[ConfigDomain('ZkpService', file: 'User')]
final class ZkpService
{
    public const int DEFAULT_TTL = 300;
    public const int MIN_TTL = 60;
    public const int MAX_TTL = 600;
    private int $ttl = self::DEFAULT_TTL;
    private bool $enabled = false;
    /** @var list<string> */
    private array $origins = [];
    private string $helpUrl = '';

    public function __construct(private readonly ChallengeRepository $challenges, private readonly Request $request) {}

    #[InjectConfig('challenge_ttl')]
    public function setChallengeTtl(int $seconds): void
    {
        $this->ttl = max(self::MIN_TTL, min(self::MAX_TTL, $seconds));
    }

    #[InjectConfig('enabled')]
    public function setEnabled(bool $value): void { $this->enabled = $value; }

    /** @param list<string> $origins */
    #[InjectConfig('origins')]
    public function setOrigins(array $origins): void { $this->origins = $origins; }

    #[InjectConfig('help_url')]
    public function setHelpUrl(string $value): void
    {
        // A link on the page: only http(s), or a path on this site.
        $this->helpUrl = self::validHelpUrl($value) ? $value : '';
    }

    public static function validHelpUrl(string $url): bool
    {
        if ($url === '') { return true; }
        if (str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f"<>]/', $url)) { return false; }
        if (str_starts_with($url, '/')) { return !str_starts_with($url, '//'); }
        return preg_match('~\Ahttps?://~', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_USER) === null;
    }

    public static function validOrigin(string $origin): bool
    {
        if (strlen($origin) > 255 || !preg_match('~\A(https?)://([a-z0-9.-]+)(?::([1-9][0-9]{0,4}))?\z~D', $origin, $parts)) {
            return false;
        }
        $https = $parts[1] === 'https';
        $port = isset($parts[3]) ? (int) $parts[3] : null;
        if ($port !== null && ($port > 65535 || $port === ($https ? 443 : 80))) { return false; }
        return $https || in_array($parts[2], ['localhost', '127.0.0.1'], true)
            || preg_match('/\A[a-z2-7]{56}\.onion\z/D', $parts[2]) === 1;
    }

    public function requestOrigin(): string
    {
        $secure = $this->request->isSecure();
        $uri = $this->request->uri();
        $scheme = $secure ? 'https' : 'http';
        $origin = $scheme . '://' . strtolower($uri->host());
        $port = $uri->port();
        // A TLS terminator may correctly set HTTPS while FastCGI still reports
        // its own port 80. Retain an explicitly requested https://host:80.
        $host = $this->request->server()->get('HTTP_HOST');
        $implicitBackendPort = $secure && $port === 80 && is_string($host) && !str_contains($host, ':');
        if (!$implicitBackendPort && $port !== null && $port !== ($secure ? 443 : 80)) {
            $origin .= ':' . $port;
        }
        return $origin;
    }

    public function origin(): ?string
    {
        if (!$this->enabled || !Proof::available()) { return null; }
        $origin = $this->requestOrigin();
        return self::validOrigin($origin) && in_array($origin, $this->origins, true) ? $origin : null;
    }

    /** Read-only checks for the system administrator on this origin. */
    public function configurationStatus(): string
    {
        if (!$this->enabled) { return 'disabled'; }
        if (!Proof::available()) { return 'sodium'; }
        if ($this->origin() === null) { return 'origin'; }
        return $this->challenges->schemaReadable() ? 'ready' : 'schema';
    }

    private function binding(): string
    {
        $key = $_SESSION['_zkp_binding'] ?? null;
        if (!is_string($key) || !preg_match('/\A[0-9a-f]{64}\z/D', $key)) {
            $key = bin2hex(random_bytes(32));
            $_SESSION['_zkp_binding'] = $key;
        }
        return hash('sha256', $key);
    }

    /** @return Result<string> Verified public key, after spending a bound challenge. */
    public function verify(string $action, string $username, string $token): Result
    {
        if (strlen($token) > 4096) { return $this->failure(); }
        $origin = $this->origin();
        $proof = Proof::decode(trim($token));
        if ($origin === null || !in_array($action, ['register', 'login', 'enroll'], true) || $proof === null
            || !is_string($proof['nonce'] ?? null) || !preg_match('/\A[0-9a-f]{64}\z/D', $proof['nonce'])) { return $this->failure(); }
        try {
            $binding = $this->binding();
            $now = time();
            $challenge = $this->challenges->find($proof['nonce'], $binding, $action, $origin, $now);
            // Even an invalid proof burns its own challenge. No second response
            // is accepted, even when the PHP session store has no locking.
            if ($challenge === null || !$this->challenges->consume($proof['nonce'], $binding, $action, $origin, $now)
                || !Proof::verify($challenge, $username, $proof)) { return $this->failure(); }
            $key = $proof['public_key'] ?? null;
            return is_string($key) ? Result::ok($key) : $this->failure();
        } catch (\PDOException | \RuntimeException) {
            return $this->failure();
        }
    }

    /** A generic error avoids disclosing whether a username has a key.
     * @return Result<never>
     */
    private function failure(): Result
    {
        return Result::err(null, Diagnostics::of(new UserLoginFailedDiagnostic('astrx.user/login_failed', DiagnosticLevel::NOTICE)));
    }

    /** Use the existing form's PRG, CSRF and captcha; only add the alternative. */
    public function present(TemplateVars $ctx, Translator $t, string $action): void
    {
        // AstrX resolves partial tags through the context, not literal filenames.
        $ctx->set('zkp_auth', 'partials/zkp_auth');
        $ctx->set('zkp_available', false);
        $origin = $this->origin();
        if ($origin === null || !in_array($action, ['register', 'login', 'enroll'], true)) { return; }
        if (!headers_sent()) { header('Cache-Control: private, no-store'); }
        try {
            $binding = $this->binding();
            $saved = $_SESSION['_zkp_challenge_' . $action] ?? '';
            $challenge = is_string($saved) ? $this->challenges->find($saved, $binding, $action, $origin, time()) : null;
            if ($challenge === null) { $challenge = $this->challenges->issue($binding, $action, $origin, time(), $this->ttl); }
            $_SESSION['_zkp_challenge_' . $action] = $challenge['nonce'];
            $t->load('User');
            $ctx->set('zkp_available', true);
            $ctx->set('zkp_challenge', Proof::encode($challenge));
            foreach (['instructions', 'challenge_label', 'proof_label', 'help'] as $label) {
                $ctx->set('zkp_' . $label, $t->t('user.zkp.' . $label));
            }
            $ctx->set('zkp_summary', $t->t($action === 'register' ? 'user.zkp.key_register' : 'user.zkp.key_login'));
            $ctx->set('zkp_action', $action);
            $ctx->set('zkp_has_help', $this->helpUrl !== '');
            $ctx->set('zkp_help_url', $this->helpUrl);
        } catch (\PDOException | \RuntimeException) {
            // Password login remains usable if the optional feature is unavailable.
            $ctx->set('zkp_available', false);
        }
    }
}
