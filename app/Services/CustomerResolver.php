<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Farm;

class CustomerResolver
{
    /**
     * @return array{customer_id: ?int, customer_name: ?string, customer_phone: ?string}|null
     */
    public static function resolveForFarm(Farm $farm, ?int $customerId, ?string $customerName, ?string $customerPhone): ?array
    {
        if (! $customerId) {
            return [
                'customer_id' => null,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
            ];
        }

        $customer = Customer::where('farm_id', $farm->id)->find($customerId);
        if (! $customer) {
            return null;
        }

        return [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
        ];
    }
}
