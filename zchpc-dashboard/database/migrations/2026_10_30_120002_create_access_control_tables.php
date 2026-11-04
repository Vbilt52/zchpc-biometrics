<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Door grants, enrollment attempts and the append-only records the access
 * module is audited by. No biometric template is stored anywhere: enrollment
 * rows hold the device's verdict and metadata only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel_door', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnDelete();
            $table->foreignId('door_id')->constrained('doors')->cascadeOnDelete();
            $table->string('plan_template_no', 16)->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('granted_by_name', 80)->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['personnel_id', 'door_id']);
        });

        Schema::create('fingerprint_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->string('employee_no', 32);
            $table->string('person_name', 64);
            $table->foreignId('terminal_id')->nullable()->constrained('terminals')->nullOnDelete();
            $table->string('terminal_name', 80);
            $table->unsignedTinyInteger('finger_id');
            $table->string('status', 24)->default('pending');
            $table->text('message')->nullable();
            $table->text('device_response')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_name', 80)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['employee_no', 'terminal_id']);
            $table->index('status');
        });

        Schema::create('access_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 80)->default('System');
            $table->string('action', 48);
            $table->string('outcome', 16)->default('info');
            $table->string('subject_type', 32)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 120)->nullable();
            $table->foreignId('door_id')->nullable()->constrained('doors')->nullOnDelete();
            $table->foreignId('terminal_id')->nullable()->constrained('terminals')->nullOnDelete();
            $table->text('description')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['action', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('device_sync_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->string('employee_no', 32)->nullable();
            $table->string('person_name', 80)->nullable();
            $table->foreignId('terminal_id')->nullable()->constrained('terminals')->nullOnDelete();
            $table->string('terminal_name', 80)->default('Controller');
            $table->string('operation', 16)->default('update');
            $table->string('status', 16)->default('success');
            $table->text('message')->nullable();
            $table->string('actor_name', 80)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['terminal_id', 'created_at']);
            $table->index(['personnel_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sync_events');
        Schema::dropIfExists('access_audit_logs');
        Schema::dropIfExists('fingerprint_enrollments');
        Schema::dropIfExists('personnel_door');
    }
};
