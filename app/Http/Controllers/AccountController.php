<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\Reports\FinancialStatements;
use App\Services\Reports\Period;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Chart of accounts: balances by type, add accounts, rename or hide them. */
class AccountController extends Controller
{
    public const TYPE_LABELS = [
        'asset' => 'Assets',
        'liability' => 'Liabilities',
        'equity' => 'Equity',
        'income' => 'Revenue',
        'cost_of_sales' => 'Cost of sales',
        'expense' => 'Operating expenses',
        'other_income' => 'Other income',
        'other_expense' => 'Other expenses',
    ];

    /** Normal balance for accounts added by hand. */
    private const NORMAL_BALANCE = [
        'asset' => 'debit', 'liability' => 'credit', 'equity' => 'credit', 'income' => 'credit',
        'cost_of_sales' => 'debit', 'expense' => 'debit', 'other_income' => 'credit', 'other_expense' => 'debit',
    ];

    public function index(Request $request): View
    {
        $company = $this->company($request);
        $statements = new FinancialStatements($company);
        $today = CarbonImmutable::today();
        $fyStart = Period::fiscalYearStart($today, $company->fiscal_year_start_month ?: 1);
        $allTime = $statements->totals(null, $today);
        $yearToDate = $statements->totals($fyStart, $today);

        $balances = $statements->accounts()->mapWithKeys(fn (Account $a) => [
            $a->id => FinancialStatements::value($a->type, FinancialStatements::isProfitAndLoss($a) ? ($yearToDate[$a->id] ?? null) : ($allTime[$a->id] ?? null)),
        ]);

        return view('accounts.index', [
            'company' => $company,
            'groups' => $statements->accounts()->groupBy('type')->sortBy(fn ($_, $type) => array_search($type, array_keys(self::TYPE_LABELS), true)),
            'balances' => $balances,
            // Accounts with at least one posting; the page can hide the rest of the (long) chart
            'usedIds' => array_flip($company->journalLines()->distinct()->pluck('account_id')->all()),
            'typeLabels' => self::TYPE_LABELS,
            'sections' => $company->accounts()->whereNotNull('section')->distinct()->orderBy('section')->pluck('section'),
            'fyStart' => $fyStart,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company = $this->company($request);
        $data = $request->validateWithBag('createAccount', [
            'kind' => ['nullable', 'in:regular,bank,card'],
            'code' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z.\-]+$/', Rule::unique('accounts')->where('company_id', $company->id)],
            'name' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::in(array_keys(self::TYPE_LABELS))],
            'section' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $kind = $data['kind'] ?? 'regular';
        unset($data['kind']);

        // Bank and card accounts take statement uploads and reconciliations, and count as cash / card debt in reports
        $data = match ($kind) {
            'bank' => ['type' => 'asset', 'section' => 'Current Assets', 'report_line' => 'Cash & Cash Equivalents', 'is_bank_account' => true] + $data,
            'card' => ['type' => 'liability', 'section' => 'Current Liabilities', 'report_line' => 'Credit Cards Payable', 'is_credit_card' => true] + $data,
            default => $data + ['report_line' => $data['name']],
        };

        $account = $company->accounts()->create($data + [
            'normal_balance' => self::NORMAL_BALANCE[$data['type']],
            'is_active' => true,
        ]);
        Audit::log('account.created', $account, ['code' => $account->code, 'name' => $account->name, 'kind' => $kind]);

        $added = "Account {$account->code} · {$account->name} added.";

        return $kind === 'regular'
            ? back()->with('status', $added)
            : redirect()->route('uploads.index', ['account' => $account->id])->with('status', "{$added} You can upload its statements now.");
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $this->authorizeCompany($request, $account->company_id);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $account->system_key) {
            return back()->with('error', "{$account->name} is used by the system and cannot be hidden.");
        }

        $account->update($data);
        if ($account->wasChanged()) {
            Audit::log('account.updated', $account, $account->getChanges());
        }

        return back()->with('status', "Account {$account->code} updated.");
    }
}
