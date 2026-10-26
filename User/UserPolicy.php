<?php
declare(strict_types=1);

namespace AstrX\User;

use AstrX\Auth\Identity;
use AstrX\Auth\Permission;
use AstrX\Auth\PolicyInterface;
use AstrX\Auth\PolicyVerdict;

/**
 * Resource-level policy for user accounts.
 *
 * Expects a UserResource (a typed id and UserGroup, built by
 * UserResource::fromRow()) and is registered against UserResource::class:
 * the policy is keyed by what callers actually pass to Gate::can().
 *
 * Prevents privilege escalation: someone who isn't an admin can't edit,
 * delete or regroup an account that ranks at or above their own.
 */
final class UserPolicy implements PolicyInterface
{
    /** @return list<Permission> */
    public function governs(): array
    {
        return [
            UserPermission::EDIT_OWN,
            UserPermission::DELETE_OWN,
            UserPermission::EDIT_ANY,
            UserPermission::DELETE_ANY,
            // USER_PROMOTE has a call site (AdminUsersController), so it must be handled
            // here, not left to fall through.
            UserPermission::PROMOTE,
        ];
    }

    public function evaluate(Permission $permission, Identity $identity, object $resource): PolicyVerdict
    {
        // Registered for UserResource only. A different object here means a
        // caller passed the wrong resource type for a user.* permission (the
        // original defect); refuse rather than guess at its fields.
        if (!$resource instanceof UserResource) {
            return PolicyVerdict::Deny;
        }

        $isSelf = $identity->isLoggedIn()
            && $resource->id !== ''
            && $resource->id === strtolower($identity->userId());

        // Compare by PRIVILEGE RANK, never the raw enum value: UserGroup's
        // integers are not privilege-ordered (USER=0, ADMIN=1, MOD=2, GUEST=3),
        // so a numeric comparison would rank a MOD (2) above an ADMIN (1).
        $targetOutranksActor = $resource->type->rank() >= UserGroup::ofIdentity($identity)->rank();

        return match ($permission) {
            UserPermission::EDIT_OWN,
            UserPermission::DELETE_OWN => $isSelf ? PolicyVerdict::Allow : PolicyVerdict::Deny,

            UserPermission::EDIT_ANY,
            UserPermission::DELETE_ANY,
            UserPermission::PROMOTE =>
                // Admins are unconstrained at this level (callers still apply
                // their own rules — e.g. AdminUsersController's no-self-promotion
                // check). Acting on your own row is not escalation. Otherwise:
                // refuse a target ranking at or above the actor, so a MOD cannot
                // reach an ADMIN — nor a peer MOD, whose account is an equally
                // good stepping stone to one.
                (UserGroup::ofIdentity($identity) === UserGroup::ADMIN || $isSelf || !$targetOutranksActor)
                    ? PolicyVerdict::Abstain
                    : PolicyVerdict::Deny,

            // Unreachable while this match and governs() agree — Gate never
            // routes anything else here. Throwing makes the drift loud instead
            // of turning a forgotten permission into a silent allow.
            default => throw new \LogicException(
                'UserPolicy::governs() lists ' . $permission->value . ' but evaluate() has no arm for it.'
            ),
        };
    }
}
