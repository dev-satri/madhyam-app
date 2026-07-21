<?php

namespace App\Models\Concerns;

use App\Models\Scopes\ClientAccountScope;

/**
 * Opt-in trait that attaches ClientAccountScope on model boot.
 *
 * Laravel auto-invokes any static method named `boot{TraitName}`
 * during Model::boot(), so simply `use ScopesToClientAccount;` on
 * a model is enough — no manual `booted()` override required.
 *
 * Applied to models that carry a `client_id` and are surfaced in
 * the client portal:
 *   Content, Workflow, Approval, Invoice, Complaint
 *
 * Escape hatch:
 *   Model::withoutGlobalScope(\App\Models\Scopes\ClientAccountScope::class)
 */
trait ScopesToClientAccount
{
    public static function bootScopesToClientAccount(): void
    {
        static::addGlobalScope(new ClientAccountScope);
    }
}
