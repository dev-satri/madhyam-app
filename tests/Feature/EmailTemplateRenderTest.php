<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Package;
use Illuminate\Mail\Markdown;
use Tests\TestCase;

/**
 * Renders each edited email markdown template and asserts that no raw
 * HTML tags leak into the rendered output as escaped text.
 *
 * A "leak" is any occurrence of &lt;table, &lt;tr, &lt;td, &lt;a, or
 * &lt;strong in the rendered HTML — the CommonMark renderer emits those
 * escaped entities when it treats HTML as literal text (usually because
 * the block wasn't recognized as a raw-HTML block).
 */
class EmailTemplateRenderTest extends TestCase
{
    private function renderMarkdown(string $view, array $data): string
    {
        /** @var Markdown $md */
        $md = app(Markdown::class);

        // render() returns rendered HTML wrapped in the theme
        return (string) $md->render($view, $data);
    }

    private function assertNoEscapedHtmlLeak(string $html, string $context): void
    {
        $needles = ['&lt;table', '&lt;tr', '&lt;td', '&lt;a ', '&lt;strong', '&lt;br'];
        foreach ($needles as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $html,
                "HTML leak in $context: found escaped `$needle` in rendered output"
            );
        }
    }

    public function test_contract_expired_renders_without_html_leak(): void
    {
        $html = $this->renderMarkdown('emails.contract-expired', [
            'contactName' => 'Ada Lovelace',
            'packageName' => 'Growth Plan',
            'daysPast' => 3,
            'dayWord' => 'days',
            'expiryDate' => 'Jul 23, 2026',
            'monthlyAmount' => '15,000.00',
            'url' => 'https://example.test/renew',
        ]);
        $this->assertNoEscapedHtmlLeak($html, 'contract-expired');
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('Growth Plan', $html);
    }

    public function test_contract_expiry_reminder_renders_without_html_leak(): void
    {
        $html = $this->renderMarkdown('emails.contract-expiry-reminder', [
            'contactName' => 'Ada Lovelace',
            'packageName' => 'Growth Plan',
            'daysUntil' => 7,
            'dayWord' => 'days',
            'expiryDate' => 'Aug 02, 2026',
            'monthlyAmount' => '15,000.00',
            'url' => 'https://example.test/billing',
        ]);
        $this->assertNoEscapedHtmlLeak($html, 'contract-expiry-reminder');
        $this->assertStringContainsString('<table', $html);
    }

    public function test_invoice_created_renders_without_html_leak(): void
    {
        $invoice = new Invoice([
            'discount' => 10,
            'net_amount' => 10000,
        ]);
        $invoice->id = 42;

        $html = $this->renderMarkdown('emails.invoice-created', [
            'contactName' => 'Ada Lovelace',
            'invoice' => $invoice,
            'client' => null,
            'netAmount' => '10,000.00',
            'dueDate' => 'Aug 15, 2026',
            'url' => 'https://example.test/billing',
        ]);
        $this->assertNoEscapedHtmlLeak($html, 'invoice-created');
        $this->assertStringContainsString('#42', $html);
    }

    public function test_invoice_due_reminder_renders_without_html_leak(): void
    {
        $invoice = new Invoice(['net_amount' => 5000]);
        $invoice->id = 99;

        $html = $this->renderMarkdown('emails.invoice-due-reminder', [
            'contactName' => 'Ada Lovelace',
            'invoice' => $invoice,
            'client' => null,
            'netAmount' => '5,000.00',
            'dueDate' => 'Aug 01, 2026',
            'daysUntilDue' => 3,
            'dayWord' => 'days',
            'urgency' => 'warning',
            'url' => 'https://example.test/billing',
        ]);
        $this->assertNoEscapedHtmlLeak($html, 'invoice-due-reminder');
    }

    public function test_invoice_overdue_renders_without_html_leak(): void
    {
        $invoice = new Invoice(['net_amount' => 5000]);
        $invoice->id = 77;

        $html = $this->renderMarkdown('emails.invoice-overdue', [
            'contactName' => 'Ada Lovelace',
            'invoice' => $invoice,
            'client' => null,
            'netAmount' => '5,000.00',
            'dueDate' => 'Jul 20, 2026',
            'daysOverdue' => 6,
            'dayWord' => 'days',
            'url' => 'https://example.test/billing',
        ]);
        $this->assertNoEscapedHtmlLeak($html, 'invoice-overdue');
    }

    public function test_package_usage_warning_renders_without_html_leak(): void
    {
        $deliverables = [
            ['type' => 'reel', 'used' => 7, 'limit' => 8, 'percent' => 88, 'over' => false],
            ['type' => 'post', 'used' => 2, 'limit' => 4, 'percent' => 50, 'over' => false],
        ];
        $html = $this->renderMarkdown('emails.package-usage-warning', [
            'contactName' => 'Ada Lovelace',
            'packageName' => 'Growth Plan',
            'category' => 'Reel',
            'used' => 7,
            'limit' => 8,
            'percent' => 88,
            'deliverables' => $deliverables,
            'url' => 'https://example.test/usage',
        ]);
        $this->assertNoEscapedHtmlLeak($html, 'package-usage-warning');
        $this->assertStringContainsString('Reel', $html);
        $this->assertStringContainsString('Post', $html);
        $this->assertStringContainsString('88%', $html);
    }

    public function test_package_usage_limit_renders_without_html_leak(): void
    {
        $deliverables = [
            ['type' => 'reel', 'used' => 8, 'limit' => 8, 'percent' => 100, 'over' => true],
            ['type' => 'post', 'used' => 2, 'limit' => 4, 'percent' => 50, 'over' => false],
        ];
        $html = $this->renderMarkdown('emails.package-usage-limit', [
            'contactName' => 'Ada Lovelace',
            'packageName' => 'Growth Plan',
            'category' => 'Reel',
            'used' => 8,
            'limit' => 8,
            'deliverables' => $deliverables,
            'url' => 'https://example.test/usage',
        ]);
        $this->assertNoEscapedHtmlLeak($html, 'package-usage-limit');
        $this->assertStringContainsString('Reel', $html);
        $this->assertStringContainsString('100%', $html);
    }

    public function test_package_usage_warning_omits_breakdown_when_empty(): void
    {
        // Legacy call site (no deliverables) must still render cleanly.
        $html = $this->renderMarkdown('emails.package-usage-warning', [
            'contactName' => 'Ada Lovelace',
            'packageName' => 'Growth Plan',
            'category' => 'Content',
            'used' => 40,
            'limit' => 50,
            'percent' => 80,
            'deliverables' => [],
            'url' => 'https://example.test/usage',
        ]);
        $this->assertNoEscapedHtmlLeak($html, 'package-usage-warning empty');
    }

    public function test_client_welcome_renders_without_html_leak(): void
    {
        $client = new Client([
            'name' => 'Himalayan Coffee Co.',
            'contact' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'amount' => 15000,
        ]);
        $client->id = 1;
        $client->contract_start = now()->subMonth();
        $client->contract_end = now()->addMonths(11);

        $package = new Package([
            'name' => 'Growth Plan',
            'slug' => 'growth',
            'deliverable_limits' => [
                ['type' => 'reels', 'limit' => 4],
                ['type' => 'stories', 'limit' => 1],
                ['type' => 'performance report', 'limit' => 1],
            ],
        ]);
        $package->id = 1;
        $client->setRelation('linkedPackage', $package);

        $html = $this->renderMarkdown('emails.client-welcome', [
            'client' => $client,
            'packageName' => 'Growth Plan',
        ]);

        $this->assertNoEscapedHtmlLeak($html, 'client-welcome');
        $this->assertStringContainsString('Ada Lovelace', $html);
        $this->assertStringContainsString('Growth Plan', $html);
        $this->assertStringContainsString('NPR 15,000.00', $html);
        $this->assertStringContainsString('4 ×', $html);
        $this->assertStringContainsString('Reels', $html);
        $this->assertStringContainsString("What's included this month", $html);
        $this->assertStringContainsString('<table', $html);
    }

    public function test_client_welcome_renders_without_optional_fields(): void
    {
        $client = new Client([
            'name' => 'Solo LLC',
            'email' => 'solo@example.test',
        ]);
        $client->id = 2;

        $html = $this->renderMarkdown('emails.client-welcome', [
            'client' => $client,
            'packageName' => null,
        ]);

        $this->assertNoEscapedHtmlLeak($html, 'client-welcome no-optional');
        $this->assertStringContainsString('Solo LLC', $html);
    }

    public function test_client_credentials_renders_without_html_leak(): void
    {
        $html = $this->renderMarkdown('emails.client-credentials', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'password' => 'Xy9!kQ7@mZ',
        ]);

        $this->assertNoEscapedHtmlLeak($html, 'client-credentials');
        $this->assertStringContainsString('Ada Lovelace', $html);
        $this->assertStringContainsString('ada@example.test', $html);
        $this->assertStringContainsString('Xy9!kQ7@mZ', $html);
        $this->assertStringContainsString('<table', $html);
    }
}
