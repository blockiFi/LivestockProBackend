<?php

namespace App\Exceptions;

use Exception;

class PermissionDoesNotExist extends Exception
{
    /**
     * Create a new exception instance.
     *
     * @param string $permission
     * @param string $guardName
     * @return void
     */
    public function __construct(string $permission, string $guardName = 'web')
    {
        parent::__construct("Permission `{$permission}` does not exist for guard `{$guardName}`.");
    }

    /**
     * Render the exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function render($request)
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'error' => 'PERMISSION_NOT_FOUND',
            'status_code' => 404
        ], 404);
    }
} 