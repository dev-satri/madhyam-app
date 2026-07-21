<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reclassify legacy `expenses.category = 'salary'` rows to `other`.
 *
 * Rationale: `salary` was removed from ExpenseCategory (P0-1). Payroll now
 * flows exclusively through the `salaries` / (future) `payslips` tables to
 * eliminate double-counting in Reports Net Profit. Existing rows with
 * `category = 'salary'` would otherwise remain unreadable via the enum and
 * still be summed as expenses. We rename them to `other` and note the
 * reclassification in the description so history is preserved.
 *
 * The DB enum column keeps `'salary'` in its allowed set so this migration
 * remains idempotent; a follow-up migration in P1 will drop the option once
 * every environment is confirmed clean.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('expenses')->where('category', 'salary')->get(['id', 'description']);

        foreach ($rows as $row) {
            $prefix = '[Reclassified from salary category] ';
            $newDesc = str_starts_with((string) $row->description, $prefix)
                ? $row->description
                : $prefix . ($row->description ?? '');

            DB::table('expenses')->where('id', $row->id)->update([
                'category' => 'other',
                'description' => $newDesc,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Non-reversible: we cannot reliably distinguish rows that were
        // legitimately `other` from those we relabeled. No-op is safest.
    }
};
