<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fixture for MigrationResolver tests: the table is created under an explicit
// schema (e.g. SQL Server's "admin" schema), exactly like a real app might.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin.users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin.users');
    }
};
