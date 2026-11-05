<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Small settings kept as JSON in `_mealboard_settings` (key -> value): invoice details, the daily order list,
 * logs. Same table and keys as the PHP API.
 */
class Settings
{
    public static function get(string $key): array
    {
        $value = DB::table('_mealboard_settings')->where('key', $key)->value('value');
        $decoded = $value === null ? null : json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function put(string $key, array $value): void
    {
        DB::table('_mealboard_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value, JSON_UNESCAPED_UNICODE)]);
    }

    /** Contractor, company, currency, VAT and payment days used on invoices and penalties. */
    public static function invoice(): array
    {
        return array_merge([
            'contractorName' => '',
            'companyName' => 'LDM Groupe',
            'contractorAddress' => '',
            'companyAddress' => '',
            'contractorEmail' => '',
            'companyEmail' => '',
            'currency' => 'DZD',
            'vat' => 19.0,
            'paymentDays' => 30,
        ], self::get('invoice'));
    }

    /** The one row of `price_of_meal`; missing columns read as 0. */
    public static function prices(): array
    {
        $row = (array) (DB::table('price_of_meal')->first() ?? []);
        $read = fn (string $column) => (float) ($row[$column] ?? 0);
        return [
            'meals' => ['breakfast' => $read('brakfast'), 'lunch' => $read('lunch'), 'dinner' => $read('dinner')],
            'specials' => [
                'enhanced' => $read('Enhanced_meal'),
                'vip' => $read('vip'),
                'special' => $read('Special_meal'),
                'snacks' => $read('Snacks'),
                'vip_snacks' => $read('VIP_snacks'),
            ],
            'guest' => $read('Guest_meal'),
            'sandwich' => $read('Sandwich'),
            'id' => $row['id'] ?? null,
        ];
    }

    /** "1,234.50 DZD" */
    public static function money(float $amount, ?string $currency = null): string
    {
        return number_format($amount, 2, '.', ',') . ' ' . ($currency ?? self::invoice()['currency']);
    }
}
