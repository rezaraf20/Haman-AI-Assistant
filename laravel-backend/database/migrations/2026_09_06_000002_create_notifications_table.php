<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Laravel's standard notifications table (as published by
// `notifications:table`), adapted for this app's UUID user IDs instead of
// the default bigint — this is what Filament's ->databaseNotifications()
// panel feature (AdminPanelProvider) and Notification::make()->
// sendToDatabase() read/write. First real use: alerting the platform admin
// when an LLM provider auto-disables itself after repeated failures.
return new class extends Migration {
    public function up(): void {
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->uuidMorphs('notifiable');
            $t->text('data');
            $t->timestampTz('read_at')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('notifications');
    }
};
