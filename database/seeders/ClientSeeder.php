<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Client::firstOrCreate(
            ['is_default' => true],
            [
                'name' => 'Default Client',
                'active' => true,
            ],
        );
    }
}
