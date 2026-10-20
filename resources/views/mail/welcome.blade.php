<x-mail::message>
# Welcome, {{ $name }}

Your BookKeepingLLC account{{ $companyName ? ' for **'.$companyName.'**' : '' }} is ready. You sign in with **{{ $email }}**.

**Getting started takes three steps:**

1. Start your plan on the payment page.
2. Go to **Dump your data** and upload your bank or card statements (PDF or Excel).
3. Check the categories we picked, then open your General Ledger, Profit & Loss and Balance Sheet.

<x-mail::button :url="$loginUrl">Open my dashboard</x-mail::button>

New to bookkeeping? The [step-by-step guide]({{ $guideUrl }}) explains every page in plain words.

Questions? Just reply to this email.

Thanks,<br>
BookKeepingLLC
</x-mail::message>
