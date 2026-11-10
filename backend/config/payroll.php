<?php

use App\Payroll\Providers\GenericCsvProvider;

return [
    // Classes implementing App\Payroll\Providers\PayrollProvider. Real provider adapters are added here.
    'providers' => [GenericCsvProvider::class],
    'max_approval_levels' => 3,
];
