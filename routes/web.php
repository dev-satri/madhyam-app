<?php

use Anuzpandey\LaravelNepaliDate\LaravelNepaliDate;
use App\Http\Controllers\DataBackupController;
use App\Http\Controllers\GoogleDriveController;
use App\Livewire\Actions\Logout;
use App\Models\Invoice;
use App\Services\InvoicePdfService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

/*
|--------------------------------------------------------------------------
| Root
|--------------------------------------------------------------------------
| Redirect based on active guard. Spec §22 has no public marketing page.
*/
Route::get('/', function () {
    if (Auth::guard('web')->check()) {
        return redirect()->route('dashboard');
    }
    if (Auth::guard('client')->check()) {
        return redirect()->route('client.dashboard');
    }

    return redirect()->route('login');
})->name('welcome');

/*
|--------------------------------------------------------------------------
| Guest Auth Routes
|--------------------------------------------------------------------------
| Combined staff/client login (single Volt view with tab toggle).
| Self-serve password reset is now included (token flow); self-serve
| registration is still deferred per spec.
*/
Route::middleware('guest:web,client')->group(function () {
    Volt::route('login', 'pages.auth.login')->name('login');
    Volt::route('client/login', 'pages.auth.login')->name('client.login');

    // Password reset — a `mode` query param (staff|client) picks the broker.
    // Two token tables (password_reset_tokens, client_password_reset_tokens)
    // keep the two guards isolated even when an email exists in both.
    Volt::route('forgot-password', 'pages.auth.forgot-password')->name('password.request');
    Volt::route('reset-password/{token}', 'pages.auth.reset-password')->name('password.reset');
});

/*
|--------------------------------------------------------------------------
| Staff Routes (auth:web)
|--------------------------------------------------------------------------
| Feature-gated only. Per plan §17, the 7 data-access permissions
| (seeAllTasks, seeAllWorkflow, seeAllPerformance, seeAllActivity,
|  canAddTasks, canMoveWorkflow, canEditWorkflow) are RUNTIME checks
| applied inside pages via RbacService::hasDataAccess — NEVER as route
| middleware. Page-level access is `feature:{key}` only.
*/
Route::middleware('auth:web')->group(function () {
    Volt::route('dashboard', 'pages.dashboard.index')->name('dashboard');

    Route::middleware('feature:clients')->group(function () {
        Volt::route('clients', 'pages.clients.index')->name('clients');
    });

    Route::middleware('feature:packages')->group(function () {
        Volt::route('packages', 'pages.packages.index')->name('packages');
    });

    Route::middleware('feature:contentPlanner')->group(function () {
        Volt::route('content-planner', 'pages.calendar.index')->name('content-planner');
    });

    Route::middleware('feature:workflow')->group(function () {
        Volt::route('workflow', 'pages.workflow.index')->name('workflow');
    });

    Route::middleware('feature:tasks')->group(function () {
        Volt::route('tasks', 'pages.tasks.index')->name('tasks');
    });

    Route::middleware('feature:approvals')->group(function () {
        Volt::route('approvals', 'pages.approvals.index')->name('approvals');
    });

    Route::middleware('feature:files')->group(function () {
        Volt::route('files', 'pages.files.index')->name('files');
        Route::get('files/{id}/download', function (int $id) {
            $file = DB::table('files')->where('id', $id)->first();
            abort_unless($file, 404);

            return Storage::download($file->path, $file->name);
        })->name('files.download');

        // XHR file upload endpoint — provides real-time progress via XMLHttpRequest
        Route::post('files/xhr-upload', function (Request $request) {
            $request->validate([
                'file' => 'required|file|max:204800', // 200MB
                'folder_id' => 'nullable|integer',
                'client_id' => 'nullable|integer',
                'tags' => 'nullable|string|max:500',
            ]);

            $file = $request->file('file');
            $retentionDays = DB::table('settings')->value('file_retention_days') ?? 5;

            $path = $file->store('files/' . now()->format('Y/m'), 'public');
            $ext = strtolower($file->getClientOriginalExtension());
            $type = match(true) {
                in_array($ext, ['jpg','jpeg','png','gif','svg','webp']) => 'image',
                in_array($ext, ['mp4','mov','avi','mkv','webm']) => 'video',
                in_array($ext, ['mp3','wav','ogg','flac']) => 'audio',
                default => 'document',
            };

            $folderId = $request->input('folder_id') ?: null;
            $clientId = $request->input('client_id') ?: null;
            $tags = $request->input('tags') ?: null;

            $fileId = DB::table('files')->insertGetId([
                'name' => $file->getClientOriginalName(),
                'path' => $path,
                'type' => $type,
                'size' => $file->getSize(),
                'storage_type' => 'local',
                'folder_id' => $folderId,
                'client_id' => $clientId,
                'tags' => $tags,
                'uploaded_by' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('file_expiries')->insert([
                'file_id' => $fileId,
                'expiry_date' => now()->addDays($retentionDays),
                'extended' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($clientId) {
                \App\Services\PackageService::recordFile($clientId, $file->getSize());
            }

            return response()->json([
                'success' => true,
                'file_id' => $fileId,
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'type' => $type,
            ]);
        })->name('files.xhr-upload');
    });

    Route::middleware('feature:reports')->group(function () {
        Volt::route('reports', 'pages.reports.index')->name('reports');
    });

    Route::middleware('feature:leaves')->group(function () {
        Volt::route('leaves', 'pages.leaves.index')->name('leaves');
    });

    Route::middleware('feature:expenses')->group(function () {
        Volt::route('expenses', 'pages.expenses.index')->name('expenses');
    });

    Route::middleware('feature:salary')->group(function () {
        Volt::route('salary', 'pages.salary.index')->name('salary');
        Route::get('salary/{id}/slip', function (int $id) {
            $salary = DB::table('salaries')->join('users', 'salaries.member_id', '=', 'users.id')
                ->where('salaries.id', $id)
                ->select('salaries.*', 'users.name as member_name', 'users.role as member_role', 'users.phone')
                ->first();
            abort_unless($salary, 404);
            $agency = DB::table('settings')->where('id', 1)->first();

            return response()->view('livewire.pages.salary.slip', compact('salary', 'agency'), 200);
        })->name('salary.slip');
    });

    Route::middleware('feature:overtime')->group(function () {
        Volt::route('overtime', 'pages.overtime.index')->name('overtime');
        Route::get('overtime/export', function () {
            $userId = Auth::id();
            $isMgr = in_array(Auth::user()->role, ['super-admin', 'admin', 'manager']);
            $q = DB::table('overtime_logs')->join('users', 'overtime_logs.member_id', '=', 'users.id')->whereNull('overtime_logs.deleted_at');
            if (! $isMgr) {
                $q->where('overtime_logs.member_id', $userId);
            }
            $logs = $q->select('overtime_logs.*', 'users.name as member_name')->orderBy('overtime_logs.date', 'desc')->get();

            $headers = ['Staff', 'Date', 'Hours', 'Rate', 'Amount', 'Description', 'Approved'];
            $rows = $logs->map(fn ($l) => [$l->member_name, $l->date, $l->hours, $l->rate, round((float) $l->hours * (float) $l->rate, 2), $l->description ?? '', $l->approved ? 'Yes' : 'No']);
            $totalHours = $logs->sum('hours');
            $totalAmount = $logs->sum(fn ($l) => (float) $l->hours * (float) $l->rate);
            $rows->push(['TOTAL', '', $totalHours, '', round($totalAmount, 2), '', '']);

            $csv = implode("\n", array_map(fn ($r) => '"' . implode('","', $r) . '"', array_merge([$headers], $rows->toArray())));

            return response($csv, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="overtime-logs-' . now()->format('Y-m') . '.csv"']);
        })->name('overtime.export');
    });

    Route::middleware('feature:team')->group(function () {
        Volt::route('team', 'pages.team.index')->name('team');
    });

    Route::middleware('feature:settings')->group(function () {
        Volt::route('settings', 'pages.settings.index')->name('settings');
        Route::get('settings/export', [DataBackupController::class, 'export'])->name('settings.export');
    });

    // Google Drive OAuth Routes (admin only)
    Route::middleware(['feature:settings'])->prefix('settings/google')->group(function () {
        Route::get('/redirect', [GoogleDriveController::class, 'redirect'])
            ->name('google.drive.redirect');
        Route::get('/callback', [GoogleDriveController::class, 'callback'])
            ->name('google.drive.callback');
        Route::post('/disconnect', [GoogleDriveController::class, 'disconnect'])
            ->name('google.drive.disconnect');
        Route::get('/status', [GoogleDriveController::class, 'status'])
            ->name('google.drive.status');
    });

    Route::middleware('feature:userGuide')->group(function () {
        Volt::route('user-guide', 'pages.userguide.index')->name('user-guide');
    });

    Route::middleware('feature:complaints')->group(function () {
        Volt::route('complaints', 'pages.complaints.index')->name('complaints');
    });

    Volt::route('trash', 'pages.trash.index')->name('trash');

    // Profile is always available to authenticated staff (no feature gate)
    Volt::route('profile', 'pages.profile.index')->name('profile');

    Route::post('logout', Logout::class)->name('logout');
});

/*
|--------------------------------------------------------------------------
| Client Portal Routes (auth:client + client whitelist)
|--------------------------------------------------------------------------
| The `client` middleware enforces the 7-item whitelist declared in
| EnsureClientPortalAccess. Each route passes its feature key so any
| accidental additions outside the whitelist are rejected.
*/
Route::middleware('auth:client')->prefix('client')->group(function () {
    Route::middleware('client:dashboard')->group(function () {
        Volt::route('dashboard', 'pages.dashboard.client-dashboard')->name('client.dashboard');
    });
    Route::middleware('client:approvals')->group(function () {
        Volt::route('approvals', 'pages.approvals.index')->name('client.approvals');
    });
    Route::middleware('client:complaints')->group(function () {
        Volt::route('complaints', 'pages.complaints.index')->name('client.complaints');
    });
    Route::middleware('client:reports')->group(function () {
        Volt::route('billing', 'pages.reports.index')->name('client.billing');
    });
    Route::middleware('client:profile')->group(function () {
        Volt::route('profile', 'pages.profile.index')->name('client.profile');
    });

    Route::post('logout', Logout::class)->name('client.logout');
});

/*
|--------------------------------------------------------------------------
| Invoice PDF Routes (accessible by both staff & client guards)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:web,client')->group(function () {
    Route::get('invoices/{id}/pdf', function (int $id) {
        $invoice = Invoice::with('client', 'payments')->find($id);
        abort_unless($invoice, 404);

        // Client can only view their own invoices
        if (Auth::guard('client')->check()) {
            $account = Auth::guard('client')->user();
            abort_unless($invoice->client_id === $account->client_id, 403);
        }

        $pdfService = app(InvoicePdfService::class);
        $pdf = $pdfService->generatePdf($invoice);

        return $pdf->download($pdfService->getFileName($invoice));
    })->name('invoices.pdf');

    Route::get('invoices/{id}/pdf/view', function (int $id) {
        $invoice = Invoice::with('client', 'payments')->find($id);
        abort_unless($invoice, 404);

        if (Auth::guard('client')->check()) {
            $account = Auth::guard('client')->user();
            abort_unless($invoice->client_id === $account->client_id, 403);
        }

        $pdfService = app(InvoicePdfService::class);
        $pdf = $pdfService->generatePdf($invoice);

        return $pdf->inline($pdfService->getFileName($invoice));
    })->name('invoices.pdf.view');
});

/*
|--------------------------------------------------------------------------
| Date Conversion API (for BS date picker)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:web,client')->prefix('api')->group(function () {
    Route::get('convert/ad-to-bs', function (Request $request) {
        $date = $request->input('date');
        if (! $date) {
            return response()->json(['error' => 'Date parameter required'], 400);
        }

        try {
            $dto = LaravelNepaliDate::from($date)->toNepaliDateArray();

            return response()->json([
                'bs_date' => $dto->year . '-' . $dto->month . '-' . $dto->day,
                'year' => (int) $dto->year,
                'month' => (int) $dto->month,
                'day' => (int) $dto->day,
                'month_name' => $dto->monthName,
                'day_name' => $dto->dayName,
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => 'Invalid date'], 400);
        }
    });

    Route::get('convert/bs-to-ad', function (Request $request) {
        $date = $request->input('date');
        if (! $date) {
            return response()->json(['error' => 'Date parameter required'], 400);
        }

        try {
            $adDate = LaravelNepaliDate::from($date, 'Y-m-d', 'np')->toEnglishDate('Y-m-d');

            return response()->json(['ad_date' => $adDate]);
        } catch (Exception $e) {
            return response()->json(['error' => 'Invalid BS date'], 400);
        }
    });

    Route::get('bs-calendar', function (Request $request) {
        $year = (int) $request->input('year');
        $month = (int) $request->input('month');

        if ($year < 2000 || $year > 2099 || $month < 1 || $month > 12) {
            return response()->json(['error' => 'Invalid year or month'], 400);
        }

        $totalDays = LaravelNepaliDate::daysInMonth($month, $year);

        $firstDayBs = sprintf('%04d-%02d-01', $year, $month);
        $firstDayAd = LaravelNepaliDate::from($firstDayBs, 'Y-m-d', 'np')->toEnglishDate('Y-m-d');
        $startOfWeek = Carbon::parse($firstDayAd)->dayOfWeek;

        $days = [];
        for ($i = 0; $i < $startOfWeek; $i++) {
            $days[] = ['day' => 0, 'bs_date' => '', 'ad_date' => '', 'other_month' => true];
        }

        for ($day = 1; $day <= $totalDays; $day++) {
            $bsDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $adDate = LaravelNepaliDate::from($bsDate, 'Y-m-d', 'np')->toEnglishDate('Y-m-d');
            $days[] = [
                'day' => $day,
                'bs_date' => $bsDate,
                'ad_date' => $adDate,
                'other_month' => false,
            ];
        }

        $remaining = (7 - (count($days) % 7)) % 7;
        for ($i = 0; $i < $remaining; $i++) {
            $days[] = ['day' => 0, 'bs_date' => '', 'ad_date' => '', 'other_month' => true];
        }

        return response()->json([
            'year' => $year,
            'month' => $month,
            'total_days' => $totalDays,
            'days' => $days,
        ]);
    });
});
