<?php

namespace App\Http\Controllers\Api;

use App\Models\NotificationPreference;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationTypeRegistry;
use App\Services\Notifications\NotificationPreferenceResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NotificationPreferenceController extends ApiController
{
    public function __construct(
        protected NotificationTypeRegistry $registry,
        protected NotificationPreferenceResolver $resolver,
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $farmId = $request->filled('farm_id') ? (int) $request->farm_id : null;

        $this->resolver->forget();

        return $this->sendResponse([
            'catalog' => $this->registry->catalog(),
            'preferences' => $this->resolver->matrixFor($user, $farmId),
            'settings' => $user->notificationSettingsOrDefault(),
            'farm_id' => $farmId,
        ], 'Notification preferences retrieved');
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'farm_id' => 'nullable|integer',
            'preferences' => 'required|array|min:1',
            'preferences.*.type' => 'required|string|max:64',
            'preferences.*.in_app' => 'required|boolean',
            'preferences.*.email' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $user = $request->user();
        $farmId = $request->filled('farm_id') ? (int) $request->farm_id : null;
        $rejected = [];

        foreach ($request->input('preferences') as $preference) {
            $type = $preference['type'];

            if (!$this->registry->has($type)) {
                $rejected[$type] = 'Unknown notification type';
                continue;
            }

            $inApp = (bool) $preference['in_app'];
            $email = (bool) $preference['email'];

            // Mandatory channels defined by the catalog or the farm admin can
            // never be switched off, so silently keep them on.
            if (!$this->registry->userCanToggle($type, NotificationChannel::IN_APP)) {
                $inApp = true;
            }
            if (!$this->registry->userCanToggle($type, NotificationChannel::EMAIL)) {
                $email = true;
            }

            NotificationPreference::updateOrCreate(
                ['user_id' => $user->id, 'farm_id' => $farmId, 'type' => $type],
                ['in_app' => $inApp, 'email' => $email],
            );
        }

        $this->resolver->forget();

        return $this->sendResponse([
            'preferences' => $this->resolver->matrixFor($user->fresh(['notificationSettings']), $farmId),
            'rejected' => $rejected,
        ], 'Notification preferences updated');
    }

    public function updateSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sound_enabled' => 'nullable|boolean',
            'browser_push_enabled' => 'nullable|boolean',
            'email_enabled' => 'nullable|boolean',
            'digest_enabled' => 'nullable|boolean',
            'quiet_hours_start' => 'nullable|date_format:H:i,H:i:s',
            'quiet_hours_end' => 'nullable|date_format:H:i,H:i:s',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError('Validation failed', $validator->errors()->toArray());
        }

        $settings = $request->user()->notificationSettingsOrDefault();
        $settings->fill($validator->validated())->save();

        return $this->sendResponse($settings->fresh(), 'Notification settings updated');
    }

    public function reset(Request $request)
    {
        $farmId = $request->filled('farm_id') ? (int) $request->farm_id : null;

        NotificationPreference::query()
            ->where('user_id', $request->user()->id)
            ->where(function ($query) use ($farmId) {
                $farmId === null
                    ? $query->whereNull('farm_id')
                    : $query->where('farm_id', $farmId);
            })
            ->delete();

        $this->resolver->forget();

        return $this->sendResponse([
            'preferences' => $this->resolver->matrixFor($request->user(), $farmId),
        ], 'Notification preferences reset to defaults');
    }
}
