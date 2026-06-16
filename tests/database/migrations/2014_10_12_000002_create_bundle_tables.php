<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A single migration that creates more than one table — exercises the
// "bundled migration" handling (freshing one must rebuild the whole file).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundle_one', function (Blueprint $table) {
            $table->id();
            $table->string('label');
        });

        Schema::create('bundle_two', function (Blueprint $table) {
            $table->id();
            $table->string('label');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_two');
        Schema::dropIfExists('bundle_one');
    }
};
