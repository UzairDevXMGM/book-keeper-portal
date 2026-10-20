<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessType;
use App\Models\CategoryType;
use App\Services\Import\Categorizer;
use App\Services\Import\SmartAssist;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Business types: the kinds of business recognised from a merchant's name ("ROOFING" → Roofing contractor →
 * Repairs & Maintenance). Changes apply to statements uploaded from now on, for every customer.
 */
class BusinessTypeController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $group = (string) $request->query('group');
        $test = trim((string) $request->query('test'));

        return view('admin.business-types.index', [
            'types' => BusinessType::with('categoryType:id,name')
                ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                    ->orWhere('keywords', 'like', '%'.mb_strtoupper($search).'%')))
                ->when($group !== '', fn ($q) => $q->where('group', $group))
                ->orderBy('sort_order')->paginate(30)->withQueryString(),
            'groups' => BusinessType::whereNotNull('group')->distinct()->orderBy('group')->pluck('group'),
            'categories' => CategoryType::orderBy('name')->get(['id', 'name']),
            'search' => $search,
            'group' => $group,
            'test' => $test,
            'match' => $test !== '' ? $this->tryName($test) : null,
            'counts' => ['types' => BusinessType::count(), 'keywords' => BusinessType::pluck('keywords')->sum(fn ($k) => count(array_filter(preg_split('/[\r\n,]+/', $k))))],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $type = BusinessType::create($this->validated($request) + ['sort_order' => (int) BusinessType::max('sort_order') + 10]);
        Audit::log('admin.business_type_created', $type, ['name' => $type->name]);

        return redirect()->route('admin.business-types.edit', $type)->with('status', "“{$type->name}” added.");
    }

    public function edit(BusinessType $businessType): View
    {
        return view('admin.business-types.edit', [
            'type' => $businessType,
            'groups' => BusinessType::whereNotNull('group')->distinct()->orderBy('group')->pluck('group'),
            'categories' => CategoryType::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, BusinessType $businessType): RedirectResponse
    {
        $businessType->update($this->validated($request));
        Audit::log('admin.business_type_updated', $businessType, ['name' => $businessType->name]);

        return redirect()->route('admin.business-types.index')->with('status', "“{$businessType->name}” saved. It is used for statements uploaded from now on.");
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'group' => ['nullable', 'string', 'max:80'],
            'keywords' => ['required', 'string', 'max:20000'],
            'category_type_id' => ['nullable', Rule::exists('category_types', 'id')],
            'direction' => ['required', 'in:out,in,any'],
            'confidence' => ['required', 'integer', 'between:40,95'],
            'is_active' => ['nullable', 'boolean'],
        ], ['keywords.required' => 'Add at least one word or phrase, one per line.']);

        // One phrase per line, in capitals, no repeats
        $data['keywords'] = implode("\n", array_values(array_unique(array_filter(array_map(
            fn ($k) => mb_strtoupper(trim($k)), preg_split('/[\r\n,]+/', $data['keywords'])
        )))));

        return $data + ['is_active' => $request->boolean('is_active')];
    }

    /** What the business types make of a name typed by the admin. */
    private function tryName(string $name): ?array
    {
        $smart = new SmartAssist([], [], [], Categorizer::businessTypes());
        $match = $smart->businessType($name, 'out');
        if (! $match) {
            return null;
        }

        return $match + ['category' => $match['category_type_id'] ? CategoryType::whereKey($match['category_type_id'])->value('name') : null];
    }
}
