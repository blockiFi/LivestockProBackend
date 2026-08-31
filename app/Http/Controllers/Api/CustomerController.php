<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Customer;
use App\Models\Farm;
use Illuminate\Http\Request;

class CustomerController extends ApiController
{
    public function index(Request $request, $farmId)
    {
        $farm = Farm::findOrFail($farmId);

        if (! $request->user()->hasPermissionTo('view sales', 'api', $farm)
            && ! $request->user()->hasPermissionTo('view customers', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view customers');
        }

        $customers = Customer::where('farm_id', $farmId)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'address', 'city', 'state']);

        return $this->sendResponse($customers, 'Customers retrieved successfully');
    }
}
