<?php

namespace Database\Seeders;

use App\Security\RoleCatalog;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Seeds reference data only; accounts are created via `wingora:platform-user` and tenant provisioning. */
    public function run(): void
    {
        RoleCatalog::syncPlatformRoles();
        $this->call([ReferenceDataSeeder::class, CountryRuleTemplateSeeder::class]);
    }
}
