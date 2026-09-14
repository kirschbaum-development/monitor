<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('monitor.records.store.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->string('run_id', 26)->unique();
            $table->string('parent_run_id', 26)->nullable()->index();
            $table->string('trace_id', 32)->index();
            $table->string('point', 191)->index();
            $table->string('domain', 191)->index();
            $table->string('origin', 255);
            $table->string('profile', 64)->nullable();
            $table->string('status', 16)->index();
            $table->string('recovered_from', 255)->nullable();
            $table->string('exception_class', 255)->nullable();
            $table->text('exception_message')->nullable();
            $table->unsignedInteger('attempts');
            $table->decimal('duration_ms', 14, 3);
            $table->json('stack');
            $table->json('context');
            $table->json('limits_breached');
            $table->json('policies');
            $table->json('timeline');
            $table->timestamp('started_at', 3);
            $table->timestamp('ended_at', 3)->index();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists($this->table());
    }

    private function table(): string
    {
        $table = config('monitor.records.store.table');

        return is_string($table) && $table !== '' ? $table : 'monitor_outcomes';
    }
};
