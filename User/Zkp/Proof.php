<?php
declare(strict_types=1);

namespace AstrX\User\Zkp;

/** Schnorr/Fiat–Shamir proof of knowledge, using libsodium's prime-order group.
 * This is the AstrX v1 transcript, not an RFC 8235 wire format or an audit claim.
 */
final class Proof
{
    public const VERSION = 1;
    public const SUITE = 'ristretto255-sha512';
    private const DOMAIN = 'AstrX Schnorr proof of knowledge v1';

    public static function available(): bool
    {
        return function_exists('sodium_crypto_scalarmult_ristretto255_base')
            && function_exists('sodium_crypto_core_ristretto255_scalar_reduce');
    }

    public static function publicKeyValid(string $hex): bool
    {
        if (!self::available() || !preg_match('/\A[0-9a-f]{64}\z/D', $hex)) { return false; }
        $point = hex2bin($hex);
        return $point !== false && $point !== str_repeat("\0", 32)
            && sodium_crypto_core_ristretto255_is_valid_point($point);
    }

    /** @param array<string,mixed> $value */
    public static function encode(array $value): string
    {
        return rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS)), '+/', '-_'), '=');
    }

    /** Strict, bounded transport. No padding, whitespace or noncanonical aliases.
     * @return array<string,mixed>|null
     */
    public static function decode(string $token): ?array
    {
        if (strlen($token) > 4096 || !preg_match('/\A[A-Za-z0-9_-]+\z/D', $token)) { return null; }
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if ($raw === false || rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') !== $token) { return null; }
        try { $value = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return null; }
        if (!is_array($value) || array_is_list($value)) { return null; }
        foreach ($value as $key => $_) { if (!is_string($key)) { return null; } }
        /** @var array<string,mixed> $value */
        try { return self::encode($value) === $token ? $value : null; }
        catch (\JsonException) { return null; }
    }

    /** @param array{nonce:string,action:string,origin:string,expires:int|string} $challenge
     * @return array{v:int,suite:string,action:string,origin:string,nonce:string,expires:int}
     */
    public static function packet(array $challenge): array
    {
        return ['v' => self::VERSION, 'suite' => self::SUITE,
            'action' => $challenge['action'], 'origin' => $challenge['origin'],
            'nonce' => $challenge['nonce'], 'expires' => (int) $challenge['expires']];
    }

    /** The server supplies the stored challenge, never a client-chosen context.
     * @param array<string,mixed> $challenge @param array<string,mixed> $proof
     */
    public static function verify(array $challenge, string $username, array $proof): bool
    {
        if (!self::available() || ($challenge['v'] ?? null) !== self::VERSION || ($challenge['suite'] ?? null) !== self::SUITE
            || !is_string($challenge['origin'] ?? null) || !is_string($challenge['action'] ?? null)
            || !is_string($challenge['nonce'] ?? null) || !is_int($challenge['expires'] ?? null)
            || !preg_match('/\A[0-9a-f]{64}\z/D', $challenge['nonce'])
            || ($proof['v'] ?? null) !== self::VERSION
            || count($proof) !== 6 || ($proof['username'] ?? null) !== $username
            || ($proof['nonce'] ?? null) !== $challenge['nonce']
            || $username === '' || strlen($username) > 128 || !mb_check_encoding($username, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f]/', $username)) { return false; }
        foreach (['public_key', 'commitment', 'response'] as $field) {
            if (!is_string($proof[$field] ?? null) || !preg_match('/\A[0-9a-f]{64}\z/D', $proof[$field])) { return false; }
        }
        /** @var array{v:int,nonce:string,username:string,public_key:string,commitment:string,response:string} $proof */
        if (!self::publicKeyValid($proof['public_key']) || !self::publicKeyValid($proof['commitment'])) { return false; }
        $key = hex2bin($proof['public_key']);
        $r = hex2bin($proof['commitment']);
        $z = hex2bin($proof['response']);
        if ($key === false || $r === false || $z === false) { return false; }
        try {
            // Reject z >= L; accepting reduced aliases would make proofs malleable.
            if (!hash_equals($z, sodium_crypto_core_ristretto255_scalar_reduce($z . str_repeat("\0", 32)))) { return false; }
            $one = "\1" . str_repeat("\0", 31);
            $fields = [self::DOMAIN, self::SUITE, sodium_crypto_scalarmult_ristretto255_base($one),
                (string) $challenge['origin'], (string) $challenge['action'],
                hex2bin((string) $challenge['nonce']), (string) $challenge['expires'], $username, $key, $r];
            $transcript = '';
            foreach ($fields as $field) {
                if (!is_string($field)) { return false; }
                $transcript .= pack('N', strlen($field)) . $field;
            }
            $c = sodium_crypto_core_ristretto255_scalar_reduce(hash('sha512', $transcript, true));
            $zero = str_repeat("\0", 32);
            $left = $z === $zero ? $zero : sodium_crypto_scalarmult_ristretto255_base($z);
            $cx = $c === $zero ? $zero : sodium_crypto_scalarmult_ristretto255($c, $key);
            return hash_equals($left, sodium_crypto_core_ristretto255_add($r, $cx));
        } catch (\SodiumException | \ValueError) {
            return false;
        }
    }
}
