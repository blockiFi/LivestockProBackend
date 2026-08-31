<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\PermissionRegistrar;

class FarmSettingController extends ApiController
{
    public function show(Farm $farm)
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('view farm', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to view this farm', [], 403);
        }

        return $this->sendResponse($farm->settingsOrDefault(), 'Farm settings retrieved successfully');
    }

    public function update(Request $request, Farm $farm)
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (!auth()->user()->can('manage farm settings', 'api', $farm->id)) {
            return $this->sendError('You do not have permission to manage farm settings', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'currency_code' => 'sometimes|required|string|size:3',
            'currency_symbol' => 'sometimes|required|string|max:8',
            'timezone' => 'sometimes|required|string|max:100',
            'date_format' => 'sometimes|required|string|max:20',
            'fiscal_year_start_month' => 'sometimes|required|integer|min:1|max:12',
            'invoice_prefix' => 'sometimes|required|string|max:20',
            'invoice_next_number' => 'sometimes|required|integer|min:1',
            'invoice_tax_enabled' => 'sometimes|required|boolean',
            'invoice_tax_rate' => 'sometimes|required|numeric|min:0|max:100',
            'invoice_payment_instructions' => 'nullable|string',
            'invoice_footer_note' => 'nullable|string',
            'schedule_reminder_days' => 'sometimes|required|integer|min:0|max:365',
            'low_stock_alerts_enabled' => 'sometimes|required|boolean',
            'mortality_alert_percent' => 'sometimes|required|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        $settings = $farm->settingsOrDefault();
        $settings->update($validator->validated());

        return $this->sendResponse($settings->fresh(), 'Farm settings updated successfully');
    }
}
