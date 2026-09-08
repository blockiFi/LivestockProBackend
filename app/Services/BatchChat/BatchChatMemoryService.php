<?php

namespace App\Services\BatchChat;

use App\Models\FlockChatMemory;
use App\Models\FlockChatMessage;
use App\Models\FlockChatSession;
use App\Services\LlmService;
use Illuminate\Support\Collection;

class BatchChatMemoryService
{
    public const RECENT_MESSAGE_LIMIT = 24;

    public const MEMORY_NOTE_LIMIT = 20;

    public function __construct(protected LlmService $llm)
    {
    }

    /**
     * @return list<array{id: int, kind: string, content: string}>
     */
    public function durableNotes(int $farmId, int $flockId, int $userId): array
    {
        return FlockChatMemory::query()
            ->where('farm_id', $farmId)
            ->where('flock_id', $flockId)
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(self::MEMORY_NOTE_LIMIT)
            ->get(['id', 'kind', 'content'])
            ->map(fn ($m) => [
                'id' => (int) $m->id,
                'kind' => (string) $m->kind,
                'content' => (string) $m->content,
            ])
            ->values()
            ->all();
    }

    public function rememberActionOutcome(
        FlockChatSession $session,
        string $toolName,
        string $message
    ): void {
        FlockChatMemory::create([
            'farm_id' => $session->farm_id,
            'flock_id' => $session->flock_id,
            'user_id' => $session->user_id,
            'kind' => FlockChatMemory::KIND_ACTION_OUTCOME,
            'content' => trim("Action {$toolName}: {$message}"),
            'source_session_id' => $session->id,
        ]);

        // Keep only the newest MEMORY_NOTE_LIMIT notes
        $ids = FlockChatMemory::query()
            ->where('farm_id', $session->farm_id)
            ->where('flock_id', $session->flock_id)
            ->where('user_id', $session->user_id)
            ->orderByDesc('id')
            ->skip(self::MEMORY_NOTE_LIMIT)
            ->take(100)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            FlockChatMemory::whereIn('id', $ids)->delete();
        }
    }

    /**
     * When the session has too many messages, fold older ones into memory_summary.
     *
     * @param  Collection<int, FlockChatMessage>  $allMessages
     * @return Collection<int, FlockChatMessage>  recent window to send to the LLM
     */
    public function compressIfNeeded(FlockChatSession $session, Collection $allMessages): Collection
    {
        if ($allMessages->count() <= self::RECENT_MESSAGE_LIMIT) {
            return $allMessages;
        }

        $dropCount = $allMessages->count() - self::RECENT_MESSAGE_LIMIT;
        $toSummarize = $allMessages->take($dropCount);
        $recent = $allMessages->slice($dropCount)->values();

        $transcript = $toSummarize
            ->filter(fn (FlockChatMessage $m) => in_array($m->role, ['user', 'assistant'], true))
            ->map(function (FlockChatMessage $m) {
                $text = trim((string) $m->content);
                if ($text === '') {
                    return null;
                }

                return strtoupper($m->role).': '.$text;
            })
            ->filter()
            ->implode("\n");

        if ($transcript !== '') {
            $existing = trim((string) ($session->memory_summary ?? ''));
            $prompt = "Existing session memory summary:\n".($existing !== '' ? $existing : '(none)')."\n\n"
                ."Older conversation to fold in:\n{$transcript}\n\n"
                .'Write an updated concise memory summary (max ~250 words) capturing facts, preferences, '
                .'and outcomes relevant to managing this poultry batch. Plain text only.';

            $summary = $this->llm->chat(
                'You compress farm batch chat history into durable memory for a poultry assistant.',
                $prompt
            );

            if (is_string($summary) && trim($summary) !== '') {
                $session->memory_summary = trim($summary);
                $session->save();
            } elseif ($existing === '') {
                $session->memory_summary = mb_substr($transcript, 0, 2000);
                $session->save();
            }
        }

        return $recent;
    }
}
