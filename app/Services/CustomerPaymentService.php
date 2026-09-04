<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Farm;
use App\Models\Invoice;
use App\Models\SalesRecord;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class CustomerPaymentService
{
    public function recordPayment(
        Farm $farm,
        Customer $customer,
        string $type,
        int $id,
        float $amount,
        ?string $paymentMethod = null,
        ?string $notes = null
    ): array {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        return match ($type) {
            'product' => $this->recordProductSalePayment($farm, $customer, $id, $amount, $paymentMethod, $notes),
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
        ?string $notes
    ): array {
        $record = SalesRecord::where('farm_id', $farm->id)
            ->where('customer_id', $customer->id)
            ->findOrFail($id);

        $total = (float) $record->total_amount;
        $currentPaid = (float) ($record->amount_paid ?? 0);
        $balance = max(0, round($total - $currentPaid, 2));

        if ($balance <= 0) {
            throw new InvalidArgumentException('This sale is already fully paid.');
        }

        $applied = min($amount, $balance);
        $newPaid = round($currentPaid + $applied, 2);
        $newBalance = max(0, round($total - $newPaid, 2));
        $status = $this->resolveSalesPaymentStatus($newPaid, $total);

        $record->update([
            'amount_paid' => $newPaid,
            'payment_status' => $status,
            'payment_method' => $paymentMethod ?: $record->payment_method,
            'notes' => $this->appendPaymentNote($record->notes, $applied, $notes),
        ]);

        return [
            'type' => 'product',
            'record' => $record->fresh(['flock:id,name,batch_number', 'customer:id,name']),
            'amount_paid' => $newPaid,
            'balance_due' => $newBalance,
            'payment_status' => $status,
        ];
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

    public function resolveSalesPaymentStatus(float $amountPaid, float $total): string
    {
        if ($total <= 0 || $amountPaid >= $total) {
            return 'paid';
        }

        return $amountPaid > 0 ? 'partial' : 'pending';
    }

    public function resolveInvoiceStatus(float $amountPaid, float $total, mixed $dueDate): string
    {
        if ($total <= 0 || $amountPaid >= $total) {
            return 'paid';
        }

        if ($dueDate && Carbon::parse($dueDate)->isPast()) {
            return 'overdue';
        }

        return 'pending';
    }

    private function appendPaymentNote(?string $existing, float $applied, ?string $note): ?string
    {
        $line = sprintf('Payment recorded: %s', number_format($applied, 2));
        if ($note) {
            $line .= ' — '.$note;
        }

        return $existing ? trim($existing."\n".$line) : $line;
    }
}
