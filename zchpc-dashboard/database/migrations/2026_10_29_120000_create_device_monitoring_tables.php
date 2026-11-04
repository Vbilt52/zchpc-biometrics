<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive device-monitoring storage. Every statement adds a table or a
 * nullable/defaulted column, so existing terminals, personnel, attendance and
 * access-event rows are preserved and the previous application version can
 * still read the terminals table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terminals', function (Blueprint $table): void {
            $table->string('status', 16)->default('unknown')->after('model')->index();
            $table->string('status_source', 16)->nullable()->after('status');
            $table->timestamp('last_checked_at')->nullable()->after('status_source');
            $table->timestamp('last_seen_at')->nullable()->after('last_checked_at');
            $table->timestamp('last_error_at')->nullable()->after('last_seen_at');
            $table->timestamp('last_reconnect_at')->nullable()->after('last_error_at');
            $table->text('last_error')->nullable()->after('last_reconnect_at');
            $table->unsignedInteger('last_latency_ms')->nullable()->after('last_error');
            $table->unsignedInteger('consecutive_failures')->default(0)->after('last_latency_ms');
        });

        Schema::table('users', function (Blueprint $table): void {
            // Existing accounts keep full access: administrators remain
            // administrators. Roles only ever narrow access for accounts an
            // administrator deliberately changes.
            $table->string('role', 16)->default('admin')->after('username')->index();
        });

        Schema::create('device_monitor_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('interval_seconds')->default(60);
            $table->unsignedInteger('alert_cooldown_minutes')->default(15);
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('updated_by_name')->nullable();
            $table->timestamps();
        });

        Schema::create('device_refresh_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 16);
            $table->string('status', 16)->default('queued')->index();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('completed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('cancelled')->default(0);
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_name')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_refresh_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_refresh_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('terminal_id')->nullable()->index();
            $table->string('terminal_name');
            $table->string('status', 16)->default('pending')->index();
            $table->text('message')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_alerts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('terminal_id')->nullable()->index();
            $table->string('terminal_name')->nullable();
            $table->string('type', 40)->index();
            $table->string('severity', 16)->default('warning')->index();
            $table->string('title');
            $table->text('message')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_occurred_at')->nullable();
            $table->timestamp('last_occurred_at')->nullable()->index();
            $table->timestamp('acknowledged_at')->nullable()->index();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->string('acknowledged_by_name')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('device_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('terminal_id')->nullable()->index();
            $table->string('terminal_name')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_name')->nullable();
            $table->string('action', 40)->index();
            $table->string('outcome', 16)->default('info');
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_activity_logs');
        Schema::dropIfExists('device_alerts');
        Schema::dropIfExists('device_refresh_items');
        Schema::dropIfExists('device_refresh_runs');
        Schema::dropIfExists('device_monitor_settings');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });

        Schema::table('terminals', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn([
                'status', 'status_source', 'last_checked_at', 'last_seen_at',
                'last_error_at', 'last_reconnect_at', 'last_error',
                'last_latency_ms', 'consecutive_failures',
            ]);
        });
    }
};
