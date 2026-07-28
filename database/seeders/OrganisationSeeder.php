<?php

namespace Database\Seeders;

use App\Models\Administration;
use App\Models\Company;
use App\Services\RoomProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class OrganisationSeeder extends Seeder
{
    /** company name => administrations */
    private const STRUCTURE = [
        'Oak Tree Technologies' => [
            'Engineering',
            'Product & Design',
            'IT Support',
        ],
        'Oak Tree Logistics' => [
            'Fleet Operations',
            'Warehouse',
            'Dispatch',
        ],
        'Oak Tree Facilities' => [
            'Maintenance',
            'Security',
        ],
    ];

    public function run(RoomProvisioner $rooms): void
    {
        foreach (self::STRUCTURE as $companyName => $administrations) {
            $company = Company::firstOrCreate(
                ['slug' => Str::slug($companyName)],
                ['name' => $companyName],
            );

            $rooms->ensureCompanyRoom($company);

            foreach ($administrations as $administrationName) {
                $administration = Administration::firstOrCreate(
                    ['company_id' => $company->getKey(), 'slug' => Str::slug($administrationName)],
                    ['name' => $administrationName],
                );

                $rooms->ensureAdministrationRoom($administration);
            }
        }
    }
}
