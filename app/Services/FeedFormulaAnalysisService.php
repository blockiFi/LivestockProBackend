<?php

namespace App\Services;

use App\Models\FeedComponent;
use App\Models\FeedComposition;
use App\Models\PoultryFeedProduct;

class FeedFormulaAnalysisService
{
    public function __construct(protected LlmService $llm)
    {
    }

    /**
     * Calculate weighted nutritional profile from product compositions.
     *
     * @return array<string,float>
     */
    public function calculateProfile(PoultryFeedProduct $product): array
    {
        $items = FeedComposition::with('component')
            ->where('poultry_feed_product_id', $product->id)
            ->get();

        $nutrients = [
            'crude_protein' => 0.0,
            'crude_fat' => 0.0,
            'crude_fiber' => 0.0,
            'calcium' => 0.0,
            'phosphorus' => 0.0,
            'metabolizable_energy' => 0.0,
            'moisture' => 0.0,
            'ash' => 0.0,
        ];

        foreach ($items as $item) {
            /** @var FeedComponent|null $c */
            $c = $item->component;
            if (!$c) {
                continue;
            }
            $pct = (float) $item->percentage;
            foreach (array_keys($nutrients) as $key) {
                $value = (float) ($c->{$key} ?? 0);
                $nutrients[$key] += $value * $pct / 100.0;
            }
        }

        // round to 2 decimals
        foreach ($nutrients as $k => $v) {
            $nutrients[$k] = round($v, 2);
        }

        return $nutrients;
    }

    /**
     * Build a concise text summary of the current formula.
     */
    public function buildFormulaSummary(PoultryFeedProduct $product): string
    {
        $items = FeedComposition::with('component')
            ->where('poultry_feed_product_id', $product->id)
            ->get();

        $parts = [];
        foreach ($items as $item) {
            if (!$item->component) {
                continue;
            }
            $parts[] = sprintf(
                '%s: %.2f%%',
                $item->component->name,
                (float) $item->percentage
            );
        }

        $summary = sprintf(
            'Feed product "%s" with components: %s.',
            $product->name,
            implode(', ', $parts)
        );

        // Include description if available
        if (!empty($product->description)) {
            $summary .= "\n\nProduct description: " . $product->description;
        }

        return $summary;
    }

    /**
     * Ask the LLM to analyse the formula (analysis only, no new formula).
     *
     * @return string|null  The analysis text, or null when LLM is unavailable.
     */
    public function analyzeOnly(PoultryFeedProduct $product): ?string
    {
        $profile = $this->calculateProfile($product);
        $summary = $this->buildFormulaSummary($product);

        $system = 'You are an expert poultry nutritionist. '
            . 'Given a feed formula and its calculated nutritional profile, provide a concise analysis. '
            . 'Focus on broilers and layers in tropical climates. '
            . 'Respond in concise English. '
            . 'Do NOT propose a new formula — only analyse the current one.';

        $user = "Here is the current feed formula:\n"
            . $summary . "\n\n"
            . 'Current nutritional profile (per 100% of feed, approximate): ' . json_encode($profile) . "\n\n"
            . 'Please analyse whether this formula is balanced and safe for poultry. '
            . 'Highlight any deficiencies, excesses, or imbalances and explain the potential impact on bird health and performance.';

        return $this->llm->chat($system, $user) ?: null;
    }

    /**
     * AI-assisted feed formulation from scratch.
     *
     * Accepts an optional target nutritional profile, optional selected components,
     * and a feed type / description. The LLM returns a formula as a numbered list.
     *
     * @param  string               $feedTypeName       e.g. "Broiler Starter"
     * @param  string|null          $description        Extra context for the LLM
     * @param  array<string,float>  $targetProfile      Desired nutrient targets (any subset)
     * @param  FeedComponent[]      $selectedComponents Components the user wants to use
     * @return array{analysis:string,formula:string}|null
     */
    public function formulate(
        string $feedTypeName,
        ?string $description,
        array $targetProfile = [],
        array $selectedComponents = []
    ): ?array {
        $hasProfile = !empty(array_filter($targetProfile, fn($v) => $v > 0));
        $hasComponents = count($selectedComponents) > 0;

        // ── build the system prompt ──────────────────────────────────
        $system = 'You are an expert poultry nutritionist specialising in tropical climates. '
            . 'Your task is to formulate a complete feed formula. '
            . 'CRITICAL RULES: '
            . '1) The formula percentages MUST sum to exactly 100.00%. '
            . '2) Format the formula section as a numbered list: "ComponentName: X.XX%" — one per line. '
            . '3) Do NOT use markdown bold (**) inside the formula list. '
            . '4) Put the formula under a heading "Proposed Formula:" on its own line. '
            . '5) Before the formula, provide a brief analysis explaining your choices.';

        // ── build the user prompt dynamically ────────────────────────
        $parts = [];
        $parts[] = "Feed type: {$feedTypeName}";

        if ($description) {
            $parts[] = "Additional description / requirements: {$description}";
        }

        // Target profile (optional)
        if ($hasProfile) {
            $profileLines = [];
            $labels = [
                'crude_protein' => 'Crude Protein (%)',
                'metabolizable_energy' => 'Metabolizable Energy (kcal/kg)',
                'crude_fat' => 'Crude Fat (%)',
                'crude_fiber' => 'Crude Fiber (%)',
                'calcium' => 'Calcium (%)',
                'phosphorus' => 'Phosphorus (%)',
                'moisture' => 'Moisture (%)',
                'ash' => 'Ash (%)',
            ];
            foreach ($targetProfile as $key => $val) {
                if ($val > 0 && isset($labels[$key])) {
                    $profileLines[] = "  - {$labels[$key]}: {$val}";
                }
            }
            if ($profileLines) {
                $parts[] = "Target nutritional profile:\n" . implode("\n", $profileLines);
            }
        }

        // Selected components with their nutritional data (optional)
        if ($hasComponents) {
            $compLines = [];
            foreach ($selectedComponents as $c) {
                $line = $c->name;
                $nutrients = [];
                if ($c->crude_protein)        $nutrients[] = "CP:{$c->crude_protein}%";
                if ($c->metabolizable_energy)  $nutrients[] = "ME:{$c->metabolizable_energy}kcal/kg";
                if ($c->crude_fat)             $nutrients[] = "Fat:{$c->crude_fat}%";
                if ($c->crude_fiber)           $nutrients[] = "Fiber:{$c->crude_fiber}%";
                if ($c->calcium)               $nutrients[] = "Ca:{$c->calcium}%";
                if ($c->phosphorus)            $nutrients[] = "P:{$c->phosphorus}%";
                if ($nutrients) {
                    $line .= ' (' . implode(', ', $nutrients) . ')';
                }
                $compLines[] = "  - {$line}";
            }
            $parts[] = "Available components selected by the user:\n" . implode("\n", $compLines);
        }

        // Mode-specific instructions
        if ($hasProfile && $hasComponents) {
            $parts[] = "\nPlease formulate a feed using the selected components to achieve the target nutritional profile. "
                . "You may add additional components if necessary to reach the targets. "
                . "Explain any additions.";
        } elseif ($hasProfile) {
            $parts[] = "\nNo specific components were selected. Please recommend suitable components and percentages "
                . "to achieve the target nutritional profile for this feed type.";
        } else {
            $parts[] = "\nNeither a target nutritional profile nor specific components were provided. "
                . "Please recommend a complete, balanced formula with appropriate components and percentages "
                . "for the feed type and description above.";
        }

        $parts[] = "\nRemember: the formula must sum to exactly 100.00%. "
            . "Verify the sum before responding. "
            . "Put the formula under the heading \"Proposed Formula:\" as a numbered list.";

        $user = implode("\n\n", $parts);

        $reply = $this->llm->chat($system, $user);
        if (!$reply) {
            return null;
        }

        return [
            'analysis' => $reply,
            'formula' => $reply,
        ];
    }

    /**
     * Revise a previously generated formula based on a user remodification message.
     *
     * The LLM receives the current formula text plus optional chat history and returns
     * an updated "Proposed Formula:" section that sums to exactly 100.00%.
     *
     * @param  string               $feedTypeName
     * @param  string|null          $description
     * @param  array<string,float>  $targetProfile
     * @param  FeedComponent[]      $selectedComponents
     * @param  string               $currentFormulaText
     * @param  array<int,array{role:string,content:string}> $messages
     * @param  string               $newMessage
     * @return array{analysis:string,formula:string}|null
     */
    public function reviseFormula(
        string $feedTypeName,
        ?string $description,
        array $targetProfile,
        array $selectedComponents,
        string $currentFormulaText,
        array $messages,
        string $newMessage
    ): ?array {
        $hasProfile = !empty(array_filter($targetProfile, fn($v) => $v > 0));
        $hasComponents = count($selectedComponents) > 0;

        $system = 'You are an expert poultry nutritionist specialising in tropical climates. '
            . 'You will revise an existing feed formula based on the user\'s requested changes. '
            . 'CRITICAL RULES: '
            . '1) The revised formula percentages MUST sum to exactly 100.00%. '
            . '2) Format the formula section as a numbered list: "ComponentName: X.XX%" — one per line. '
            . '3) Do NOT use markdown bold (**) inside the formula list. '
            . '4) Put the formula under a heading "Proposed Formula:" on its own line. '
            . '5) Before the formula, provide a brief analysis explaining what changed and why.';

        $parts = [];
        $parts[] = "Feed type: {$feedTypeName}";

        if ($description) {
            $parts[] = "Additional description / requirements: {$description}";
        }

        if ($hasProfile) {
            $labels = [
                'crude_protein' => 'Crude Protein (%)',
                'metabolizable_energy' => 'Metabolizable Energy (kcal/kg)',
                'crude_fat' => 'Crude Fat (%)',
                'crude_fiber' => 'Crude Fiber (%)',
                'calcium' => 'Calcium (%)',
                'phosphorus' => 'Phosphorus (%)',
                'moisture' => 'Moisture (%)',
                'ash' => 'Ash (%)',
            ];
            $profileLines = [];
            foreach ($targetProfile as $key => $val) {
                if ($val > 0 && isset($labels[$key])) {
                    $profileLines[] = "  - {$labels[$key]}: {$val}";
                }
            }
            if ($profileLines) {
                $parts[] = "Target nutritional profile:\n" . implode("\n", $profileLines);
            }
        }

        if ($hasComponents) {
            $compLines = [];
            foreach ($selectedComponents as $c) {
                $line = $c->name;
                $nutrients = [];
                if ($c->crude_protein)        $nutrients[] = "CP:{$c->crude_protein}%";
                if ($c->metabolizable_energy)  $nutrients[] = "ME:{$c->metabolizable_energy}kcal/kg";
                if ($c->crude_fat)             $nutrients[] = "Fat:{$c->crude_fat}%";
                if ($c->crude_fiber)           $nutrients[] = "Fiber:{$c->crude_fiber}%";
                if ($c->calcium)               $nutrients[] = "Ca:{$c->calcium}%";
                if ($c->phosphorus)            $nutrients[] = "P:{$c->phosphorus}%";
                if ($nutrients) {
                    $line .= ' (' . implode(', ', $nutrients) . ')';
                }
                $compLines[] = "  - {$line}";
            }
            $parts[] = "Available components selected by the user:\n" . implode("\n", $compLines);
        }

        $parts[] = "Current AI output (contains the current formula):\n" . $currentFormulaText;

        // Optional multi-turn context (lightweight transcript)
        if (!empty($messages)) {
            $transcript = [];
            foreach ($messages as $m) {
                $role = ($m['role'] ?? 'user') === 'assistant' ? 'Assistant' : 'User';
                $content = (string) ($m['content'] ?? '');
                if ($content !== '') {
                    $transcript[] = "{$role}: {$content}";
                }
            }
            if (!empty($transcript)) {
                $parts[] = "Conversation so far:\n" . implode("\n", $transcript);
            }
        }

        $parts[] = "User remodification request:\n" . $newMessage;

        $parts[] = "Return ONLY your revised analysis plus a revised formula under the heading \"Proposed Formula:\". "
            . "Verify the formula totals exactly 100.00% before responding.";

        $user = implode("\n\n", $parts);

        $reply = $this->llm->chat($system, $user);
        if (!$reply) {
            return null;
        }

        return [
            'analysis' => $reply,
            'formula' => $reply,
        ];
    }

    /**
     * Ask the LLM to analyse the formula and recommend improvements.
     *
     * @return array{analysis?:string,recommendation?:string}|null
     */
    public function analyzeAndRecommend(PoultryFeedProduct $product): ?array
    {
        $profile = $this->calculateProfile($product);
        $summary = $this->buildFormulaSummary($product);

        $system = 'You are an expert poultry nutritionist. '
            . 'Given a feed formula and its calculated nutritional profile, analyse it and suggest clear, practical improvements. '
            . 'Focus on broilers and layers in tropical climates. '
            . 'Respond in concise English. '
            . 'CRITICAL: When proposing a formula, the percentages MUST sum to exactly 100.00%. '
            . 'Format the formula as a numbered list with each component on a new line: "Component Name: X.XX%"';

        $user = "Here is the current feed formula:\n"
            . $summary . "\n\n"
            . 'Current nutritional profile (per 100% of feed, approximate): ' . json_encode($profile) . "\n\n"
            . '1) Briefly analyse if this formula is balanced and safe for poultry.' . "\n"
            . '2) Suggest improvements to optimize growth and health, keeping costs reasonable.' . "\n"
            . '3) Propose one improved formula as a numbered list of components and percentages. '
            . 'IMPORTANT: The percentages MUST sum to exactly 100.00%. Use this format for each line: "Component Name: X.XX%" '
            . 'Double-check that all percentages add up to exactly 100.00% before responding.';

        $reply = $this->llm->chat($system, $user);
        if (!$reply) {
            return null;
        }

        return [
            'analysis' => $reply,
            'recommendation' => $reply,
        ];
    }
}

