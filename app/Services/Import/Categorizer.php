<?php

namespace App\Services\Import;

use App\Models\BusinessType;
use App\Models\CategorizationRule;
use App\Models\CategoryType;
use App\Models\Company;
use App\Models\ImportRow;
use Illuminate\Support\Facades\DB;
use App\Support\Settings;

/**
 * Suggests an account for a statement line from the keyword rules.
 *
 * Order: the company's own rules (learned in the review queue) → bank rules → named vendors → generic patterns →
 * the bank's own category text. Within a tier the longest keyword wins (so "UBER EATS" beats "UBER").
 * Nothing matched → suspense, which always waits for review.
 *
 * Global rules point at a categorization type ("Fuel"), and the type decides the account for this company: a system
 * account (transfers, owner draws), the company's industry account (Trucking: 5215 Fuel - Trucks), or the master
 * chart code (6800 Vehicle Fuel). A company's own rules point straight at one of its accounts.
 */
class Categorizer
{
    /** Priority given to the company's own rules (lower runs first). */
    public const USER_RULE_PRIORITY = 10;

    /** @var list<array> */
    private array $rules = [];
    private ?int $suspenseIn;
    private ?int $suspenseOut;
    private ?int $transfers;
    private int $threshold;
    private SmartAssist $smart;

    /** @var array<int, array{id: int, name: string, account_id: ?int, needs_review: bool}> category type id => where it posts */
    private array $typeAccounts = [];

    public function __construct(Company $company)
    {
        $accounts = $company->accounts()->where('is_active', true)->get(['id', 'canonical_code', 'system_key']);
        $byCode = $accounts->whereNotNull('canonical_code')->pluck('id', 'canonical_code')->all();
        $bySystemKey = $accounts->whereNotNull('system_key')->pluck('id', 'system_key')->all();
        $this->suspenseIn = $bySystemKey['suspense_income'] ?? null;
        $this->suspenseOut = $bySystemKey['suspense_expense'] ?? null;
        $this->transfers = $bySystemKey['transfers_clearing'] ?? null;
        $this->threshold = (int) Settings::get('review_threshold');
        $activeIds = $accounts->pluck('id')->flip();

        // Where each categorization type posts for this company
        $activeTypes = CategoryType::with('industryAccounts')->where('is_active', true)->get();
        $types = $activeTypes->mapWithKeys(fn (CategoryType $type) => [$type->id => [
            'id' => $type->id,
            'name' => $type->name,
            'account_id' => $type->system_key
                ? ($bySystemKey[$type->system_key] ?? null)
                : ($byCode[$type->accountCodeFor($company->industry)] ?? $byCode[$type->account_code] ?? null),
            'needs_review' => $type->needs_review,
        ]]);
        $this->typeAccounts = $types->all();

        // Smart Assist for lines no rule knows: this company's past choices, then the words in the description
        $suspense = array_filter([$this->suspenseIn, $this->suspenseOut]);
        $history = [];
        foreach (DB::table('import_rows')->where('company_id', $company->id)->where('status', ImportRow::STATUS_POSTED)
            ->whereNotNull('account_id')->whereNotIn('account_id', $suspense)
            ->groupBy('description', 'direction', 'account_id')
            ->selectRaw('description, direction, account_id, MAX(category_type_id) AS category_type_id, COUNT(*) AS n')->get() as $row) {
            $key = self::keywordFor((string) $row->description).'|'.$row->direction;
            $history[$key][$row->account_id]['n'] = ($history[$key][$row->account_id]['n'] ?? 0) + (int) $row->n;
            $history[$key][$row->account_id]['category_type_id'] = $row->category_type_id ? (int) $row->category_type_id : null;
        }
        $this->smart = new SmartAssist(
            $activeTypes->mapWithKeys(fn (CategoryType $t) => [$t->slug => $types[$t->id]])->all(),
            $history,
            $types->map(fn ($t) => $t['name'])->all(),
            self::businessTypes(),
        );

        foreach (CategorizationRule::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))->get() as $rule) {
            $type = $rule->category_type_id ? ($types[$rule->category_type_id] ?? null) : null;
            if ($rule->category_type_id && ! $type) {
                continue;   // the type is switched off
            }
            $accountId = $rule->account_id ?? $type['account_id'] ?? ($byCode[$rule->account_code] ?? null);
            if (! $accountId || ! isset($activeIds[$accountId])) {
                continue;
            }
            $this->rules[] = [
                'id' => $rule->id,
                'source' => $rule->source,
                'name' => $rule->name,
                'keyword' => mb_strtoupper(trim($rule->keyword)),
                'words' => self::normalize($rule->keyword),
                'match_type' => $rule->match_type,
                'direction' => $rule->direction,
                'applies_to' => $rule->applies_to,
                'account_id' => $accountId,
                'category_type_id' => $rule->category_type_id,
                'type' => $type['name'] ?? null,
                'priority' => $rule->company_id ? self::USER_RULE_PRIORITY : $rule->priority,
                'confidence' => $rule->confidence,
                'needs_review' => $rule->needs_review || ($type['needs_review'] ?? false),
                // A one-word "Local restaurant (RESTAURANT)" pattern from the vendor library rather than a named merchant
                'generic' => $rule->source === 'vendor' && $rule->company_id === null && str_starts_with((string) $rule->name, 'Local '),
            ];
        }

        usort($this->rules, fn ($a, $b) => [$a['priority'], mb_strlen($b['keyword'])] <=> [$b['priority'], mb_strlen($a['keyword'])]);
    }

    /**
     * @param  string  $kind  'bank' or 'credit_card' (the statement account's kind)
     * @return array{account_id: ?int, rule_id: ?int, category_type_id: ?int, type: ?string, method: string, confidence: int, needs_review: bool, business_type: ?string}
     */
    public function suggest(string $description, string $direction, string $kind, ?string $bankCategory, int $statementAccountId): array
    {
        // Whatever gave the category, say what kind of business the merchant is ("Shell" → Gas station)
        return $this->categorize($description, $direction, $kind, $bankCategory, $statementAccountId)
            + ['business_type' => $this->smart->businessType($description, $direction)['name'] ?? null];
    }

    /** @return list<array{name: string, category_type_id: ?int, direction: string, confidence: int, keywords: list<string>}> the active business types */
    public static function businessTypes(): array
    {
        return BusinessType::where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn (BusinessType $t) => ['name' => $t->name, 'category_type_id' => $t->category_type_id, 'direction' => $t->direction,
                'confidence' => $t->confidence, 'keywords' => $t->keywordList()])->all();
    }

    private function categorize(string $description, string $direction, string $kind, ?string $bankCategory, int $statementAccountId): array
    {
        $text = ' '.mb_strtoupper(preg_replace('/\s+/', ' ', $description)).' ';
        $words = ' '.self::normalize($description).' ';
        $category = $bankCategory ? mb_strtoupper(trim($bankCategory)) : null;

        foreach ($this->rules as $rule) {
            if (($rule['direction'] !== 'any' && $rule['direction'] !== $direction)
                || ($rule['applies_to'] !== 'any' && $rule['applies_to'] !== $kind)) {
                continue;
            }
            $hit = $rule['source'] === 'bank_category'
                ? $category !== null && $category === $rule['keyword']
                : $this->matches($text, $words, $rule);
            if (! $hit) {
                continue;
            }

            // A rule pointing at the statement's own account (e.g. a transfer) goes to Transfers clearing instead
            $accountId = $rule['account_id'] === $statementAccountId ? $this->transfers : $rule['account_id'];
            if (! $accountId) {
                continue;
            }

            // A generic one-word pattern gives way to this business's own past choices, or to the kind of business
            // the merchant's name shows ("Allied Waste Services" is trash pickup, not the "Local utility" pattern)
            if ($rule['generic'] && ($better = $this->smart->beforeGeneric($description, $direction, $statementAccountId))) {
                return array_merge(['rule_id' => null], $better, ['needs_review' => $better['needs_review'] || $better['confidence'] < $this->threshold]);
            }

            return [
                'account_id' => $accountId,
                'rule_id' => $rule['id'],
                'category_type_id' => $rule['category_type_id'],
                'type' => $rule['type'],
                'method' => $rule['source'],
                'confidence' => $rule['confidence'],
                'needs_review' => $rule['needs_review'] || $rule['confidence'] < $this->threshold,
            ];
        }

        // No rule knows it: Smart Assist suggests a category (from past choices or the words), else it waits unsorted
        if ($guess = $this->smart->guess($description, $direction, $statementAccountId)) {
            return array_merge(['rule_id' => null], $guess, ['needs_review' => $guess['needs_review'] || $guess['confidence'] < $this->threshold]);
        }

        return [
            'account_id' => $direction === 'in' ? $this->suspenseIn : $this->suspenseOut,
            'rule_id' => null,
            'category_type_id' => null,
            'type' => null,
            'method' => 'suspense',
            'confidence' => 0,
            'needs_review' => true,
        ];
    }

    /**
     * Category choices for this company in plain names ("Fuel", "Meals & Entertainment") with the account each one
     * posts to here, for the category pickers.
     *
     * @return array<int, array{id: int, name: string, account_id: ?int, needs_review: bool}>
     */
    public function categoryChoices(): array
    {
        return array_filter($this->typeAccounts, fn ($t) => $t['account_id'] !== null);
    }

    /** Card-network and bank prefixes in front of the merchant name: "CHECKCARD 0109 LOVE'S", "TST* CHEESY BITE". */
    private const PREFIXES = ['POS', 'DEBIT', 'CREDIT', 'PURCHASE', 'AUTHORIZED', 'ON', 'ACH', 'CHECKCARD', 'CARD', 'RECURRING', 'TST', 'SQ', 'SP', 'DD', 'DNH', 'ICI', 'PMT'];

    /** Labels that end a name inside a bank description ("... DES:FUNDING ID:832 INDN:..."). */
    private const STOP_WORDS = ['FOR', 'CONF', 'ID', 'REF', 'TRN', 'DES', 'INDN', 'ORIG', 'SEQ', 'IMAD', 'SND', 'BNF', 'DATE', 'TIME', 'DET', 'TRANSACTION', 'WEB', 'PPD', 'CCD', 'SEC', 'NULL', 'ON'];

    /** Upper-case words and digits only: "SQ *Blue Bottle #42" -> "SQ BLUE BOTTLE 42". Rules also match on this form. */
    public static function normalize(string $text): string
    {
        return trim(preg_replace('/[^A-Z0-9&]+/', ' ', mb_strtoupper(str_replace("'", '', $text))));
    }

    /**
     * The part of a bank description worth remembering as a rule. It is always a run of whole words from the
     * description, so the rule finds it again: "SQ *BLUE BOTTLE 0423 SAN FRAN" -> "BLUE BOTTLE".
     * Payments to or from someone keep the name ("ZELLE PAYMENT FROM JANE DOE", "GM FINANCIAL" from a Chase ACH line,
     * the wire beneficiary), and checks keep their number, so one approved line never catches every Zelle or check.
     */
    public static function keywordFor(string $description): string
    {
        // Wells Fargo: "Purchase authorized on 05/31 Wal-Mart #1406 ...", "Business to Business ACH Debit - Bcbs Health ..."
        $description = preg_replace([
            '/^(recurring payment|purchase return|purchase|atm withdrawal|money transfer|cash advance)\s+authorized on\s+\d{1,2}\/\d{1,2}\s*/i',
            '/^business to business ach (debit|credit)\s*-\s*/i',
        ], '', $description);
        $words = array_values(array_filter(explode(' ', self::normalize($description)), fn ($w) => $w !== ''));
        $count = count($words);
        // Up to $max words; a word with digits ends the name, except a first word like "E2E" or "FEEFORTBENDMUD25"
        $take = function (int $from, int $max) use ($words, $count): array {
            $name = [];
            for ($i = $from; $i < $count && count($name) < $max; $i++) {
                $hasDigits = (bool) preg_match('/\d/', $words[$i]);
                if (in_array($words[$i], self::STOP_WORDS, true) || ($hasDigits && ($name || preg_match_all('/[A-Z]/', $words[$i]) < 2))) {
                    break;
                }
                $name[] = $words[$i];
            }

            return $name;
        };
        $after = function (array $phrase) use ($words, $count): ?int {
            for ($i = 0; $i + count($phrase) <= $count; $i++) {
                if (array_slice($words, $i, count($phrase)) === $phrase) {
                    return $i + count($phrase);
                }
            }

            return null;
        };

        if (($words[0] ?? null) === 'CHECK' && preg_match('/^\d+$/', $words[1] ?? '')) {
            return "CHECK {$words[1]}";
        }

        foreach ([['ZELLE', 'PAYMENT', 'FROM'], ['ZELLE', 'PAYMENT', 'TO'], ['ZELLE', 'FROM'], ['ZELLE', 'TO'],
            ['ONLINE', 'TRANSFER', 'FROM'], ['ONLINE', 'TRANSFER', 'TO'], ['VENMO', 'FROM'], ['VENMO', 'TO']] as $phrase) {
            if (($at = $after($phrase)) !== null && ($name = $take($at, 3))) {
                return implode(' ', array_merge($phrase, $name));
            }
        }

        // Chase ACH "ORIG CO NAME:GM Financial ORIG ID:..."; wires "BNF:ACME LLC ID:..." or "ORIG:1/JANE DOE ID:..."
        foreach ([['ORIG', 'CO', 'NAME'], ['BNF'], ['ORIG']] as $label) {
            if (($at = $after($label)) !== null) {
                while ($at < $count && ctype_digit($words[$at])) {
                    $at++;
                }
                if ($name = $take($at, 4)) {
                    return implode(' ', $name);
                }
            }
        }

        $start = 0;
        while ($start < $count && (in_array($words[$start], self::PREFIXES, true) || ctype_digit($words[$start]))) {
            $start++;
        }

        return implode(' ', $take($start, 3)) ?: mb_strtoupper(mb_substr(trim($description), 0, 30));
    }

    /** The rule's own text, or the same words with punctuation ignored ("SQ BLUE BOTTLE" finds "SQ *BLUE BOTTLE"). */
    private function matches(string $text, string $words, array $rule): bool
    {
        $sameWords = $rule['words'] !== '' && str_contains($words, ' '.$rule['words'].' ');

        return match ($rule['match_type']) {
            'exact' => trim($text) === $rule['keyword'] || trim($words) === $rule['words'],
            'word' => $sameWords || (bool) preg_match('/(?<![A-Z0-9])'.preg_quote($rule['keyword'], '/').'(?![A-Z0-9])/u', $text),
            default => $sameWords || str_contains($text, $rule['keyword']),
        };
    }

    /** Same test as a "contains" rule, for checking a keyword against one description outside a full suggest(). */
    public static function containsKeyword(string $description, string $keyword): bool
    {
        $keyword = mb_strtoupper(trim($keyword));
        $words = self::normalize($keyword);

        return str_contains(' '.mb_strtoupper(preg_replace('/\s+/', ' ', $description)).' ', $keyword)
            || ($words !== '' && str_contains(' '.self::normalize($description).' ', ' '.$words.' '));
    }
}
