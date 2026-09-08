<?php

namespace App\Services\BatchChat;

use App\Models\Farm;
use App\Models\Flock;
use App\Models\FlockChatMessage;
use App\Models\FlockChatSession;
use App\Models\User;
use App\Services\LlmService;
use Illuminate\Support\Str;

class BatchChatAgentService
{
    public const MAX_USER_CHARS = 4000;

    public const MAX_TOOL_LOOPS = 5;

    public const PENDING_TTL_MINUTES = 30;

    public function __construct(
        protected LlmService $llm,
        protected BatchChatContextService $context,
        protected BatchChatToolRegistry $registry,
        protected BatchChatToolExecutor $executor,
        protected BatchChatMemoryService $memory,
    ) {
    }

    /**
     * @return array{
     *   session: FlockChatSession,
     *   messages: list<FlockChatMessage>,
     *   assistant_message: FlockChatMessage,
     *   pending_actions: list<array>,
     *   refresh: list<string>
     * }
     */
    public function handleUserMessage(FlockChatSession $session, Farm $farm, Flock $flock, User $user, string $content): array
    {
        $content = trim(mb_substr($content, 0, self::MAX_USER_CHARS));
        if ($content === '') {
            throw new \InvalidArgumentException('Message content is required.');
        }

        if (! $session->title) {
            $session->title = Str::limit($content, 60, '…');
        }

        $userMessage = FlockChatMessage::create([
            'session_id' => $session->id,
            'role' => 'user',
            'content' => $content,
        ]);

        $session->last_message_at = now();
        $session->save();

        $llmMessages = $this->buildLlmMessages($session, $farm, $flock, $user);
        $tools = $this->registry->openAiTools();

        $pendingActions = [];
        $refresh = [];
        $assistantContent = null;
        $lastLlmError = null;

        for ($i = 0; $i < self::MAX_TOOL_LOOPS; $i++) {
            $result = $this->llm->chatWithTools($llmMessages, $tools, ['temperature' => 0.3]);
            if ($result === null) {
                $lastLlmError = $this->llm->getLastError();
                $assistantContent = 'Sorry — the AI service is unavailable right now. '.$lastLlmError;
                break;
            }

            $toolCalls = $result['tool_calls'] ?? [];
            $assistantContent = $result['content'];

            if ($toolCalls === []) {
                break;
            }

            $assistantToolMessage = [
                'role' => 'assistant',
                'content' => $assistantContent,
                'tool_calls' => array_map(fn ($c) => [
                    'id' => $c['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $c['name'],
                        'arguments' => json_encode($c['arguments'] ?? new \stdClass),
                    ],
                ], $toolCalls),
            ];
            $llmMessages[] = $assistantToolMessage;

            $hasPendingWrite = false;
            foreach ($toolCalls as $call) {
                $name = $call['name'];
                $args = $call['arguments'] ?? [];

                if ($this->registry->isWriteTool($name)) {
                    $actionId = (string) Str::uuid();
                    $pendingActions[] = [
                        'id' => $actionId,
                        'tool_call_id' => $call['id'],
                        'tool' => $name,
                        'arguments' => $args,
                        'summary' => $this->registry->humanSummary($name, $args),
                        'expires_at' => now()->addMinutes(self::PENDING_TTL_MINUTES)->toIso8601String(),
                    ];
                    $hasPendingWrite = true;
                    continue;
                }

                $exec = $this->executor->execute($name, $args, $farm, $flock, $user);
                if (! empty($exec['refresh']) && is_array($exec['refresh'])) {
                    $refresh = array_values(array_unique(array_merge($refresh, $exec['refresh'])));
                }

                $toolPayload = json_encode($exec);
                $llmMessages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'content' => $toolPayload,
                ];

                FlockChatMessage::create([
                    'session_id' => $session->id,
                    'role' => 'tool',
                    'content' => $toolPayload,
                    'tool_call_id' => $call['id'],
                    'tool_name' => $name,
                    'metadata' => ['result' => $exec],
                ]);
            }

            if ($hasPendingWrite) {
                if (! $assistantContent) {
                    $assistantContent = 'I can do that — please confirm the action below.';
                }
                break;
            }
        }

        if ($assistantContent === null || trim($assistantContent) === '') {
            $assistantContent = $pendingActions !== []
                ? 'Please confirm the proposed action(s) below.'
                : 'I could not generate a response. Please try again.';
        }

        $assistantMessage = FlockChatMessage::create([
            'session_id' => $session->id,
            'role' => 'assistant',
            'content' => $assistantContent,
            'pending_actions' => $pendingActions !== [] ? $pendingActions : null,
            'metadata' => array_filter([
                'refresh' => $refresh !== [] ? $refresh : null,
                'llm_error' => $lastLlmError,
            ]),
        ]);

        $session->last_message_at = now();
        $session->save();

        return [
            'session' => $session->fresh(),
            'user_message' => $userMessage,
            'assistant_message' => $assistantMessage,
            'pending_actions' => $pendingActions,
            'refresh' => $refresh,
            'messages' => $this->publicMessages($session),
        ];
    }

    /**
     * @return array{
     *   session: FlockChatSession,
     *   assistant_message: FlockChatMessage,
     *   pending_actions: list<array>,
     *   refresh: list<string>
     * }
     */
    public function confirmAction(
        FlockChatSession $session,
        Farm $farm,
        Flock $flock,
        User $user,
        string $actionId
    ): array {
        $assistantWithPending = FlockChatMessage::query()
            ->where('session_id', $session->id)
            ->where('role', 'assistant')
            ->whereNotNull('pending_actions')
            ->orderByDesc('id')
            ->first();

        if (! $assistantWithPending) {
            throw new \RuntimeException('No pending actions found for this chat.');
        }

        $actions = $assistantWithPending->pending_actions ?? [];
        $match = null;
        $matchIndex = null;
        foreach ($actions as $i => $action) {
            if (($action['id'] ?? null) === $actionId) {
                $match = $action;
                $matchIndex = $i;
                break;
            }
        }

        if (! $match) {
            throw new \RuntimeException('Pending action not found or already handled.');
        }

        $expiresAt = isset($match['expires_at']) ? strtotime((string) $match['expires_at']) : false;
        if ($expiresAt !== false && $expiresAt < time()) {
            throw new \RuntimeException('This pending action has expired. Ask again to recreate it.');
        }

        $tool = (string) ($match['tool'] ?? '');
        $args = is_array($match['arguments'] ?? null) ? $match['arguments'] : [];
        $toolCallId = (string) ($match['tool_call_id'] ?? ('confirmed_'.$actionId));

        $exec = $this->executor->execute($tool, $args, $farm, $flock, $user);

        // Remove this pending action from the message
        array_splice($actions, (int) $matchIndex, 1);
        $assistantWithPending->pending_actions = $actions !== [] ? array_values($actions) : null;
        $assistantWithPending->save();

        FlockChatMessage::create([
            'session_id' => $session->id,
            'role' => 'tool',
            'content' => json_encode($exec),
            'tool_call_id' => $toolCallId,
            'tool_name' => $tool,
            'metadata' => ['result' => $exec, 'confirmed_action_id' => $actionId],
        ]);

        if ($exec['ok'] ?? false) {
            $this->memory->rememberActionOutcome($session, $tool, (string) ($exec['message'] ?? 'OK'));
        }

        // Continue the LLM with the tool result so it can narrate outcome.
        $llmMessages = $this->buildLlmMessages($session, $farm, $flock, $user);
        $llmMessages[] = [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [[
                'id' => $toolCallId,
                'type' => 'function',
                'function' => [
                    'name' => $tool,
                    'arguments' => json_encode($args),
                ],
            ]],
        ];
        $llmMessages[] = [
            'role' => 'tool',
            'tool_call_id' => $toolCallId,
            'content' => json_encode($exec),
        ];

        $followUp = $this->llm->chatWithTools($llmMessages, [], ['temperature' => 0.3]);
        $text = $followUp['content']
            ?? (($exec['ok'] ?? false)
                ? (string) ($exec['message'] ?? 'Done.')
                : ('Action failed: '.($exec['message'] ?? 'unknown error')));

        $refresh = is_array($exec['refresh'] ?? null) ? $exec['refresh'] : [];

        $assistantMessage = FlockChatMessage::create([
            'session_id' => $session->id,
            'role' => 'assistant',
            'content' => $text,
            'metadata' => [
                'confirmed_action_id' => $actionId,
                'refresh' => $refresh,
                'tool_result' => $exec,
            ],
        ]);

        $session->last_message_at = now();
        $session->save();

        return [
            'session' => $session->fresh(),
            'assistant_message' => $assistantMessage,
            'pending_actions' => $assistantWithPending->fresh()->pending_actions ?? [],
            'refresh' => $refresh,
            'messages' => $this->publicMessages($session),
            'tool_result' => $exec,
        ];
    }

    public function cancelAction(FlockChatSession $session, string $actionId): array
    {
        $assistantWithPending = FlockChatMessage::query()
            ->where('session_id', $session->id)
            ->where('role', 'assistant')
            ->whereNotNull('pending_actions')
            ->orderByDesc('id')
            ->first();

        if (! $assistantWithPending) {
            throw new \RuntimeException('No pending actions found for this chat.');
        }

        $actions = $assistantWithPending->pending_actions ?? [];
        $filtered = array_values(array_filter(
            $actions,
            fn ($a) => ($a['id'] ?? null) !== $actionId
        ));

        if (count($filtered) === count($actions)) {
            throw new \RuntimeException('Pending action not found.');
        }

        $assistantWithPending->pending_actions = $filtered !== [] ? $filtered : null;
        $assistantWithPending->save();

        $assistantMessage = FlockChatMessage::create([
            'session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'Cancelled. No changes were made.',
            'metadata' => ['cancelled_action_id' => $actionId],
        ]);

        $session->last_message_at = now();
        $session->save();

        return [
            'session' => $session->fresh(),
            'assistant_message' => $assistantMessage,
            'pending_actions' => $filtered,
            'messages' => $this->publicMessages($session),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildLlmMessages(FlockChatSession $session, Farm $farm, Flock $flock, User $user): array
    {
        $context = $this->context->build($flock);
        $notes = $this->memory->durableNotes((int) $farm->id, (int) $flock->id, (int) $user->id);

        $system = $this->context->systemPrompt($flock)."\n\n"
            ."Live batch context (JSON):\n".json_encode($context)."\n\n"
            .'Durable memories for this user+batch (JSON): '.json_encode($notes)."\n";

        if ($session->memory_summary) {
            $system .= "\nSession memory summary:\n".$session->memory_summary."\n";
        }

        $all = FlockChatMessage::query()
            ->where('session_id', $session->id)
            ->orderBy('id')
            ->get();

        $recent = $this->memory->compressIfNeeded($session->fresh(), $all);

        $messages = [
            ['role' => 'system', 'content' => $system],
        ];

        foreach ($recent as $msg) {
            if ($msg->role === 'user') {
                $messages[] = ['role' => 'user', 'content' => (string) $msg->content];
            } elseif ($msg->role === 'assistant') {
                $messages[] = ['role' => 'assistant', 'content' => (string) ($msg->content ?? '')];
            }
            // Skip persisted tool rows — OpenAI requires tool messages to follow
            // an assistant tool_calls turn in the same request, which we rebuild live.
        }

        return $messages;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function publicMessages(FlockChatSession $session): array
    {
        return FlockChatMessage::query()
            ->where('session_id', $session->id)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('id')
            ->get()
            ->map(fn (FlockChatMessage $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'pending_actions' => $m->pending_actions,
                'metadata' => $m->metadata,
                'created_at' => optional($m->created_at)?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
