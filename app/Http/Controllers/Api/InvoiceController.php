<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\Farm;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InvoiceController extends ApiController
{
    public function __construct(
        private readonly InvoiceService $invoiceService
    ) {
    }

    private function canViewInvoices($user, Farm $farm): bool
    {
        return $user->hasPermissionTo('view invoices', 'api', $farm)
            || $user->hasPermissionTo('manage sales', 'api', $farm)
            || $user->hasPermissionTo('view sales', 'api', $farm);
    }

    private function canCreateInvoices($user, Farm $farm): bool
    {
        return $user->hasPermissionTo('create invoices', 'api', $farm)
            || $user->hasPermissionTo('manage sales', 'api', $farm)
            || $user->hasPermissionTo('create sales', 'api', $farm);
    }

    private function canUpdateInvoices($user, Farm $farm): bool
    {
        return $user->hasPermissionTo('update invoices', 'api', $farm)
            || $user->hasPermissionTo('manage sales', 'api', $farm)
            || $user->hasPermissionTo('update sales', 'api', $farm);
    }

    private function canDeleteInvoices($user, Farm $farm): bool
    {
        return $user->hasPermissionTo('delete invoices', 'api', $farm)
            || $user->hasPermissionTo('manage sales', 'api', $farm)
            || $user->hasPermissionTo('delete sales', 'api', $farm);
    }

    public function index(Request $request, $farm, $paginated = null)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (! $this->canViewInvoices($user, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view invoices');
        }

        $query = Invoice::where('farm_id', $farm->id)
            ->with(['customer:id,name,email,phone', 'items'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($paginated) {
            $perPage = (int) $request->input('per_page', 10);
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

        if (! $this->canCreateInvoices($user, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to create invoices');
        }

        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:customers,id',
            'invoice_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:invoice_date',
            'status' => 'nullable|in:pending,paid,overdue',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $customer = Customer::where('farm_id', $farm->id)->find($request->input('customer_id'));
        if (! $customer) {
            return $this->sendValidationError('Validation failed', [
                'customer_id' => ['Customer does not belong to this farm.'],
            ]);
        }

        $totals = $this->invoiceService->calculateTotals($farm, $request->input('items'));

        $invoice = DB::transaction(function () use ($request, $farm, $user, $totals) {
            $invoice = Invoice::create([
                'farm_id' => $farm->id,
                'customer_id' => $request->input('customer_id'),
                'invoice_number' => $this->invoiceService->nextInvoiceNumber($farm),
                'invoice_date' => $request->input('invoice_date'),
                'due_date' => $request->input('due_date'),
                'subtotal' => $totals['subtotal'],
                'tax_amount' => $totals['tax_amount'],
                'total' => $totals['total'],
                'status' => $request->input('status', 'pending'),
                'notes' => $request->input('notes'),
                'created_by' => $user->id,
            ]);

            $invoice->items()->createMany($totals['items']);

            return $invoice->load(['customer', 'items']);
        });

        return $this->sendResponse($invoice, 'Invoice created successfully', 201);
    }

    public function show(Request $request, $farm, $invoice)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (! $this->canViewInvoices($user, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to view invoices');
        }

        $invoice = $this->invoiceService->findForFarm($farm, (int) $invoice);

        return $this->sendResponse($invoice, 'Invoice retrieved successfully');
    }

    public function update(Request $request, $farm, $invoice)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (! $this->canUpdateInvoices($user, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to update invoices');
        }

        $invoice = $this->invoiceService->findForFarm($farm, (int) $invoice);

        $validator = Validator::make($request->all(), [
            'customer_id' => 'sometimes|required|exists:customers,id',
            'invoice_date' => 'sometimes|required|date',
            'due_date' => 'sometimes|required|date',
            'status' => 'sometimes|required|in:pending,paid,overdue',
            'notes' => 'nullable|string',
            'items' => 'sometimes|array|min:1',
            'items.*.description' => 'required_with:items|string|max:255',
            'items.*.quantity' => 'required_with:items|integer|min:1',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        if ($request->filled('customer_id')) {
            $customer = Customer::where('farm_id', $farm->id)->find($request->input('customer_id'));
            if (! $customer) {
                return $this->sendValidationError('Validation failed', [
                    'customer_id' => ['Customer does not belong to this farm.'],
                ]);
            }
        }

        $invoice = DB::transaction(function () use ($request, $farm, $invoice) {
            $payload = $request->only(['customer_id', 'invoice_date', 'due_date', 'status', 'notes']);

            if ($request->has('items')) {
                $totals = $this->invoiceService->calculateTotals($farm, $request->input('items'));
                $payload = array_merge($payload, [
                    'subtotal' => $totals['subtotal'],
                    'tax_amount' => $totals['tax_amount'],
                    'total' => $totals['total'],
                ]);
                $invoice->items()->delete();
                $invoice->items()->createMany($totals['items']);
            }

            $invoice->update($payload);

            return $invoice->fresh(['customer', 'items']);
        });

        return $this->sendResponse($invoice, 'Invoice updated successfully');
    }

    public function destroy(Request $request, $farm, $invoice)
    {
        $user = $request->user();
        $farm = Farm::findOrFail($farm);

        if (! $this->canDeleteInvoices($user, $farm)) {
            return $this->sendUnauthorizedError('Unauthorized to delete invoices');
        }

        $invoice = $this->invoiceService->findForFarm($farm, (int) $invoice);
        $invoice->delete();

        return $this->sendResponse(null, 'Invoice deleted successfully');
    }
}
