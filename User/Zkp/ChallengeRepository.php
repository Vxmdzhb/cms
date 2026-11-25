<?php
declare(strict_types=1);

namespace AstrX\User\Zkp;

use PDO;

/** Durable one-shot challenges. Session storage alone is not a concurrency lock. */
final class ChallengeRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,mixed>|null */
    public function find(string $nonce, string $binding, string $action, string $origin, int $now): ?array
    {
        $stmt = $this->pdo->prepare('SELECT nonce, action, origin, expires
            FROM user_zkp_challenge WHERE nonce = :nonce AND session_hash = :binding
            AND action = :action AND origin = :origin AND expires > :now AND used = 0');
        $stmt->execute([':nonce' => $nonce, ':binding' => $binding, ':action' => $action, ':origin' => $origin, ':now' => $now]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['nonce'] ?? null) || !is_string($row['action'] ?? null)
            || !is_string($row['origin'] ?? null) || !(is_int($row['expires'] ?? null) || is_string($row['expires'] ?? null))) { return null; }
        return Proof::packet(['nonce'=>$row['nonce'], 'action'=>$row['action'], 'origin'=>$row['origin'], 'expires'=>$row['expires']]);
    }

    public function schemaReadable(): bool
    {
        try {
            return $this->pdo->query('SELECT user_id, public_key FROM user_zkp_key WHERE 1=0') !== false
                && $this->pdo->query('SELECT nonce, session_hash, action, origin, expires, used FROM user_zkp_challenge WHERE 1=0') !== false;
        } catch (\PDOException) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    public function issue(string $binding, string $action, string $origin, int $now, int $ttl = ZkpService::DEFAULT_TTL): array
    {
        $clean = $this->pdo->prepare('DELETE FROM user_zkp_challenge WHERE expires <= :now');
        $clean->execute([':now' => $now]);
        // Keep consumed rows until expiry. This issuance cap is best-effort under
        // concurrency; replay protection below uses an atomic update instead.
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM user_zkp_challenge WHERE session_hash = :binding');
        $count->execute([':binding' => $binding]);
        if ((int) $count->fetchColumn() >= 12) { throw new \RuntimeException('Challenge limit reached'); }
        $row = ['v' => Proof::VERSION, 'suite' => Proof::SUITE, 'nonce' => bin2hex(random_bytes(32)),
            'action' => $action, 'origin' => $origin, 'expires' => $now + $ttl];
        $stmt = $this->pdo->prepare('INSERT INTO user_zkp_challenge
            (nonce, session_hash, action, origin, expires, used) VALUES (:nonce, :binding, :action, :origin, :expires, 0)');
        $stmt->execute([':nonce' => $row['nonce'], ':binding' => $binding,
            ':action' => $action, ':origin' => $origin, ':expires' => $row['expires']]);
        return $row;
    }

    /** Atomic compare-and-swap: exactly one concurrent request can spend it. */
    public function consume(string $nonce, string $binding, string $action, string $origin, int $now): bool
    {
        $stmt = $this->pdo->prepare('UPDATE user_zkp_challenge SET used = 1
            WHERE nonce = :nonce AND session_hash = :binding AND action = :action
            AND origin = :origin AND expires > :now AND used = 0');
        $stmt->execute([':nonce' => $nonce, ':binding' => $binding, ':action' => $action, ':origin' => $origin, ':now' => $now]);
        return $stmt->rowCount() === 1;
    }
}
