<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last counter handed out under each number stem, one row per series and period,
 * readable on its own:
 *
 *   table_name   column_name    stem        last_value
 *   orders       order_number   KZ112026    41    (next: KZ11202642)
 *   payouts      reference      PO112026    7     (next: PO1120268)
 *
 * App\Support\SerialCounter raises a row under a row lock, so two saves at the same moment
 * never get the same number, and a counter never goes back: a number whose record was
 * deleted is never handed out again (docs/reference-numbers.md). Keyed by table, column
 * and stem together: a stem can hold free text, so two series could spell the same stem.
 *
 * No foreign keys: it names tables, it does not point at rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serial_counters', function (Blueprint $table) {
            $table->string('table_name', 64)->comment('The table whose records carry these numbers');
            $table->string('column_name', 64)->comment('The column that holds the number');
            $table->string('stem', 50)->comment('The fixed part before the counter, e.g. TX112026');
            $table->unsignedBigInteger('last_value')->comment('The last counter handed out; the next number uses last_value + 1');

            $table->timestamps();

            $table->primary(['table_name', 'column_name', 'stem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serial_counters');
    }
};
