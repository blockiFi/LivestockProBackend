<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\Farm;
use App\Services\CustomerPaymentService;
use App\Services\CustomerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CustomerController extends ApiController
{
    public function __construct(
        private readonly CustomerService $customerService,
        private readonly CustomerPaymentService $paymentService
    ) {
    }

    public function index(Request $request, $farmId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $this->canViewCustomers($request, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view customers');
        }

        $activeOnly = $request->has('active')
            ? filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        $customers = $this->customerService->listForFarm(
            $farm,
            $request->input('search'),
            $activeOnly
        );

        $customers = $customers->map(function (Customer $customer) {
            $summary = $this->customerService->summary($customer);

            return array_merge($customer->toArray(), ['summary' => $summary]);
        });

        return $this->sendResponse($customers, 'Customers retrieved successfully');
    }

    public function store(Request $request, $farmId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $request->user()->hasPermissionTo('create customers', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create customers');
        }

        $validator = Validator::make($request->all(), $this->rules($farmId));
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $customer = Customer::create(array_merge(
            $validator->validated(),
            ['farm_id' => $farm->id, 'is_active' => $request->boolean('is_active', true)]
        ));

        return $this->sendResponse(
            $customer->load('country:id,name,iso_code'),
            'Customer created successfully',
            201
        );
    }

    public function show(Request $request, $farmId, $customerId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $this->canViewCustomers($request, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view customers');
        }

        $customer = Customer::where('farm_id', $farm->id)
            ->with('country:id,name,iso_code')
            ->findOrFail($customerId);

        return $this->sendResponse([
            'customer' => $customer,
            'summary' => $this->customerService->summary($customer),
        ], 'Customer retrieved successfully');
    }

    public function update(Request $request, $farmId, $customerId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $request->user()->hasPermissionTo('update customers', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update customers');
        }

        $customer = Customer::where('farm_id', $farm->id)->findOrFail($customerId);

        $validator = Validator::make($request->all(), $this->rules($farmId, $customer->id));
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $customer->fill($validator->validated());
        if ($request->has('is_active')) {
            $customer->is_active = $request->boolean('is_active');
        }
        $customer->save();

        return $this->sendResponse(
            $customer->load('country:id,name,iso_code'),
            'Customer updated successfully'
        );
    }

    public function destroy(Request $request, $farmId, $customerId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $request->user()->hasPermissionTo('delete customers', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to delete customers');
        }

        $customer = Customer::where('farm_id', $farm->id)->findOrFail($customerId);
        $customer->delete();

        return $this->sendResponse(null, 'Customer deleted successfully');
    }

    public function history(Request $request, $farmId, $customerId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $this->canViewCustomers($request, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view customer history');
        }

        $customer = Customer::where('farm_id', $farm->id)->findOrFail($customerId);
        $history = $this->customerService->history(
            $customer,
            $request->input('type'),
            (int) $request->input('per_page', 15)
        );

        return $this->sendResponse($history, 'Customer history retrieved successfully');
    }

    public function recordPayment(Request $request, $farmId, $customerId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $this->canRecordPayments($request, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to record customer payments');
        }

        $customer = Customer::where('farm_id', $farm->id)->findOrFail($customerId);

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:product,invoice',
            'id' => 'required|integer|min:1',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        try {
            $result = $this->paymentService->recordPayment(
                $farm,
                $customer,
                $request->input('type'),
                (int) $request->input('id'),
                (float) $request->input('amount'),
                $request->input('payment_method'),
                $request->input('notes')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }

        return $this->sendResponse([
            'payment' => [
                'type' => $result['type'],
                'id' => $result['record']->id,
                'amount_paid' => $result['amount_paid'],
                'balance_due' => $result['balance_due'],
                'payment_status' => $result['payment_status'],
            ],
            'summary' => $this->customerService->summary($customer),
        ], 'Payment recorded successfully');
    }

    private function canRecordPayments(Request $request, Farm $farm): bool
    {
        return $this->canAny($request->user(), $farm, [
            'update sales',
            'manage sales',
            'update invoices',
            'manage customers',
            'update customers',
            'create sales',
        ]);
    }

    private function canAny($user, Farm $farm, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            try {
                if ($user->hasPermissionTo($permission, 'api', $farm)) {
                    return true;
                }
            } catch (\App\Exceptions\PermissionDoesNotExist) {
                continue;
            }
        }

        return false;
    }

    private function canViewCustomers(Request $request, Farm $farm): bool
    {
        return $request->user()->hasPermissionTo('view customers', 'api', $farm)
            || $request->user()->hasPermissionTo('view sales', 'api', $farm);
    }

    private function rules(int $farmId, ?int $customerId = null): array
    {
        $uniqueRule = 'unique:customers,name,NULL,id,farm_id,'.$farmId.',deleted_at,NULL';
        if ($customerId) {
            $uniqueRule = 'unique:customers,name,'.$customerId.',id,farm_id,'.$farmId.',deleted_at,NULL';
        }

        return [
            'name' => ['required', 'string', 'max:255', $uniqueRule],
            'company_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country_id' => 'required|exists:countries,id',
            'notes' => 'nullable|string|max:5000',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
