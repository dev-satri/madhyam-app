<?php

namespace App\Services;

use App\Models\GoogleDriveConnection;
use App\Models\Setting;
use App\Support\Attachment;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleDriveService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const DRIVE_API_BASE = 'https://www.googleapis.com/drive/v3';

    private const DRIVE_UPLOAD_BASE = 'https://www.googleapis.com/upload/drive/v3';

    private function getClientId(): ?string
    {
        $setting = Setting::current();

        return $setting->google_client_id ?: config('services.google.client_id');
    }

    private function getClientSecret(): ?string
    {
        $setting = Setting::current();

        return $setting->google_client_secret ?: config('services.google.client_secret');
    }

    public function getAuthUrl(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $this->getClientId(),
            'redirect_uri' => config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => implode(' ', config('services.google.scopes')),
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);
    }

    private function httpPost(string $url, array $data): Response
    {
        $http = Http::retry(2, 1000);

        if (config('app.env') !== 'production') {
            $http = $http->withoutVerifying();
        }

        return $http->post($url, $data);
    }

    private function httpGet(string $url, array $headers = []): Response
    {
        $http = Http::retry(2, 1000);

        if (config('app.env') !== 'production') {
            $http = $http->withoutVerifying();
        }

        if ($headers) {
            $http = $http->withHeaders($headers);
        }

        return $http->get($url);
    }

    public function exchangeCode(string $code): array
    {
        $clientId = $this->getClientId();
        $clientSecret = $this->getClientSecret();
        $redirectUri = config('services.google.redirect');

        Log::info('Google OAuth token exchange attempt', [
            'client_id' => $clientId,
            'client_secret_len' => strlen($clientSecret),
            'redirect_uri' => $redirectUri,
        ]);

        $http = Http::withoutVerifying()->asForm();

        $response = $http->post(self::TOKEN_URL, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        Log::info('Google OAuth token exchange response', [
            'status' => $response->status(),
            'ok' => $response->successful(),
        ]);

        if ($response->failed()) {
            Log::error('Google token exchange failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to exchange authorization code: ' . $response->body());
        }

        return $response->json();
    }

    public function refreshAccessToken(GoogleDriveConnection $connection): array
    {
        $response = Http::post(self::TOKEN_URL, [
            'client_id' => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
            'refresh_token' => $connection->refresh_token_encrypted,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('Failed to refresh access token: ' . $response->body());
        }

        $data = $response->json();

        $connection->update([
            'access_token_encrypted' => $data['access_token'],
            'access_token_expires_at' => now()->addSeconds($data['expires_in']),
        ]);

        return $data;
    }

    public function getAccessToken(): ?string
    {
        $connection = GoogleDriveConnection::getActive();

        if (! $connection) {
            return null;
        }

        if ($connection->access_token_expires_at->subMinutes(5)->isPast()) {
            try {
                $this->refreshAccessToken($connection);
                $connection->refresh();
            } catch (\Exception $e) {
                Log::error('Failed to refresh Google Drive token', [
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        return $connection->access_token_encrypted;
    }

    private function driveRequest(string $method, string $url, array $data = []): Response
    {
        $http = Http::retry(2, 1000);

        if (config('app.env') !== 'production') {
            $http = $http->withoutVerifying();
        }

        $http = $http->withToken($this->getAccessToken());

        return match ($method) {
            'get' => $http->get($url, $data),
            'post' => $http->post($url, $data),
            'patch' => $http->patch($url, $data),
            'delete' => $http->delete($url),
            default => $http->get($url, $data),
        };
    }

    public function getStorageQuota(): ?array
    {
        try {
            $response = $this->driveRequest('get', 'https://www.googleapis.com/drive/v3/about', [
                'fields' => 'storageQuota',
            ]);

            if ($response->failed()) {
                return null;
            }

            $quota = $response->json('storageQuota', []);

            $used = $quota['usage'] ?? 0;
            $limit = $quota['limit'] ?? 0;
            $usedInDrive = $quota['usageInDrive'] ?? 0;
            $usedInTrash = $quota['usageInDriveTrash'] ?? 0;

            return [
                'used' => $used,
                'limit' => $limit,
                'used_in_drive' => $usedInDrive,
                'used_in_trash' => $usedInTrash,
                'used_mb' => round($used / 1048576, 2),
                'used_gb' => round($used / 1073741824, 2),
                'limit_mb' => $limit > 0 ? round($limit / 1048576, 0) : 0,
                'limit_gb' => $limit > 0 ? round($limit / 1073741824, 2) : 0,
                'free' => $limit > 0 ? $limit - $used : 0,
                'free_gb' => $limit > 0 ? round(($limit - $used) / 1073741824, 2) : 0,
                'percent' => $limit > 0 ? min(100, (int) round(($used / $limit) * 100)) : 0,
                'is_near' => $limit > 0 && ($used / $limit) >= 0.8,
                'is_over' => $limit > 0 && $used > $limit,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get Google Drive storage quota', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function getUserInfo(string $accessToken): array
    {
        $response = Http::withoutVerifying()
            ->withToken($accessToken)
            ->get('https://www.googleapis.com/oauth2/v2/userinfo');

        if ($response->failed()) {
            throw new \RuntimeException('Failed to get user info: ' . $response->body());
        }

        return $response->json();
    }

    public function listFiles(?string $folderId = null, int $pageSize = 100, ?string $pageToken = null): array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            throw new \RuntimeException('Google Drive not connected');
        }

        $params = [
            'pageSize' => $pageSize,
            'fields' => 'nextPageToken, files(id, name, mimeType, size, thumbnailLink, webViewLink, modifiedTime, iconLink)',
            'orderBy' => 'folder, name',
        ];

        $params['q'] = $folderId
            ? "'{$folderId}' in parents and trashed = false"
            : "'root' in parents and trashed = false";

        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }

        $response = $this->driveRequest('get', self::DRIVE_API_BASE . '/files', $params);

        if ($response->failed()) {
            throw new \RuntimeException('Failed to list files: ' . $response->body());
        }

        return $response->json();
    }

    public function createFolder(string $name, ?string $parentId = null): array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            throw new \RuntimeException('Google Drive not connected');
        }

        $metadata = [
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        ];

        if ($parentId) {
            $metadata['parents'] = [$parentId];
        }

        $response = $this->driveRequest('post', self::DRIVE_API_BASE . '/files', $metadata);

        if ($response->failed()) {
            throw new \RuntimeException('Failed to create folder: ' . $response->body());
        }

        return $response->json();
    }

    public function uploadFile(string $name, $content, ?string $folderId = null, string $mimeType = 'application/octet-stream'): array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            throw new \RuntimeException('Google Drive not connected');
        }

        $metadata = ['name' => $name];

        if ($parentId = $folderId) {
            $metadata['parents'] = [$parentId];
        }

        $http = Http::retry(2, 1000);
        if (config('app.env') !== 'production') {
            $http = $http->withoutVerifying();
        }

        // Handle both file handles (resource) and string content
        if (is_resource($content)) {
            // For large files, read the stream content
            $fileContent = stream_get_contents($content);
        } else {
            $fileContent = $content;
        }

        $response = $http->withToken($accessToken)
            ->attach('metadata', json_encode($metadata), null, ['Content-Type' => 'application/json'])
            ->attach('file', $fileContent, $name, ['Content-Type' => $mimeType])
            ->post(self::DRIVE_UPLOAD_BASE . '/files?uploadType=multipart');

        if ($response->failed()) {
            throw new \RuntimeException('Failed to upload file: ' . $response->body());
        }

        return $response->json();
    }

    public function deleteFile(string $fileId): bool
    {
        $response = $this->driveRequest('delete', self::DRIVE_API_BASE . '/files/' . $fileId);

        return $response->successful();
    }

    public function renameFile(string $fileId, string $newName): bool
    {
        $response = $this->driveRequest('patch', self::DRIVE_API_BASE . '/files/' . $fileId, [
            'name' => $newName,
        ]);

        return $response->successful();
    }

    public function moveFile(string $fileId, string $newParentId, string $oldParentId): bool
    {
        $response = $this->driveRequest('patch', self::DRIVE_API_BASE . '/files/' . $fileId, [
            'addParents' => $newParentId,
            'removeParents' => $oldParentId,
        ]);

        return $response->successful();
    }

    public function getFile(string $fileId): array
    {
        $response = $this->driveRequest('get', self::DRIVE_API_BASE . '/files/' . $fileId, [
            'fields' => 'id, name, mimeType, size, thumbnailLink, webViewLink, modifiedTime, iconLink, parents',
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('Failed to get file: ' . $response->body());
        }

        return $response->json();
    }

    public function getDownloadUrl(string $fileId): string
    {
        return self::DRIVE_API_BASE . '/files/' . $fileId . '?alt=media&access_token=' . $this->getAccessToken();
    }

    public function searchFiles(string $query): array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            throw new \RuntimeException('Google Drive not connected');
        }

        $escapedQuery = addslashes($query);

        $response = $this->driveRequest('get', self::DRIVE_API_BASE . '/files', [
            'q' => "name contains '{$escapedQuery}' and trashed = false",
            'fields' => 'nextPageToken, files(id, name, mimeType, size, thumbnailLink, webViewLink, modifiedTime, iconLink)',
            'pageSize' => 50,
            'orderBy' => 'name',
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('Failed to search files: ' . $response->body());
        }

        return $response->json();
    }

    public static function attachmentFromDrive(array $driveFile): array
    {
        return Attachment::fromDrive($driveFile);
    }
}
