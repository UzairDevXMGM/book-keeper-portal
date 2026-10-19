<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountTemplate;
use App\Models\CategorizationRule;
use App\Models\CategoryType;
use App\Models\CategoryTypeIndustryAccount;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin → Categories: the categorization types ("Fuel", "Meals & Entertainment"), the account each posts to, the
 * merchant keywords that belong to it, and industry accounts that replace the default for one industry.
 * Changes apply to lines categorized from now on; lines already posted stay where they are.
 */
class CategoryTypeController extends Controller
{
    /** System accounts a type can post to instead of a chart code. */
    public const SYSTEM_TARGETS = [
        'transfers_clearing' => 'Transfers clearing (money between own accounts)',
        'owner_draws' => "Owner's draws (personal spending)",
        'suspense_expense' => 'Uncategorized expense (always review)',
        'suspense_income' => 'Uncategorized income (always review)',
    ];

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $types = CategoryType::withCount(['rules as keywords_count' => fn ($q) => $q->whereNull('company_id'), 'industryAccounts'])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                ->orWhereHas('rules', fn ($r) => $r->whereNull('company_id')->where('keyword', 'like', "%{$search}%"))))
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        return view('admin.categories.index', [
            'types' => $types,
            'search' => $search,
            'accounts' => $this->masterAccounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateType($request, 'createType');
        $type = CategoryType::create($data + [
            'slug' => $this->uniqueSlug($data['name']),
            'sort_order' => (int) CategoryType::max('sort_order') + 10,
            'is_active' => true,
        ]);
        Audit::log('admin.category_created', $type, ['name' => $type->name]);

        return redirect()->route('admin.categories.edit', $type)->with('status', "Category “{$type->name}” added. Now add the merchant keywords that belong to it.");
    }

    public function edit(Request $request, CategoryType $category): View
    {
        $search = trim((string) $request->query('q'));

        return view('admin.categories.edit', [
            'type' => $category->load('industryAccounts'),
            'keywords' => $category->rules()->whereNull('company_id')
                ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('keyword', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
                ->orderBy('keyword')->paginate(50)->withQueryString(),
            'search' => $search,
            'accounts' => $this->masterAccounts(),
            'industries' => config('business.industries'),
            'industryNames' => AccountTemplate::whereNotNull('industry')->whereIn('code', $category->industryAccounts->pluck('account_code'))
                ->get(['industry', 'code', 'name'])->keyBy(fn ($t) => "{$t->industry}|{$t->code}"),
        ]);
    }

    public function update(Request $request, CategoryType $category): RedirectResponse
    {
        $data = $this->validateType($request, 'default');
        $category->update($data + ['is_active' => $request->boolean('is_active')]);
        Audit::log('admin.category_updated', $category, array_keys($category->getChanges()));

        return back()->with('status', "“{$category->name}” saved. New uploads use it right away.");
    }

    public function addKeyword(Request $request, CategoryType $category): RedirectResponse
    {
        $data = $request->validateWithBag('keyword', [
            'keyword' => ['required', 'string', 'min:3', 'max:150'],
            'name' => ['nullable', 'string', 'max:200'],
            'match_type' => ['required', 'in:contains,word'],
            'applies_to' => ['required', 'in:any,bank,credit_card'],
        ]);
        $keyword = mb_strtoupper(trim($data['keyword']));

        // One keyword belongs to one category: adding it here moves it away from any other category
        $existing = CategorizationRule::whereNull('company_id')->where('keyword', $keyword)->with('categoryType')->get();
        $moved = $existing->first(fn ($r) => $r->category_type_id !== $category->id)?->categoryType?->name;
        $rules = $existing->isNotEmpty() ? $existing : collect([new CategorizationRule(['company_id' => null, 'keyword' => $keyword, 'direction' => 'any'])]);
        foreach ($rules as $rule) {
            $rule->fill([
                'source' => $rule->source ?: 'vendor',
                'name' => ($data['name'] ?? null) ?: ($rule->name ?: Str::title(mb_strtolower($keyword))),
                'match_type' => $data['match_type'],
                'applies_to' => $data['applies_to'],
                'account_code' => null,
                'account_id' => null,
                'category_type_id' => $category->id,
                'priority' => $rule->priority ?: 30,
                'confidence' => max(90, (int) $rule->confidence),
                'needs_review' => $category->needs_review,
                'is_active' => true,
            ])->save();
        }
        Audit::log('admin.category_keyword_added', $category, ['keyword' => $keyword, 'moved_from' => $moved]);

        return back()->with('status', "“{$keyword}” now goes to {$category->name}.".($moved ? " (moved from {$moved})" : ''));
    }

    public function removeKeyword(CategoryType $category, CategorizationRule $rule): RedirectResponse
    {
        abort_unless($rule->category_type_id === $category->id && $rule->company_id === null, 404);
        $rule->delete();
        Audit::log('admin.category_keyword_removed', $category, ['keyword' => $rule->keyword]);

        return back()->with('status', "“{$rule->keyword}” removed from {$category->name}.");
    }

    public function setIndustryAccount(Request $request, CategoryType $category): RedirectResponse
    {
        $data = $request->validateWithBag('industry', [
            'industry' => ['required', 'string', Rule::in(array_merge(...array_values(config('business.industries'))))],
            'account_code' => ['required', 'string', 'max:20'],
        ]);
        $exists = AccountTemplate::forIndustry($data['industry'])->where('code', $data['account_code'])->exists();
        if (! $exists) {
            return back()->withErrors(['account_code' => "{$data['industry']} has no account {$data['account_code']}."], 'industry')->withInput();
        }

        CategoryTypeIndustryAccount::updateOrCreate(['category_type_id' => $category->id, 'industry' => $data['industry']], ['account_code' => $data['account_code']]);
        Audit::log('admin.category_industry_set', $category, $data);

        return back()->with('status', "{$data['industry']}: {$category->name} posts to {$data['account_code']}.");
    }

    public function removeIndustryAccount(CategoryType $category, CategoryTypeIndustryAccount $industryAccount): RedirectResponse
    {
        abort_unless($industryAccount->category_type_id === $category->id, 404);
        $industryAccount->delete();
        Audit::log('admin.category_industry_removed', $category, ['industry' => $industryAccount->industry]);

        return back()->with('status', "{$industryAccount->industry} uses the default account again.");
    }

    private function validateType(Request $request, string $bag): array
    {
        $data = $request->validateWithBag($bag, [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'direction' => ['required', 'in:in,out,any'],
            'target' => ['required', 'string', 'max:40'],
            'needs_review' => ['nullable', 'boolean'],
        ]);

        // "target" is either a master chart code or one of the system accounts
        $system = array_key_exists($data['target'], self::SYSTEM_TARGETS) ? $data['target'] : null;
        if (! $system && ! AccountTemplate::whereNull('industry')->where('code', $data['target'])->exists()) {
            throw ValidationException::withMessages(['target' => 'Choose an account from the chart.'])->errorBag($bag);
        }

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'direction' => $data['direction'],
            'account_code' => $system ? null : $data['target'],
            'system_key' => $system,
            'needs_review' => $request->boolean('needs_review'),
        ];
    }

    /** Master chart accounts a category can post to, grouped by statement section. */
    private function masterAccounts()
    {
        return AccountTemplate::whereNull('industry')->whereNull('system_key')
            ->get(['code', 'name', 'section'])->sortBy(fn ($a) => (int) $a->code)->groupBy('section');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        for ($i = 2; CategoryType::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
