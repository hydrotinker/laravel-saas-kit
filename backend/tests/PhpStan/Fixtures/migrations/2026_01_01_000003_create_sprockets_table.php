<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fixture migration whose table name (sprockets) is matched via Eloquent's
// default naming convention, not an explicit $table on the model.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sprockets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });
    }
};
