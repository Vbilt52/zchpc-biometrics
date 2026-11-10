<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add organizational scope, account status, security flags, and audit fields to users.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'office')) {
                $table->string('office', 100)->nullable()->after('role');
            }
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('office');
            }
            if (! Schema::hasColumn('users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false)->after('is_active');
            }
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('must_change_password');
            }
            if (! Schema::hasColumn('users', 'last_login_ip')) {
                $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            }
            if (! Schema::hasColumn('users', 'permissions')) {
                $table->text('permissions')->nullable()->after('last_login_ip');
            }
        });

        // Set default office for existing accounts if empty
        DB::table('users')->whereNull('office')->orWhere('office', '')->update([
            'office' => 'ZCHPC Operations',
            'is_active' => true,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'office',
                'is_active',
                'must_change_password',
                'last_login_at',
                'last_login_ip',
                'permissions',
            ]);
        });
    }
};
