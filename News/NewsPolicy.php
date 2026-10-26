<?php
declare(strict_types=1);

namespace AstrX\News;

use AstrX\Auth\Identity;
use AstrX\Auth\Permission;
use AstrX\Auth\PolicyInterface;
use AstrX\Auth\PolicyVerdict;

/**
 * Resource-level policy for news items.
 *
 * News has no owner today (all news is site-wide) and nothing passes a news
 * resource to Gate::can(); this is here for later, e.g. per-author news.
 *
 * governs() is empty on purpose: Gate denies any resource check routed here,
 * which is right for a policy with no rules.
 */
final class NewsPolicy implements PolicyInterface
{
    /** @return list<Permission> */
    public function governs(): array
    {
        return [];
    }

    public function evaluate(Permission $permission, Identity $identity, object $resource): PolicyVerdict
    {
        // Unreachable: Gate only calls evaluate() for permissions in governs(),
        // which is empty. Throwing here means "somebody added a permission to
        // governs() and forgot the rule" rather than a quiet allow.
        throw new \LogicException(
            'NewsPolicy has no rules; ' . $permission->value . ' should not have been routed to it.'
        );
    }
}
