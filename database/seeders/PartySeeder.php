<?php

namespace Database\Seeders;

use App\Models\Party;
use Illuminate\Database\Seeder;

class PartySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Same record POS and Daily Book fall back to when no customer is
        // picked — see Party::walkIn().
        Party::walkIn();

        Party::firstOrCreate(
            ['phone' => '01700000002'],
            [
                'name' => 'Default Supplier',
                'is_customer' => false,
                'is_supplier' => true,
                'status' => true,
            ]
        );
    }
}
