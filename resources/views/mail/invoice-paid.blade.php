<x-mail::message>
# Thank you for your payment

Hi {{ $customerName }},

We received your payment of **{{ $amount }}** for **{{ $companyName }}**.

<x-mail::table>
| | |
|:--|--:|
| Invoice number | {{ $number }} |
| Paid on | {{ $paidOn }} |
@if ($description)
| Description | {{ $description }} |
@endif
@if ($period)
| Billing period | {{ $period }} |
@endif
| **Amount paid** | **{{ $amount }}** |
</x-mail::table>

@if ($hasPdf)
Your invoice is attached as a PDF. You can also view or download it online any time:
@else
You can view and download your invoice online:
@endif

@if ($invoiceUrl)
<x-mail::button :url="$invoiceUrl">View invoice</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
