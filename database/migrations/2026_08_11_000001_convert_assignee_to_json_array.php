<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Helper to check if foreign key exists ──
        $hasForeignKey = function ($table, $column) {
            $keys = DB::select('
                SELECT CONSTRAINT_NAME 
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = ? 
                AND COLUMN_NAME = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ', [$table, $column]);

            return ! empty($keys);
        };

        // ── Helper to get all indexes on a column ──
        $getIndexes = function ($table, $column) {
            return DB::select("SHOW INDEX FROM `{$table}` WHERE Column_name = ?", [$column]);
        };

        // ── Drop ALL indexes on assignee column for each table ──
        foreach (['workflows', 'tasks', 'contents'] as $tableName) {
            $indexes = $getIndexes($tableName, 'assignee');
            foreach ($indexes as $index) {
                if ($index->Key_name !== 'PRIMARY') {
                    try {
                        Schema::table($tableName, function (Blueprint $table) use ($index) {
                            $table->dropIndex($index->Key_name);
                        });
                    } catch (Exception $e) {
                        // Index might already be dropped, continue
                    }
                }
            }
        }

        // ── Drop foreign keys if they exist ──
        if ($hasForeignKey('workflows', 'assignee')) {
            Schema::table('workflows', function (Blueprint $table) {
                $table->dropForeign(['assignee']);
            });
        }
        if ($hasForeignKey('tasks', 'assignee')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->dropForeign(['assignee']);
            });
        }
        if ($hasForeignKey('contents', 'assignee')) {
            Schema::table('contents', function (Blueprint $table) {
                $table->dropForeign(['assignee']);
            });
        }

        // ── Change column type to text first (intermediate step) ──
        DB::statement('ALTER TABLE workflows MODIFY assignee TEXT NULL');
        DB::statement('ALTER TABLE tasks MODIFY assignee TEXT NULL');
        DB::statement('ALTER TABLE contents MODIFY assignee TEXT NULL');

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
        DB::statement('ALTER TABLE workflows MODIFY assignee JSON NULL');
        DB::statement('ALTER TABLE tasks MODIFY assignee JSON NULL');
        DB::statement('ALTER TABLE contents MODIFY assignee JSON NULL');
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

        // ── Change column type back to bigint ──
        Schema::table('workflows', function (Blueprint $table) {
            $table->unsignedBigInteger('assignee')->nullable()->change();
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('assignee')->nullable()->change();
        });
        Schema::table('contents', function (Blueprint $table) {
            $table->unsignedBigInteger('assignee')->nullable()->change();
        });

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
