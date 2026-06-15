<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fixture migration for EnforceBelongsToTenantRuleTest: a table WITHOUT tenant_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gadgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
};
