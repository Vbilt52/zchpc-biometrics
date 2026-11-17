<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_events', fn (Blueprint $table) => $table->index(['employee_no', 'occurred_at'], 'access_events_employee_time'));
        Schema::table('access_audit_logs', fn (Blueprint $table) => $table->index('created_at', 'access_audit_created_at'));
    }

    public function down(): void
    {
        Schema::table('access_events', fn (Blueprint $table) => $table->dropIndex('access_events_employee_time'));
        Schema::table('access_audit_logs', fn (Blueprint $table) => $table->dropIndex('access_audit_created_at'));
    }
};
