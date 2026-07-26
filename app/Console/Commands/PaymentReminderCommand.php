<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\NotificationRule;
use App\Notifications\InvoiceDueReminderNotification;
use App\Notifications\InvoiceOverdueNotification;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class PaymentReminderCommand extends Command
{
    protected $signature = 'notifications:payment-reminders';

    protected $description = 'Send payment due and overdue reminders to clients';

    public function handle(): int
    {
        $rule = NotificationRule::where('trigger', 'payment-reminder')->where('active', true)->first();
        if (! $rule) {
            $this->info('No active payment-reminder rule found.');

            return self::SUCCESS;
        }

        $count = 0;
        $count += $this->sendDueReminders();
        $count += $this->sendOverdueReminders();

        $this->info("Sent {$count} payment reminder(s).");

        return self::SUCCESS;
    }

    protected function sendDueReminders(): int
    {
        $count = 0;

        // Check invoices due at exactly 7, 3, or 1 day(s) from now
        $checkDays = [7, 3, 1];

        foreach ($checkDays as $days) {
            $targetDate = now()->addDays($days)->toDateString();

            $invoices = Invoice::where('status', '!=', 'paid')
                ->whereDate('due_date', $targetDate)
                ->with('client')
                ->get();

            foreach ($invoices as $invoice) {
                $client = $invoice->client;
                if (! $client || ! $client->email) {
                    continue;
                }

                // Send mail to client
                $client->accounts()->each(function ($account) use ($invoice, $days) {
                    $account->notify(new InvoiceDueReminderNotification($invoice, $days));
                });

                // In-app notification for the client
                $contactName = $client->contact ?? $client->name;
                $netAmount = number_format($invoice->net_amount, 2);
                $dayWord = $days === 1 ? 'day' : 'days';

                app(NotificationService::class)->sendNotification(
                    text: "Payment of NPR {$netAmount} due in {$days} {$dayWord} for invoice #{$invoice->id}",
                    type: $days <= 1 ? 'error' : 'warning',
                    link: route('client.billing', absolute: false),
                    forRole: 'client',
                    clientId: $client->id,
                );

                $count++;
            }
        }

        return $count;
    }

    protected function sendOverdueReminders(): int
    {
        $count = 0;

        $overdueInvoices = Invoice::where('status', '!=', 'paid')
            ->whereDate('due_date', '<', now())
            ->with('client')
            ->get();

        foreach ($overdueInvoices as $invoice) {
            $client = $invoice->client;
            if (! $client || ! $client->email) {
                continue;
            }

            $daysOverdue = (int) now()->diffInDays($invoice->due_date);

            // Send mail to client
            $client->accounts()->each(function ($account) use ($invoice, $daysOverdue) {
                $account->notify(new InvoiceOverdueNotification($invoice, $daysOverdue));
            });

            // In-app notification for the client
            $netAmount = number_format($invoice->net_amount, 2);
            $dayWord = $daysOverdue === 1 ? 'day' : 'days';

            app(NotificationService::class)->sendNotification(
                text: "OVERDUE: Payment of NPR {$netAmount} was {$daysOverdue} {$dayWord} late (invoice #{$invoice->id})",
                type: 'error',
                link: route('client.billing', absolute: false),
                forRole: 'client',
                clientId: $client->id,
            );

            $count++;
        }

        return $count;
    }
}
