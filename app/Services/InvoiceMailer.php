<?php

namespace App\Services;

use App\Mail\InvoicePaid;
use App\Models\Company;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Cashier;
use Stripe\Invoice;
use Throwable;

/**
 * Emails a paid Stripe invoice (with Stripe's own PDF) to the company owner, with a copy to the admin.
 * Called from the checkout return page (first invoice) and from the Stripe webhook (first invoice and renewals);
 * each invoice is emailed once.
 */
class InvoiceMailer
{
    public function sendById(Company $company, string $invoiceId): bool
    {
        return $this->send($company, Cashier::stripe()->invoices->retrieve($invoiceId));
    }

    public function send(Company $company, Invoice $invoice): bool
    {
        $to = $company->owner?->email;

        if (! $to || $invoice->status !== 'paid' || $invoice->amount_paid <= 0 || $invoice->customer !== $company->stripe_id) {
            return false;
        }

        // Claim the invoice first: if the webhook and the return page race, only one of them gets the row
        $claimed = DB::table('invoice_emails')->insertOrIgnore([
            'company_id' => $company->id,
            'stripe_invoice_id' => $invoice->id,
            'sent_to' => $to,
            'sent_at' => now(),
        ]);
        if ($claimed === 0) {
            return false;
        }

        try {
            $mail = Mail::to($to);
            if ($copy = config('bookkeeper.mail.admin_copy')) {
                $mail->bcc($copy);
            }
            $mail->send(new InvoicePaid($company, $invoice, $this->downloadPdf($invoice)));
            Audit::log('invoice.emailed', $company, ['invoice' => $invoice->number, 'to' => $to], $company);

            return true;
        } catch (Throwable $e) {
            // Release the claim so the next webhook delivery can try again
            DB::table('invoice_emails')->where('stripe_invoice_id', $invoice->id)->delete();
            Log::error('Invoice email failed', ['company_id' => $company->id, 'invoice' => $invoice->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** Stripe's invoice PDF; the email still goes out with the online link if the download fails. */
    private function downloadPdf(Invoice $invoice): ?string
    {
        if (! $invoice->invoice_pdf) {
            return null;
        }

        try {
            $response = Http::timeout(20)->get($invoice->invoice_pdf);

            return $response->successful() ? $response->body() : null;
        } catch (Throwable $e) {
            Log::warning('Could not download Stripe invoice PDF', ['invoice' => $invoice->id, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
