<?php

namespace App\Http\Controllers\Api;

use App\Models\LiterType;
use Illuminate\Http\Request;

class LiterTypeController extends ApiController
{
    /**
     * Return all litter (liter) types.
     */
    public function index(Request $request)
    {
        $types = LiterType::query()
            ->orderBy('name')
            ->get(['id', 'name', 'description']);

        return $this->sendResponse($types, 'Liter types retrieved successfully');
    }
}

