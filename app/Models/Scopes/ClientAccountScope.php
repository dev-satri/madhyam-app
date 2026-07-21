<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Transparently constrains queries to the currently-authenticated
 * client_account's client_id.
 *
 * Rationale: the client portal must never leak cross-tenant data.
 * A missing WHERE in a Volt page becomes a data-loss incident;
 * pushing tenant isolation into a global scope makes leaks
 * impossible even when a developer forgets to filter manually.
 *
 * Applies ONLY when the `client` guard is authenticated. Staff
 * requests (web guard) and unauthenticated CLI/queue contexts are
 * unaffected — the query proceeds unscoped.
 *
 * If you need to bypass this scope for a legitimate cross-tenant
 * query (e.g. an admin report), call `Model::withoutGlobalScope(ClientAccountScope::class)`.
 */
class ClientAccountScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $account = Auth::guard('client')->user();

        if ($account === null) {
            return;
        }

        // Guard against ClientAccount records missing a client_id (shouldn't
        // happen given the migration's cascade FK, but the check is cheap).
        $clientId = $account->client_id ?? null;
        if ($clientId === null) {
            // Force zero results rather than leak all rows.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->getTable().'.client_id', $clientId);
    }
}
