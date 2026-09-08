<?php

namespace App\Services\Notifications;

use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerAccountTransaction;
use App\Models\Farm;
use App\Notifications\NotificationMessage;
use App\Notifications\NotificationPriority;
use App\Notifications\NotificationType;

class CustomerAccountNotifier
{
    public const RECIPIENT_PERMISSIONS = ['view customers', 'manage customers', 'view customer accounts'];

    public function __construct(protected NotificationService $notifications)
    {
    }

    public function topUp(
        Farm $farm,
        Customer $customer,
        CustomerAccountTransaction $transaction,
        CustomerAccount $account
    ): void {
        $amount = number_format((float) $transaction->amount, 2);
        $balance = number_format((float) $account->balance, 2);

        $this->notifications->send(
            NotificationMessage::make(NotificationType::CUSTOMER_ACCOUNT_TOP_UP)
                ->farm($farm)
                ->toFarmMembersWithPermission(...self::RECIPIENT_PERMISSIONS)
                ->title('Customer account topped up')
                ->body("{$customer->name} credited with {$amount}. New balance: {$balance}.")
                ->action('/dashboard/crm/customers/'.$customer->id, 'View customer')
                ->dedupe('customer_account_top_up:'.$transaction->id)
                ->with([
                    'customer_name' => $customer->name,
                    'amount' => $amount,
                    'new_balance' => $balance,
                ])
        );
    }

    public function salePayment(
        Farm $farm,
        Customer $customer,
        CustomerAccountTransaction $transaction,
        CustomerAccount $account
    ): void {
        $amount = number_format((float) $transaction->amount, 2);
        $balance = number_format((float) $account->balance, 2);
        $saleId = $transaction->sales_record_id;

        $this->notifications->send(
            NotificationMessage::make(NotificationType::CUSTOMER_ACCOUNT_PAYMENT)
                ->farm($farm)
                ->toFarmMembersWithPermission(...self::RECIPIENT_PERMISSIONS)
                ->title('Customer account payment')
                ->body("{$amount} deducted from {$customer->name}'s account for Sale #{$saleId}. Remaining: {$balance}.")
                ->priority(NotificationPriority::NORMAL)
                ->action('/dashboard/crm/customers/'.$customer->id, 'View customer')
                ->dedupe('customer_account_payment:'.$transaction->id)
                ->with([
                    'customer_name' => $customer->name,
                    'amount' => $amount,
                    'new_balance' => $balance,
                    'sale_id' => $saleId,
                ])
        );

        if ($account->isLowBalance()) {
            $this->lowBalance($farm, $customer, $account);
        }
    }

    public function lowBalance(Farm $farm, Customer $customer, CustomerAccount $account): void
    {
        $balance = number_format((float) $account->balance, 2);
        $threshold = number_format((float) $account->low_balance_threshold, 2);

        $this->notifications->send(
            NotificationMessage::make(NotificationType::CUSTOMER_ACCOUNT_LOW_BALANCE)
                ->farm($farm)
                ->toFarmMembersWithPermission(...self::RECIPIENT_PERMISSIONS)
                ->title('Low customer account balance')
                ->body("{$customer->name} balance is {$balance} (threshold {$threshold}).")
                ->priority(NotificationPriority::HIGH)
                ->action('/dashboard/crm/customers/'.$customer->id, 'View customer')
                ->dedupe('customer_account_low:'.$customer->id.':'.now()->format('Ymd'))
                ->with([
                    'customer_name' => $customer->name,
                    'new_balance' => $balance,
                    'threshold' => $threshold,
                ])
        );
    }
}
