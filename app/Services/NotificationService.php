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

    public function sendNotification(string $text, string $type = 'info', ?string $link = null, string $forRole = 'all', ?int $clientId = null): Notification
    {
        $note = Notification::create([
            'text' => $text,
            'type' => $type,
            'link' => $link,
            'for_role' => $forRole,
            'client_id' => $clientId,
            'read' => false,
        ]);
        $this->trim();

        return $note;
    }

    public function markRead(int $id): void
    {
        Notification::whereKey($id)->update(['read' => true]);
    }

    public function markAllRead(?string $role = null, ?int $clientId = null): void
    {
        $q = Notification::query()->where('read', false);

        if ($role) {
            $q->where(function ($q) use ($role) {
                $q->where('for_role', $role)->orWhere('for_role', 'all');
            });
        }

        if ($clientId) {
            $q->where(function ($q) use ($clientId) {
                $q->whereNull('client_id')->orWhere('client_id', $clientId);
            });
        }

        $q->update(['read' => true]);
    }

    public function emailNotify(string $to, string $subject, string $body): void
    {
        Log::info('emailNotify (stub)', compact('to', 'subject', 'body'));
    }

    public function notifyNewMember(string $name, string $email, string $role): Notification
    {
        return $this->sendNotification(
            text: "New team member added: {$name} ({$email}) as " . ucfirst(str_replace('-', ' ', $role)),
            type: 'success',
            link: route('team', absolute: false),
            forRole: 'manager',
        );
    }

    public function notifyNewClient(string $clientName, string $accountEmail): Notification
    {
        return $this->sendNotification(
            text: "New client onboarded: {$clientName} — portal account created ({$accountEmail})",
            type: 'success',
            link: route('clients', absolute: false),
            forRole: 'manager',
        );
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
            clientId: $client->id,
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
            clientId: $client->id,
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

    // ── Content Workflow Notifications ──────────────────────────

    public function notifyContentSubmittedForApproval(string $title, int $contentId): Notification
    {
        return $this->sendNotification(
            text: "New content awaiting your approval: {$title}",
            type: 'info',
            link: route('approvals', absolute: false),
            forRole: 'manager',
        );
    }

    public function notifyContentApproved(string $title): Notification
    {
        return $this->sendNotification(
            text: "Your content '{$title}' has been approved. Workflow started.",
            type: 'success',
            link: route('workflow', absolute: false),
            forRole: 'all',
        );
    }

    public function notifyContentRevision(string $title, ?string $reason = null): Notification
    {
        $text = "Content '{$title}' needs revision";
        if ($reason) {
            $text .= ": {$reason}";
        }

        return $this->sendNotification(
            text: $text,
            type: 'warning',
            link: route('content-planner', absolute: false),
            forRole: 'all',
        );
    }

    public function notifyContentRejected(string $title, ?string $reason = null): Notification
    {
        $text = "Content '{$title}' has been rejected";
        if ($reason) {
            $text .= ": {$reason}";
        }

        return $this->sendNotification(
            text: $text,
            type: 'error',
            link: route('content-planner', absolute: false),
            forRole: 'all',
        );
    }

    public function notifyWorkflowReadyForReview(string $title): Notification
    {
        return $this->sendNotification(
            text: "'{$title}' is ready for final review",
            type: 'info',
            link: route('approvals', absolute: false),
            forRole: 'manager',
        );
    }

    public function notifyAdminApprovedFinal(string $title): Notification
    {
        return $this->sendNotification(
            text: "Admin approved '{$title}' — now awaiting client approval",
            type: 'success',
            link: route('approvals', absolute: false),
            forRole: 'all',
        );
    }

    public function notifyAdminRejectedFinal(string $title, ?string $reason = null): Notification
    {
        $text = "Admin rejected '{$title}'";
        if ($reason) {
            $text .= ": {$reason}";
        }
        $text .= '. Sent back for revision.';

        return $this->sendNotification(
            text: $text,
            type: 'error',
            link: route('workflow', absolute: false),
            forRole: 'all',
        );
    }

    public function notifyClientApprovedFinal(string $title): Notification
    {
        return $this->sendNotification(
            text: "Client approved '{$title}' for publishing",
            type: 'success',
            link: route('workflow', absolute: false),
            forRole: 'manager',
        );
    }

    public function notifyClientRejectedFinal(string $title, ?string $reason = null): Notification
    {
        $text = "Client rejected '{$title}'";
        if ($reason) {
            $text .= ": {$reason}";
        }
        $text .= '. Sent back for revision.';

        return $this->sendNotification(
            text: $text,
            type: 'error',
            link: route('workflow', absolute: false),
            forRole: 'manager',
        );
    }

    public function notifyContentPublished(string $title): Notification
    {
        return $this->sendNotification(
            text: "'{$title}' has been published!",
            type: 'success',
            link: route('content-planner', absolute: false),
            forRole: 'all',
        );
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
