<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->timestamp('featured_at')->nullable()->after('is_featured')->index();
        });

        DB::table('courses')
            ->where('is_featured', true)
            ->whereNull('featured_at')
            ->update([
                'featured_at' => DB::raw('coalesce(updated_at, published_at, created_at, now())'),
            ]);
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropIndex(['featured_at']);
            $table->dropColumn('featured_at');
        });
    }
};
