<?php

namespace App\Listeners;

use App\Models\Company;
use App\Services\InvoiceMailer;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;
use Stripe\Invoice;

/** Emails every paid invoice that Stripe reports through the webhook (monthly renewals included). */
class SendInvoiceEmail
{
    private const EVENTS = ['invoice.payment_succeeded', 'invoice.paid'];

    public function __construct(private InvoiceMailer $mailer)
    {
    }

    public function handle(WebhookReceived $event): void
    {
        if (! in_array($event->payload['type'] ?? null, self::EVENTS, true)) {
            return;
        }

        $invoice = Invoice::constructFrom($event->payload['data']['object']);
        $company = Cashier::findBillable($invoice->customer);

        if ($company instanceof Company) {
            $this->mailer->send($company, $invoice);
        }
    }
}
