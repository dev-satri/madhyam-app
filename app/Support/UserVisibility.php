<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;

/**
 * Single source of truth for hiding the super-admin (provider) role
 * from every non-super-admin viewer.
 *
 * The super-admin represents the SaaS provider and is not part of the
 * agency's staff. Admins and below must never see them in pickers,
 * team lists, dashboards, or leaderboards.
 *
 * Use this helper for raw `DB::table('users')` queries. For Eloquent
 * queries, prefer `User::visibleTo($viewer)` (see User model scope).
 */
class UserVisibility
{
    /**
     * Apply the super-admin hiding rule to a query builder.
     *
     * @param  QueryBuilder|EloquentBuilder  $query
     * @param  User|null  $viewer  Defaults to the currently authenticated staff user.
     * @param  string  $table  The table alias the `role` column lives on. Defaults to 'users'.
     */
    public static function apply($query, ?User $viewer = null, string $table = 'users')
    {
        $viewer = $viewer ?? Auth::user();

        // Only staff super-admins bypass the filter. Client-guard users
        // (Auth::guard('client')) always fall through to the filtered path.
        if ($viewer instanceof User && $viewer->isSuperAdmin()) {
            return $query;
        }

        return $query->where("{$table}.role", '!=', 'super-admin');
    }

    /**
     * Returns true when the current viewer is allowed to see super-admins.
     * Useful for masking display fields (e.g. approvals.submitter_name).
     */
    public static function canSeeSuperAdmins(?User $viewer = null): bool
    {
        $viewer = $viewer ?? Auth::user();

        return $viewer instanceof User && $viewer->isSuperAdmin();
    }
}
