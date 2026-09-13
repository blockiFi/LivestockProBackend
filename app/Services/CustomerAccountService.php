<?php

namespace App\Services;

use App\Exceptions\InsufficientCustomerAccountBalance;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerAccountTransaction;
use App\Models\Farm;
use App\Models\SalesRecord;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerAccountService
{
    public function ensureAccount(Customer $customer): CustomerAccount
    {
        $existing = CustomerAccount::where('customer_id', $customer->id)->first();
        if ($existing) {
            return $existing;
        }

        return CustomerAccount::create([
            'farm_id' => $customer->farm_id,
            'customer_id' => $customer->id,
            'balance' => 0,
            'status' => CustomerAccount::STATUS_ACTIVE,
            'total_credited' => 0,
            'total_debited' => 0,
        ]);
    }

    /**
     * @param  array{
     *   amount: float|int|string,
     *   payment_method?: string|null,
     *   reference?: string|null,
     *   description?: string|null,
     *   notes?: string|null,
     *   occurred_at?: mixed,
     *   idempotency_key?: string|null,
     * }  $data
     * @return array{account: CustomerAccount, transaction: CustomerAccountTransaction}
     */
    public function topUp(Customer $customer, array $data, ?User $actor = null): array
    {
        $amount = $this->positiveAmount($data['amount'] ?? 0);

        return $this->mutate($customer, function (CustomerAccount $account) use ($customer, $data, $amount, $actor) {
            return $this->applyCredit(
                $account,
                $customer,
                CustomerAccountTransaction::TYPE_TOP_UP,
                $amount,
                $data,
                $actor,
                updateTopUp: true,
            );
        }, $data['idempotency_key'] ?? null);
    }

    /**
     * @param  array{
     *   payment_method?: string|null,
     *   reference?: string|null,
     *   description?: string|null,
     *   notes?: string|null,
     *   idempotency_key?: string|null,
     * }  $data
     * @return array{account: CustomerAccount, transaction: CustomerAccountTransaction}
     */
    public function debitForSale(
        Customer $customer,
        SalesRecord $sale,
        float $amount,
        array $data = [],
        ?User $actor = null
    ): array {
        $amount = $this->positiveAmount($amount);

        return $this->mutate($customer, function (CustomerAccount $account) use ($customer, $sale, $amount, $data, $actor) {
            $this->assertActive($account);
            $this->assertSufficient($account, $amount);

            $before = (float) $account->balance;
            $after = round($before - $amount, 2);

            $transaction = $this->createTransaction($account, $customer, [
                'type' => CustomerAccountTransaction::TYPE_SALE_PAYMENT,
                'direction' => CustomerAccountTransaction::DIRECTION_DEBIT,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'payment_method' => $data['payment_method'] ?? 'customer_account',
                'reference' => $data['reference'] ?? ('SALE-'.$sale->id),
                'description' => $data['description'] ?? ('Sale payment #'.$sale->id),
                'notes' => $data['notes'] ?? null,
                'sales_record_id' => $sale->id,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'created_by' => $actor?->id,
            ]);

            $account->forceFill([
                'balance' => $after,
                'total_debited' => round((float) $account->total_debited + $amount, 2),
                'last_payment_at' => now(),
            ])->save();

            return ['account' => $account->fresh(), 'transaction' => $transaction];
        }, $data['idempotency_key'] ?? null);
    }

    /**
     * @param  array{
     *   amount: float|int|string,
     *   direction: string,
     *   reason: string,
     *   reference?: string|null,
     *   notes?: string|null,
     *   idempotency_key?: string|null,
     * }  $data
     * @return array{account: CustomerAccount, transaction: CustomerAccountTransaction}
     */
    public function adjust(Customer $customer, array $data, ?User $actor = null): array
    {
        $amount = $this->positiveAmount($data['amount'] ?? 0);
        $direction = (string) ($data['direction'] ?? '');
        $reason = trim((string) ($data['reason'] ?? ''));

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required for account adjustments.');
        }

        if (! in_array($direction, [
            CustomerAccountTransaction::DIRECTION_CREDIT,
            CustomerAccountTransaction::DIRECTION_DEBIT,
        ], true)) {
            throw new InvalidArgumentException('Adjustment direction must be credit or debit.');
        }

        return $this->mutate($customer, function (CustomerAccount $account) use ($customer, $data, $amount, $direction, $reason, $actor) {
            $this->assertActive($account);

            if ($direction === CustomerAccountTransaction::DIRECTION_DEBIT) {
                $this->assertSufficient($account, $amount);
            }

            $payload = array_merge($data, [
                'description' => 'Adjustment: '.$reason,
                'notes' => $data['notes'] ?? $reason,
            ]);

            if ($direction === CustomerAccountTransaction::DIRECTION_CREDIT) {
                return $this->applyCredit(
                    $account,
                    $customer,
                    CustomerAccountTransaction::TYPE_ADJUSTMENT,
                    $amount,
                    $payload,
                    $actor,
                );
            }

            $before = (float) $account->balance;
            $after = round($before - $amount, 2);
            $transaction = $this->createTransaction($account, $customer, [
                'type' => CustomerAccountTransaction::TYPE_ADJUSTMENT,
                'direction' => CustomerAccountTransaction::DIRECTION_DEBIT,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'reference' => $data['reference'] ?? null,
                'description' => 'Adjustment: '.$reason,
                'notes' => $data['notes'] ?? $reason,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'created_by' => $actor?->id,
            ]);

            $account->forceFill([
                'balance' => $after,
                'total_debited' => round((float) $account->total_debited + $amount, 2),
            ])->save();

            return ['account' => $account->fresh(), 'transaction' => $transaction];
        }, $data['idempotency_key'] ?? null);
    }

    /**
     * @param  array{
     *   amount?: float|int|string|null,
     *   reference?: string|null,
     *   notes?: string|null,
     *   idempotency_key?: string|null,
     * }  $data
     * @return array{account: CustomerAccount, transaction: CustomerAccountTransaction}
     */
    public function refundSalePayment(
        Customer $customer,
        SalesRecord $sale,
        array $data = [],
        ?User $actor = null
    ): array {
        $alreadyRefunded = (float) CustomerAccountTransaction::query()
            ->where('sales_record_id', $sale->id)
            ->where('type', CustomerAccountTransaction::TYPE_REFUND)
            ->where('direction', CustomerAccountTransaction::DIRECTION_CREDIT)
            ->sum('amount');

        $debited = (float) CustomerAccountTransaction::query()
            ->where('sales_record_id', $sale->id)
            ->where('type', CustomerAccountTransaction::TYPE_SALE_PAYMENT)
            ->where('direction', CustomerAccountTransaction::DIRECTION_DEBIT)
            ->sum('amount');

        $reversals = (float) CustomerAccountTransaction::query()
            ->where('sales_record_id', $sale->id)
            ->where('type', CustomerAccountTransaction::TYPE_REVERSAL)
            ->where('direction', CustomerAccountTransaction::DIRECTION_CREDIT)
            ->sum('amount');

        $refundable = round(max(0, $debited - $alreadyRefunded - $reversals), 2);
        if ($refundable <= 0) {
            throw new InvalidArgumentException('No account payment remains to refund for this sale.');
        }

        $amount = isset($data['amount'])
            ? $this->positiveAmount($data['amount'])
            : $refundable;

        if ($amount > $refundable) {
            throw new InvalidArgumentException('Refund amount exceeds the account-paid portion of this sale.');
        }

        return $this->mutate($customer, function (CustomerAccount $account) use ($customer, $sale, $amount, $data, $actor) {
            return $this->applyCredit(
                $account,
                $customer,
                CustomerAccountTransaction::TYPE_REFUND,
                $amount,
                array_merge($data, [
                    'description' => $data['description'] ?? ('Refund for sale #'.$sale->id),
                    'reference' => $data['reference'] ?? ('REFUND-SALE-'.$sale->id),
                    'sales_record_id' => $sale->id,
                ]),
                $actor,
            );
        }, $data['idempotency_key'] ?? null);
    }

    /**
     * @param  array{reason?: string|null, notes?: string|null, idempotency_key?: string|null}  $data
     * @return array{account: CustomerAccount, transaction: CustomerAccountTransaction}
     */
    public function reverseTransaction(
        CustomerAccountTransaction $original,
        array $data = [],
        ?User $actor = null
    ): array {
        if ($original->type === CustomerAccountTransaction::TYPE_REVERSAL) {
            throw new InvalidArgumentException('Cannot reverse a reversal transaction.');
        }

        $alreadyReversed = CustomerAccountTransaction::query()
            ->where('reverses_transaction_id', $original->id)
            ->exists();

        if ($alreadyReversed) {
            throw new InvalidArgumentException('This transaction has already been reversed.');
        }

        $customer = Customer::findOrFail($original->customer_id);
        $opposite = $original->direction === CustomerAccountTransaction::DIRECTION_CREDIT
            ? CustomerAccountTransaction::DIRECTION_DEBIT
            : CustomerAccountTransaction::DIRECTION_CREDIT;

        return $this->mutate($customer, function (CustomerAccount $account) use ($customer, $original, $opposite, $data, $actor) {
            $this->assertActive($account);
            $amount = (float) $original->amount;

            if ($opposite === CustomerAccountTransaction::DIRECTION_DEBIT) {
                $this->assertSufficient($account, $amount);
            }

            $before = (float) $account->balance;
            $after = $opposite === CustomerAccountTransaction::DIRECTION_CREDIT
                ? round($before + $amount, 2)
                : round($before - $amount, 2);

            $reason = trim((string) ($data['reason'] ?? ''));
            $transaction = $this->createTransaction($account, $customer, [
                'type' => CustomerAccountTransaction::TYPE_REVERSAL,
                'direction' => $opposite,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'payment_method' => $original->payment_method,
                'reference' => $data['reference'] ?? ('REV-'.$original->uuid),
                'description' => $reason !== ''
                    ? 'Reversal: '.$reason
                    : 'Reversal of transaction '.$original->uuid,
                'notes' => $data['notes'] ?? null,
                'sales_record_id' => $original->sales_record_id,
                'reverses_transaction_id' => $original->id,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'created_by' => $actor?->id,
            ]);

            $updates = ['balance' => $after];
            if ($opposite === CustomerAccountTransaction::DIRECTION_CREDIT) {
                $updates['total_credited'] = round((float) $account->total_credited + $amount, 2);
            } else {
                $updates['total_debited'] = round((float) $account->total_debited + $amount, 2);
            }
            $account->forceFill($updates)->save();

            if (
                $original->type === CustomerAccountTransaction::TYPE_SALE_PAYMENT
                && $original->sales_record_id
                && $opposite === CustomerAccountTransaction::DIRECTION_CREDIT
            ) {
                $sale = SalesRecord::find($original->sales_record_id);
                if ($sale) {
                    $newPaid = max(0, round((float) $sale->amount_paid - $amount, 2));
                    $total = (float) $sale->total_amount;
                    $status = $newPaid <= 0
                        ? 'pending'
                        : ($newPaid + 0.001 >= $total ? 'paid' : 'partial');
                    $sale->forceFill([
                        'amount_paid' => $newPaid,
                        'payment_status' => $status,
                    ])->save();
                }
            }

            return ['account' => $account->fresh(), 'transaction' => $transaction];
        }, $data['idempotency_key'] ?? null);
    }

    public function getBalance(Customer $customer): CustomerAccount
    {
        return $this->ensureAccount($customer)->fresh();
    }

    /**
     * @param  array{
     *   type?: string|null,
     *   direction?: string|null,
     *   date_from?: string|null,
     *   date_to?: string|null,
     *   sales_record_id?: int|null,
     *   created_by?: int|null,
     *   search?: string|null,
     *   per_page?: int|null,
     * }  $filters
     */
    public function getStatement(Customer $customer, array $filters = []): LengthAwarePaginator
    {
        $account = $this->ensureAccount($customer);
        $query = CustomerAccountTransaction::query()
            ->with(['createdBy:id,name', 'salesRecord:id,type,total_amount,date'])
            ->where('customer_account_id', $account->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['direction'])) {
            $query->where('direction', $filters['direction']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('occurred_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('occurred_at', '<=', $filters['date_to']);
        }
        if (! empty($filters['sales_record_id'])) {
            $query->where('sales_record_id', (int) $filters['sales_record_id']);
        }
        if (! empty($filters['created_by'])) {
            $query->where('created_by', (int) $filters['created_by']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $query->where(function ($q) use ($term) {
                $q->where('reference', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('notes', 'like', $term)
                    ->orWhere('uuid', 'like', $term);
            });
        }

        return $query->paginate((int) ($filters['per_page'] ?? 25));
    }

    public function exportStatement(Customer $customer, array $filters = []): StreamedResponse
    {
        $filters['per_page'] = 10000;
        $rows = $this->getStatement($customer, $filters)->getCollection();

        $filename = 'customer-'.$customer->id.'-account-statement.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Date', 'UUID', 'Type', 'Direction', 'Amount', 'Balance Before', 'Balance After',
                'Reference', 'Description', 'Payment Method', 'Sale ID', 'Created By',
            ]);
            foreach ($rows as $row) {
                fputcsv($out, [
                    optional($row->occurred_at)->toDateTimeString(),
                    $row->uuid,
                    $row->type,
                    $row->direction,
                    $row->amount,
                    $row->balance_before,
                    $row->balance_after,
                    $row->reference,
                    $row->description,
                    $row->payment_method,
                    $row->sales_record_id,
                    $row->createdBy?->name,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array{
     *   total_balances: float,
     *   total_deposits: float,
     *   total_account_payments: float,
     *   total_refunds: float,
     *   customers_with_balance: int,
     *   low_balance_customers: int
     * }
     */
    public function farmSummary(Farm $farm): array
    {
        $accounts = CustomerAccount::query()->where('farm_id', $farm->id)->get();

        $totalRefunds = (float) CustomerAccountTransaction::query()
            ->where('farm_id', $farm->id)
            ->where('type', CustomerAccountTransaction::TYPE_REFUND)
            ->where('direction', CustomerAccountTransaction::DIRECTION_CREDIT)
            ->sum('amount');

        return [
            'total_balances' => round((float) $accounts->sum('balance'), 2),
            'total_deposits' => round((float) CustomerAccountTransaction::query()
                ->where('farm_id', $farm->id)
                ->where('type', CustomerAccountTransaction::TYPE_TOP_UP)
                ->sum('amount'), 2),
            'total_account_payments' => round((float) CustomerAccountTransaction::query()
                ->where('farm_id', $farm->id)
                ->where('type', CustomerAccountTransaction::TYPE_SALE_PAYMENT)
                ->where('direction', CustomerAccountTransaction::DIRECTION_DEBIT)
                ->sum('amount'), 2),
            'total_refunds' => round($totalRefunds, 2),
            'customers_with_balance' => $accounts->filter(fn (CustomerAccount $a) => (float) $a->balance > 0)->count(),
            'low_balance_customers' => $accounts->filter(fn (CustomerAccount $a) => $a->isLowBalance())->count(),
        ];
    }

    /**
     * @return Collection<int, CustomerAccountTransaction>
     */
    public function recentTransactions(Customer $customer, int $limit = 10): Collection
    {
        $account = $this->ensureAccount($customer);

        return CustomerAccountTransaction::query()
            ->with(['createdBy:id,name', 'salesRecord:id,type,total_amount'])
            ->where('customer_account_id', $account->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  callable(CustomerAccount): array{account: CustomerAccount, transaction: CustomerAccountTransaction}  $callback
     * @return array{account: CustomerAccount, transaction: CustomerAccountTransaction}
     */
    protected function mutate(Customer $customer, callable $callback, ?string $idempotencyKey = null): array
    {
        if ($idempotencyKey) {
            $existing = CustomerAccountTransaction::query()
                ->where('farm_id', $customer->farm_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return [
                    'account' => $this->ensureAccount($customer)->fresh(),
                    'transaction' => $existing,
                ];
            }
        }

        try {
            return DB::transaction(function () use ($customer, $callback) {
                $this->ensureAccount($customer);
                $account = CustomerAccount::where('customer_id', $customer->id)->lockForUpdate()->firstOrFail();

                return $callback($account);
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($idempotencyKey) {
                $existing = CustomerAccountTransaction::query()
                    ->where('farm_id', $customer->farm_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return [
                        'account' => $this->ensureAccount($customer)->fresh(),
                        'transaction' => $existing,
                    ];
                }
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{account: CustomerAccount, transaction: CustomerAccountTransaction}
     */
    protected function applyCredit(
        CustomerAccount $account,
        Customer $customer,
        string $type,
        float $amount,
        array $data,
        ?User $actor,
        bool $updateTopUp = false,
    ): array {
        $this->assertActive($account);

        $before = (float) $account->balance;
        $after = round($before + $amount, 2);

        $transaction = $this->createTransaction($account, $customer, [
            'type' => $type,
            'direction' => CustomerAccountTransaction::DIRECTION_CREDIT,
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
            'payment_method' => $data['payment_method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'description' => $data['description'] ?? null,
            'notes' => $data['notes'] ?? null,
            'sales_record_id' => $data['sales_record_id'] ?? null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'created_by' => $actor?->id,
            'occurred_at' => $data['occurred_at'] ?? now(),
        ]);

        $updates = [
            'balance' => $after,
            'total_credited' => round((float) $account->total_credited + $amount, 2),
        ];
        if ($updateTopUp) {
            $updates['last_top_up_at'] = $data['occurred_at'] ?? now();
        }
        $account->forceFill($updates)->save();

        return ['account' => $account->fresh(), 'transaction' => $transaction];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createTransaction(
        CustomerAccount $account,
        Customer $customer,
        array $attributes
    ): CustomerAccountTransaction {
        return CustomerAccountTransaction::create(array_merge([
            'farm_id' => $account->farm_id,
            'customer_account_id' => $account->id,
            'customer_id' => $customer->id,
        ], $attributes));
    }

    protected function assertActive(CustomerAccount $account): void
    {
        if ($account->isFrozen()) {
            throw new InvalidArgumentException('This customer account is frozen.');
        }
    }

    protected function assertSufficient(CustomerAccount $account, float $amount): void
    {
        $available = (float) $account->balance;
        if ($amount > $available + 0.00001) {
            $deficit = round($amount - $available, 2);
            throw new InsufficientCustomerAccountBalance($available, $amount, $deficit);
        }
    }

    protected function positiveAmount(float|int|string $amount): float
    {
        $value = round((float) $amount, 2);
        if ($value <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        return $value;
    }
}
