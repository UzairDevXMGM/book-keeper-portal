<?php

/*
|--------------------------------------------------------------------------
| Business details asked for at sign-up
|--------------------------------------------------------------------------
|
| Keys are what is stored on the company; values are what the user sees.
| Industries are the 152 industries of the client's chart of accounts library
| (database/data/coa/industries.json), grouped by sector for the dropdown. Each
| one adds its own accounts to the master chart. "General" businesses get the
| master chart only.
|
*/

$industries = [];
foreach (json_decode((string) file_get_contents(__DIR__.'/../database/data/coa/industries.json'), true) ?: [] as $industry) {
    $industries[$industry['sector']][] = $industry['name'];
}
ksort($industries);
$industries['General'] = ['General Business', 'Self-Employed / Freelancer'];

return [

    'types' => [
        'sole_proprietor' => 'Sole proprietor',
        'single_member_llc' => 'Single-member LLC',
        'multi_member_llc' => 'Multi-member LLC',
        'partnership' => 'Partnership',
        's_corp' => 'S corporation',
        'c_corp' => 'C corporation',
        'nonprofit' => 'Nonprofit',
    ],

    'industries' => $industries,

    'states' => [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
        'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida',
        'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana',
        'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
        'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
        'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire',
        'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota',
        'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
        'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
        'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin',
        'WY' => 'Wyoming',
    ],

];
