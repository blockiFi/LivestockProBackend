<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Farm;
use App\Models\FlockSale;
use App\Models\Invoice;
use App\Models\SalesRecord;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class CustomerService
{
    public function summary(Customer $customer): array
    {
        $productSalesQuery = SalesRecord::where('customer_id', $customer->id);
        $flockSalesQuery = FlockSale::where('customer_id', $customer->id);
        $invoiceQuery = Invoice::where('customer_id', $customer->id);

        $productRevenue = (float) $productSalesQuery->sum('total_amount');
        $flockRevenue = (float) $flockSalesQuery->sum('total_amount');
        $invoiceTotal = (float) $invoiceQuery->sum('total');

        $lastProductDate = $productSalesQuery->max('date');
        $lastFlockDate = $flockSalesQuery->max('date');
        $lastInvoiceDate = $invoiceQuery->max('invoice_date');

        $dates = collect([$lastProductDate, $lastFlockDate, $lastInvoiceDate])
            ->filter()
            ->map(fn ($date) => Carbon::parse($date));

        return [
            'product_sale_count' => (int) $productSalesQuery->count(),
            'flock_sale_count' => (int) $flockSalesQuery->count(),
            'invoice_count' => (int) $invoiceQuery->count(),
            'product_revenue' => round($productRevenue, 2),
            'flock_revenue' => round($flockRevenue, 2),
            'invoice_total' => round($invoiceTotal, 2),
            'total_revenue' => round($productRevenue + $flockRevenue, 2),
            'last_purchase_at' => $dates->max()?->toDateString(),
        ];
    }

    public function history(Customer $customer, ?string $type = null, int $perPage = 15): LengthAwarePaginator
    {
        $items = collect();

        if (! $type || $type === 'product') {
            SalesRecord::with(['flock:id,name,batch_number'])
                ->where('customer_id', $customer->id)
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get()
                ->each(function (SalesRecord $record) use ($items) {
                    $items->push([
                        'type' => 'product',
                        'id' => $record->id,
                        'date' => $record->date?->toDateString(),
                        'description' => ucfirst($record->type).' sale'.($record->flock ? ' · '.$record->flock->name : ''),
                        'amount' => (float) $record->total_amount,
                        'meta' => [
                            'sale_type' => $record->type,
                            'flock_id' => $record->flock_id,
                            'payment_status' => $record->payment_status,
                        ],
                    ]);
                });
        }

        if (! $type || $type === 'flock') {
            FlockSale::with(['flock:id,name,batch_number'])
                ->where('customer_id', $customer->id)
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get()
                ->each(function (FlockSale $sale) use ($items) {
                    $items->push([
                        'type' => 'flock',
                        'id' => $sale->id,
                        'date' => $sale->date?->toDateString(),
                        'description' => 'Live bird sale'.($sale->flock ? ' · '.$sale->flock->name : ''),
                        'amount' => (float) $sale->total_amount,
                        'meta' => [
                            'flock_id' => $sale->flock_id,
                            'quantity' => $sale->quantity,
                        ],
                    ]);
                });
        }

        if (! $type || $type === 'invoice') {
            Invoice::where('customer_id', $customer->id)
                ->orderByDesc('invoice_date')
                ->orderByDesc('id')
                ->get()
                ->each(function (Invoice $invoice) use ($items) {
                    $items->push([
                        'type' => 'invoice',
                        'id' => $invoice->id,
                        'date' => $invoice->invoice_date?->toDateString(),
                        'description' => 'Invoice '.$invoice->invoice_number,
                        'amount' => (float) $invoice->total,
                        'meta' => [
                            'status' => $invoice->status,
                            'invoice_number' => $invoice->invoice_number,
                        ],
                    ]);
                });
        }

        $sorted = $items->sortByDesc(fn ($item) => $item['date'] ?? '')->values();
        $page = max(1, (int) request()->input('page', 1));
        $offset = ($page - 1) * $perPage;
        $slice = $sorted->slice($offset, $perPage)->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $slice,
            $sorted->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    public function listForFarm(Farm $farm, ?string $search = null, ?bool $activeOnly = null)
    {
        $query = Customer::with('country:id,name,iso_code')
            ->where('farm_id', $farm->id)
            ->orderBy('name');

        if ($search) {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('company_name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%');
            });
        }

        if ($activeOnly === true) {
            $query->where('is_active', true);
        } elseif ($activeOnly === false) {
            $query->where('is_active', false);
        }

        return $query->get();
    }
}
