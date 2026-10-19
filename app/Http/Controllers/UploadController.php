<?php

namespace App\Http\Controllers;

use App\Models\ImportRow;
use App\Models\Upload;
use App\Services\Import\UploadProcessor;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** "Dump your data": upload bank / card statements, see what was read, post it to the books. */
class UploadController extends Controller
{
    public const STATUS_FILTERS = [
        'all' => 'All',
        ImportRow::STATUS_POSTED => 'In your books',
        ImportRow::STATUS_PENDING => 'Ready to add',
        ImportRow::STATUS_NEEDS_REVIEW => 'Check category',
        ImportRow::STATUS_DUPLICATE => 'Already uploaded',
        ImportRow::STATUS_OTHER_YEAR => 'Different year',
        ImportRow::STATUS_ERROR => "Couldn't read",
        ImportRow::STATUS_IGNORED => 'Skipped',
    ];

    /** How many years back the year picker goes. */
    private const YEARS_BACK = 7;

    public function index(Request $request): View
    {
        $company = $this->company($request);
        $years = $this->years();
        $yearFilter = in_array($request->integer('year'), $years, true) ? $request->integer('year') : null;

        return view('uploads.index', [
            'company' => $company,
            'statementAccounts' => $this->statementAccounts($company),
            'uploads' => $company->uploads()->with('account:id,code,name')
                ->when($yearFilter, fn ($q, $y) => $q->where('year', $y))
                ->latest()->paginate(10)->withQueryString(),
            'years' => $years,
            'selectedYear' => (int) old('year', session('upload_year', now()->year)),
            'yearFilter' => $yearFilter,
            'maxMb' => Settings::get('upload_max_mb'),
            'waiting' => ImportRow::where('company_id', $company->id)->where('status', ImportRow::STATUS_NEEDS_REVIEW)->count(),
        ]);
    }

    /** This year back to YEARS_BACK years ago, newest first. */
    private function years(): array
    {
        return range(now()->year, now()->year - self::YEARS_BACK);
    }

    public function store(Request $request, UploadProcessor $processor): RedirectResponse
    {
        // A year of card activity can be several thousand lines (about 8 ms each); allow up to 5 minutes
        @set_time_limit(300);
        $company = $this->company($request);
        $data = $request->validate([
            'year' => ['required', 'integer', Rule::in($this->years())],
            'file' => ['required', 'file', 'max:'.(Settings::get('upload_max_mb') * 1024), 'mimes:csv,txt,xlsx,pdf'],
            'account_id' => ['required', 'integer', Rule::in($this->statementAccounts($company)->pluck('id'))],
            'sign_convention' => ['required', 'in:auto,negative_is_out,positive_is_out'],
            'opening_balance' => ['nullable', 'numeric', 'between:-9999999999,9999999999'],
            'closing_balance' => ['nullable', 'numeric', 'between:-9999999999,9999999999'],
            'auto_post' => ['nullable', 'boolean'],
        ], [
            'year.required' => 'Pick the year these statements are for.',
            'year.in' => 'Pick the year these statements are for.',
            'file.mimes' => 'Upload a PDF, Excel (.xlsx) or CSV file. Old .xls files: open in Excel and save as .xlsx.',
            'account_id.in' => 'Choose the bank or card account this statement belongs to.',
        ]);
        session(['upload_year' => (int) $data['year']]);   // the next upload starts on the same year

        // The same file for the same year is refused. For another year it is allowed: a December–January statement
        // is uploaded once for each year and each upload keeps its own year's lines.
        $file = $request->file('file');
        $hash = hash_file('sha256', $file->getRealPath());
        $previous = $company->uploads()->where('file_hash', $hash)->where('year', $data['year'])->where('status', '!=', Upload::STATUS_FAILED)->first();
        if ($previous) {
            return back()->withInput()->with('error', "This file was already uploaded for {$data['year']} on {$previous->created_at->format('M j, Y')}. Nothing was added twice.");
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $type = match (true) {
            $extension === 'pdf' => 'pdf',
            $extension === 'xlsx' => 'xlsx',
            default => 'csv',
        };
        $account = $company->accounts()->findOrFail($data['account_id']);
        $sign = $data['sign_convention'];   // "auto" is worked out from the file itself (StatementParser)

        $path = $file->storeAs("uploads/{$company->id}", Str::uuid().'.'.$type, 'local');
        $upload = $company->uploads()->create([
            'user_id' => $request->user()->id,
            'account_id' => $account->id,
            'year' => (int) $data['year'],
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'disk' => 'local',
            'file_path' => $path,
            'mime_type' => mb_substr((string) $file->getMimeType(), 0, 120),
            'file_size' => $file->getSize(),
            'file_type' => $type,
            'file_hash' => $hash,
            'opening_balance' => $data['opening_balance'] ?? null,
            'closing_balance' => $data['closing_balance'] ?? null,
        ]);

        $processor->process($upload, $sign, $request->boolean('auto_post'), $request->user());
        $upload->refresh();
        Audit::log('upload.created', $upload, ['file' => $upload->original_name, 'rows' => $upload->rows_total, 'posted' => $upload->rows_posted]);

        if ($upload->status === Upload::STATUS_FAILED) {
            return redirect()->route('uploads.show', $upload)->with('error', $upload->error_message);
        }

        $review = $upload->rows()->where('status', ImportRow::STATUS_NEEDS_REVIEW)->count();
        $otherYear = $upload->rows()->where('status', ImportRow::STATUS_OTHER_YEAR)->count();

        $one = fn (int $n, string $single, string $many) => $n === 1 ? $single : $many;

        return redirect()->route('uploads.show', $upload)->with('status',
            "Read {$upload->rows_total} ".$one($upload->rows_total, 'transaction', 'transactions').": {$upload->rows_posted} added to your {$upload->year} books with a category".
            ($review ? ", {$review} ".$one($review, 'needs', 'need').' you to check the category' : '').
            ($upload->rows_duplicate ? ", {$upload->rows_duplicate} ".$one($upload->rows_duplicate, 'was', 'were').' already uploaded' : '').
            ($otherYear ? ", {$otherYear} ".$one($otherYear, 'is from another year and was', 'are from another year and were').' left out' : '').'.');
    }

    public function show(Request $request, Upload $upload): View
    {
        $company = $this->authorizeCompany($request, $upload->company_id);
        $filter = array_key_exists($request->query('status'), self::STATUS_FILTERS) ? $request->query('status') : 'all';

        $counts = $upload->rows()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $totals = $upload->rows()->whereNotIn('status', [ImportRow::STATUS_ERROR, ImportRow::STATUS_DUPLICATE, ImportRow::STATUS_IGNORED])
            ->selectRaw("SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END) AS money_in, SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END) AS money_out")
            ->first();

        return view('uploads.show', [
            'company' => $company,
            'upload' => $upload->load('account', 'user'),
            'rows' => $upload->rows()->with(['account:id,code,name,system_key', 'categoryType:id,name', 'rule:id,name,keyword'])
                ->when($filter !== 'all', fn ($q) => $q->where('status', $filter))
                ->orderBy('row_number')->paginate(50)->withQueryString(),
            'filter' => $filter,
            'filters' => self::STATUS_FILTERS,
            'counts' => $counts,
            'moneyIn' => (float) ($totals->money_in ?? 0),
            'moneyOut' => (float) ($totals->money_out ?? 0),
        ]);
    }

    public function post(Request $request, Upload $upload, UploadProcessor $processor): RedirectResponse
    {
        $this->authorizeCompany($request, $upload->company_id);
        $posted = $processor->postReady($upload, $request->user());
        Audit::log('upload.posted', $upload, ['posted' => $posted]);

        return back()->with('status', $posted ? "{$posted} line(s) posted to your books." : 'Nothing was ready to post.');
    }

    public function download(Request $request, Upload $upload): StreamedResponse
    {
        $this->authorizeCompany($request, $upload->company_id);

        return Storage::disk($upload->disk)->download($upload->file_path, $upload->original_name);
    }

    public function destroy(Request $request, Upload $upload): RedirectResponse
    {
        $this->authorizeCompany($request, $upload->company_id);
        if ($upload->rows()->where('status', ImportRow::STATUS_POSTED)->exists()) {
            return back()->with('error', 'Lines from this file are already in your books. Reverse those entries first if you need to undo it.');
        }

        Storage::disk($upload->disk)->delete($upload->file_path);
        Audit::log('upload.deleted', $upload, ['file' => $upload->original_name]);
        $upload->delete();

        return redirect()->route('uploads.index')->with('status', 'Upload deleted.');
    }

    private function statementAccounts($company)
    {
        return $company->accounts()->where('is_active', true)
            ->where(fn ($q) => $q->where('is_bank_account', true)->orWhere('is_credit_card', true))
            ->orderBy('code')->get(['id', 'code', 'name', 'is_credit_card']);
    }
}
