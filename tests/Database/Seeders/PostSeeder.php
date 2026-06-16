<?php

declare(strict_types=1);

namespace Kerroldj\MigrateFreshTable\Tests\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PostSeeder extends Seeder
{
    public function run(): void
    {
        $userId = DB::table('users')->value('id')
            ?? DB::table('users')->insertGetId([
                'name' => 'Seeded',
                'email' => 'seeded@example.test',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('posts')->insert([
            'user_id' => $userId,
            'title' => 'Seeded post',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
