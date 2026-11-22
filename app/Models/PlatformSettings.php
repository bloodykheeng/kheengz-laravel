<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The installation's money rules and payment switches: one row, read through current().
 *
 * @property int $commission_bps
 * @property int $fixed_fee_per_order
 * @property int $service_fee_per_order
 * @property int $debt_limit_amount
 * @property int $debt_limit_days
 * @property bool $marzpay_enabled
 * @property bool $olycash_enabled
 * @property bool $card_enabled
 * @property bool $pay_on_delivery_enabled
 */
class PlatformSettings extends Model implements HasSnapshotSchema
{
    use HasUlids;

    protected $table = 'platform_settings';

    protected $fillable = [
        'commission_bps',
        'fixed_fee_per_order',
        'service_fee_per_order',
        'debt_limit_amount',
        'debt_limit_days',
        'marzpay_enabled',
        'olycash_enabled',
        'card_enabled',
        'pay_on_delivery_enabled',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'commission_bps' => 'integer',
            'fixed_fee_per_order' => 'integer',
            'service_fee_per_order' => 'integer',
            'debt_limit_amount' => 'integer',
            'debt_limit_days' => 'integer',
            'marzpay_enabled' => 'boolean',
            'olycash_enabled' => 'boolean',
            'card_enabled' => 'boolean',
            'pay_on_delivery_enabled' => 'boolean',
        ];
    }

    /**
     * The one row. Made with the column defaults the first time it is asked for (and by the seeder),
     * so a fresh install always has rules to apply.
     */
    public static function current(): self
    {
        // Read back after creating: the defaults live in the columns, and a model made with no
        // attributes would otherwise answer null for every one of them.
        return self::query()->oldest()->first() ?? self::query()->create()->refresh();
    }

    /** @return array<string, array<string, mixed>> */
    public static function snapshotSchema(): array
    {
        return [
            'commission_bps' => ['label' => 'Commission (basis points)', 'type' => 'number'],
            'fixed_fee_per_order' => ['label' => 'Fixed fee per order (UGX)', 'type' => 'number'],
            'service_fee_per_order' => ['label' => 'Service fee per order (UGX)', 'type' => 'number'],
            'debt_limit_amount' => ['label' => 'Debt limit (UGX)', 'type' => 'number'],
            'debt_limit_days' => ['label' => 'Debt limit (days)', 'type' => 'number'],
            'marzpay_enabled' => ['label' => 'MarzPay on', 'type' => 'boolean'],
            'olycash_enabled' => ['label' => 'OlyCash on', 'type' => 'boolean'],
            'card_enabled' => ['label' => 'Card on', 'type' => 'boolean'],
            'pay_on_delivery_enabled' => ['label' => 'Pay on delivery on', 'type' => 'boolean'],
        ];
    }
}
