<?php

use App\Models\Terminal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terminals', function (Blueprint $table): void {
            $table->string('integration')->default(Terminal::INTEGRATION_HIKVISION_ISAPI)->after('name');
            $table->string('model')->nullable()->after('integration');
        });
    }

    public function down(): void
    {
        Schema::table('terminals', function (Blueprint $table): void {
            $table->dropColumn(['integration', 'model']);
        });
    }
};
