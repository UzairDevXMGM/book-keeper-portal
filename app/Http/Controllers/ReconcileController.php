<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\JournalLine;
use App\Models\Reconciliation;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Bank / card reconciliation: tick the ledger lines that appear on the statement until the books agree with the
 * statement balance, then finish (optionally locking the books through the statement date).
 * Balances are on the account's normal side: money in the bank, or the amount owed on a card.
 */
class ReconcileController extends Controller
{
    public function index(Request $request): View
    {
        $company = $this->company($request);
        $accounts = $this->statementAccounts($company);

        return view('reconcile.index', [
            'company' => $company,
            'accounts' => $accounts,
            'open' => Reconciliation::where('company_id', $company->id)->where('status', Reconciliation::STATUS_OPEN)->with('account')->get(),
            'history' => Reconciliation::where('company_id', $company->id)->where('status', Reconciliation::STATUS_COMPLETED)
                ->with(['account', 'completedBy'])->latest('statement_date')->latest('id')->paginate(10)->withQueryString(),
            // Statement balances read from uploads, offered as a starting point
            'suggestions' => $company->uploads()->whereNotNull('closing_balance')->whereNotNull('statement_end_date')
                ->latest('statement_end_date')->limit(5)->get(['id', 'account_id', 'statement_end_date', 'closing_balance', 'original_name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company = $this->company($request);
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::in($this->statementAccounts($company)->pluck('id'))],
            'statement_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'statement_balance' => ['required', 'numeric', 'between:-9999999999,9999999999'],
        ]);

        $existing = Reconciliation::where('company_id', $company->id)->where('account_id', $data['account_id'])
            ->where('status', Reconciliation::STATUS_OPEN)->first();
        if ($existing) {
            return redirect()->route('reconcile.show', $existing)->with('error', 'Finish or discard the reconciliation already open for this account first.');
        }

        $last = Reconciliation::where('company_id', $company->id)->where('account_id', $data['account_id'])
            ->where('status', Reconciliation::STATUS_COMPLETED)->latest('statement_date')->first();
        if ($last && $data['statement_date'] <= $last->statement_date->toDateString()) {
            return back()->withInput()->with('error', 'This account is already reconciled through '.$last->statement_date->format('M j, Y').'. Pick a later statement date.');
        }

        $reconciliation = Reconciliation::create($data + [
            'company_id' => $company->id,
            'opening_balance' => $last?->statement_balance ?? 0,
            'status' => Reconciliation::STATUS_OPEN,
        ]);
        Audit::log('reconciliation.started', $reconciliation, ['statement_date' => $data['statement_date'], 'balance' => $data['statement_balance']]);

        return redirect()->route('reconcile.show', $reconciliation);
    }

    public function show(Request $request, Reconciliation $reconciliation): View
    {
        $company = $this->authorizeCompany($request, $reconciliation->company_id);
        $account = $reconciliation->account;

        $lines = JournalLine::where('company_id', $company->id)->where('account_id', $account->id)
            ->where(fn ($q) => $q->whereNull('reconciliation_id')->orWhere('reconciliation_id', $reconciliation->id))
            ->when($reconciliation->isOpen(), fn ($q) => $q->where('entry_date', '<=', $reconciliation->statement_date->toDateString()))
            ->with('journalEntry:id,entry_number,description')
            ->orderBy('entry_date')->orderBy('id')
            ->get();

        return view('reconcile.show', [
            'company' => $company,
            'reconciliation' => $reconciliation,
            'account' => $account,
            'lines' => $lines,
            'creditNormal' => $account->normal_balance === 'credit',
        ]);
    }

    /** Saves the ticks; finishes when asked and the difference is zero. */
    public function update(Request $request, Reconciliation $reconciliation): RedirectResponse
    {
        $company = $this->authorizeCompany($request, $reconciliation->company_id);
        abort_unless($reconciliation->isOpen(), 404);
        $data = $request->validate([
            'cleared' => ['nullable', 'array'],
            'cleared.*' => ['integer'],
            'finish' => ['nullable', 'boolean'],
            'lock' => ['nullable', 'boolean'],
        ]);

        $eligible = JournalLine::where('company_id', $company->id)->where('account_id', $reconciliation->account_id)
            ->where('entry_date', '<=', $reconciliation->statement_date->toDateString())
            ->where(fn ($q) => $q->whereNull('reconciliation_id')->orWhere('reconciliation_id', $reconciliation->id));
        $cleared = (clone $eligible)->whereIn('id', $data['cleared'] ?? [])->pluck('id');

        DB::transaction(function () use ($reconciliation, $eligible, $cleared) {
            (clone $eligible)->where('reconciliation_id', $reconciliation->id)->update(['reconciliation_id' => null]);
            JournalLine::whereIn('id', $cleared)->update(['reconciliation_id' => $reconciliation->id]);
        });

        $clearedBalance = $this->clearedBalance($reconciliation->fresh());
        $reconciliation->update(['cleared_balance' => $clearedBalance]);

        if (! $request->boolean('finish')) {
            return back()->with('status', 'Progress saved.');
        }

        $difference = round((float) $reconciliation->statement_balance - $clearedBalance, 2);
        if (abs($difference) >= 0.005) {
            return back()->with('error', 'The difference must be $0.00 to finish. It is '.money($difference).'.');
        }

        $reconciliation->update(['status' => Reconciliation::STATUS_COMPLETED, 'completed_by' => $request->user()->id, 'completed_at' => now()]);
        if ($request->boolean('lock') && (! $company->locked_through || $company->locked_through->lt($reconciliation->statement_date))) {
            $company->update(['locked_through' => $reconciliation->statement_date->toDateString()]);
        }
        Audit::log('reconciliation.completed', $reconciliation, ['cleared_lines' => $cleared->count(), 'locked' => $request->boolean('lock')]);

        return redirect()->route('reconcile.index')->with('status', $reconciliation->account->name.' reconciled through '.$reconciliation->statement_date->format('M j, Y').'.');
    }

    public function destroy(Request $request, Reconciliation $reconciliation): RedirectResponse
    {
        $this->authorizeCompany($request, $reconciliation->company_id);
        abort_unless($reconciliation->isOpen(), 404);

        JournalLine::where('reconciliation_id', $reconciliation->id)->update(['reconciliation_id' => null]);
        Audit::log('reconciliation.discarded', $reconciliation);
        $reconciliation->delete();

        return redirect()->route('reconcile.index')->with('status', 'Reconciliation discarded.');
    }

    /** Opening (last reconciled) balance plus every line cleared in this reconciliation, on the normal side. */
    private function clearedBalance(Reconciliation $reconciliation): float
    {
        $sum = JournalLine::where('reconciliation_id', $reconciliation->id)->selectRaw('COALESCE(SUM(debit), 0) AS d, COALESCE(SUM(credit), 0) AS c')->first();
        $net = $reconciliation->account->normal_balance === 'credit' ? $sum->c - $sum->d : $sum->d - $sum->c;

        return round((float) $reconciliation->opening_balance + $net, 2);
    }

    private function statementAccounts(Company $company)
    {
        return $company->accounts()->where('is_active', true)
            ->where(fn ($q) => $q->where('is_bank_account', true)->orWhere('is_credit_card', true))
            ->orderBy('code')->get(['id', 'code', 'name', 'normal_balance', 'is_credit_card']);
    }
}
