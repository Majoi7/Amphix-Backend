<?php

namespace Database\Seeders;

use App\Models\Course;
use Illuminate\Database\Seeder;

class CourseSeeder extends Seeder
{
    public function run(): void
    {
        Course::updateOrCreate(
            ['title' => 'Formation Amphix Test'],
            [
                'description' => 'Formation de test pour valider le flux de paiement AMPHIX.',
                'price' => 1000,
                'currency' => 'XOF',
                'image' => null,
                'status' => 'active',
            ]
        );
    }
}
