<?php

namespace App\Http\Controllers;

use App\Models\JournalEntry;
use App\Services\Ledger\LedgerException;
use App\Services\Ledger\LedgerService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Manual journal entries (adjustments, opening balances) and the entry detail page with "reverse". */
class JournalEntryController extends Controller
{
    public function create(Request $request): View
    {
        $company = $this->company($request);

        return view('journal.create', [
            'company' => $company,
            'accounts' => $company->accounts()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'recent' => $company->journalEntries()->where('source', '!=', 'upload')->with('lines')
                ->latest('entry_date')->latest('entry_number')->limit(8)->get(),
        ]);
    }

    public function store(Request $request, LedgerService $ledger): RedirectResponse
    {
        $company = $this->company($request);
        $data = $request->validate([
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', 'in:manual,opening_balance,adjustment'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.account_id' => ['nullable', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.debit' => ['nullable', 'string', 'max:20'],
            'lines.*.credit' => ['nullable', 'string', 'max:20'],
        ]);

        try {
            $entry = $ledger->post($company, [
                'entry_date' => $data['entry_date'],
                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
                'source' => $data['source'] ?? 'manual',
            ], $data['lines'], $request->user());
        } catch (LedgerException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        Audit::log('journal.posted', $entry, ['number' => $entry->entry_number, 'amount' => $entry->lines->sum('debit')]);

        return redirect()->route('journal.show', $entry)->with('status', "Entry #{$entry->entry_number} posted.");
    }

    public function show(Request $request, JournalEntry $entry): View
    {
        $company = $this->authorizeCompany($request, $entry->company_id);
        $entry->load(['lines.account', 'creator', 'reverses', 'upload']);

        return view('journal.show', [
            'company' => $company,
            'entry' => $entry,
            'reversedBy' => JournalEntry::where('reverses_entry_id', $entry->id)->first(),
        ]);
    }

    public function reverse(Request $request, JournalEntry $entry, LedgerService $ledger): RedirectResponse
    {
        $this->authorizeCompany($request, $entry->company_id);

        try {
            $reversal = $ledger->reverse($entry, $request->user());
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        Audit::log('journal.reversed', $entry, ['number' => $entry->entry_number, 'reversal' => $reversal->entry_number]);

        return redirect()->route('journal.show', $reversal)->with('status', "Entry #{$entry->entry_number} reversed by entry #{$reversal->entry_number}.");
    }
}
