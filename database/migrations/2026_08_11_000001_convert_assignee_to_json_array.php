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

        // ── Drop ALL indexes on assignee column for each table ──
        foreach (['workflows', 'tasks', 'contents'] as $tableName) {
            try {
                $indexes = Schema::getConnection()->getDoctrineSchemaManager()->listTableIndexes($tableName);
                foreach ($indexes as $index) {
                    $columns = $index->getColumns();
                    $name = $index->getName();
                    if (in_array('assignee', $columns) && $name !== 'PRIMARY') {
                        try {
                            DB::statement("ALTER TABLE {$tableName} DROP INDEX {$name}");
                        } catch (Exception $e) {
                            // Index might already be dropped, continue
                        }
                    }
                }
            } catch (Exception $e) {
                // Might fail on fresh database, continue
            }
        }

        // ── Drop foreign keys if they exist ──
        foreach (['workflows', 'tasks', 'contents'] as $tableName) {
            try {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropForeign(['assignee']);
                });
            } catch (Exception $e) {
                // FK might not exist
            }
        }

        // ── Change column type to text first (intermediate step) ──
        if ($driver === 'sqlite') {
            // SQLite doesn't support MODIFY; the column is already flexible
        } else {
            // Use Schema builder instead of raw SQL for better compatibility
            Schema::table('workflows', function (Blueprint $table) {
                $table->text('assignee')->nullable()->change();
            });
            Schema::table('tasks', function (Blueprint $table) {
                $table->text('assignee')->nullable()->change();
            });
            Schema::table('contents', function (Blueprint $table) {
                $table->text('assignee')->nullable()->change();
            });
        }

        // ── Migrate data: single int → JSON array ──
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

        // ── Change column type to json ──
        if ($driver === 'sqlite') {
            // SQLite stores JSON as TEXT; no column type change needed
        } else {
            Schema::table('workflows', function (Blueprint $table) {
                $table->json('assignee')->nullable()->change();
            });
            Schema::table('tasks', function (Blueprint $table) {
                $table->json('assignee')->nullable()->change();
            });
            Schema::table('contents', function (Blueprint $table) {
                $table->json('assignee')->nullable()->change();
            });
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
