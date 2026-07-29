<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $workflows = DB::table('workflows')
            ->whereNotNull('attachments')
            ->get();

        foreach ($workflows as $wf) {
            $raw = $wf->attachments;
            $decoded = json_decode($raw, true);

            if (is_string($decoded)) {
                DB::table('workflows')
                    ->where('id', $wf->id)
                    ->update(['attachments' => $decoded]);
            }
        }
    }

    public function down(): void {}
};
