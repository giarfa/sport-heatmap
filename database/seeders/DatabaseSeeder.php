<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * There is deliberately no generic test user: an account without a Strava athlete
     * id would surface on the dashboard as a second, empty athlete.
     */
    public function run(): void
    {
        $this->call(TrainingBlockSeeder::class);
    }
}
