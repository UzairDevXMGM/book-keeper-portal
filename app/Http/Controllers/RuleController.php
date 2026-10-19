<?php

namespace App\Http\Controllers;

use App\Models\CategorizationRule;
use App\Support\Audit;
use App\Support\CategoryChoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "My rules": the merchants a business told us to always categorize the same way (the "Always" tick on
 * Check categories and Transactions). They can change the category of a rule or delete it.
 */
class RuleController extends Controller
{
    public function index(Request $request): View
    {
        $company = $this->company($request);
        $search = trim((string) $request->query('q'));

        return view('rules.index', [
            'company' => $company,
            'rules' => $company->categorizationRules()->where('source', 'user')
                ->when($search !== '', fn ($q) => $q->where('keyword', 'like', '%'.mb_strtoupper($search).'%'))
                ->with(['categoryType:id,name', 'account:id,code,name'])
                ->orderBy('keyword')->paginate(25)->withQueryString(),
            'search' => $search,
            'choices' => CategoryChoice::choices($company),
            'accounts' => $company->accounts()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function update(Request $request, CategorizationRule $rule): RedirectResponse
    {
        $company = $this->authorizeCompany($request, $rule->company_id);
        $data = $request->validate(['category' => ['required', CategoryChoice::RULE]], ['category.required' => 'Pick a category.']);
        $choice = CategoryChoice::resolve($company, (string) $data['category']);
        if (! $choice) {
            return back()->with('error', 'Pick a category.');
        }
        $rule->update(['account_id' => $choice[0], 'category_type_id' => $choice[1], 'is_active' => true]);
        Audit::log('rule.updated', $rule, ['keyword' => $rule->keyword, 'account_id' => $choice[0]]);

        return back()->with('status', "“{$rule->keyword}” now gets the new category on your next uploads.");
    }

    public function destroy(Request $request, CategorizationRule $rule): RedirectResponse
    {
        $this->authorizeCompany($request, $rule->company_id);
        abort_unless($rule->source === 'user', 404);
        $rule->delete();
        Audit::log('rule.deleted', null, ['keyword' => $rule->keyword]);

        return back()->with('status', "Rule for “{$rule->keyword}” deleted. Its transactions keep their category; new ones are categorized as usual.");
    }
}
