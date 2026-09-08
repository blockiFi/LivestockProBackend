<?php

namespace App\Services;

use App\Exceptions\InsufficientCustomerAccountBalance;
use App\Models\Customer;
use App\Models\Farm;
use App\Models\Invoice;
use App\Models\SalesRecord;
use App\Services\Notifications\CustomerAccountNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CustomerPaymentService
{
    public function __construct(
        private readonly CustomerAccountService $accounts,
        private readonly CustomerAccountNotifier $accountNotifier,
    ) {
    }

    public function recordPayment(
        Farm $farm,
        Customer $customer,
        string $type,
        int $id,
        float $amount,
        ?string $paymentMethod = null,
        ?string $notes = null,
        ?string $paymentMode = null,
        ?float $accountAmount = null,
        ?float $otherAmount = null,
        ?string $otherPaymentMethod = null,
        ?string $idempotencyKey = null,
        $actor = null,
    ): array {
        if ($amount <= 0 && ($accountAmount === null || $accountAmount <= 0)) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        return match ($type) {
            'product' => $this->recordProductSalePayment(
                $farm,
                $customer,
                $id,
                $amount,
                $paymentMethod,
                $notes,
                $paymentMode,
                $accountAmount,
                $otherAmount,
                $otherPaymentMethod,
                $idempotencyKey,
                $actor,
            ),
            'invoice' => $this->recordInvoicePayment($farm, $customer, $id, $amount, $paymentMethod, $notes),
            default => throw new InvalidArgumentException('Unsupported payment type.'),
        };
    }

    /**
     * @return array{type: string, record: SalesRecord|Invoice, amount_paid: float, balance_due: float, payment_status: string}
     */
    private function recordProductSalePayment(
        Farm $farm,
        Customer $customer,
        int $id,
        float $amount,
        ?string $paymentMethod,
        ?string $notes,
        ?string $paymentMode,
        ?float $accountAmount,
        ?float $otherAmount,
        ?string $otherPaymentMethod,
        ?string $idempotencyKey,
        $actor,
    ): array {
        return DB::transaction(function () use (
            $farm, $customer, $id, $amount, $paymentMethod, $notes,
            $paymentMode, $accountAmount, $otherAmount, $otherPaymentMethod, $idempotencyKey, $actor
        ) {
            $record = SalesRecord::where('farm_id', $farm->id)
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->findOrFail($id);

            $total = (float) $record->total_amount;
            $currentPaid = (float) ($record->amount_paid ?? 0);
            $balance = max(0, round($total - $currentPaid, 2));

            if ($balance <= 0) {
                throw new InvalidArgumentException('This sale is already fully paid.');
            }

            $mode = $paymentMode
                ?? (in_array($paymentMethod, ['customer_account'], true) ? 'customer_account' : null);

            $debitAccount = 0.0;
            $applyCash = 0.0;
            $method = $paymentMethod;

            if ($mode === 'customer_account') {
                $debitAccount = min($balance, $amount > 0 ? $amount : $balance);
                $method = 'customer_account';
            } elseif ($mode === 'account_and_other') {
                $debitAccount = round((float) ($accountAmount ?? 0), 2);
                $applyCash = round((float) ($otherAmount ?? 0), 2);
                if ($debitAccount <= 0) {
                    throw new InvalidArgumentException('Account amount must be greater than zero.');
                }
                if ($debitAccount > $balance) {
                    throw new InvalidArgumentException('Account amount exceeds the balance due.');
                }
                if ($applyCash > 0 && ! $otherPaymentMethod) {
                    throw new InvalidArgumentException('Select a payment method for the remaining amount.');
                }
                $method = $otherPaymentMethod
                    ? 'customer_account+'.$otherPaymentMethod
                    : 'customer_account';
            } else {
                $applyCash = min($amount, $balance);
                $method = $paymentMethod ?: $record->payment_method;
            }

            if ($debitAccount > 0) {
                try {
                    $result = $this->accounts->debitForSale(
                        $customer,
                        $record,
                        $debitAccount,
                        [
                            'idempotency_key' => $idempotencyKey,
                            'description' => 'Sale payment #'.$record->id,
                            'notes' => $notes,
                        ],
                        $actor
                    );
                    $this->accountNotifier->salePayment($farm, $customer, $result['transaction'], $result['account']);
                } catch (InsufficientCustomerAccountBalance $e) {
                    throw $e;
                }
            }

            $applied = round(min($balance, $debitAccount + $applyCash), 2);
            if ($applied <= 0) {
                throw new InvalidArgumentException('Payment amount must be greater than zero.');
            }

            $newPaid = round($currentPaid + $applied, 2);
            $newBalance = max(0, round($total - $newPaid, 2));
            $status = $this->resolveSalesPaymentStatus($newPaid, $total);

            $record->update([
                'amount_paid' => $newPaid,
                'payment_status' => $status,
                'payment_method' => $method,
                'notes' => $this->appendPaymentNote($record->notes, $applied, $notes),
            ]);

            return [
                'type' => 'product',
                'record' => $record->fresh(['flock:id,name,batch_number', 'customer:id,name']),
                'amount_paid' => $newPaid,
                'balance_due' => $newBalance,
                'payment_status' => $status,
            ];
        });
    }

    /**
     * @return array{type: string, record: Invoice, amount_paid: float, balance_due: float, payment_status: string}
     */
    private function recordInvoicePayment(
        Farm $farm,
        Customer $customer,
        int $id,
        float $amount,
        ?string $paymentMethod,
        ?string $notes
    ): array {
        $invoice = Invoice::where('farm_id', $farm->id)
            ->where('customer_id', $customer->id)
            ->findOrFail($id);

        $total = (float) $invoice->total;
        $currentPaid = (float) ($invoice->amount_paid ?? 0);
        $balance = max(0, round($total - $currentPaid, 2));

        if ($balance <= 0) {
            throw new InvalidArgumentException('This invoice is already fully paid.');
        }

        $applied = min($amount, $balance);
        $newPaid = round($currentPaid + $applied, 2);
        $newBalance = max(0, round($total - $newPaid, 2));
        $status = $this->resolveInvoiceStatus($newPaid, $total, $invoice->due_date);

        $invoice->update([
            'amount_paid' => $newPaid,
            'status' => $status,
            'notes' => $this->appendPaymentNote($invoice->notes, $applied, $notes),
        ]);

        return [
            'type' => 'invoice',
            'record' => $invoice->fresh(['customer:id,name,email,phone', 'items']),
            'amount_paid' => $newPaid,
            'balance_due' => $newBalance,
            'payment_status' => $status,
        ];
    }

    private function resolveSalesPaymentStatus(float $paid, float $total): string
    {
        if ($paid <= 0) {
            return 'pending';
        }

        return $paid + 0.001 >= $total ? 'paid' : 'partial';
    }

    private function resolveInvoiceStatus(float $paid, float $total, mixed $dueDate): string
    {
        if ($paid + 0.001 >= $total) {
            return 'paid';
        }

        if ($dueDate && Carbon::parse($dueDate)->endOfDay()->isPast()) {
            return 'overdue';
        }

        return 'pending';
    }

    private function appendPaymentNote(?string $existing, float $applied, ?string $notes): string
    {
        $line = 'Payment recorded: '.number_format($applied, 2);
        if ($notes) {
            $line .= ' — '.$notes;
        }

        return trim(($existing ? $existing."\n" : '').$line);
    }
}
