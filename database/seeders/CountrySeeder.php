<?php
namespace Database\Seeders;
use App\Domain\Country\CountryConfiguration;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
class CountrySeeder extends Seeder
{
    public function run(): void
    {
        foreach (CountryConfiguration::supported() as $country) {
            DB::table('countries')->updateOrInsert(['code' => $country->code], [
                'name' => $country->name, 'currency_code' => $country->currency,
                'minor_unit_digits' => $country->minorUnitDigits, 'locale' => $country->locale,
                'timezone' => $country->timezone, 'is_enabled' => true,
                'updated_at' => now(), 'created_at' => now(),
            ]);
        }
    }
}
