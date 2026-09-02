<?php

namespace App\Services;

use App\Models\Farm;
use App\Models\FarmSetting;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    /**
     * @param  array<int, array{description: string, quantity: int|float, unit_price: float|int}>  $items
     * @return array{subtotal: float, tax_amount: float, total: float, items: array<int, array{description: string, quantity: int, unit_price: float, total: float}>}
     */
    public function calculateTotals(Farm $farm, array $items): array
    {
        $settings = FarmSetting::firstOrCreate(['farm_id' => $farm->id]);
        $normalizedItems = [];
        $subtotal = 0.0;

        foreach ($items as $item) {
            $quantity = (int) $item['quantity'];
            $unitPrice = round((float) $item['unit_price'], 2);
            $lineTotal = round($quantity * $unitPrice, 2);
            $subtotal += $lineTotal;

            $normalizedItems[] = [
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => $lineTotal,
            ];
        }

        $subtotal = round($subtotal, 2);
        $taxAmount = 0.0;
        if ($settings->invoice_tax_enabled) {
            $taxAmount = round($subtotal * ((float) $settings->invoice_tax_rate / 100), 2);
        }

        return [
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => round($subtotal + $taxAmount, 2),
            'items' => $normalizedItems,
        ];
    }

    public function nextInvoiceNumber(Farm $farm): string
    {
        return DB::transaction(function () use ($farm) {
            $settings = FarmSetting::lockForUpdate()->firstOrCreate(['farm_id' => $farm->id]);
            $number = (int) $settings->invoice_next_number;
            $prefix = $settings->invoice_prefix ?: 'INV';
            $invoiceNumber = sprintf('%s-%s', $prefix, str_pad((string) $number, 4, '0', STR_PAD_LEFT));

            $settings->invoice_next_number = $number + 1;
            $settings->save();

            return $invoiceNumber;
        });
    }

    public function findForFarm(Farm $farm, int $invoiceId): Invoice
    {
        return Invoice::where('farm_id', $farm->id)
            ->with(['customer', 'items'])
            ->findOrFail($invoiceId);
    }
}
