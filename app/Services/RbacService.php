<?php

namespace App\Services;

use App\Models\DataAccess;
use App\Models\FeatureAccess;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class RbacService
{
    public const FEATURES = [
        'dashboard', 'clients', 'packages', 'contentPlanner', 'workflow', 'tasks',
        'approvals', 'files', 'reports', 'leaves', 'expenses',
        'salary', 'overtime', 'team', 'settings', 'userGuide',
        'complaints', 'clientPortal',
    ];

    public const PERMISSIONS = [
        'seeAllTasks', 'seeAllWorkflow', 'seeAllPerformance',
        'seeAllActivity', 'canAddTasks', 'canMoveWorkflow', 'canEditWorkflow',
    ];

    public const BUILT_IN_ROLES = [
        'super-admin', 'admin', 'manager', 'editor',
        'videographer', 'designer', 'copywriter', 'social-media',
    ];

    public function hasFeature(?string $role, string $feature): bool
    {
        if (! $role) {
            return false;
        }
        if ($role === 'super-admin') {
            return true;
        }
        $features = $this->featuresFor($role);

        return (bool) ($features[$feature] ?? false);
    }

    public function hasDataAccess(?string $role, string $permission): bool
    {
        if (! $role) {
            return false;
        }
        if ($role === 'super-admin') {
            return true;
        }
        $perms = $this->permissionsFor($role);

        return (bool) ($perms[$permission] ?? false);
    }

    public function isSuperAdmin(?User $user): bool
    {
        return $user !== null && $user->role === 'super-admin';
    }

    public function canEditMember(?User $actor, User $target): bool
    {
        if (! $actor) {
            return false;
        }
        if ($target->role === 'super-admin' && $actor->id !== $target->id) {
            return false;
        }

        return $this->isSuperAdmin($actor) || in_array($actor->role, ['admin', 'manager'], true);
    }

    public function canDeleteMember(?User $actor, User $target): bool
    {
        if (! $actor) {
            return false;
        }
        if ($target->role === 'super-admin') {
            return false;
        }
        if ($actor->id === $target->id) {
            return false;
        }

        return $this->isSuperAdmin($actor) || $actor->role === 'admin';
    }

    public function isVideographerRestricted(?User $user): bool
    {
        return $user !== null && $user->role === 'videographer';
    }

    protected function featuresFor(string $role): array
    {
        return Cache::store('array')->rememberForever("features:{$role}", function () use ($role) {
            $row = FeatureAccess::where('role', $role)->first();

            return $row?->features ?? [];
        });
    }

    protected function permissionsFor(string $role): array
    {
        return Cache::store('array')->rememberForever("perms:{$role}", function () use ($role) {
            $row = DataAccess::where('role', $role)->first();

            return $row?->permissions ?? [];
        });
    }
}
