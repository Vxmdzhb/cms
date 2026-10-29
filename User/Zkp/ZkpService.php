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
    private bool $enabled = false;
    /** @var list<string> */
    private array $origins = [];

    public function __construct(private readonly ChallengeRepository $challenges, private readonly Request $request) {}

    #[InjectConfig('enabled')]
    public function setEnabled(bool $value): void { $this->enabled = $value; }

    /** @param list<string> $origins */
    #[InjectConfig('origins')]
    public function setOrigins(array $origins): void { $this->origins = $origins; }

    /** An allowlist, never a Host-header-derived trust decision. */
    public function origin(): ?string
    {
        if (!$this->enabled || !Proof::available()) { return null; }
        $uri = $this->request->uri();
        $scheme = $this->request->isSecure() ? 'https' : 'http';
        $host = strtolower($uri->host());
        $port = $uri->port();
        $origin = $scheme . '://' . $host;
        if ($port !== null && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $origin .= ':' . $port;
        }
        if (!in_array($origin, $this->origins, true) || strlen($origin) > 255
            || !preg_match('/\A[a-z0-9.-]+\z/D', $host)) { return null; }
        if ($scheme !== 'https' && !preg_match('/\A[a-z2-7]{56}\.onion\z/D', $host)
            && !in_array($host, ['localhost', '127.0.0.1'], true)) { return null; }
        return $origin;
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
        $ctx->set('zkp_available', false);
        $origin = $this->origin();
        if ($origin === null || !in_array($action, ['register', 'login', 'enroll'], true)) { return; }
        if (!headers_sent()) { header('Cache-Control: private, no-store'); }
        try {
            $binding = $this->binding();
            $saved = $_SESSION['_zkp_challenge_' . $action] ?? '';
            $challenge = is_string($saved) ? $this->challenges->find($saved, $binding, $action, $origin, time()) : null;
            if ($challenge === null) { $challenge = $this->challenges->issue($binding, $action, $origin, time()); }
            $_SESSION['_zkp_challenge_' . $action] = $challenge['nonce'];
            $t->load('User');
            $ctx->set('zkp_available', true);
            $ctx->set('zkp_challenge', Proof::encode($challenge));
            foreach (['method', 'password', 'key', 'instructions', 'challenge_label', 'proof_label', 'mail_notice'] as $label) {
                $ctx->set('zkp_' . $label, $t->t('user.zkp.' . $label));
            }
            $ctx->set('zkp_action', $action);
        } catch (\PDOException | \RuntimeException) {
            // Password login remains usable if the optional feature is unavailable.
            $ctx->set('zkp_available', false);
        }
    }
}
