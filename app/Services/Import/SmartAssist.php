<?php

namespace App\Services\Import;

/**
 * Smart Assist: gives a category to statement lines that no keyword rule knows, the way a bookkeeper would.
 *
 *  1. Your past choices: a payee you put in the same category before goes there again
 *     ("ZELLE TO JOHN DOE" was Contract Labor twice -> Contract Labor).
 *  2. What the words say: "SMITH'S GRILL" is a restaurant, "LAKESIDE INN" is lodging, "BOB'S TIRE & LUBE" is a
 *     vehicle repair. The longest matching phrase wins ("ELECTRIC SUPPLY" -> repairs beats "ELECTRIC" -> electricity).
 *
 * Runs on the server only; no transaction data leaves it. Guesses below the review threshold are filled in but still
 * wait for a person to approve them.
 */
class SmartAssist
{
    /**
     * category type slug => [confidence, direction ('in' | 'out' | 'any'), phrases]. Phrases are whole words in the
     * normalized description (upper case, punctuation dropped), so "INN" does not match "INNOVATIONS".
     */
    private const PATTERNS = [
        'meals' => [74, 'out', ['RESTAURANT', 'RESTAURANTS', 'GRILL', 'CAFE', 'CAFFE', 'COFFEE', 'ESPRESSO', 'PIZZA', 'PIZZERIA', 'BURGER', 'BURGERS',
            'TACO', 'TACOS', 'TAQUERIA', 'BBQ', 'BARBECUE', 'KITCHEN', 'DINER', 'BAKERY', 'DONUT', 'DONUTS', 'SUSHI', 'WINGS', 'CHICKEN',
            'STEAKHOUSE', 'BISTRO', 'EATERY', 'BUFFET', 'NOODLE', 'NOODLES', 'RAMEN', 'PHO', 'DELI', 'SANDWICH', 'SANDWICHES', 'SUBS',
            'CREAMERY', 'ICE CREAM', 'SMOOTHIE', 'JUICE BAR', 'TEA HOUSE', 'HALAL', 'KABOB', 'KEBAB', 'BIRYANI', 'CURRY', 'TANDOOR',
            'SWEETS', 'CATERING', 'FOOD TRUCK', 'TAVERN', 'PUB', 'BAR & GRILL', 'SEAFOOD', 'CANTINA', 'WAFFLE', 'CREPES', 'BAGEL', 'BAGELS']],
        'groceries' => [66, 'out', ['GROCERY', 'GROCERIES', 'SUPERMARKET', 'FOOD MARKET', 'FRESH MARKET', 'FARMERS MARKET', 'MEAT MARKET',
            'MEAT SHOP', 'BAZAAR', 'INTERNATIONAL FOOD', 'SPICE', 'SPICES', 'PRODUCE', 'ASIAN MARKET', 'INDIAN MARKET', 'HALAL MARKET',
            'HALAL MEAT', 'ORIENTAL MARKET', 'MERCADO', 'CARNICERIA']],
        'fuel' => [74, 'out', ['GAS STATION', 'FUEL', 'FUELS', 'DIESEL', 'PETROLEUM', 'PETRO', 'TRUCK STOP', 'TRAVEL CENTER', 'TRAVEL CENTERS',
            'TRAVEL PLAZA', 'GAS & GO', 'GAS N GO', 'FUEL STOP', 'TRAVEL PLA', 'TRAVEL CTR', 'TRUCKSTOP', 'TRUCK STOPS']],
        'lodging' => [74, 'out', ['HOTEL', 'HOTELS', 'MOTEL', 'INN', 'SUITES', 'RESORT', 'LODGE', 'HOSTEL', 'AIRBNB', 'VRBO']],
        'airfare' => [74, 'out', ['AIRLINES', 'AIRLINE', 'AIRWAYS', 'AIR LINES']],
        'travel' => [66, 'out', ['TAXI', 'CAB', 'LIMO', 'LIMOUSINE', 'SHUTTLE', 'RENT A CAR', 'RENTAL CAR', 'CAR RENTAL', 'AMTRAK', 'GREYHOUND']],
        'parking-tolls' => [74, 'out', ['PARKING', 'TOLL', 'TOLLS', 'TOLLWAY', 'TOLL ROAD', 'EZ TAG', 'EZTAG', 'EZPASS', 'E ZPASS', 'TXTAG', 'SUNPASS']],
        'vehicle-repairs' => [74, 'out', ['AUTO REPAIR', 'AUTO PARTS', 'AUTOMOTIVE', 'TIRE', 'TIRES', 'COLLISION', 'BODY SHOP', 'MECHANIC', 'LUBE',
            'OIL CHANGE', 'MUFFLER', 'TRANSMISSION', 'BRAKE', 'BRAKES', 'CAR WASH', 'TRUCK WASH', 'TOWING', 'WRECKER', 'TRUCK REPAIR',
            'TRAILER REPAIR', 'DIESEL REPAIR', 'AUTO SPA', 'AUTO CARE', 'TRUCK PARTS', 'TRAILER PARTS', 'SMOG', 'INSPECTION STATION']],
        'vehicle-registration' => [66, 'out', ['DMV', 'MOTOR VEHICLE', 'MOTOR VEHICLES', 'TAG OFFICE', 'VEHICLE REGISTRATION']],
        'repairs' => [66, 'out', ['HARDWARE', 'PLUMBING', 'ELECTRIC SUPPLY', 'LUMBER', 'HOME CENTER', 'HOME IMPROVEMENT', 'PAINT', 'LOCKSMITH',
            'HVAC', 'ROOFING', 'APPLIANCE REPAIR', 'HANDYMAN', 'MAINTENANCE']],
        'supplies' => [62, 'out', ['OFFICE SUPPLY', 'OFFICE SUPPLIES', 'OFFICE', 'SUPPLY', 'SUPPLIES', 'STATIONERY', 'PRINTING', 'PRINT SHOP', 'COPY', 'DOLLAR',
            'DOLLARTREE']],
        'postage' => [74, 'out', ['POSTAL', 'POST OFFICE', 'STAMPS', 'POSTAGE']],
        'shipping' => [74, 'out', ['SHIPPING', 'FREIGHT', 'COURIER', 'FEDEX', 'DHL', 'SHIP']],
        'software' => [66, 'out', ['SOFTWARE', 'SUBSCRIPTION', 'SAAS', 'APP STORE', 'APPS', 'CLOUD', 'ONLINE SERVICES']],
        'web-hosting' => [74, 'out', ['HOSTING', 'DOMAIN', 'DOMAINS', 'WEBSITE', 'WEB SERVICES', 'WEB HOSTING']],
        'phone' => [74, 'out', ['WIRELESS', 'MOBILE', 'CELLULAR', 'CELL PHONE', 'PHONE', 'TELECOM', 'TELEPHONE']],
        'internet' => [74, 'out', ['INTERNET', 'BROADBAND', 'FIBER', 'CABLE TV']],
        'electricity' => [74, 'out', ['ELECTRIC', 'ELECTRICITY', 'ENERGY', 'POWER', 'UTILITIES', 'UTILITY']],
        'natural-gas' => [74, 'out', ['NATURAL GAS', 'GAS COMPANY', 'GAS CO', 'GAS UTILITY']],
        'water' => [74, 'out', ['WATER', 'SEWER', 'MUD', 'UTILITY DISTRICT', 'WATER DISTRICT']],
        'trash' => [74, 'out', ['WASTE', 'TRASH', 'DISPOSAL', 'RECYCLING', 'SANITATION']],
        'insurance' => [74, 'out', ['INSURANCE', 'INSUR', 'ASSURANCE', 'MUTUAL', 'UNDERWRITERS', 'INS CO']],
        'health-insurance' => [74, 'out', ['HEALTH PLAN', 'HEALTH INSURANCE', 'BLUE CROSS', 'BCBS', 'DENTAL PLAN', 'VISION PLAN']],
        'auto-insurance' => [74, 'out', ['AUTO INSURANCE', 'CAR INSURANCE', 'TRUCK INSURANCE', 'COMMERCIAL AUTO']],
        'accounting' => [74, 'out', ['CPA', 'CPAS', 'ACCOUNTING', 'BOOKKEEPING', 'TAX SERVICE', 'TAX SERVICES', 'TAX PREP']],
        'legal' => [74, 'out', ['LAW', 'LAWYER', 'LAWYERS', 'ATTORNEY', 'ATTORNEYS', 'LEGAL', 'LAW FIRM', 'LAW OFFICE', 'LAW OFFICES']],
        'professional-services' => [62, 'out', ['CONSULTING', 'CONSULTANTS', 'CONSULTANT', 'ADVISORS']],
        'advertising' => [74, 'out', ['ADVERTISING', 'MARKETING', 'ADS', 'PROMOTIONS', 'SIGNS', 'SIGNAGE', 'BILLBOARD']],
        'training' => [74, 'out', ['SCHOOL', 'ACADEMY', 'UNIVERSITY', 'COLLEGE', 'INSTITUTE', 'TRAINING', 'COURSE', 'COURSES', 'TUITION',
            'SEMINAR', 'CERTIFICATION', 'CDL']],
        'charity' => [74, 'out', ['CHURCH', 'MOSQUE', 'MASJID', 'TEMPLE', 'DONATION', 'DONATIONS', 'DONATE', 'FOUNDATION', 'CHARITY', 'CHARITIES', 'RELIEF FUND']],
        'licenses' => [66, 'out', ['PERMIT', 'PERMITS', 'LICENSE', 'LICENSING', 'CITY OF', 'COUNTY OF', 'STATE OF', 'COMPTROLLER', 'SECRETARY OF STATE']],
        'rent' => [66, 'out', ['RENT', 'LEASE', 'LEASING', 'PROPERTY MANAGEMENT', 'APARTMENTS', 'REALTY', 'PROPERTIES']],
        'storage' => [74, 'out', ['STORAGE', 'SELF STORAGE', 'MINI STORAGE']],
        'equipment-rental' => [66, 'out', ['EQUIPMENT RENTAL', 'RENTALS', 'TRAILER RENTAL', 'TRUCK RENTAL']],
        'payroll' => [66, 'out', ['PAYROLL', 'SALARY', 'WAGES']],
        'bank-fees' => [74, 'any', ['SERVICE CHARGE', 'SERVICE FEE', 'OVERDRAFT', 'NSF', 'MAINTENANCE FEE', 'WIRE FEE', 'ATM FEE', 'FEE',
            'CASH DEPOSIT PROCESSING']],
        'merchant-fees' => [70, 'out', ['MERCHANT SERVICES', 'MERCHANT SERVICE', 'MERCHANT FEE', 'MERCHANT FEES', 'MERCH FEE', 'DISCOUNT FEE',
            'PROCESSING FEE', 'CARD PROCESSING']],
        'sales-tax' => [66, 'out', ['SALES TAX', 'SALES AND USE TAX', 'WEBFILE', 'WEBFILE TAX']],
        'card-payment' => [66, 'any', ['CARD PAYMENT', 'CARD PMT', 'CRCARDPMT', 'CREDIT CARD', 'CREDIT CRD', 'CRD EPAY', 'BARCLAYCARD', 'MC VISA',
            'CARD AUTOPAY', 'PAYMENT THANK YOU']],
        'interest-expense' => [74, 'out', ['INTEREST CHARGE', 'FINANCE CHARGE', 'INTEREST']],
        'personal' => [62, 'out', ['SALON', 'SPA', 'NAIL', 'NAILS', 'BARBER', 'BARBERSHOP', 'BEAUTY', 'COSMETICS', 'PHARMACY', 'DRUG STORE', 'FITNESS',
            'GYM', 'CLOTHING', 'APPAREL', 'FASHION', 'BOUTIQUE', 'SHOES', 'JEWELRY', 'DENTIST', 'DENTAL', 'MEDICAL', 'CLINIC', 'PEDIATRICS']],
        // Cash taken out is usually the owner's, and a bounced payment is usually a bank charge; both are only guesses
        'personal|cash' => [55, 'out', ['ATM WITHDRAWAL', 'CASH WITHDRAWAL', 'ATM WITHDRWL', 'ATM W/D', 'COUNTER WITHDRAWAL']],
        'bank-fees|returns' => [55, 'out', ['RETURNED ITEM', 'RETURN ITEM', 'CHARGEBACK', 'CHARGE BACK', 'RETURNED CHECK', 'RETURNED DEPOSIT']],
        'entertainment' => [66, 'out', ['CINEMA', 'CINEMAS', 'THEATER', 'THEATRE', 'MOVIES', 'BOWLING', 'ARCADE', 'GOLF', 'CONCERT', 'TICKETS',
            'AMUSEMENT', 'ZOO', 'MUSEUM', 'WATER PARK', 'THEME PARK']],
        'loan-payment' => [60, 'out', ['LOAN', 'LOAN PAYMENT', 'LOAN PMT', 'MORTGAGE', 'AUTO LOAN', 'FINANCING']],
        'transfer' => [74, 'any', ['TRANSFER', 'XFER', 'TO SAVINGS', 'FROM SAVINGS', 'TO CHECKING', 'FROM CHECKING']],
        'sales' => [60, 'in', ['DEPOSIT', 'MOBILE DEPOSIT', 'REMOTE DEPOSIT', 'COUNTER CREDIT', 'BRANCH DEPOSIT', 'ATM DEPOSIT',
            'MERCHANT DEPOSIT', 'SETTLEMENT', 'PAYOUT', 'CUSTOMER PAYMENT', 'INVOICE PAYMENT', 'MERCHANT SERVICES', 'MERCHANT SERVICE']],
        'interest-income' => [74, 'in', ['INTEREST', 'INTEREST PAID', 'INTEREST EARNED', 'DIVIDEND']],
    ];

    /** A payee categorized the same way this many times (and never differently) is posted without asking. */
    private const HISTORY_SURE = 2;

    /**
     * @param  array<string, array{id: int, name: string, account_id: ?int, needs_review: bool}>  $types  by slug, for this company
     * @param  array<string, array<int, array{n: int, category_type_id: ?int}>>  $history  "KEYWORD|direction" => account id => times chosen
     */
    public function __construct(private array $types, private array $history, private array $typeNames = [])
    {
    }

    /** @return array{account_id: int, category_type_id: ?int, type: ?string, method: string, confidence: int, needs_review: bool}|null */
    public function guess(string $description, string $direction, int $statementAccountId): ?array
    {
        return $this->fromHistory($description, $direction, $statementAccountId)
            ?? $this->fromWords($description, $direction, $statementAccountId);
    }

    private function fromHistory(string $description, string $direction, int $statementAccountId): ?array
    {
        $choices = $this->history[Categorizer::keywordFor($description).'|'.$direction] ?? null;
        if (! $choices) {
            return null;
        }
        uasort($choices, fn ($a, $b) => $b['n'] <=> $a['n']);
        $accountId = (int) array_key_first($choices);
        if ($accountId === $statementAccountId) {
            return null;
        }
        $times = $choices[$accountId]['n'];
        $sure = count($choices) === 1 && $times >= self::HISTORY_SURE;
        $typeId = $choices[$accountId]['category_type_id'];

        return [
            'account_id' => $accountId,
            'category_type_id' => $typeId,
            'type' => $typeId ? ($this->typeNames[$typeId] ?? null) : null,
            'method' => 'history',
            'confidence' => $sure ? 88 : 65,
            'needs_review' => ! $sure,
        ];
    }

    private function fromWords(string $description, string $direction, int $statementAccountId): ?array
    {
        $words = ' '.Categorizer::normalize($description).' ';
        $best = null;
        foreach (self::PATTERNS as $key => [$confidence, $patternDirection, $phrases]) {
            // "slug|note" keys give one category a second, weaker set of words (always sent for checking)
            $slug = explode('|', $key)[0];
            if (($patternDirection !== 'any' && $patternDirection !== $direction) || ! isset($this->types[$slug])) {
                continue;
            }
            foreach ($phrases as $phrase) {
                if (str_contains($words, ' '.$phrase.' ') && (! $best || strlen($phrase) > strlen($best['phrase']))) {
                    $best = ['slug' => $slug, 'phrase' => $phrase, 'confidence' => $confidence];
                }
            }
        }
        if (! $best) {
            return null;
        }

        $type = $this->types[$best['slug']];
        if (! $type['account_id'] || $type['account_id'] === $statementAccountId) {
            return null;
        }

        return [
            'account_id' => $type['account_id'],
            'category_type_id' => $type['id'],
            'type' => $type['name'],
            'method' => 'smart',
            'confidence' => $best['confidence'],
            'needs_review' => $type['needs_review'],
        ];
    }
}
