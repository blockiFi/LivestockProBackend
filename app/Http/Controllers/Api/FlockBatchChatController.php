<?php

namespace App\Http\Controllers\Api;

use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockChatMemory;
use App\Models\FlockChatSession;
use App\Services\BatchChat\BatchChatAgentService;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

class FlockBatchChatController extends ApiController
{
    public function __construct(protected BatchChatAgentService $agent)
    {
    }

    public function indexSessions(Request $request, $farm, $flock)
    {
        [$farm, $flock, $error] = $this->authorizeFlock($request, $farm, $flock);
        if ($error) {
            return $error;
        }

        $sessions = FlockChatSession::query()
            ->where('farm_id', $farm->id)
            ->where('flock_id', $flock->id)
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (FlockChatSession $s) {
                $preview = $s->messages()
                    ->where('role', 'user')
                    ->orderByDesc('id')
                    ->value('content');

                return [
                    'id' => $s->id,
                    'title' => $s->title ?: 'New chat',
                    'status' => $s->status,
                    'last_message_at' => optional($s->last_message_at)?->toIso8601String(),
                    'has_memory_summary' => filled($s->memory_summary),
                    'preview' => $preview ? \Illuminate\Support\Str::limit($preview, 80) : null,
                    'created_at' => optional($s->created_at)?->toIso8601String(),
                ];
            });

        return $this->sendResponse(['sessions' => $sessions], 'Batch chat sessions retrieved');
    }

    public function storeSession(Request $request, $farm, $flock)
    {
        [$farm, $flock, $error] = $this->authorizeFlock($request, $farm, $flock);
        if ($error) {
            return $error;
        }

        $session = FlockChatSession::create([
            'farm_id' => $farm->id,
            'flock_id' => $flock->id,
            'user_id' => $request->user()->id,
            'title' => null,
            'status' => FlockChatSession::STATUS_ACTIVE,
            'last_message_at' => now(),
        ]);

        return $this->sendResponse($this->sessionPayload($session), 'Batch chat session created', 201);
    }

    public function showSession(Request $request, $farm, $flock, $session)
    {
        [$farm, $flock, $error] = $this->authorizeFlock($request, $farm, $flock);
        if ($error) {
            return $error;
        }

        $session = $this->ownedSession($farm, $flock, $request->user()->id, $session);

        return $this->sendResponse($this->sessionPayload($session), 'Batch chat session retrieved');
    }

    public function updateSession(Request $request, $farm, $flock, $session)
    {
        [$farm, $flock, $error] = $this->authorizeFlock($request, $farm, $flock);
        if ($error) {
            return $error;
        }

        $session = $this->ownedSession($farm, $flock, $request->user()->id, $session);

        $data = $request->validate([
            'title' => 'sometimes|nullable|string|max:120',
            'status' => 'sometimes|in:active,archived',
        ]);

        $session->fill($data)->save();

        return $this->sendResponse($this->sessionPayload($session->fresh()), 'Batch chat session updated');
    }

    public function destroySession(Request $request, $farm, $flock, $session)
    {
        [$farm, $flock, $error] = $this->authorizeFlock($request, $farm, $flock);
        if ($error) {
            return $error;
        }

        $session = $this->ownedSession($farm, $flock, $request->user()->id, $session);
        $session->delete();

        return $this->sendResponse(null, 'Batch chat session deleted');
    }

    public function messages(Request $request, $farm, $flock, $session)
    {
        [$farm, $flock, $error] = $this->authorizeFlock($request, $farm, $flock);
        if ($error) {
            return $error;
        }

        $session = $this->ownedSession($farm, $flock, $request->user()->id, $session);

        return $this->sendResponse([
            'session' => $this->sessionPayload($session),
            'messages' => $this->agent->publicMessages($session),
        ], 'Batch chat messages retrieved');
    }

    public function postMessage(Request $request, $farm, $flock, $session)
    {
        [$farm, $flock, $error] = $this->authorizeFlock($request, $farm, $flock);
        if ($error) {
            return $error;
        }

        $session = $this->ownedSession($farm, $flock, $request->user()->id, $session);

        $data = $request->validate([
            'content' => 'required|string|max:4000',
        ]);

        try {
            $result = $this->agent->handleUserMessage(
                $session,
                $farm,
                $flock,
                $request->user(),
                $data['content']
            );
        } catch (\InvalidArgumentException $e) {
            return $this->sendValidationError($e->getMessage());
        } catch (\Throwable $e) {
            return $this->sendError($e->getMessage(), [], 500);
        }

        return $this->sendResponse([
            'session' => $this->sessionPayload($result['session']),
            'messages' => $result['messages'],
            'assistant_message' => $this->messagePayload($result['assistant_message']),
            'pending_actions' => $result['pending_actions'],
            'refresh' => $result['refresh'],
        ], 'Message processed');
    }

    public function confirmAction(Request $request, $farm, $flock, $session, string $actionId)
    {
        [$farm, $flock, $error] = $this->authorizeFlock($request, $farm, $flock);
        if ($error) {
            return $error;
        }

        $session = $this->ownedSession($farm, $flock, $request->user()->id, $session);

        try {
            $result = $this->agent->confirmAction(
                $session,
                $farm,
                $flock,
                $request->user(),
                $actionId
            );
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        } catch (\Throwable $e) {
            return $this->sendError($e->getMessage(), [], 500);
        }

        return $this->sendResponse([
            'session' => $this->sessionPayload($result['session']),
            'messages' => $result['messages'],
            'assistant_message' => $this->messagePayload($result['assistant_message']),
            'pending_actions' => $result['pending_actions'],
            'refresh' => $result['refresh'],
            'tool_result' => $result['tool_result'] ?? null,
        ], 'Action confirmed');
    }

    public function cancelAction(Request $request, $farm, $flock, $session, string $actionId)
    {
        [$farm, $flock, $error] = $this->authorizeFlock($request, $farm, $flock);
        if ($error) {
            return $error;
        }

        $session = $this->ownedSession($farm, $flock, $request->user()->id, $session);

        try {
            $result = $this->agent->cancelAction($session, $actionId);
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }

        return $this->sendResponse([
            'session' => $this->sessionPayload($result['session']),
            'messages' => $result['messages'],
            'assistant_message' => $this->messagePayload($result['assistant_message']),
            'pending_actions' => $result['pending_actions'],
        ], 'Action cancelled');
    }

    public function indexMemories(Request $request, $farm, $flock)
    {
        [$farm, $flock, $error] = $this->authorizeFlock($request, $farm, $flock);
        if ($error) {
            return $error;
        }

        $memories = FlockChatMemory::query()
            ->where('farm_id', $farm->id)
            ->where('flock_id', $flock->id)
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'kind', 'content', 'created_at']);

        return $this->sendResponse(['memories' => $memories], 'Batch chat memories retrieved');
    }

    public function destroyMemories(Request $request, $farm, $flock)
    {
        [$farm, $flock, $error] = $this->authorizeFlock($request, $farm, $flock);
        if ($error) {
            return $error;
        }

        FlockChatMemory::query()
            ->where('farm_id', $farm->id)
            ->where('flock_id', $flock->id)
            ->where('user_id', $request->user()->id)
            ->delete();

        return $this->sendResponse(null, 'Batch chat memories cleared');
    }

    /**
     * @return array{0: ?Farm, 1: ?Flock, 2: mixed}
     */
    private function authorizeFlock(Request $request, $farm, $flock): array
    {
        $farm = Farm::findOrFail($farm);
        $flock = Flock::where('farm_id', $farm->id)->findOrFail($flock);

        app(PermissionRegistrar::class)->setPermissionsTeamId($farm->id);

        if (! $request->user()->can('view flocks')) {
            return [null, null, $this->sendUnauthorizedError('You do not have permission to view flocks')];
        }

        return [$farm, $flock, null];
    }

    private function ownedSession(Farm $farm, Flock $flock, int $userId, $sessionId): FlockChatSession
    {
        return FlockChatSession::query()
            ->where('farm_id', $farm->id)
            ->where('flock_id', $flock->id)
            ->where('user_id', $userId)
            ->findOrFail($sessionId);
    }

    private function sessionPayload(FlockChatSession $session): array
    {
        return [
            'id' => $session->id,
            'title' => $session->title,
            'status' => $session->status,
            'last_message_at' => optional($session->last_message_at)?->toIso8601String(),
            'has_memory_summary' => filled($session->memory_summary),
            'created_at' => optional($session->created_at)?->toIso8601String(),
        ];
    }

    private function messagePayload($message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'pending_actions' => $message->pending_actions,
            'metadata' => $message->metadata,
            'created_at' => optional($message->created_at)?->toIso8601String(),
        ];
    }
}
