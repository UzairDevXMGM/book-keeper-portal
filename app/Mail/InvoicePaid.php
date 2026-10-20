<?php

namespace App\Mail;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Stripe\Invoice;

/** "Payment received" email with the Stripe invoice PDF attached. */
class InvoicePaid extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Company $company,
        public Invoice $invoice,
        public ?string $pdf = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Payment received - invoice {$this->invoice->number}",
        );
    }

    public function content(): Content
    {
        $line = $this->invoice->lines->data[0] ?? null;

        return new Content(
            markdown: 'mail.invoice-paid',
            with: [
                'customerName' => $this->company->owner?->name,
                'companyName' => $this->company->name,
                'number' => $this->invoice->number,
                'paidOn' => Carbon::createFromTimestamp($this->invoice->status_transitions->paid_at ?? $this->invoice->created)->format('M j, Y'),
                'description' => $line?->description,
                'period' => $line ? Carbon::createFromTimestamp($line->period->start)->format('M j, Y').' - '.Carbon::createFromTimestamp($line->period->end)->format('M j, Y') : null,
                'amount' => self::money($this->invoice->amount_paid, $this->invoice->currency),
                // Our own invoice page (sign-in needed), so the customer never has to leave the portal
                'invoiceUrl' => route('billing.invoice', $this->invoice->id),
                'hasPdf' => $this->pdf !== null,
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        if ($this->pdf === null) {
            return [];
        }

        return [
            Attachment::fromData(fn () => $this->pdf, "Invoice-{$this->invoice->number}.pdf")->withMime('application/pdf'),
        ];
    }

    /** Stripe amounts are in cents. */
    public static function money(int $cents, string $currency): string
    {
        $amount = number_format($cents / 100, 2);

        return strtolower($currency) === 'usd' ? "\${$amount}" : $amount.' '.strtoupper($currency);
    }
}
