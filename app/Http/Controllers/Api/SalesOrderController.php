<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\EggStockExceeded;
use App\Exceptions\InsufficientCustomerAccountBalance;
use App\Models\Customer;
use App\Models\CustomerAccountTransaction;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\SalesOrder;
use App\Models\SalesRecord;
use App\Services\CustomerResolver;
use App\Support\EggMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Product sales drawn from one or more batches at once.
 *
 * An order has one product type, customer, date and payment. Its lines are
 * ordinary sales_records rows, one per flock, so per-flock P&L, egg stock and
 * customer balances keep reading sales_records as before. The order's payment
 * (amount paid and any customer account debit) is split across the lines in
 * proportion to each line's total.
 *
 * Extends SalesRecordController to reuse its payment-mode resolution.
 */
class SalesOrderController extends SalesRecordController
{
    private const LINE_RELATIONS = ['flock:id,name,batch_number,status'];

    public function show(Request $request, $farmId, $orderId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $this->userCanViewSales($request, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view product sales');
        }

        $order = SalesOrder::where('farm_id', $farmId)->findOrFail($orderId);

        return $this->sendResponse($this->present($order), 'Sale order retrieved successfully');
    }

    /**
     * Egg stock for every batch on the farm as of a date, so the sale form can
     * show availability per batch and offer "Sell all stock".
     */
    public function eggStockByFlock(Request $request, $farmId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $this->userCanViewSales($request, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view product sales');
        }

        $validator = Validator::make($request->all(), [
            'date' => 'nullable|date',
            'exclude_order_id' => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $asOf = $request->input('date', now()->toDateString());
        $excludeIds = $this->lineIdsForOrder((int) $farmId, $request->input('exclude_order_id'));
        $profitLoss = app(\App\Services\SalesProfitLossService::class);

        $rows = Flock::where('farm_id', $farmId)
            ->orderBy('name')
            ->get(['id', 'name', 'batch_number', 'status'])
            ->map(function (Flock $flock) use ($profitLoss, $farmId, $asOf, $excludeIds) {
                $stock = $profitLoss->computeEggStock((int) $farmId, (int) $flock->id, $asOf, $excludeIds);

                return [
                    'flock_id' => $flock->id,
                    'name' => $flock->name,
                    'batch_number' => $flock->batch_number,
                    'status' => $flock->status,
                    'produced' => $stock['produced'],
                    'sold' => $stock['sold'],
                    'available' => $stock['available'],
                    'available_crates' => round(EggMetrics::eggsToCrates($stock['available']), 2),
                    'as_of' => $stock['as_of'],
                ];
            })
            ->values();

        return $this->sendResponse($rows, 'Egg stock by batch retrieved successfully');
    }

    public function store(Request $request, $farmId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $request->user()->hasPermissionTo('create sales', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create product sales');
        }

        return $this->save($request, $farm, null);
    }

    public function update(Request $request, $farmId, $orderId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $request->user()->hasPermissionTo('update sales', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update product sales');
        }

        $order = SalesOrder::where('farm_id', $farmId)->findOrFail($orderId);

        return $this->save($request, $farm, $order);
    }

    public function destroy(Request $request, $farmId, $orderId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $request->user()->hasPermissionTo('delete sales', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to delete product sales');
        }

        $order = SalesOrder::where('farm_id', $farmId)->findOrFail($orderId);

        try {
            DB::transaction(function () use ($order, $request) {
                $this->refundAndRemoveLines($order, $request);
                $order->delete();
            });
        } catch (InvalidArgumentException $e) {
            return $this->sendValidationError($e->getMessage());
        }

        return $this->sendResponse(null, 'Sale order deleted successfully');
    }

    private function save(Request $request, Farm $farm, ?SalesOrder $existing): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:'.implode(',', SalesRecord::TYPES),
            'date' => 'required|date',
            'lines' => 'required|array|min:1|max:50',
            'lines.*.flock_id' => 'nullable|integer',
            // For type=egg: quantity is crates, unit_price is price per crate.
            'lines.*.quantity' => 'required|numeric|min:0.01',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'customer_id' => 'nullable|exists:customers,id',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'payment_method' => 'nullable|string|max:50',
            'payment_status' => 'nullable|in:pending,paid,partial',
            'payment_mode' => 'nullable|in:cash,bank_transfer,pos,other,pending,partial,customer_account,account_and_other',
            'account_amount' => 'nullable|numeric|min:0',
            'other_amount' => 'nullable|numeric|min:0',
            'other_payment_method' => 'nullable|in:cash,bank_transfer,pos,other',
            'amount_paid' => 'nullable|numeric|min:0',
            'idempotency_key' => 'nullable|string|max:150',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $farmId = (int) $farm->id;
        $type = $request->input('type');
        $date = substr((string) $request->input('date'), 0, 10);

        $lines = [];
        $lineErrors = [];
        foreach (array_values($request->input('lines')) as $index => $line) {
            $flockId = isset($line['flock_id']) && $line['flock_id'] !== '' ? (int) $line['flock_id'] : null;
            if (in_array($type, ['egg', 'meat'], true) && ! $flockId) {
                $lineErrors["lines.{$index}.flock_id"] = ['Pick a batch for each egg or meat line.'];

                continue;
            }
            $quantity = round((float) $line['quantity'], 2);
            $unitPrice = round((float) $line['unit_price'], 2);
            $lines[] = [
                'flock_id' => $flockId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => round($quantity * $unitPrice, 2),
            ];
        }

        $flockIds = array_values(array_unique(array_filter(array_column($lines, 'flock_id'))));
        $farmFlockIds = Flock::where('farm_id', $farmId)->whereIn('id', $flockIds)->pluck('id')->all();
        foreach (array_diff($flockIds, $farmFlockIds) as $missing) {
            $lineErrors['lines'][] = "Batch #{$missing} does not belong to this farm.";
        }

        if ($lineErrors !== []) {
            return $this->sendValidationError('Validation failed', $lineErrors);
        }

        $customerFields = CustomerResolver::resolveForFarm(
            $farm,
            $request->input('customer_id'),
            $request->input('customer_name'),
            $request->input('customer_phone')
        );
        if ($customerFields === null) {
            return $this->sendValidationError('Validation failed', [
                'customer_id' => ['Customer does not belong to this farm.'],
            ]);
        }

        $total = round(array_sum(array_column($lines, 'total')), 2);
        $paymentPlan = $this->resolvePaymentPlan($request, $farm, $total, $customerFields['customer_id']);
        if ($paymentPlan['error'] !== null) {
            return $paymentPlan['error'];
        }

        $lineTotals = array_column($lines, 'total');
        $paidShares = $this->allocate((float) $paymentPlan['amount_paid'], $lineTotals);
        $accountShares = $this->allocate((float) $paymentPlan['account_debit'], $lineTotals);

        try {
            $order = DB::transaction(function () use (
                $request, $farm, $farmId, $existing, $type, $date, $lines, $flockIds, $total,
                $customerFields, $paymentPlan, $paidShares, $accountShares
            ) {
                // Serialise concurrent sales on the same batches before the stock check.
                if ($flockIds !== []) {
                    Flock::whereIn('id', $flockIds)->lockForUpdate()->get(['id']);
                }

                if ($type === 'egg') {
                    $this->assertEggStock($farmId, $date, $lines, $existing);
                }

                if ($existing) {
                    $this->refundAndRemoveLines($existing, $request);
                }

                $order = $existing ?? new SalesOrder([
                    'farm_id' => $farmId,
                    'created_by' => $request->user()->id,
                ]);
                $order->fill([
                    'type' => $type,
                    'date' => $date,
                    'customer_id' => $customerFields['customer_id'],
                    'customer_name' => $customerFields['customer_name'],
                    'customer_phone' => $customerFields['customer_phone'],
                    'total_amount' => $total,
                    'amount_paid' => $paymentPlan['amount_paid'],
                    'payment_method' => $paymentPlan['payment_method'],
                    'payment_status' => $paymentPlan['payment_status'],
                    'notes' => $request->input('notes'),
                ]);
                $order->save();

                $customer = $customerFields['customer_id']
                    ? Customer::find($customerFields['customer_id'])
                    : null;
                $accountTotal = 0.0;
                $lastDebit = null;
                $baseKey = $request->input('idempotency_key');

                foreach ($lines as $index => $line) {
                    $paid = $paidShares[$index];
                    $record = SalesRecord::create([
                        'farm_id' => $farmId,
                        'sales_order_id' => $order->id,
                        'flock_id' => $line['flock_id'],
                        'type' => $type,
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'total_amount' => $line['total'],
                        'amount_paid' => $paid,
                        'date' => $date,
                        'customer_id' => $customerFields['customer_id'],
                        'customer_name' => $customerFields['customer_name'],
                        'customer_phone' => $customerFields['customer_phone'],
                        'payment_method' => $paymentPlan['payment_method'],
                        'payment_status' => $this->lineStatus($paid, $line['total']),
                        'notes' => $request->input('notes'),
                        'created_by' => $request->user()->id,
                    ]);

                    if ($accountShares[$index] > 0 && $customer) {
                        $lastDebit = $this->accounts->debitForSale(
                            $customer,
                            $record,
                            $accountShares[$index],
                            [
                                'idempotency_key' => $baseKey ? $baseKey.':'.$index : null,
                                'reference' => 'SALE-ORDER-'.$order->id,
                                'description' => 'Sale order #'.$order->id.' payment',
                            ],
                            $request->user()
                        );
                        $accountTotal += $accountShares[$index];
                    }
                }

                if ($lastDebit && $customer) {
                    $this->accountNotifier->salesOrderPayment(
                        $farm,
                        $customer,
                        (int) $order->id,
                        round($accountTotal, 2),
                        $lastDebit['account'],
                        (int) $lastDebit['transaction']->id
                    );
                }

                return $order;
            });
        } catch (EggStockExceeded $e) {
            return $this->sendError($e->getMessage(), $e->details, 422);
        } catch (InsufficientCustomerAccountBalance $e) {
            return $this->sendValidationError($e->getMessage(), $e->toArray());
        } catch (InvalidArgumentException $e) {
            return $this->sendValidationError($e->getMessage());
        }

        return $this->sendResponse(
            $this->present($order->fresh()),
            $existing ? 'Sale order updated successfully' : 'Sale order created successfully',
            $existing ? 200 : 201
        );
    }

    /**
     * Each batch must have the eggs its lines sell. Lines naming the same batch
     * are summed first. When editing, the order's current lines are left out.
     *
     * @param  array<int, array{flock_id: ?int, quantity: float}>  $lines
     */
    private function assertEggStock(int $farmId, string $date, array $lines, ?SalesOrder $existing): void
    {
        $cratesByFlock = [];
        foreach ($lines as $line) {
            $cratesByFlock[$line['flock_id']] = ($cratesByFlock[$line['flock_id']] ?? 0) + $line['quantity'];
        }

        $excludeIds = $existing ? $existing->lines()->pluck('id')->all() : [];
        $shortages = [];

        foreach ($cratesByFlock as $flockId => $crates) {
            $check = $this->profitLossService->validateEggSaleQuantity($farmId, (int) $flockId, $date, (float) $crates, $excludeIds);
            if (! $check['valid']) {
                $flock = Flock::find($flockId);
                $shortages[] = [
                    'flock_id' => (int) $flockId,
                    'flock_name' => $flock?->name,
                    'requested_crates' => round((float) $crates, 2),
                    'available' => $check['available'] ?? 0,
                    'available_crates' => round(EggMetrics::eggsToCrates($check['available'] ?? 0), 2),
                ];
            }
        }

        if ($shortages !== []) {
            $names = implode(', ', array_map(
                fn ($s) => ($s['flock_name'] ?: 'Batch #'.$s['flock_id']).' has '.$s['available_crates'].' crates',
                $shortages
            ));
            throw new EggStockExceeded("Not enough eggs in stock as of {$date}: {$names}.", ['shortages' => $shortages]);
        }
    }

    /**
     * Refund whatever is still account-paid on the order's lines, then remove them.
     */
    private function refundAndRemoveLines(SalesOrder $order, Request $request): void
    {
        foreach ($order->lines()->get() as $line) {
            $debited = (float) CustomerAccountTransaction::query()
                ->where('sales_record_id', $line->id)
                ->where('type', CustomerAccountTransaction::TYPE_SALE_PAYMENT)
                ->where('direction', CustomerAccountTransaction::DIRECTION_DEBIT)
                ->sum('amount');

            if ($debited > 0 && $line->customer_id) {
                $customer = Customer::find($line->customer_id);
                if ($customer) {
                    try {
                        $this->accounts->refundSalePayment($customer, $line, [
                            'description' => 'Refund for sale order #'.$order->id.' change',
                            'reference' => 'REFUND-SALE-ORDER-'.$order->id,
                        ], $request->user());
                    } catch (InvalidArgumentException $e) {
                        // Nothing left to refund on this line (already refunded or reversed).
                    }
                }
            }

            $line->delete();
        }
    }

    /**
     * Split an amount across lines in proportion to their totals, to the cent.
     * Rounding leftovers go to the largest line so the shares add up exactly.
     *
     * @param  float[]  $totals
     * @return float[]
     */
    private function allocate(float $amount, array $totals): array
    {
        $count = count($totals);
        $shares = array_fill(0, $count, 0.0);
        $sum = array_sum($totals);
        if ($amount <= 0 || $count === 0) {
            return $shares;
        }

        $cents = (int) round($amount * 100);
        $allocated = 0;
        foreach ($totals as $i => $lineTotal) {
            $share = $sum > 0 ? (int) floor($cents * $lineTotal / $sum) : intdiv($cents, $count);
            $shares[$i] = $share;
            $allocated += $share;
        }

        $largest = array_keys($totals, max($totals))[0];
        $shares[$largest] += $cents - $allocated;

        return array_map(fn ($c) => round($c / 100, 2), $shares);
    }

    private function lineStatus(float $paid, float $total): string
    {
        if ($paid + 0.001 >= $total) {
            return 'paid';
        }

        return $paid > 0 ? 'partial' : 'pending';
    }

    private function present(SalesOrder $order): array
    {
        $order->load(['customer:id,name']);
        $lines = $order->lines()
            ->with(self::LINE_RELATIONS)
            ->withSum(['accountTransactions as account_debited' => function ($q) {
                $q->where('type', CustomerAccountTransaction::TYPE_SALE_PAYMENT)
                    ->where('direction', CustomerAccountTransaction::DIRECTION_DEBIT);
            }], 'amount')
            ->orderBy('id')
            ->get();

        return array_merge($order->toArray(), [
            'lines' => $lines,
            'account_debited' => round((float) $lines->sum('account_debited'), 2),
        ]);
    }

    /**
     * @return int[]
     */
    private function lineIdsForOrder(int $farmId, $orderId): array
    {
        if (! $orderId) {
            return [];
        }

        return SalesRecord::where('farm_id', $farmId)
            ->where('sales_order_id', (int) $orderId)
            ->pluck('id')
            ->all();
    }

    private function userCanViewSales(Request $request, Farm $farm): bool
    {
        return $request->user()->hasPermissionTo('view sales', 'api', $farm)
            || $request->user()->hasPermissionTo('view flocks', 'api', $farm);
    }
}
