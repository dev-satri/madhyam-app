<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use App\Services\DataBackupService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class DataBackupController extends Controller
{
    public function __construct(
        protected DataBackupService $backup,
        protected ActivityLogger $activity,
    ) {}

    /**
     * Download a full JSON backup of all domain tables.
     * Restricted to super-admin and admin per plan §5.
     */
    public function export(Request $request): Response
    {
        $this->authorizeAdmin();

        $data = $this->backup->export();

        $this->activity->record(Auth::user(), 'Exported data backup');

        $filename = 'madhyam-backup-'.now()->format('Y-m-d').'.json';

        return response(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            200,
            [
                'Content-Type' => 'application/json',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
            ]
        );
    }

    protected function authorizeAdmin(): void
    {
        $user = Auth::user();
        abort_unless($user && in_array($user->role, ['super-admin', 'admin'], true), 403);
    }
}
