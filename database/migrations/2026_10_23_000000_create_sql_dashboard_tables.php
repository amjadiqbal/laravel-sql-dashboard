<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('sql-dashboard.storage_connection');
    }

    public function up(): void
    {
        Schema::create('sql_dashboard_queries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('statement');
            $table->json('variables')->nullable();
            $table->string('chart', 16)->default('auto');
            $table->boolean('shared')->default(true);
            $table->string('owner_id')->nullable()->index();
            $table->string('owner_label')->nullable();
            $table->timestamps();
        });

        Schema::create('sql_dashboard_dashboards', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('shared')->default(true);
            $table->string('owner_id')->nullable()->index();
            $table->string('owner_label')->nullable();
            $table->timestamps();
        });

        Schema::create('sql_dashboard_dashboard_query', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_id')->constrained('sql_dashboard_dashboards')->cascadeOnDelete();
            $table->foreignId('query_id')->constrained('sql_dashboard_queries')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
        });

        Schema::create('sql_dashboard_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_id')->constrained('sql_dashboard_queries')->cascadeOnDelete();
            $table->string('name');
            $table->string('interval', 32)->default('hourly');
            $table->string('condition', 16)->default('rows');
            $table->string('threshold_column')->nullable();
            $table->string('operator', 4)->nullable();
            $table->string('threshold_value')->nullable();
            $table->text('mail_to')->nullable();
            $table->boolean('slack')->default(false);
            $table->string('state', 16)->default('unknown');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_changed_at')->nullable();
            $table->text('last_message')->nullable();
            $table->string('owner_id')->nullable();
            $table->string('owner_label')->nullable();
            $table->timestamps();
        });

        Schema::create('sql_dashboard_audits', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->nullable()->index();
            $table->string('user_label')->nullable();
            $table->unsignedBigInteger('query_id')->nullable()->index();
            $table->string('source', 16);
            $table->string('connection')->nullable();
            $table->text('statement');
            $table->json('variables')->nullable();
            $table->string('status', 16);
            $table->unsignedInteger('row_count')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('message')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sql_dashboard_audits');
        Schema::dropIfExists('sql_dashboard_checks');
        Schema::dropIfExists('sql_dashboard_dashboard_query');
        Schema::dropIfExists('sql_dashboard_dashboards');
        Schema::dropIfExists('sql_dashboard_queries');
    }
};
