<?php

namespace App\Http\Controllers;

use App\Models\GoogleDriveConnection;
use App\Services\GoogleDriveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class GoogleDriveController extends Controller
{
    public function __construct(
        protected GoogleDriveService $googleDriveService,
    ) {}

    public function redirect()
    {
        abort_unless(Auth::check() && in_array(Auth::user()->role, ['super-admin', 'admin'], true), 403);

        return redirect($this->googleDriveService->getAuthUrl());
    }

    public function callback(Request $request)
    {
        abort_unless(Auth::check() && in_array(Auth::user()->role, ['super-admin', 'admin'], true), 403);

        $request->validate([
            'code' => 'required|string',
        ]);

        try {
            $tokenData = $this->googleDriveService->exchangeCode($request->code);
            $userInfo = $this->googleDriveService->getUserInfo($tokenData['access_token']);

            GoogleDriveConnection::store([
                'google_email' => $userInfo['email'],
                'refresh_token_encrypted' => $tokenData['refresh_token'],
                'access_token_encrypted' => $tokenData['access_token'],
                'access_token_expires_at' => now()->addSeconds($tokenData['expires_in']),
                'scopes' => explode(' ', $tokenData['scope']),
                'connected_by_user_id' => Auth::id(),
            ]);

            return redirect()->route('settings')
                ->with('toast', [
                    'type' => 'success',
                    'title' => 'Google Drive Connected',
                    'message' => "Connected as {$userInfo['email']}",
                ]);
        } catch (\Exception $e) {
            Log::error('Google Drive OAuth callback failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return redirect()->route('settings')
                ->with('toast', [
                    'type' => 'error',
                    'title' => 'Connection Failed',
                    'message' => 'Failed to connect Google Drive. Please try again.',
                ]);
        }
    }

    public function disconnect(Request $request)
    {
        abort_unless(Auth::check() && in_array(Auth::user()->role, ['super-admin', 'admin'], true), 403);

        $request->validate([
            'password' => 'required|string',
        ]);

        if (! Hash::check($request->password, Auth::user()->password)) {
            return back()->with('toast', [
                'type' => 'error',
                'title' => 'Invalid Password',
                'message' => 'Please enter your password to disconnect.',
            ]);
        }

        GoogleDriveConnection::disconnect();

        return back()->with('toast', [
            'type' => 'success',
            'title' => 'Google Drive Disconnected',
            'message' => 'Google Drive has been disconnected.',
        ]);
    }

    public function status()
    {
        $connection = GoogleDriveConnection::getActive();

        return response()->json([
            'connected' => $connection && $connection->isActive(),
            'email' => $connection?->google_email,
            'connected_at' => $connection?->created_at?->toISOString(),
            'expires_at' => $connection?->access_token_expires_at?->toISOString(),
        ]);
    }
}
