<?php

namespace App\Services;

use App\Models\Farm;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the Paystack REST API for NGN subscription billing.
 */
class PaystackService
{
    public function isConfigured(): bool
    {
        return ! empty(config('paystack.secret_key'));
    }

    /**
     * Start a hosted checkout for a farm's plan.
     *
     * Paystack subscribes the customer automatically when the transaction is
     * initialised with a plan code, so a single call covers both cases.
     *
     * @return array{authorization_url:string,access_code:string,reference:string}|null
     */
    public function initializeSubscription(Farm $farm, SubscriptionPlan $plan, User $user): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $payload = [
            'email' => $user->email,
            'amount' => $plan->price_kobo,
            'currency' => $plan->currency,
            'callback_url' => config('paystack.callback_url').'?farm='.$farm->id,
            'metadata' => [
                'farm_id' => $farm->id,
                'plan_slug' => $plan->slug,
                'user_id' => $user->id,
            ],
        ];

        if ($plan->paystack_plan_code) {
            $payload['plan'] = $plan->paystack_plan_code;
        }

        $response = $this->request('post', '/transaction/initialize', $payload);

        if ($response === null) {
            return null;
        }

        return [
            'authorization_url' => $response['authorization_url'] ?? '',
            'access_code' => $response['access_code'] ?? '',
            'reference' => $response['reference'] ?? '',
        ];
    }

    /**
     * Stop future charges while leaving the current period intact.
     */
    public function disableSubscription(string $subscriptionCode, string $emailToken): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        return $this->request('post', '/subscription/disable', [
            'code' => $subscriptionCode,
            'token' => $emailToken,
        ]) !== null;
    }

    public function enableSubscription(string $subscriptionCode, string $emailToken): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        return $this->request('post', '/subscription/enable', [
            'code' => $subscriptionCode,
            'token' => $emailToken,
        ]) !== null;
    }

    /**
     * Paystack signs webhooks with HMAC SHA512 over the raw request body.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        $secret = config('paystack.secret_key');

        if (empty($secret) || empty($signature)) {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $rawBody, $secret), $signature);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    private function request(string $method, string $path, array $payload = []): ?array
    {
        try {
            $response = Http::withToken(config('paystack.secret_key'))
                ->timeout((int) config('paystack.timeout', 30))
                ->acceptJson()
                ->{$method}(config('paystack.base_url').$path, $payload);

            if (! $response->successful()) {
                Log::warning('Paystack request failed', [
                    'path' => $path,
                    'status' => $response->status(),
                    'body' => substr($response->body() ?? '', 0, 500),
                ]);

                return null;
            }

            $body = $response->json();

            if (($body['status'] ?? false) !== true) {
                Log::warning('Paystack returned an error', [
                    'path' => $path,
                    'message' => $body['message'] ?? 'unknown',
                ]);

                return null;
            }

            return $body['data'] ?? [];
        } catch (\Throwable $e) {
            Log::error('Paystack exception', ['path' => $path, 'message' => $e->getMessage()]);

            return null;
        }
    }
}
