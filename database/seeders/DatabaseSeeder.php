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
     * There is deliberately no generic test user: the demo athlete is the one account
     * of the instance (the owner, `giarfa`, with no password until the first sign-in).
     */
    public function run(): void
    {
        $this->call(TrainingBlockSeeder::class);
    }
}
