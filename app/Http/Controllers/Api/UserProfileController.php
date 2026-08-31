<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserProfileController extends ApiController
{
    public function update(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
            'phone' => 'nullable|string|max:20',
            'profile_photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        $data = $validator->validated();

        if ($request->hasFile('profile_photo')) {
            if ($user->profile_photo) {
                Storage::disk('public')->delete($user->profile_photo);
            }

            $data['profile_photo'] = $request->file('profile_photo')->store('profile-photos', 'public');
        }

        $user->update($data);
        $user->load('farms');

        return $this->sendResponse($user, 'User profile updated successfully');
    }

    public function showPreferences(Request $request)
    {
        return $this->sendResponse(
            $request->user()->settingsOrDefault(),
            'User preferences retrieved successfully'
        );
    }

    public function updatePreferences(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'theme' => 'sometimes|required|in:light,dark,system',
            'locale' => 'sometimes|required|string|max:10',
            'timezone' => 'sometimes|required|string|max:100',
            'date_format' => 'sometimes|required|string|max:20',
            'notify_schedules' => 'sometimes|required|boolean',
            'notify_low_stock' => 'sometimes|required|boolean',
            'notify_mortality' => 'sometimes|required|boolean',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation Error', $validator->errors()->all());
        }

        $settings = $request->user()->settingsOrDefault();
        $settings->update($validator->validated());

        return $this->sendResponse($settings->fresh(), 'User preferences updated successfully');
    }

    public function logoutOtherDevices(Request $request)
    {
        $currentToken = $request->user()->currentAccessToken();

        $request->user()
            ->tokens()
            ->when($currentToken, fn ($query) => $query->where('id', '!=', $currentToken->id))
            ->delete();

        return $this->sendResponse([], 'Signed out of other devices successfully');
    }
}
