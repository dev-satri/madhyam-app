<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['workflows', 'tasks', 'contents', 'approvals', 'comments'];

        foreach ($tables as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table) || ! DB::getSchemaBuilder()->hasColumn($table, 'attachments')) {
                continue;
            }

            $rows = DB::table($table)->whereNotNull('attachments')->get();

            foreach ($rows as $row) {
                $raw = $row->attachments;
                if (! is_string($raw)) {
                    continue;
                }

                $decoded = json_decode($raw, true);

                while (is_string($decoded)) {
                    $decoded = json_decode($decoded, true);
                }

                if (is_array($decoded)) {
                    $clean = array_values(array_filter($decoded, fn ($a) => is_array($a) && ! empty($a['url'])));
                    DB::table($table)->where('id', $row->id)->update([
                        'attachments' => json_encode($clean),
                    ]);
                } else {
                    DB::table($table)->where('id', $row->id)->update([
                        'attachments' => null,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        //
    }
};
