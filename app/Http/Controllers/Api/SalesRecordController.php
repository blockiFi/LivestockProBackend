<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientCustomerAccountBalance;
use App\Models\Customer;
use App\Models\CustomerAccountTransaction;
use App\Models\Farm;
use App\Models\Flock;
use App\Models\SalesRecord;
use App\Services\CustomerAccountService;
use App\Services\CustomerResolver;
use App\Services\Notifications\CustomerAccountNotifier;
use App\Services\SalesProfitLossService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class SalesRecordController extends ApiController
{
    public function __construct(
        protected readonly SalesProfitLossService $profitLossService,
        protected readonly CustomerAccountService $accounts,
        protected readonly CustomerAccountNotifier $accountNotifier,
    ) {
    }

    public function index(Request $request, $farmId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $this->canViewSales($request, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view product sales');
        }

        $query = SalesRecord::with(['flock:id,name,batch_number', 'customer:id,name'])
            ->withSum(['accountTransactions as account_debited' => function ($q) {
                $q->where('type', CustomerAccountTransaction::TYPE_SALE_PAYMENT)
                    ->where('direction', CustomerAccountTransaction::DIRECTION_DEBIT);
            }], 'amount')
            ->where('farm_id', $farmId);

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('flock_id')) {
            $query->where('flock_id', $request->flock_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        $records = $query->orderByDesc('date')->orderByDesc('id')->get();

        return $this->sendResponse($records, 'Product sales retrieved successfully');
    }

    public function eggStock(Request $request, $farmId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $this->canViewSales($request, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view product sales');
        }

        $validator = Validator::make($request->all(), [
            'flock_id' => 'required|exists:flocks,id',
            'date' => 'nullable|date',
            'exclude_record_id' => 'nullable|integer|exists:sales_records,id',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $flockId = (int) $request->input('flock_id');
        $flock = Flock::where('farm_id', $farmId)->find($flockId);
        if (! $flock) {
            return $this->sendValidationError('Validation failed', [
                'flock_id' => ['Flock does not belong to this farm.'],
            ]);
        }

        $asOf = $request->input('date', now()->toDateString());
        $excludeId = $request->filled('exclude_record_id')
            ? (int) $request->input('exclude_record_id')
            : null;

        $stock = $this->profitLossService->computeEggStock(
            (int) $farmId,
            $flockId,
            $asOf,
            $excludeId
        );

        return $this->sendResponse($stock, 'Egg stock retrieved successfully');
    }

    public function store(Request $request, $farmId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $request->user()->hasPermissionTo('create sales', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create product sales');
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:egg,meat,manure',
            'flock_id' => 'nullable|exists:flocks,id',
            // For type=egg: quantity is crates, unit_price is price per crate.
            'quantity' => 'required|numeric|min:0.01',
            'unit_price' => 'required|numeric|min:0',
            'date' => 'required|date',
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
            'idempotency_key' => 'nullable|string|max:191',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $type = $request->input('type');
        $flockId = $request->input('flock_id');

        if (in_array($type, ['egg', 'meat'], true) && ! $flockId) {
            return $this->sendValidationError('Validation failed', [
                'flock_id' => ['Flock is required for egg and meat sales.'],
            ]);
        }

        if ($flockId) {
            $flock = Flock::where('farm_id', $farmId)->find($flockId);
            if (! $flock) {
                return $this->sendValidationError('Validation failed', [
                    'flock_id' => ['Flock does not belong to this farm.'],
                ]);
            }
        }

        if ($type === 'egg' && $flockId && ! $request->attributes->get('skip_egg_stock_check')) {
            $check = $this->profitLossService->validateEggSaleQuantity(
                (int) $farmId,
                (int) $flockId,
                $request->input('date'),
                (float) $request->input('quantity')
            );
            if (! $check['valid']) {
                return $this->sendError($check['message'], ['available' => $check['available'] ?? 0], 422);
            }
        }

        $quantity = round((float) $request->input('quantity'), 2);
        $unitPrice = round((float) $request->input('unit_price'), 2);
        $total = round($quantity * $unitPrice, 2);

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

        $paymentPlan = $this->resolvePaymentPlan($request, $farm, $total, $customerFields['customer_id']);
        if ($paymentPlan['error'] !== null) {
            return $paymentPlan['error'];
        }

        try {
            $record = DB::transaction(function () use ($request, $farm, $farmId, $flockId, $type, $quantity, $unitPrice, $total, $customerFields, $paymentPlan) {
                $record = SalesRecord::create([
                    'farm_id' => $farmId,
                    'flock_id' => $flockId,
                    'type' => $type,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_amount' => $total,
                    'amount_paid' => $paymentPlan['amount_paid'],
                    'date' => $request->input('date'),
                    'customer_id' => $customerFields['customer_id'],
                    'customer_name' => $customerFields['customer_name'],
                    'customer_phone' => $customerFields['customer_phone'],
                    'payment_method' => $paymentPlan['payment_method'],
                    'payment_status' => $paymentPlan['payment_status'],
                    'notes' => $request->input('notes'),
                    'created_by' => $request->user()->id,
                ]);

                if ($paymentPlan['account_debit'] > 0) {
                    $customer = Customer::findOrFail($customerFields['customer_id']);
                    $result = $this->accounts->debitForSale(
                        $customer,
                        $record,
                        $paymentPlan['account_debit'],
                        [
                            'idempotency_key' => $request->input('idempotency_key'),
                            'description' => 'Sale payment #'.$record->id,
                        ],
                        $request->user()
                    );
                    $this->accountNotifier->salePayment($farm, $customer, $result['transaction'], $result['account']);
                }

                return $record;
            });
        } catch (InsufficientCustomerAccountBalance $e) {
            return $this->sendValidationError($e->getMessage(), $e->toArray());
        } catch (InvalidArgumentException $e) {
            return $this->sendValidationError($e->getMessage());
        }

        return $this->sendResponse(
            $record->load(['flock:id,name,batch_number', 'customer:id,name']),
            'Product sale created successfully',
            201
        );
    }

    public function update(Request $request, $farmId, $recordId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $request->user()->hasPermissionTo('update sales', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update product sales');
        }

        $record = SalesRecord::where('farm_id', $farmId)->findOrFail($recordId);

        if ($record->sales_order_id) {
            return $this->sendError('This sale covers several batches. Edit or delete it as a whole sale order.', ['sales_order_id' => $record->sales_order_id], 409);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'sometimes|in:egg,meat,manure',
            'flock_id' => 'nullable|exists:flocks,id',
            'quantity' => 'sometimes|numeric|min:0.01',
            'unit_price' => 'sometimes|numeric|min:0',
            'date' => 'sometimes|date',
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
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $type = $request->input('type', $record->type);
        $flockId = $request->has('flock_id') ? $request->input('flock_id') : $record->flock_id;
        $quantity = round((float) $request->input('quantity', $record->quantity), 2);
        $unitPrice = round((float) $request->input('unit_price', $record->unit_price), 2);
        $total = round($quantity * $unitPrice, 2);
        $dateValue = $request->input('date', $record->date);
        $date = $dateValue instanceof \DateTimeInterface
            ? $dateValue->format('Y-m-d')
            : substr((string) $dateValue, 0, 10);

        if (in_array($type, ['egg', 'meat'], true) && ! $flockId) {
            return $this->sendValidationError('Validation failed', [
                'flock_id' => ['Flock is required for egg and meat sales.'],
            ]);
        }

        if ($flockId) {
            $flock = Flock::where('farm_id', $farmId)->find($flockId);
            if (! $flock) {
                return $this->sendValidationError('Validation failed', [
                    'flock_id' => ['Flock does not belong to this farm.'],
                ]);
            }
        }

        if ($type === 'egg' && $flockId) {
            $check = $this->profitLossService->validateEggSaleQuantity(
                (int) $farmId,
                (int) $flockId,
                $date,
                $quantity,
                (int) $record->id
            );
            if (! $check['valid']) {
                return $this->sendError($check['message'], ['available' => $check['available'] ?? 0], 422);
            }
        }

        $customerFields = CustomerResolver::resolveForFarm(
            $farm,
            $request->has('customer_id') ? $request->input('customer_id') : $record->customer_id,
            $request->has('customer_name') ? $request->input('customer_name') : $record->customer_name,
            $request->has('customer_phone') ? $request->input('customer_phone') : $record->customer_phone
        );
        if ($customerFields === null) {
            return $this->sendValidationError('Validation failed', [
                'customer_id' => ['Customer does not belong to this farm.'],
            ]);
        }

        $paymentStatus = $record->payment_status;
        $amountPaid = (float) $record->amount_paid;
        $paymentMethod = $record->payment_method;
        $accountDebit = null;

        if ($request->filled('payment_mode')) {
            $paymentPlan = $this->resolvePaymentPlan($request, $farm, $total, $customerFields['customer_id']);
            if ($paymentPlan['error'] !== null) {
                return $paymentPlan['error'];
            }
            $amountPaid = $paymentPlan['amount_paid'];
            $paymentStatus = $paymentPlan['payment_status'];
            $paymentMethod = $paymentPlan['payment_method'];
            $accountDebit = $paymentPlan['account_debit'];
        } elseif ($request->has('payment_status')) {
            $paymentStatus = $request->input('payment_status', $record->payment_status);
            $amountPaid = match ($paymentStatus) {
                'paid' => $total,
                'pending' => 0,
                default => min($total, round((float) $request->input('amount_paid', $record->amount_paid), 2)),
            };
            if ($request->has('payment_method')) {
                $paymentMethod = $request->input('payment_method');
            }
        } elseif ($total !== (float) $record->total_amount) {
            $amountPaid = min($total, (float) ($record->amount_paid ?? 0));
        }

        $previousCustomerId = $record->customer_id ? (int) $record->customer_id : null;

        try {
            DB::transaction(function () use ($request, $record, $farm, $type, $flockId, $quantity, $unitPrice, $total, $amountPaid, $date, $customerFields, $paymentMethod, $paymentStatus, $accountDebit, $previousCustomerId) {
                $record->fill([
                    'type' => $type,
                    'flock_id' => $flockId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_amount' => $total,
                    'amount_paid' => $amountPaid,
                    'date' => $date,
                    'customer_id' => $customerFields['customer_id'],
                    'customer_name' => $customerFields['customer_name'],
                    'customer_phone' => $customerFields['customer_phone'],
                    'payment_method' => $paymentMethod,
                    'payment_status' => $paymentStatus,
                    'notes' => $request->has('notes') ? $request->input('notes') : $record->notes,
                ]);
                $record->save();

                if ($accountDebit !== null) {
                    $this->syncAccountDebit(
                        $farm,
                        $record,
                        $previousCustomerId,
                        $customerFields['customer_id'] ? (int) $customerFields['customer_id'] : null,
                        (float) $accountDebit,
                        $request->user()
                    );
                }
            });
        } catch (InsufficientCustomerAccountBalance $e) {
            return $this->sendValidationError($e->getMessage(), $e->toArray());
        } catch (InvalidArgumentException $e) {
            return $this->sendValidationError($e->getMessage());
        }

        return $this->sendResponse($record->load(['flock:id,name,batch_number', 'customer:id,name']), 'Product sale updated successfully');
    }

    public function destroy(Request $request, $farmId, $recordId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $request->user()->hasPermissionTo('delete sales', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to delete product sales');
        }

        $record = SalesRecord::where('farm_id', $farmId)->findOrFail($recordId);

        if ($record->sales_order_id) {
            return $this->sendError('This sale covers several batches. Edit or delete it as a whole sale order.', ['sales_order_id' => $record->sales_order_id], 409);
        }
        $record->delete();

        return $this->sendResponse(null, 'Product sale deleted successfully');
    }

    /**
     * @return array{amount_paid: float, payment_status: string, payment_method: ?string, account_debit: float, error: mixed}
     */
    protected function resolvePaymentPlan(Request $request, Farm $farm, float $total, ?int $customerId): array
    {
        $mode = $request->input('payment_mode');

        if (! $mode) {
            $paymentStatus = $request->input('payment_status', 'paid');
            $amountPaid = match ($paymentStatus) {
                'paid' => $total,
                'pending' => 0.0,
                default => min($total, round((float) $request->input('amount_paid', 0), 2)),
            };

            return [
                'amount_paid' => $amountPaid,
                'payment_status' => $paymentStatus,
                'payment_method' => $request->input('payment_method'),
                'account_debit' => 0.0,
                'error' => null,
            ];
        }

        if (in_array($mode, ['customer_account', 'account_and_other'], true)) {
            $canUseAccount = false;
            foreach (['use customer account for payment', 'manage customers', 'create sales', 'update sales'] as $permission) {
                try {
                    if ($request->user()->hasPermissionTo($permission, 'api', $farm)) {
                        $canUseAccount = true;
                        break;
                    }
                } catch (\App\Exceptions\PermissionDoesNotExist) {
                    continue;
                }
            }

            if (! $canUseAccount) {
                return [
                    'amount_paid' => 0,
                    'payment_status' => 'pending',
                    'payment_method' => null,
                    'account_debit' => 0,
                    'error' => $this->sendUnauthorizedError('Unauthorized to use customer account for payment'),
                ];
            }

            if (! $customerId) {
                return [
                    'amount_paid' => 0,
                    'payment_status' => 'pending',
                    'payment_method' => null,
                    'account_debit' => 0,
                    'error' => $this->sendValidationError('A linked customer is required to pay from account.', [
                        'customer_id' => ['Select a customer to use account balance.'],
                    ]),
                ];
            }
        }

        if ($mode === 'pending') {
            return [
                'amount_paid' => 0.0,
                'payment_status' => 'pending',
                'payment_method' => null,
                'account_debit' => 0.0,
                'error' => null,
            ];
        }

        if ($mode === 'partial') {
            $amountPaid = round((float) $request->input('amount_paid', 0), 2);
            $method = $request->input('payment_method');

            if ($amountPaid <= 0) {
                return [
                    'amount_paid' => 0,
                    'payment_status' => 'pending',
                    'payment_method' => null,
                    'account_debit' => 0,
                    'error' => $this->sendValidationError('Amount paid is required for partial payment.', [
                        'amount_paid' => ['Enter an amount greater than zero.'],
                    ]),
                ];
            }

            if ($amountPaid >= $total) {
                return [
                    'amount_paid' => 0,
                    'payment_status' => 'pending',
                    'payment_method' => null,
                    'account_debit' => 0,
                    'error' => $this->sendValidationError('Partial payment must be less than the sale total.', [
                        'amount_paid' => ['Use a full payment method if the full amount was paid.'],
                    ]),
                ];
            }

            if (! $method || ! in_array($method, ['cash', 'bank_transfer', 'pos', 'other'], true)) {
                return [
                    'amount_paid' => 0,
                    'payment_status' => 'pending',
                    'payment_method' => null,
                    'account_debit' => 0,
                    'error' => $this->sendValidationError('Select how the partial amount was paid.', [
                        'payment_method' => ['Required for partial payment.'],
                    ]),
                ];
            }

            return [
                'amount_paid' => $amountPaid,
                'payment_status' => 'partial',
                'payment_method' => $method,
                'account_debit' => 0.0,
                'error' => null,
            ];
        }

        if (in_array($mode, ['cash', 'bank_transfer', 'pos', 'other'], true)) {
            return [
                'amount_paid' => $total,
                'payment_status' => 'paid',
                'payment_method' => $mode,
                'account_debit' => 0.0,
                'error' => null,
            ];
        }

        if ($mode === 'customer_account') {
            return [
                'amount_paid' => $total,
                'payment_status' => 'paid',
                'payment_method' => 'customer_account',
                'account_debit' => $total,
                'error' => null,
            ];
        }

        // account_and_other
        $accountAmount = round((float) $request->input('account_amount', 0), 2);
        $otherAmount = round((float) $request->input('other_amount', 0), 2);
        $otherMethod = $request->input('other_payment_method');

        if ($accountAmount <= 0) {
            return [
                'amount_paid' => 0,
                'payment_status' => 'pending',
                'payment_method' => null,
                'account_debit' => 0,
                'error' => $this->sendValidationError('Account amount must be greater than zero for split payment.', [
                    'account_amount' => ['Required for Account + Other payment.'],
                ]),
            ];
        }

        if ($accountAmount > $total) {
            return [
                'amount_paid' => 0,
                'payment_status' => 'pending',
                'payment_method' => null,
                'account_debit' => 0,
                'error' => $this->sendValidationError('Account amount cannot exceed the sale total.', [
                    'account_amount' => ['Must be less than or equal to sale total.'],
                ]),
            ];
        }

        if ($otherAmount > 0 && ! $otherMethod) {
            return [
                'amount_paid' => 0,
                'payment_status' => 'pending',
                'payment_method' => null,
                'account_debit' => 0,
                'error' => $this->sendValidationError('Select a payment method for the remaining amount.', [
                    'other_payment_method' => ['Required when other amount is provided.'],
                ]),
            ];
        }

        $amountPaid = round(min($total, $accountAmount + max(0, $otherAmount)), 2);
        $status = $amountPaid <= 0
            ? 'pending'
            : ($amountPaid + 0.001 >= $total ? 'paid' : 'partial');

        $method = $otherMethod
            ? 'customer_account+'.$otherMethod
            : 'customer_account';

        return [
            'amount_paid' => $amountPaid,
            'payment_status' => $status,
            'payment_method' => $method,
            'account_debit' => $accountAmount,
            'error' => null,
        ];
    }

    /**
     * Move the customer-account portion of a sale to the requested amount.
     * Re-saving an unchanged sale does not debit again.
     */
    private function syncAccountDebit(
        Farm $farm,
        SalesRecord $record,
        ?int $previousCustomerId,
        ?int $newCustomerId,
        float $targetDebit,
        $actor
    ): void {
        $existing = $this->netAccountDebit($record);
        $targetDebit = round(max(0, $targetDebit), 2);

        if ($previousCustomerId && $previousCustomerId !== $newCustomerId && $existing > 0) {
            $this->accounts->refundSalePayment(
                Customer::findOrFail($previousCustomerId),
                $record,
                ['amount' => $existing, 'description' => 'Sale update refund #'.$record->id],
                $actor
            );
            $existing = 0.0;
        }

        $delta = round($targetDebit - $existing, 2);
        if (abs($delta) < 0.01 || ! $newCustomerId) {
            return;
        }

        $customer = Customer::findOrFail($newCustomerId);
        if ($delta > 0) {
            $result = $this->accounts->debitForSale(
                $customer,
                $record,
                $delta,
                ['description' => 'Sale update #'.$record->id],
                $actor
            );
            $this->accountNotifier->salePayment($farm, $customer, $result['transaction'], $result['account']);

            return;
        }

        $this->accounts->refundSalePayment(
            $customer,
            $record,
            ['amount' => abs($delta), 'description' => 'Sale update refund #'.$record->id],
            $actor
        );
    }

    private function netAccountDebit(SalesRecord $record): float
    {
        $debited = (float) CustomerAccountTransaction::query()
            ->where('sales_record_id', $record->id)
            ->where('type', CustomerAccountTransaction::TYPE_SALE_PAYMENT)
            ->where('direction', CustomerAccountTransaction::DIRECTION_DEBIT)
            ->sum('amount');

        $returned = (float) CustomerAccountTransaction::query()
            ->where('sales_record_id', $record->id)
            ->whereIn('type', [CustomerAccountTransaction::TYPE_REFUND, CustomerAccountTransaction::TYPE_REVERSAL])
            ->where('direction', CustomerAccountTransaction::DIRECTION_CREDIT)
            ->sum('amount');

        return round(max(0, $debited - $returned), 2);
    }

    private function canViewSales(Request $request, Farm $farm): bool
    {
        return $request->user()->hasPermissionTo('view sales', 'api', $farm)
            || $request->user()->hasPermissionTo('view flocks', 'api', $farm);
    }
}
