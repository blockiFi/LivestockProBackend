<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientCustomerAccountBalance;
use App\Models\Customer;
use App\Models\CustomerAccountTransaction;
use App\Models\Farm;
use App\Services\CustomerAccountService;
use App\Services\Notifications\CustomerAccountNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class CustomerAccountController extends ApiController
{
    public function __construct(
        private readonly CustomerAccountService $accounts,
        private readonly CustomerAccountNotifier $notifier,
    ) {
    }

    public function farmSummary(Request $request, $farmId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $this->canViewAccounts($request, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view customer accounts');
        }

        return $this->sendResponse(
            $this->accounts->farmSummary($farm),
            'Customer account summary retrieved successfully'
        );
    }

    public function show(Request $request, $farmId, $customerId)
    {
        [$farm, $customer] = $this->resolveCustomer($farmId, $customerId);

        if (! $this->canViewAccounts($request, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view customer accounts');
        }

        $account = $this->accounts->getBalance($customer);

        return $this->sendResponse([
            'account' => $account,
            'recent_transactions' => $this->accounts->recentTransactions($customer, 10),
            'is_low_balance' => $account->isLowBalance(),
        ], 'Customer account retrieved successfully');
    }

    public function topUp(Request $request, $farmId, $customerId)
    {
        [$farm, $customer] = $this->resolveCustomer($farmId, $customerId);

        if (! $request->user()->hasPermissionTo('top up customer accounts', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to top up customer accounts');
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|gt:0',
            'payment_method' => 'required|in:cash,bank_transfer,pos,other',
            'reference' => 'nullable|string|max:191',
            'description' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:2000',
            'occurred_at' => 'nullable|date',
            'idempotency_key' => 'nullable|string|max:191',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        try {
            $result = $this->accounts->topUp($customer, $validator->validated(), $request->user());
            $this->notifier->topUp($farm, $customer, $result['transaction'], $result['account']);

            return $this->sendResponse([
                'previous_balance' => (float) $result['transaction']->balance_before,
                'top_up' => (float) $result['transaction']->amount,
                'new_balance' => (float) $result['transaction']->balance_after,
                'account' => $result['account'],
                'transaction' => $result['transaction'],
            ], 'Account topped up successfully', 201);
        } catch (InvalidArgumentException $e) {
            return $this->sendValidationError($e->getMessage());
        }
    }

    public function statement(Request $request, $farmId, $customerId)
    {
        [$farm, $customer] = $this->resolveCustomer($farmId, $customerId);

        if (! $this->canViewAccounts($request, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view customer accounts');
        }

        $filters = $request->only([
            'type', 'direction', 'date_from', 'date_to', 'sales_record_id', 'created_by', 'search', 'per_page',
        ]);

        $account = $this->accounts->getBalance($customer);
        $paginator = $this->accounts->getStatement($customer, $filters);

        return $this->sendResponse([
            'account' => $account,
            'opening_balance' => $this->openingBalance($customer, $filters),
            'transactions' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ], 'Account statement retrieved successfully');
    }

    public function exportStatement(Request $request, $farmId, $customerId)
    {
        [$farm, $customer] = $this->resolveCustomer($farmId, $customerId);

        if (! $this->canViewAccounts($request, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view customer accounts');
        }

        return $this->accounts->exportStatement($customer, $request->only([
            'type', 'direction', 'date_from', 'date_to', 'sales_record_id', 'created_by', 'search',
        ]));
    }

    public function adjust(Request $request, $farmId, $customerId)
    {
        [$farm, $customer] = $this->resolveCustomer($farmId, $customerId);

        if (! $request->user()->hasPermissionTo('adjust customer accounts', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to adjust customer accounts');
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|gt:0',
            'direction' => 'required|in:credit,debit',
            'reason' => 'required|string|max:1000',
            'reference' => 'nullable|string|max:191',
            'notes' => 'nullable|string|max:2000',
            'idempotency_key' => 'nullable|string|max:191',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        try {
            $result = $this->accounts->adjust($customer, $validator->validated(), $request->user());

            return $this->sendResponse($result, 'Account adjusted successfully', 201);
        } catch (InsufficientCustomerAccountBalance $e) {
            return $this->sendValidationError($e->getMessage(), $e->toArray());
        } catch (InvalidArgumentException $e) {
            return $this->sendValidationError($e->getMessage());
        }
    }

    public function reverse(Request $request, $farmId, $customerId, $transactionId)
    {
        [$farm, $customer] = $this->resolveCustomer($farmId, $customerId);

        if (! $request->user()->hasPermissionTo('reverse customer account transactions', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to reverse account transactions');
        }

        $transaction = CustomerAccountTransaction::query()
            ->where('farm_id', $farm->id)
            ->where('customer_id', $customer->id)
            ->findOrFail($transactionId);

        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:2000',
            'idempotency_key' => 'nullable|string|max:191',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        try {
            $result = $this->accounts->reverseTransaction($transaction, $validator->validated(), $request->user());

            return $this->sendResponse($result, 'Transaction reversed successfully', 201);
        } catch (InsufficientCustomerAccountBalance $e) {
            return $this->sendValidationError($e->getMessage(), $e->toArray());
        } catch (InvalidArgumentException $e) {
            return $this->sendValidationError($e->getMessage());
        }
    }

    public function refundSale(Request $request, $farmId, $customerId)
    {
        [$farm, $customer] = $this->resolveCustomer($farmId, $customerId);

        if (! $request->user()->hasPermissionTo('refund customer accounts', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to refund customer accounts');
        }

        $validator = Validator::make($request->all(), [
            'sales_record_id' => 'required|integer|exists:sales_records,id',
            'amount' => 'nullable|numeric|gt:0',
            'reference' => 'nullable|string|max:191',
            'notes' => 'nullable|string|max:2000',
            'idempotency_key' => 'nullable|string|max:191',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $sale = \App\Models\SalesRecord::where('farm_id', $farm->id)
            ->where('customer_id', $customer->id)
            ->findOrFail((int) $request->input('sales_record_id'));

        try {
            $result = $this->accounts->refundSalePayment(
                $customer,
                $sale,
                $validator->validated(),
                $request->user()
            );

            return $this->sendResponse($result, 'Account refund recorded successfully', 201);
        } catch (InvalidArgumentException $e) {
            return $this->sendValidationError($e->getMessage());
        }
    }

    /**
     * @return array{0: Farm, 1: Customer}
     */
    protected function resolveCustomer($farmId, $customerId): array
    {
        $farm = Farm::findOrFail($farmId);
        $customer = Customer::where('farm_id', $farm->id)->findOrFail($customerId);

        return [$farm, $customer];
    }

    protected function canViewAccounts(Request $request, Farm $farm): bool
    {
        $user = $request->user();

        return $user->hasPermissionTo('view customer accounts', 'api', $farm)
            || $user->hasPermissionTo('view customers', 'api', $farm)
            || $user->hasPermissionTo('manage customers', 'api', $farm);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function openingBalance(Customer $customer, array $filters): ?float
    {
        if (empty($filters['date_from'])) {
            return null;
        }

        $lastBefore = CustomerAccountTransaction::query()
            ->where('customer_id', $customer->id)
            ->whereDate('occurred_at', '<', $filters['date_from'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        return $lastBefore ? (float) $lastBefore->balance_after : 0.0;
    }
}
