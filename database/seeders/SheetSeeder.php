<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use App\Models\User;
use App\Models\Sheet;
use App\Models\SheetData;
use Illuminate\Support\Facades\Hash;

class SheetSeeder extends Seeder
{
    public function run()
    {
        // Clear existing data
        SheetData::truncate();
        Sheet::truncate();
        User::where('email', 'admin@admin.com')->delete();

        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@admin.com',
            'password' => Hash::make('password'),
        ]);

        $sheetNames = [
            "ЧСК Точиктелеком",
            "Филиали ВМКБ",
            "Филиали Сугд",
            "филиали Маркази",
            "Филиали ШЧТМ",
            "Филиали Хатлон",
            "ҶДММ \"Композит Т.А.\"",
            "ООО \"Азия Кемикал\" (копия)",
            "ООО \"Весто Эдукейшн\"",
            "Проект \"Сити Кард\" (копия)",
            "Проект \"Сити Карт\"",
            "ООО \"Филиззот\" (копия)",
            "ООО\"Филиззот\"",
            "ООО \"КМ Муосир\""
        ];

        foreach ($sheetNames as $index => $name) {
            $sheet = Sheet::create([
                'name' => $name,
                'user_id' => $user->id,
                'order' => $index
            ]);

            // Add some dummy data to the first sheet for demo
            if ($index === 0) {
                $vacancies = [
                    ['title' => 'Frontend Developer', 'department' => 'IT', 'salary' => '150 000', 'status' => 'Active', 'date' => '2026-04-24'],
                    ['title' => 'Backend Developer', 'department' => 'IT', 'salary' => '170 000', 'status' => 'Draft', 'date' => '2026-04-20'],
                ];
                foreach ($vacancies as $vIndex => $data) {
                    SheetData::create([
                        'sheet_id' => $sheet->id,
                        'row_data' => $data,
                        'row_index' => $vIndex
                    ]);
                }
            }
        }
    }
}
