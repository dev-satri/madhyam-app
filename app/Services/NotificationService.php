<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Notification;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    protected const CAP = 50;

    public function sendNotification(string $text, string $type = 'info', ?string $link = null, string $forRole = 'all'): Notification
    {
        $note = Notification::create([
            'text' => $text,
            'type' => $type,
            'link' => $link,
            'for_role' => $forRole,
            'read' => false,
        ]);
        $this->trim();

        return $note;
    }

    public function markRead(int $id): void
    {
        Notification::whereKey($id)->update(['read' => true]);
    }

    public function markAllRead(?string $role = null): void
    {
        $q = Notification::query()->where('read', false);
        if ($role) {
            $q->whereIn('for_role', [$role, 'all']);
        }
        $q->update(['read' => true]);
    }

    public function emailNotify(string $to, string $subject, string $body): void
    {
        Log::info('emailNotify (stub)', compact('to', 'subject', 'body'));
    }

    public function notifyTaskAssignment(Task $task): void
    {
        if (! $task->assigneeUser) {
            return;
        }
        $this->sendNotification(
            text: "New task assigned: {$task->title}",
            type: 'info',
            link: route('tasks', absolute: false),
            forRole: $task->assigneeUser->role,
        );
    }

    // ── Contract Expiry Notifications ──────────────────────────

    public function sendContractExpiryNotification(Client $client, int $daysUntil, string $type = 'warning'): Notification
    {
        $package = $client->linkedPackage?->name ?? ucfirst($client->package);

        $note = $this->sendNotification(
            text: "Contract for {$client->name} ({$package}) expires in {$daysUntil} day(s) on {$client->contract_end->format('M d, Y')}. Monthly: NPR " . number_format($client->amount, 2),
            type: $type,
            link: route('clients', absolute: false),
            forRole: 'manager',
        );

        // Also email the client contact if available
        if ($client->email) {
            $contactName = $client->contact ?? $client->name;
            $expiryDate = $client->contract_end->format('M d, Y');
            $monthlyAmount = number_format($client->amount, 2);
            $this->emailNotify(
                to: $client->email,
                subject: "Contract Expiry Reminder — {$package} Package",
                body: "Dear {$contactName},\n\nYour {$package} package contract with Madhyam expires on {$expiryDate}.\n\nMonthly amount: NPR {$monthlyAmount}\n\nPlease contact us to renew your contract.\n\nBest regards,\nMadhyam Team",
            );
        }

        return $note;
    }

    public function sendContractExpiredNotification(Client $client, int $daysPast): Notification
    {
        $package = $client->linkedPackage?->name ?? ucfirst($client->package);

        $note = $this->sendNotification(
            text: "EXPIRED: {$client->name}'s contract ({$package}) expired {$daysPast} day(s) ago. Immediate follow-up required.",
            type: 'error',
            link: route('clients', absolute: false),
            forRole: 'manager',
        );

        // Email escalation to manager
        $managerEmail = User::where('role', 'manager')->value('email');
        if ($managerEmail) {
            $expiryDate = $client->contract_end->format('M d, Y');
            $monthlyAmount = number_format($client->amount, 2);
            $this->emailNotify(
                to: $managerEmail,
                subject: "URGENT: Contract Expired — {$client->name}",
                body: "The contract for {$client->name} ({$package}) expired {$daysPast} day(s) ago.\n\nClient: {$client->name}\nPackage: {$package}\nMonthly: NPR {$monthlyAmount}\nContract ended: {$expiryDate}\n\nImmediate action required.",
            );
        }

        return $note;
    }

    protected function trim(): void
    {
        $count = Notification::count();
        if ($count > self::CAP) {
            $excess = $count - self::CAP;
            $ids = Notification::orderBy('id')->limit($excess)->pluck('id');
            Notification::whereIn('id', $ids)->delete();
        }
    }
}
