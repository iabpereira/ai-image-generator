<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_id')->nullable()->unique();
            $table->string('status')->index();
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');
            $table->bigInteger('estimated_charge')->comment('nanodollars, typical charge shown before submit');
            $table->bigInteger('maximum_charge')->comment('nanodollars, worst-case charge');
            $table->bigInteger('provider_cost')->nullable()->comment('nanodollars Pixelforge billed us');
            $table->bigInteger('charged_amount')->nullable()->comment('nanodollars charged to the user');
            $table->json('rates')->nullable();
            $table->json('usage')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('image_path')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('submission_attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'completed_at']);
            $table->index(['user_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
