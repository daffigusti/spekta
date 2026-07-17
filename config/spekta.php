<?php

// Paket sesuai BUSINESS_RULES.md BR-01
return [
    // FR-23: Midtrans Snap (sandbox default). Produksi: MIDTRANS_IS_PRODUCTION=true.
    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY', ''),
        'client_key' => env('MIDTRANS_CLIENT_KEY', ''),
        'base_url' => env('MIDTRANS_IS_PRODUCTION', false)
            ? 'https://app.midtrans.com'
            : 'https://app.sandbox.midtrans.com',
    ],

    // BR-03: harga top-up kredit (berlaku 12 bulan, dipakai setelah kredit paket)
    'topup' => [
        'price_per_credit_idr' => 75_000,
        'packs' => [5, 10, 25],
    ],

    'plans' => [
        'free' => [
            'label' => 'Free',
            'price_idr' => 0,
            'blueprints_per_month' => 2,
            'ai_chats_per_month' => 10,
            'members' => 1,
            'client_portal' => false,
            'estimator' => true, // MD-only tanpa portal; estimator penuh di Pro (BR-01)
        ],
        'starter' => [
            'label' => 'Starter',
            'price_idr' => 149_000,
            'blueprints_per_month' => 8,
            'ai_chats_per_month' => 100,
            'members' => 3,
            'client_portal' => false,
            'estimator' => true,
        ],
        'pro' => [
            'label' => 'Pro',
            'price_idr' => 399_000,
            'blueprints_per_month' => 25,
            'ai_chats_per_month' => 400,
            'members' => 10,
            'client_portal' => true,
            'estimator' => true,
        ],
        'team' => [
            'label' => 'Team',
            'price_idr_per_seat' => 249_000,
            'min_seats' => 3,
            'blueprints_per_month' => null, // unlimited sesuai seat
            'ai_chats_per_month' => null,
            'members' => null,
            'client_portal' => true,
            'estimator' => true,
        ],
    ],

    // FR-07: set dokumen scale-adaptive + dependency graph (ARCHITECTURE.md pipeline)
    'doc_pipeline' => [
        'PRD' => [],
        'REQUIREMENTS' => ['PRD'],
        'USER_FLOWS' => ['PRD'],
        'WIREFRAMES' => ['USER_FLOWS'],
        'BUSINESS_RULES' => ['PRD'],
        'DATABASE' => ['REQUIREMENTS'],
        'API' => ['REQUIREMENTS'],
        'ARCHITECTURE' => ['REQUIREMENTS'],
        'FEATURES' => ['REQUIREMENTS', 'USER_FLOWS'],
        'TESTING' => ['DATABASE', 'API'],
        'DESIGN' => ['USER_FLOWS'],
        'ROADMAP' => ['PRD', 'REQUIREMENTS'],
    ],

    // Kompleksitas → subset dokumen (FR-07)
    'doc_sets' => [
        1 => ['PRD', 'REQUIREMENTS', 'USER_FLOWS', 'WIREFRAMES', 'ROADMAP'],
        2 => ['PRD', 'REQUIREMENTS', 'USER_FLOWS', 'WIREFRAMES', 'DATABASE', 'API', 'ROADMAP'],
        3 => ['PRD', 'REQUIREMENTS', 'USER_FLOWS', 'WIREFRAMES', 'BUSINESS_RULES', 'DATABASE', 'API', 'ARCHITECTURE', 'FEATURES', 'TESTING', 'DESIGN', 'ROADMAP'],
        4 => ['PRD', 'REQUIREMENTS', 'USER_FLOWS', 'WIREFRAMES', 'BUSINESS_RULES', 'DATABASE', 'API', 'ARCHITECTURE', 'FEATURES', 'TESTING', 'DESIGN', 'ROADMAP'],
        5 => ['PRD', 'REQUIREMENTS', 'USER_FLOWS', 'WIREFRAMES', 'BUSINESS_RULES', 'DATABASE', 'API', 'ARCHITECTURE', 'FEATURES', 'TESTING', 'DESIGN', 'ROADMAP'],
    ],

    // BR-20/BR-21
    'estimate' => [
        'integration_overhead_pct' => 10,
        'buffer_pct' => 15,
        'confidence_range_pct' => 15,
    ],

    'llm' => [
        // anthropic | openai | stub — auto: anthropic bila ada ANTHROPIC_API_KEY, openai bila ada OPENAI_API_KEY
        'driver' => env('SPEKTA_LLM_DRIVER', env('ANTHROPIC_API_KEY') ? 'anthropic' : (env('OPENAI_API_KEY') ? 'openai' : 'stub')),

        // Log prompt+response mentah ke storage/logs/llm.log (channel 'llm')
        'log' => env('SPEKTA_LLM_LOG', true),

        // Driver anthropic — base_url bisa diganti ke endpoint Anthropic-compatible
        'anthropic_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),

        // Driver openai — kompatibel OpenAI/Groq/DeepSeek/OpenRouter/Ollama (ganti base_url)
        'openai_key' => env('OPENAI_API_KEY'),
        'openai_base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        // BR-50: routing per node-class
        'models' => [
            'reasoning' => env('SPEKTA_MODEL_REASONING', 'claude-fable-5'),
            'standard' => env('SPEKTA_MODEL_STANDARD', 'claude-sonnet-5'),
            'economy' => env('SPEKTA_MODEL_ECONOMY', 'claude-haiku-4-5-20251001'),
        ],
    ],
];
