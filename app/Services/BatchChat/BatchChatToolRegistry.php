<?php

namespace App\Services\BatchChat;

class BatchChatToolRegistry
{
    /** @var list<string> */
    public const READ_TOOLS = [
        'get_batch_overview',
        'get_egg_stock',
        'list_recent_records',
        'get_schedule_status',
        'get_profit_loss',
    ];

    /** @var list<string> */
    public const WRITE_TOOLS = [
        'create_daily_record',
        'create_mortality_report',
        'create_weight_report',
        'create_egg_report',
        'create_feed_usage',
        'create_medication_record',
        'create_vaccination_record',
        'create_expenditure',
        'create_flock_sale',
        'create_product_sale',
    ];

    public function isWriteTool(string $name): bool
    {
        return in_array($name, self::WRITE_TOOLS, true);
    }

    public function isReadTool(string $name): bool
    {
        return in_array($name, self::READ_TOOLS, true);
    }

    /**
     * OpenAI tools payload.
     *
     * @return list<array<string, mixed>>
     */
    public function openAiTools(): array
    {
        $defs = [
            [
                'name' => 'get_batch_overview',
                'description' => 'Get a fresh overview snapshot of this batch (birds, performance, financials).',
                'parameters' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
            ],
            [
                'name' => 'get_egg_stock',
                'description' => 'Get available egg stock as of a date (collected − sold − broken).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string', 'description' => 'Y-m-d; defaults to today'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'list_recent_records',
                'description' => 'List flock records by type. ALWAYS pass date_from and date_to for month/range questions (e.g. all of August). Embedded context only has ~14 recent days. Returns rows plus summary totals.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => [
                            'type' => 'string',
                            'enum' => ['daily', 'mortality', 'eggs', 'weights', 'feed', 'medications', 'vaccinations', 'expenditures', 'bird_sales', 'product_sales'],
                        ],
                        'date_from' => [
                            'type' => 'string',
                            'description' => 'Inclusive start date YYYY-MM-DD. Required for historical/month queries.',
                        ],
                        'date_to' => [
                            'type' => 'string',
                            'description' => 'Inclusive end date YYYY-MM-DD. Required for historical/month queries.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 300,
                            'description' => 'Max rows to return (default 200 with a date range, else 14).',
                        ],
                    ],
                    'required' => ['type'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'get_schedule_status',
                'description' => 'Get this flock\'s schedule health: due/overdue counts AND upcoming planned vaccinations, medications, and feedings (with next_* and upcoming_* lists). Use this for questions like "when is my next vaccination", "what vaccines are coming up", or whether anything is overdue. Do not treat zero due counts as "no schedule".',
                'parameters' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
            ],
            [
                'name' => 'get_profit_loss',
                'description' => 'Get profit and loss summary for this batch. Pass date_from/date_to to scope a month or custom range.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'date_from' => ['type' => 'string', 'description' => 'Inclusive start YYYY-MM-DD'],
                        'date_to' => ['type' => 'string', 'description' => 'Inclusive end YYYY-MM-DD'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'create_daily_record',
                'description' => 'Propose creating a daily flock record. Requires user Confirm.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string'],
                        'mortality_count' => ['type' => 'integer'],
                        'culling_count' => ['type' => 'integer'],
                        'eggs_collected' => ['type' => 'integer'],
                        'eggs_broken' => ['type' => 'integer'],
                        'feed_consumed_kg' => ['type' => 'number'],
                        'water_consumed_liters' => ['type' => 'number'],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['date'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'create_mortality_report',
                'description' => 'Propose a mortality report. Requires user Confirm.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string'],
                        'mortality_count' => ['type' => 'integer'],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['date', 'mortality_count'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'create_weight_report',
                'description' => 'Propose a weight report. Requires user Confirm.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'report_date' => ['type' => 'string'],
                        'average_weight' => ['type' => 'number', 'description' => 'Average weight in kg'],
                        'sample_size' => ['type' => 'integer'],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['report_date', 'average_weight'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'create_egg_report',
                'description' => 'Propose an egg production report. Requires user Confirm.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string'],
                        'eggs_collected' => ['type' => 'integer'],
                        'eggs_broken' => ['type' => 'integer'],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['date', 'eggs_collected'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'create_feed_usage',
                'description' => 'Propose feed usage against inventory. Requires poultry_feed_inventory_id. Requires Confirm.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'usage_date' => ['type' => 'string'],
                        'quantity_kg' => ['type' => 'number'],
                        'poultry_feed_inventory_id' => ['type' => 'integer'],
                    ],
                    'required' => ['usage_date', 'quantity_kg', 'poultry_feed_inventory_id'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'create_medication_record',
                'description' => 'Propose a medication administration record. Requires Confirm.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string'],
                        'poultry_medication_id' => ['type' => 'integer'],
                        'poultry_medication_inventory_id' => ['type' => 'integer'],
                        'dosage' => ['type' => 'integer'],
                        'quantity' => ['type' => 'number'],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['date', 'poultry_medication_id'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'create_vaccination_record',
                'description' => 'Propose a vaccination record. Requires Confirm.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string'],
                        'poultry_vaccine_id' => ['type' => 'integer'],
                        'poultry_vaccine_inventory_id' => ['type' => 'integer'],
                        'dosage' => ['type' => 'integer'],
                        'quantity' => ['type' => 'number'],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['date', 'poultry_vaccine_id'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'create_expenditure',
                'description' => 'Propose a flock expenditure. Requires Confirm.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string'],
                        'category' => [
                            'type' => 'string',
                            'enum' => ['feed', 'medication', 'vaccination', 'labour', 'transport', 'utilities', 'equipment', 'housing', 'chicks', 'maintenance', 'other'],
                        ],
                        'amount' => ['type' => 'number'],
                        'description' => ['type' => 'string'],
                        'payment_method' => ['type' => 'string'],
                    ],
                    'required' => ['date', 'category', 'amount'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'create_flock_sale',
                'description' => 'Propose selling birds from this batch. Requires Confirm. May end the batch if all birds are sold.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string'],
                        'quantity' => ['type' => 'integer'],
                        'unit_price' => ['type' => 'number'],
                        'customer_name' => ['type' => 'string'],
                        'customer_phone' => ['type' => 'string'],
                        'customer_id' => ['type' => 'integer'],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['date', 'quantity', 'unit_price'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'create_product_sale',
                'description' => 'Propose a product sale (egg/meat/manure). Egg sales check stock. Requires Confirm.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string'],
                        'type' => ['type' => 'string', 'enum' => ['egg', 'meat', 'manure']],
                        'quantity' => ['type' => 'number'],
                        'unit_price' => ['type' => 'number'],
                        'customer_name' => ['type' => 'string'],
                        'customer_phone' => ['type' => 'string'],
                        'customer_id' => ['type' => 'integer'],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['date', 'type', 'quantity', 'unit_price'],
                    'additionalProperties' => false,
                ],
            ],
        ];

        return array_map(fn (array $d) => [
            'type' => 'function',
            'function' => $d,
        ], $defs);
    }

    public function humanSummary(string $name, array $args): string
    {
        return match ($name) {
            'create_daily_record' => sprintf(
                'Create daily record on %s (mortality %s, eggs %s, feed %s kg)',
                $args['date'] ?? '?',
                $args['mortality_count'] ?? 0,
                $args['eggs_collected'] ?? 0,
                $args['feed_consumed_kg'] ?? 0
            ),
            'create_mortality_report' => sprintf('Record mortality of %s on %s', $args['mortality_count'] ?? '?', $args['date'] ?? '?'),
            'create_weight_report' => sprintf('Weight report %s kg avg on %s', $args['average_weight'] ?? '?', $args['report_date'] ?? '?'),
            'create_egg_report' => sprintf('Egg report: %s collected on %s', $args['eggs_collected'] ?? '?', $args['date'] ?? '?'),
            'create_feed_usage' => sprintf('Feed usage %s kg (inventory #%s) on %s', $args['quantity_kg'] ?? '?', $args['poultry_feed_inventory_id'] ?? '?', $args['usage_date'] ?? '?'),
            'create_medication_record' => sprintf('Medication #%s on %s', $args['poultry_medication_id'] ?? '?', $args['date'] ?? '?'),
            'create_vaccination_record' => sprintf('Vaccination #%s on %s', $args['poultry_vaccine_id'] ?? '?', $args['date'] ?? '?'),
            'create_expenditure' => sprintf('%s expenditure of %s on %s', $args['category'] ?? '?', $args['amount'] ?? '?', $args['date'] ?? '?'),
            'create_flock_sale' => sprintf('Sell %s birds @ %s on %s', $args['quantity'] ?? '?', $args['unit_price'] ?? '?', $args['date'] ?? '?'),
            'create_product_sale' => sprintf('Sell %s %s @ %s on %s', $args['quantity'] ?? '?', $args['type'] ?? 'product', $args['unit_price'] ?? '?', $args['date'] ?? '?'),
            default => $name,
        };
    }
}
