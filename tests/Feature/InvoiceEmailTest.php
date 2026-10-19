<?php

namespace Tests\Feature;

use App\Listeners\SendInvoiceEmail;
use App\Mail\InvoicePaid;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyProvisioner;
use App\Services\InvoiceMailer;
use Database\Seeders\AccountTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Events\WebhookReceived;
use Stripe\Invoice;
use Tests\TestCase;

class InvoiceEmailTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccountTemplateSeeder::class);
        config(['bookkeeper.mail.admin_copy' => 'admin@example.test']);
        Mail::fake();
        Http::fake(['files.stripe.test/*' => Http::response('%PDF-1.4 test', 200, ['Content-Type' => 'application/pdf'])]);

        $owner = User::factory()->create(['name' => 'Test Owner', 'email' => 'owner@acme.test']);
        $this->company = app(CompanyProvisioner::class)->create($owner, ['name' => 'Acme Supplies LLC']);
        $this->company->forceFill(['stripe_id' => 'cus_test'])->save();
    }

    public function test_a_paid_invoice_is_emailed_to_the_owner_with_the_pdf_and_a_copy_to_admin(): void
    {
        $this->assertTrue(app(InvoiceMailer::class)->send($this->company, $this->invoice()));

        Mail::assertSent(InvoicePaid::class, function (InvoicePaid $mail) {
            $mail->assertHasTo('owner@acme.test');
            $mail->assertHasBcc('admin@example.test');
            $mail->assertHasAttachedData('%PDF-1.4 test', 'Invoice-INV-0001.pdf', ['mime' => 'application/pdf']);
            $mail->assertSeeInHtml('$25.00');
            $mail->assertSeeInHtml('INV-0001');

            return true;
        });
        $this->assertDatabaseHas('invoice_emails', ['stripe_invoice_id' => 'in_test_1', 'sent_to' => 'owner@acme.test']);
    }

    public function test_each_invoice_is_emailed_only_once(): void
    {
        $mailer = app(InvoiceMailer::class);

        $this->assertTrue($mailer->send($this->company, $this->invoice()));
        $this->assertFalse($mailer->send($this->company, $this->invoice()));

        Mail::assertSentCount(1);
    }

    public function test_unpaid_free_or_foreign_invoices_are_not_emailed(): void
    {
        $mailer = app(InvoiceMailer::class);

        $this->assertFalse($mailer->send($this->company, $this->invoice(['id' => 'in_open', 'status' => 'open'])));
        $this->assertFalse($mailer->send($this->company, $this->invoice(['id' => 'in_zero', 'amount_paid' => 0])));
        $this->assertFalse($mailer->send($this->company, $this->invoice(['id' => 'in_other', 'customer' => 'cus_someone_else'])));

        Mail::assertNothingSent();
    }

    public function test_the_email_still_goes_out_when_the_pdf_cannot_be_downloaded(): void
    {
        $this->assertTrue(app(InvoiceMailer::class)->send($this->company, $this->invoice(['invoice_pdf' => null])));

        Mail::assertSent(InvoicePaid::class, fn (InvoicePaid $mail) => $mail->attachments() === []);
    }

    public function test_the_stripe_webhook_sends_renewal_invoices(): void
    {
        $payload = ['type' => 'invoice.payment_succeeded', 'data' => ['object' => $this->invoice(['id' => 'in_renewal'])->toArray()]];

        app(SendInvoiceEmail::class)->handle(new WebhookReceived($payload));

        Mail::assertSent(InvoicePaid::class);
        $this->assertDatabaseHas('invoice_emails', ['stripe_invoice_id' => 'in_renewal']);
    }

    private function invoice(array $overrides = []): Invoice
    {
        return Invoice::constructFrom(array_merge([
            'id' => 'in_test_1',
            'object' => 'invoice',
            'customer' => 'cus_test',
            'number' => 'INV-0001',
            'status' => 'paid',
            'amount_paid' => 2500,
            'currency' => 'usd',
            'created' => 1791830000,
            'status_transitions' => ['paid_at' => 1791830000],
            'hosted_invoice_url' => 'https://invoice.stripe.test/i/test',
            'invoice_pdf' => 'https://files.stripe.test/invoice.pdf',
            'lines' => ['object' => 'list', 'data' => [[
                'id' => 'il_1',
                'object' => 'line_item',
                'description' => '1 x Basic plan (at $25.00 / month)',
                'period' => ['start' => 1791830000, 'end' => 1794508400],
            ]]],
        ], $overrides));
    }
}
