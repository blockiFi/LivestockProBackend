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
            'payment_analysis' => $this->paymentAnalysis($customer),
        ];
    }

    /**
     * Aggregate payment status across product sales, flock sales, and invoices.
     *
     * @return array{
     *   buckets: array<string, array{count: int, amount: float}>,
     *   total_amount: float,
     *   outstanding: float,
     *   collection_rate: float,
     *   by_source: array<string, array<string, array{count: int, amount: float}>>
     * }
     */
    public function paymentAnalysis(Customer $customer): array
    {
        $emptyBucket = fn () => ['count' => 0, 'amount' => 0.0];

        $buckets = [
            'paid' => $emptyBucket(),
            'pending' => $emptyBucket(),
            'partial' => $emptyBucket(),
            'overdue' => $emptyBucket(),
        ];

        $bySource = [
            'product_sales' => [
                'paid' => $emptyBucket(),
                'pending' => $emptyBucket(),
                'partial' => $emptyBucket(),
            ],
            'flock_sales' => [
                'paid' => $emptyBucket(),
            ],
            'invoices' => [
                'paid' => $emptyBucket(),
                'pending' => $emptyBucket(),
                'partial' => $emptyBucket(),
                'overdue' => $emptyBucket(),
            ],
        ];

        $addToBucket = function (string $status, float $amount, bool $incrementCount = true) use (&$buckets): void {
            $key = in_array($status, ['paid', 'pending', 'partial', 'overdue'], true) ? $status : 'pending';
            if ($incrementCount) {
                $buckets[$key]['count']++;
            }
            $buckets[$key]['amount'] += $amount;
        };

        $addToSource = function (string $source, string $status, float $amount, bool $incrementCount = true) use (&$bySource): void {
            if (! isset($bySource[$source][$status])) {
                return;
            }
            if ($incrementCount) {
                $bySource[$source][$status]['count']++;
            }
            $bySource[$source][$status]['amount'] += $amount;
        };

        $totalBilled = 0.0;
        $collected = 0.0;

        SalesRecord::where('customer_id', $customer->id)
            ->get(['payment_status', 'total_amount', 'amount_paid'])
            ->each(function (SalesRecord $record) use ($addToBucket, $addToSource, &$totalBilled, &$collected) {
                $total = (float) $record->total_amount;
                $paid = $this->resolveAmountPaid($total, $record->amount_paid, $record->payment_status);
                $balance = max(0, round($total - $paid, 2));
                $status = $record->payment_status ?: 'paid';

                $totalBilled += $total;
                $collected += $paid;

                if ($paid > 0) {
                    $addToBucket('paid', $paid, false);
                    $addToSource('product_sales', 'paid', $paid, false);
                }
                if ($balance > 0) {
                    $addToBucket($status, $balance);
                    $addToSource('product_sales', $status, $balance);
                }
            });

        FlockSale::where('customer_id', $customer->id)
            ->get(['total_amount'])
            ->each(function (FlockSale $sale) use ($addToBucket, $addToSource, &$totalBilled, &$collected) {
                $amount = (float) $sale->total_amount;
                $totalBilled += $amount;
                $collected += $amount;
                $addToBucket('paid', $amount);
                $addToSource('flock_sales', 'paid', $amount);
            });

        Invoice::where('customer_id', $customer->id)
            ->get(['status', 'total', 'amount_paid', 'due_date'])
            ->each(function (Invoice $invoice) use ($addToBucket, $addToSource, &$totalBilled, &$collected) {
                $total = (float) $invoice->total;
                $paid = $this->resolveAmountPaid($total, $invoice->amount_paid, $invoice->status === 'paid' ? 'paid' : null);
                $balance = max(0, round($total - $paid, 2));
                $status = $balance > 0
                    ? ($invoice->status === 'overdue' ? 'overdue' : ($paid > 0 ? 'partial' : 'pending'))
                    : 'paid';

                $totalBilled += $total;
                $collected += $paid;

                if ($paid > 0) {
                    $addToBucket('paid', $paid, false);
                    $addToSource('invoices', 'paid', $paid, false);
                }
                if ($balance > 0) {
                    $addToBucket($status, $balance);
                    $addToSource('invoices', $status, $balance);
                }
            });

        foreach ($buckets as $key => $bucket) {
            $buckets[$key]['amount'] = round($bucket['amount'], 2);
        }

        foreach ($bySource as $source => $statuses) {
            foreach ($statuses as $status => $bucket) {
                $bySource[$source][$status]['amount'] = round($bucket['amount'], 2);
            }
        }

        $totalAmount = round($totalBilled, 2);
        $outstanding = round(max(0, $totalBilled - $collected), 2);
        $collectionRate = $totalAmount > 0
            ? round(($collected / $totalAmount) * 100, 1)
            : 0.0;

        return [
            'buckets' => $buckets,
            'total_amount' => $totalAmount,
            'collected' => round($collected, 2),
            'outstanding' => $outstanding,
            'collection_rate' => $collectionRate,
            'by_source' => $bySource,
        ];
    }

    private function resolveAmountPaid(float $total, mixed $amountPaid, ?string $status): float
    {
        $paid = ($amountPaid !== null && $amountPaid !== '')
            ? round((float) $amountPaid, 2)
            : null;

        if ($status === 'paid') {
            return ($paid !== null && $paid > 0) ? min($total, $paid) : $total;
        }

        if ($paid !== null && $paid > 0) {
            return min($total, $paid);
        }

        return 0.0;
    }

    /**
     * @return array{amount_paid: float, balance_due: float}
     */
    private function paymentAmounts(float $total, mixed $amountPaid, ?string $status): array
    {
        $paid = $this->resolveAmountPaid($total, $amountPaid, $status);

        return [
            'amount_paid' => $paid,
            'balance_due' => max(0, round($total - $paid, 2)),
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
                    $payments = $this->paymentAmounts(
                        (float) $record->total_amount,
                        $record->amount_paid,
                        $record->payment_status
                    );
                    $items->push([
                        'type' => 'product',
                        'id' => $record->id,
                        'date' => $record->date?->toDateString(),
                        'description' => ucfirst($record->type).' sale'.($record->flock ? ' · '.$record->flock->name : ''),
                        'amount' => (float) $record->total_amount,
                        'amount_paid' => $payments['amount_paid'],
                        'balance_due' => $payments['balance_due'],
                        'payment_status' => $record->payment_status ?: 'paid',
                        'meta' => [
                            'sale_type' => $record->type,
                            'flock_id' => $record->flock_id,
                            'flock_name' => $record->flock?->name,
                            'batch_number' => $record->flock?->batch_number,
                            'payment_status' => $record->payment_status,
                            'payment_method' => $record->payment_method,
                            'amount_paid' => $payments['amount_paid'],
                            'balance_due' => $payments['balance_due'],
                            'quantity' => (float) $record->quantity,
                            'unit_price' => (float) $record->unit_price,
                            'notes' => $record->notes,
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
                    $total = (float) $sale->total_amount;
                    $items->push([
                        'type' => 'flock',
                        'id' => $sale->id,
                        'date' => $sale->date?->toDateString(),
                        'description' => 'Live bird sale'.($sale->flock ? ' · '.$sale->flock->name : ''),
                        'amount' => $total,
                        'amount_paid' => $total,
                        'balance_due' => 0.0,
                        'payment_status' => 'paid',
                        'meta' => [
                            'flock_id' => $sale->flock_id,
                            'flock_name' => $sale->flock?->name,
                            'batch_number' => $sale->flock?->batch_number,
                            'quantity' => (int) $sale->quantity,
                            'unit_price' => (float) $sale->unit_price,
                            'notes' => $sale->notes,
                            'customer_phone' => $sale->customer_phone,
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
                    $payments = $this->paymentAmounts(
                        (float) $invoice->total,
                        $invoice->amount_paid,
                        $invoice->status === 'paid' ? 'paid' : null
                    );
                    $status = $invoice->status ?: 'pending';
                    if ($payments['balance_due'] > 0 && $payments['amount_paid'] > 0 && $status !== 'overdue') {
                        $status = 'partial';
                    }
                    $items->push([
                        'type' => 'invoice',
                        'id' => $invoice->id,
                        'date' => $invoice->invoice_date?->toDateString(),
                        'description' => 'Invoice '.$invoice->invoice_number,
                        'amount' => (float) $invoice->total,
                        'amount_paid' => $payments['amount_paid'],
                        'balance_due' => $payments['balance_due'],
                        'payment_status' => $status,
                        'meta' => [
                            'status' => $invoice->status,
                            'invoice_number' => $invoice->invoice_number,
                            'due_date' => $invoice->due_date?->toDateString(),
                            'subtotal' => (float) $invoice->subtotal,
                            'tax_amount' => (float) $invoice->tax_amount,
                            'amount_paid' => $payments['amount_paid'],
                            'balance_due' => $payments['balance_due'],
                            'notes' => $invoice->notes,
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
