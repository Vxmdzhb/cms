<?php
declare(strict_types=1);

namespace AstrX\Admin;

use AstrX\Admin\Diagnostic\AuditLogDiagnostic;
use AstrX\Http\Request;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use AstrX\User\UserSession;
use PDO;
use AstrX\Result\DiagnosticLevel;

/**
 * Writes tamper-evident audit log entries for significant admin actions.
 *
 * Each entry stores entry_hash = SHA-256(prev_hash ‖ fields), where prev_hash
 * is the previous entry's hash, so editing or deleting an entry in the middle
 * breaks the chain there. An anchor (the latest hash and an entry count) is
 * kept in `site_config` and advanced in the same transaction as each append,
 * so {@see verifyChain()} also notices entries cut off at either end. Appends
 * lock the anchor row (`SELECT … FOR UPDATE`) inside a transaction, so
 * concurrent admin actions wait instead of forking the chain.
 *
 * This is tamper evidence, not prevention. Someone with UPDATE/DELETE on both
 * `admin_audit_log` and the anchor could rebuild a consistent chain (the hash
 * is unkeyed and both live in the same database). To prevent tampering, deny
 * the application's database role UPDATE/DELETE on `admin_audit_log`;
 * publishing the head hash in the warrant canary anchors it outside the
 * database.
 *
 * Table admin_audit_log: id, user_id, username, action, resource, detail, ip,
 * created_at, prev_hash, entry_hash. created_at is not hashed: how a
 * TIMESTAMP reads back depends on the session time zone. The id keeps the
 * order.
 */
final class AuditLogger
{
    private const ANCHOR_HEAD  = 'audit_chain_head';
    private const ANCHOR_COUNT = 'audit_chain_count';

    public function __construct(
        private readonly PDO         $pdo,
        private readonly UserSession $session,
        private readonly Request     $request,
    ) {}

    /**
     * Record an admin action, extending the tamper-evident chain + anchor.
     *
     * @return Result<bool>
     */
    public function log(string $action, string $resource, string $detail = ''): Result
    {
        $userId    = $this->session->userId();
        $username  = $this->session->username();
        $ip        = $this->request->ip();
        $createdAt = gmdate('Y-m-d H:i:s');

        $owns = false;
        try {
            // Ensure the anchor rows exist so FOR UPDATE has something to lock
            // (idempotent — a no-op once seeded by the migration / first call).
            $this->pdo->prepare(
                "INSERT IGNORE INTO `site_config` (`key`, `value`)
                 VALUES (:hk, '') , (:ck, '0')"
            )->execute([':hk' => self::ANCHOR_HEAD, ':ck' => self::ANCHOR_COUNT]);

            $owns = !$this->pdo->inTransaction();
            if ($owns) { $this->pdo->beginTransaction(); }

            // Serialize appends: locking the anchor head row blocks a concurrent
            // writer until this transaction commits — no fork, no fail-open.
            $sel = $this->pdo->prepare(
                "SELECT `value` FROM `site_config` WHERE `key` = :k FOR UPDATE"
            );
            $sel->execute([':k' => self::ANCHOR_HEAD]);
            $prevHash = self::str($sel->fetchColumn());
            $sel->closeCursor();

            $cntStmt = $this->pdo->prepare(
                "SELECT `value` FROM `site_config` WHERE `key` = :k FOR UPDATE"
            );
            $cntStmt->execute([':k' => self::ANCHOR_COUNT]);
            $count = self::intval($cntStmt->fetchColumn());
            $cntStmt->closeCursor();

            $entryHash = self::computeHash($prevHash, $userId, $username, $action, $resource, $detail, $ip);

            $this->pdo->prepare(
                'INSERT INTO `admin_audit_log`
                     (`user_id`, `username`, `action`, `resource`, `detail`, `ip`,
                      `created_at`, `prev_hash`, `entry_hash`)
                 VALUES (UNHEX(:uid), :username, :action, :resource, :detail, :ip,
                      :created_at, :prev_hash, :entry_hash)'
            )->execute([
                ':uid'        => $userId,
                ':username'   => $username,
                ':action'     => $action,
                ':resource'   => $resource,
                ':detail'     => $detail,
                ':ip'         => $ip,
                ':created_at' => $createdAt,
                ':prev_hash'  => $prevHash,
                ':entry_hash' => $entryHash,
            ]);

            // Advance the anchor in the same transaction.
            $this->pdo->prepare(
                "UPDATE `site_config` SET `value` = :v WHERE `key` = :k"
            )->execute([':v' => $entryHash, ':k' => self::ANCHOR_HEAD]);
            $this->pdo->prepare(
                "UPDATE `site_config` SET `value` = :v WHERE `key` = :k"
            )->execute([':v' => (string) ($count + 1), ':k' => self::ANCHOR_COUNT]);

            if ($owns) { $this->pdo->commit(); }
            return Result::ok(true);
        } catch (\Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                try { $this->pdo->rollBack(); } catch (\Throwable) { /* ignore */ }
            }
            return Result::err(null, Diagnostics::of(new AuditLogDiagnostic(
                'astrx.admin/audit_log_write_failed', DiagnosticLevel::WARNING, $e->getMessage()
            )));
        }
    }

    /**
     * Verify the tamper-evident chain against its anchor.
     *
     * Walks the chained rows (entry_hash != '' — legacy pre-R12 rows are skipped)
     * oldest-first and checks (a) each entry_hash recomputes and (b) each
     * prev_hash equals the previous chained entry's entry_hash (middle
     * edit/deletion). Then compares the walked count + last hash against the
     * anchor, so prefix/suffix TRUNCATION (which a plain chain misses) is caught.
     *
     * @return array{status:string,checked:int,broken_id:int}
     *         status: 'intact' | 'broken' | 'empty'
     */
    public function verifyChain(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT `id`, LOWER(HEX(`user_id`)) AS `user_id`, `username`, `action`,
                        `resource`, `detail`, `ip`, `prev_hash`, `entry_hash`
                   FROM `admin_audit_log`
                  ORDER BY `id` ASC'
            );
            if ($stmt === false) {
                return ['status' => 'broken', 'checked' => 0, 'broken_id' => 0];
            }
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return ['status' => 'broken', 'checked' => 0, 'broken_id' => 0];
        }

        $checked  = 0;
        $expected = null;   // expected prev_hash of the next chained entry
        $lastHash = '';
        $lastId   = 0;
        foreach ($rows as $row) {
            $entryHash = self::str($row['entry_hash'] ?? null);
            if ($entryHash === '') {
                continue;   // legacy, pre-chain entry
            }
            $prevHash = self::str($row['prev_hash'] ?? null);
            $id       = self::intval($row['id'] ?? null);

            if ($expected !== null && $prevHash !== $expected) {
                return ['status' => 'broken', 'checked' => $checked, 'broken_id' => $id];
            }
            $recomputed = self::computeHash(
                $prevHash,
                self::str($row['user_id'] ?? null),
                self::str($row['username'] ?? null),
                self::str($row['action'] ?? null),
                self::str($row['resource'] ?? null),
                self::str($row['detail'] ?? null),
                self::str($row['ip'] ?? null),
            );
            if (!hash_equals($recomputed, $entryHash)) {
                return ['status' => 'broken', 'checked' => $checked, 'broken_id' => $id];
            }
            $expected = $entryHash;
            $lastHash = $entryHash;
            $lastId   = $id;
            $checked++;
        }

        // Anchor comparison — catches truncation of the newest/oldest entries,
        // which the per-row walk alone cannot see.
        [$anchorHead, $anchorCount] = $this->anchor();
        if ($anchorCount !== $checked) {
            // The count doesn't match: entries were removed, or the anchor was tampered
            // with. Report the last chained id still there (0 if none), so the admin sees
            // where the visible chain ends.
            return ['status' => 'broken', 'checked' => $checked, 'broken_id' => $lastId];
        }
        if ($checked > 0 && !hash_equals($anchorHead, $lastHash)) {
            return ['status' => 'broken', 'checked' => $checked, 'broken_id' => $lastId];
        }

        return ['status' => $checked === 0 ? 'empty' : 'intact', 'checked' => $checked, 'broken_id' => 0];
    }

    // -------------------------------------------------------------------------

    private static function computeHash(
        string $prevHash, string $userIdHex, string $username, string $action,
        string $resource, string $detail, string $ip,
    ): string {
        // \x1f (unit separator) delimits fields so no concatenation ambiguity can
        // let two distinct entries collide.
        return hash('sha256', implode("\x1f", [
            $prevHash, $userIdHex, $username, $action, $resource, $detail, $ip,
        ]));
    }

    /** @return array{0:string,1:int} [head hash, entry count] */
    private function anchor(): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT `key`, `value` FROM `site_config` WHERE `key` IN (:h, :c)"
            );
            $stmt->execute([':h' => self::ANCHOR_HEAD, ':c' => self::ANCHOR_COUNT]);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return ['', 0];
        }
        $head = ''; $count = 0;
        foreach ($rows as $r) {
            if (self::str($r['key'] ?? null) === self::ANCHOR_HEAD)  { $head  = self::str($r['value'] ?? null); }
            if (self::str($r['key'] ?? null) === self::ANCHOR_COUNT) { $count = self::intval($r['value'] ?? null); }
        }
        return [$head, $count];
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    private static function intval(mixed $v): int
    {
        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : 0);
    }
}
