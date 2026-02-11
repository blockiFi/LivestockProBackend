<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InvoiceController extends ApiController
{
    //
    public function index(Request $request, $farm , $paginated = null)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view invoices', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view flock weight reports');
        }
        $query = Invoice::where('farm_id', $farm->id)->with('customer', 'items');
        if ($paginated) {
            $perPage = $request->input('per_page', 10);
            $invoices = $query->paginate($perPage);
        } else {
            $invoices = $query->get();
        }
        return $this->sendResponse($invoices, 'Invoices retrieved successfully');
    }
    public function store(Request $request, $farm)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('create invoices', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create invoices');
        }
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:customers,id',
            'invoice_number' => 'required|string|max:255',
            'invoice_date' => 'required|date',
            'due_date' => 'required|date',
            'total' => 'required|numeric|min:0',
            'status' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'items' => 'required|array',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.total' => 'required|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $invoice = Invoice::create($request->all());
        $invoice->items()->createMany($request->items);

        return $this->sendResponse($invoice, 'Invoice created successfully');

    }
    public function show(Request $request, $farm, $invoice)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('view invoices', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view invoices');
        }
        $invoice = Invoice::findOrFail($invoice);
        return $this->sendResponse($invoice, 'Invoice retrieved successfully');
    }
    public function update(Request $request, $farm, $invoice)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('update invoices', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update invoices');
        }
        $validator = Validator::make($request->all(), [
            'customer_id' => 'sometimes|required|exists:customers,id',
            'invoice_number' => 'sometimes|required|string|max:255',
            'invoice_date' => 'sometimes|required|date',
            'due_date' => 'sometimes|required|date',
            'total' => 'sometimes|required|numeric|min:0',
            'status' => 'sometimes|required|string|max:255',
            'notes' => 'nullable|string',
        ]);
        $invoice = Invoice::findOrFail($invoice);
        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }
        $invoice->update($request->all());
        return $this->sendResponse($invoice, 'Invoice updated successfully');
    }
    public function destroy(Request $request, $farm, $invoice)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);
        if (!$user->hasPermissionTo('delete invoices', 'api', $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to delete invoices');
        }
        $invoice = Invoice::findOrFail($invoice);
        $invoice->delete();
        return $this->sendResponse($invoice, 'Invoice deleted successfully');
    }
}
