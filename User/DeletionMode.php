<?php
declare(strict_types=1);

namespace AstrX\User;

/**
 * How an account and its content are handled when it is deleted.
 *
 * full_delete    The user row is removed and comments cascade. No ghost
 *                account; thread structure is lost.
 *
 * hard_redact    Personal data is wiped; comments move to the ghost account so
 *                threads stay intact. A tombstone row remains, with
 *                deletion_mode=hard_redact, so auditors can see a deletion
 *                happened.
 *
 * soft_redact    The row stays as is; pages show "[deleted]" for the username
 *                and content. Reversible.
 *
 * keep_visible   The account is closed (no login) but the profile and content
 *                stay visible. For people retiring an account.
 *
 * keep_suspended Disabled by an admin. Content is hidden from the public but
 *                visible to admins. For investigations or legal holds.
 */
enum DeletionMode: string
{
    case NONE            = 'none';
    case KEEP_VISIBLE    = 'keep_visible';
    case KEEP_SUSPENDED  = 'keep_suspended';
    case SOFT_REDACT     = 'soft_redact';
    case HARD_REDACT     = 'hard_redact';
    case FULL_DELETE     = 'full_delete';

    /** Whether this mode still leaves the user row in the database. */
    public function keepsRow(): bool
    {
        return $this !== self::FULL_DELETE;
    }

    /** Whether this mode redacts or hides content from public view. */
    public function hidesContent(): bool
    {
        return match ($this) {
            self::SOFT_REDACT, self::HARD_REDACT, self::KEEP_SUSPENDED => true,
            default => false,
        };
    }

    /** Whether the account can still log in under this mode. */
    public function allowsLogin(): bool
    {
        return $this === self::NONE;
    }
}
