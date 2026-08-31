<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use App\Http\Controllers\Api\ApiController;
use App\Services\Notifications\AccountNotifier;
use Illuminate\Support\Str;

use Validator;
class AuthController extends ApiController
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "name" =>  "required",
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|'
      ]);

      if ($validator->fails()) {

            $errors = $validator->messages()->all();
            return $this->sendValidationError("Error Validation Registration Data", $errors);
      }

        // Create the user
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            
        ]);

      

        // Generate token
        $token = $user->createToken('auth_token')->plainTextToken;
        $data = [
                'user' => $user,
                'access_token' => $token,
                'token_type' => 'Bearer',
        ];

        app(AccountNotifier::class)->welcome($user);

       return $this->sendResponse($data , 'User registered successfully' ,  201);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
                'email' => 'required|string|email|exists:users',
                'password' => 'required|string',
          ]);
    
          if ($validator->fails()) {
    
                $errors = $validator->messages()->all();
                return $this->sendValidationError("Error Validation Registration Data", $errors , 401 );
          }
          $user = User::where('email', $request->email)->first();
        if  (! $user || ! Hash::check($request->password, $user->password)){

            return $this->sendError('The provided credentials are incorrect.' , [] , 401);

        }

        $user = User::where('email', $request->email)->firstOrFail();

        if ($user->is_platform_admin) {
            $user->update(['last_admin_login_at' => now()]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;
        $data = [
                'user' => $user,
                'token' => $token,
                'access_token' => $token,
                'token_type' => 'Bearer',
        ];
        return $this->sendResponse($data,'Login successful' , 200 );
        
    }

    public function logout(Request $request)
    {

        $request->user()->currentAccessToken()->delete();
        return $this->sendResponse( [] ,'Successfully logged out' , 200 );
       
    }

    public function forgotPassword(Request $request)
{
    // Validate the email
    $validator = Validator::make($request->all(), [
        'email' => 'required|string|email|exists:users,email',
    ]);

    if ($validator->fails()) {
        return $this->sendValidationError("Error Validating Email", $validator->errors()->all());
    }

    // Override the default URL generation for password reset
    Password::broker()->createToken(
        $user = \App\Models\User::where('email', $request->email)->first()
    );

    // Optional: Send the email yourself using a custom notification
    $status = Password::sendResetLink(
        $request->only('email')
    );

    if ($status === Password::RESET_LINK_SENT) {
        return $this->sendResponse(null, 'Password reset link sent to your email', 200);
    }

    return $this->sendError(
        'Error Sending Reset Link',
        ['email' => [trans($status)]]
    );
}

    public function resetPassword(Request $request)
    {    
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
                $errors = $validator->messages()->all();
                return $this->sendValidationError("Error Validating Reset password Data", $errors);
        }
        
            
        $credentials = $validator->validated();
        
        $status = Password::reset($credentials, function ($user, $password) {
                $user->password = Hash::make($password);
                $user->save();
                app(AccountNotifier::class)->passwordChanged($user);
            });
        
        if ($status === Password::PASSWORD_RESET) {
                return $this->sendResponse(null , 'Password reset successfully' );
        }

        return $this->sendError( 'Error Resesting Password' ,[ 'email' => [trans($status)]]);
    }

    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
                $errors = $validator->messages()->all();
                return $this->sendValidationError("Error Validating Update password Data", $errors);
        }
        
            
        $credentials = $validator->validated();
        $user = $request->user();

        if (!Hash::check($credentials['current_password'], $user->password)) {
            return $this->sendValidationError('The provided password does not match your current password.');
        }

        $user->password = Hash::make($credentials['password']);
        $user->save();

        app(AccountNotifier::class)->passwordChanged($user);

        return $this->sendResponse( [], 'Password updated successfully');
    }
    /**
     * Refresh the user's token.
     */
    public function refreshToken(Request $request)
    {
        $user = $request->user();
        
        // Revoke current token
        $request->user()->currentAccessToken()->delete();

        // Create new token
        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->sendResponse([
            'access_token' => $token,
            'token_type' => 'Bearer',
        ], 'Token refreshed successfully');
    }

    /**
     * Verify email address.
     */
    public function verifyEmail(EmailVerificationRequest $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return $this->sendResponse(
                null,
                'Email already verified'
            );
        }

        if ($request->user()->markEmailAsVerified()) {
            return $this->sendResponse(
                null,
                'Email verified successfully'
            );
        }

        return $this->sendError(
            'Email verification failed',
            [],
            500
        );
    }

    /**
     * Resend email verification link.
     */
    public function resendVerificationEmail(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return $this->sendResponse(
                null,
                'Email already verified'
            );
        }

        $request->user()->sendEmailVerificationNotification();

        return $this->sendResponse(
            null,
            'Verification link sent successfully'
        );
    }
    public function user(Request $request)
    {
        $user = $request->user();

        // Load farms
        $user->load('farms', 'roles', 'permissions');

        $userData = $user->toArray();
        $token = $user->currentAccessToken();

        if ($token && str_starts_with($token->name, 'impersonation:')) {
            $parts = explode(':', $token->name, 3);
            $adminId = isset($parts[1]) ? (int) $parts[1] : null;
            $admin = $adminId ? User::query()->find($adminId) : null;

            $userData['impersonation'] = [
                'active' => true,
                'impersonated_by' => $admin?->only(['id', 'name', 'email']),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ];
        }

        return $this->sendResponse($userData, 'User profile retrieved successfully');
    }
}
