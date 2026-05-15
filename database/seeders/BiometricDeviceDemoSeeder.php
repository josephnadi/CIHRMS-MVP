<?php

namespace Database\Seeders;

use App\Models\BiometricDevice;
use App\Models\Department;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Two demo biometric devices so the attendance UI and webhook flow
 * have something to render out-of-the-box. Shared secrets are randomly
 * generated — production must rotate these per the deployment runbook.
 */
class BiometricDeviceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $hr  = Department::where('code', 'HR')->first();
        $mkt = Department::where('code', 'MKT')->first();

        BiometricDevice::updateOrCreate(
            ['code' => 'DEV-ACCRA-MAIN-01'],
            [
                'name'          => 'Main Lobby — Accra HQ',
                'vendor'        => 'zkteco',
                'location'      => 'Accra Headquarters, Ground Floor',
                'department_id' => $hr?->id,
                'shared_secret' => Str::random(48),
                'geo_lat'       => 5.5560,   // Accra approx
                'geo_lng'       => -0.1969,
                'geo_radius_m'  => 100,
                'is_active'     => true,
            ],
        );

        BiometricDevice::updateOrCreate(
            ['code' => 'DEV-ACCRA-MAIN-02'],
            [
                'name'          => 'Side Entrance — Accra HQ',
                'vendor'        => 'hikvision',
                'location'      => 'Accra Headquarters, Side Entrance',
                'department_id' => $mkt?->id,
                'shared_secret' => Str::random(48),
                'geo_lat'       => 5.5562,
                'geo_lng'       => -0.1971,
                'geo_radius_m'  => 80,
                'is_active'     => true,
            ],
        );
    }
}
