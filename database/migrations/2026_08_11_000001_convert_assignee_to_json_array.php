<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = config('database.default');

        // ── Step 1: Drop ALL indexes and foreign keys on assignee column ──
        foreach (['workflows', 'tasks', 'contents'] as $tableName) {
            // Drop foreign keys first
            try {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropForeign(['assignee']);
                });
            } catch (Exception $e) {
                // FK might not exist, continue
            }

            // Drop indexes using raw SQL for MySQL
            if ($driver !== 'sqlite') {
                try {
                    // Get all indexes on the table
                    $indexes = DB::select("SHOW INDEX FROM {$tableName}");
                    foreach ($indexes as $index) {
                        // If index includes assignee column and is not PRIMARY
                        if (strtolower($index->Column_name ?? '') === 'assignee' &&
                            strtoupper($index->Key_name ?? '') !== 'PRIMARY') {
                            try {
                                DB::statement("ALTER TABLE {$tableName} DROP INDEX `{$index->Key_name}`");
                            } catch (Exception $e) {
                                // Index might be already dropped
                            }
                        }
                    }
                } catch (Exception $e) {
                    // Table might not exist yet in fresh install
                }
            }
        }

        // ── Step 2: Change column type to text first (intermediate step) ──
        if ($driver === 'sqlite') {
            // SQLite doesn't support MODIFY; the column is already flexible
        } else {
            // For MySQL, we need to use raw SQL after ensuring no indexes exist
            try {
                DB::statement('ALTER TABLE workflows MODIFY assignee TEXT NULL');
            } catch (Exception $e) {
                // Column might already be TEXT
            }
            try {
                DB::statement('ALTER TABLE tasks MODIFY assignee TEXT NULL');
            } catch (Exception $e) {
                // Column might already be TEXT
            }
            try {
                DB::statement('ALTER TABLE contents MODIFY assignee TEXT NULL');
            } catch (Exception $e) {
                // Column might already be TEXT
            }
        }

        // ── Step 3: Migrate data: single int → JSON array ──
        foreach (['workflows', 'tasks', 'contents'] as $table) {
            $rows = DB::table($table)->select('id', 'assignee')->whereNotNull('assignee')->get();
            foreach ($rows as $row) {
                // Only convert if it's a plain integer or numeric string
                if (is_numeric($row->assignee) && ! str_contains($row->assignee, '[')) {
                    DB::table($table)->where('id', $row->id)->update([
                        'assignee' => json_encode([(int) $row->assignee]),
                    ]);
                }
            }
            // Set empty array for NULL values
            DB::table($table)->whereNull('assignee')->update([
                'assignee' => json_encode([]),
            ]);
        }

        // ── Step 4: Change column type to json ──
        if ($driver === 'sqlite') {
            // SQLite stores JSON as TEXT; no column type change needed
        } else {
            try {
                DB::statement('ALTER TABLE workflows MODIFY assignee JSON NULL');
            } catch (Exception $e) {
                // Column might already be JSON
            }
            try {
                DB::statement('ALTER TABLE tasks MODIFY assignee JSON NULL');
            } catch (Exception $e) {
                // Column might already be JSON
            }
            try {
                DB::statement('ALTER TABLE contents MODIFY assignee JSON NULL');
            } catch (Exception $e) {
                // Column might already be JSON
            }
        }
    }

    public function down(): void
    {
        // ── Migrate data back: JSON array → first element ──
        foreach (['workflows', 'tasks', 'contents'] as $table) {
            $rows = DB::table($table)->select('id', 'assignee')->get();
            foreach ($rows as $row) {
                $decoded = json_decode($row->assignee, true);
                $first = is_array($decoded) && ! empty($decoded) ? $decoded[0] : null;
                DB::table($table)->where('id', $row->id)->update([
                    'assignee' => $first,
                ]);
            }
        }

        // ── Change column type back to bigint (requires doctrine/dbal) ──
        try {
            Schema::table('workflows', function (Blueprint $table) {
                $table->unsignedBigInteger('assignee')->nullable()->change();
            });
            Schema::table('tasks', function (Blueprint $table) {
                $table->unsignedBigInteger('assignee')->nullable()->change();
            });
            Schema::table('contents', function (Blueprint $table) {
                $table->unsignedBigInteger('assignee')->nullable()->change();
            });
        } catch (Throwable $e) {
            // doctrine/dbal not installed; column stays as TEXT on SQLite
        }

        // ── Re-add foreign keys ──
        Schema::table('workflows', function (Blueprint $table) {
            $table->foreign('assignee')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreign('assignee')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('contents', function (Blueprint $table) {
            $table->foreign('assignee')->references('id')->on('users')->nullOnDelete();
        });

        // ── Re-add composite indexes ──
        Schema::table('workflows', fn (Blueprint $t) => $t->index(['assignee', 'stage'], 'workflows_assignee_stage_idx'));
        Schema::table('tasks', fn (Blueprint $t) => $t->index(['assignee', 'status'], 'tasks_assignee_status_idx'));
    }
};
