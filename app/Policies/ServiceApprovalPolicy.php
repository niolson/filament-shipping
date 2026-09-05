<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\ServiceApproval;
use App\Models\User;

/**
 * Approving a discovered service is authorizing unattended spending on a
 * client's account, which puts it with the other Admin acts rather than with
 * the Manager-level mapping it depends on.
 *
 * The same line `ClientResource` already draws: `clients.blind_purchase_enabled`
 * is the other consent-to-spend flag in the app and it is edited by admins.
 * Naming a service is a manager's job; deciding money may be spent on it
 * without anyone watching is not.
 */
class ServiceApprovalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function view(User $user, ServiceApproval $serviceApproval): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function create(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function update(User $user, ServiceApproval $serviceApproval): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    public function delete(User $user, ServiceApproval $serviceApproval): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }

    /**
     * Withdrawing approvals wholesale, with no particular row in hand.
     *
     * What unmapping a service does: `ObservedServiceMapper::unmap()` revokes
     * every approval of it, in every environment. That is the same act as
     * unticking the clients one at a time, so it answers to the same role —
     * otherwise mapping, which opens at `Manager`, would be a way round this
     * policy rather than a separate concern from it.
     */
    public function deleteAny(User $user): bool
    {
        return $user->role->isAtLeast(Role::Admin);
    }
}
