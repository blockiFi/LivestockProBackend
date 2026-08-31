<?php

return [
    /*
    |--------------------------------------------------------------------------
    | LLM Provider
    |--------------------------------------------------------------------------
    |
    | Currently only OpenAI-style chat APIs are supported. You can later extend
    | this config to support other providers.
    |
    */
    'provider' => env('LLM_PROVIDER', 'openai'),

    'openai' => [
        'api_key' => env('AI_API_KEY', env('LLM_API_KEY')),
        'base_url' => rtrim(env('OPENAI_BASE_URL', 'https://api.openai.com'), '/'),
        'model' => env('OPENAI_MODEL', env('LLM_MODEL', 'gpt-4o')),
        // Vision calls with multiple pages can take longer than 30s.
        'timeout' => env('OPENAI_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule import (PDF/Image) settings
    |--------------------------------------------------------------------------
    */
    'schedule_import' => [
        // Max number of PDF pages to render + send to the vision model
        'pdf_max_pages' => env('SCHEDULE_IMPORT_PDF_MAX_PAGES', 6),
        // Rendering resolution (higher = better OCR, slower/larger payload)
        'pdf_render_dpi' => env('SCHEDULE_IMPORT_PDF_RENDER_DPI', 200),
    ],
];

