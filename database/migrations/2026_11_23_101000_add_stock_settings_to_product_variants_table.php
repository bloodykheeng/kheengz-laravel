<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per variant: whether its stock is counted at all (made to order, or fresh produce sold from what
     * came in today, is not), and from how many the shop is told it is running low. docs/stock.md.
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->boolean('track_stock')->default(true)->after('is_active');
            $table->decimal('low_stock_at', 12, 3)->nullable()->after('track_stock');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['track_stock', 'low_stock_at']);
        });
    }
};
