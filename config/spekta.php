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
            'contradiction_checks_per_month' => 3,
            'members' => 1,
            'client_portal' => false,
            'estimator' => true, // MD-only tanpa portal; estimator penuh di Pro (BR-01)
        ],
        'starter' => [
            'label' => 'Starter',
            'price_idr' => 149_000,
            'blueprints_per_month' => 8,
            'ai_chats_per_month' => 100,
            'contradiction_checks_per_month' => 20,
            'members' => 3,
            'client_portal' => false,
            'estimator' => true,
        ],
        'pro' => [
            'label' => 'Pro',
            'price_idr' => 399_000,
            'blueprints_per_month' => 25,
            'ai_chats_per_month' => 400,
            'contradiction_checks_per_month' => 80,
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
            'contradiction_checks_per_month' => null,
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
        'SECURITY' => ['REQUIREMENTS', 'ARCHITECTURE'],
        'FEATURES' => ['REQUIREMENTS', 'USER_FLOWS'],
        // REQUIREMENTS ikut jadi upstream: TESTING wajib melihat daftar FR (rule fr_has_test)
        'TESTING' => ['REQUIREMENTS', 'DATABASE', 'API'],
        'DESIGN' => ['USER_FLOWS'],
        'ROADMAP' => ['PRD', 'REQUIREMENTS'],
    ],

    // Kompleksitas → subset dokumen (FR-07)
    'doc_sets' => [
        1 => ['PRD', 'REQUIREMENTS', 'USER_FLOWS', 'WIREFRAMES', 'ROADMAP'],
        2 => ['PRD', 'REQUIREMENTS', 'USER_FLOWS', 'WIREFRAMES', 'DATABASE', 'API', 'ROADMAP'],
        3 => ['PRD', 'REQUIREMENTS', 'USER_FLOWS', 'WIREFRAMES', 'BUSINESS_RULES', 'DATABASE', 'API', 'ARCHITECTURE', 'SECURITY', 'FEATURES', 'TESTING', 'DESIGN', 'ROADMAP'],
        4 => ['PRD', 'REQUIREMENTS', 'USER_FLOWS', 'WIREFRAMES', 'BUSINESS_RULES', 'DATABASE', 'API', 'ARCHITECTURE', 'SECURITY', 'FEATURES', 'TESTING', 'DESIGN', 'ROADMAP'],
        5 => ['PRD', 'REQUIREMENTS', 'USER_FLOWS', 'WIREFRAMES', 'BUSINESS_RULES', 'DATABASE', 'API', 'ARCHITECTURE', 'SECURITY', 'FEATURES', 'TESTING', 'DESIGN', 'ROADMAP'],
    ],

    // BR-20/BR-21
    'estimate' => [
        'integration_overhead_pct' => 10,
        'buffer_pct' => 15,
        'confidence_range_pct' => 15,

        // Kapasitas paralel untuk durasi (FR-15): 3 track × 5 hari kerja/minggu
        'parallel_tracks' => 3,
        'days_per_week' => 5,

        // Distribusi MD per peran — satu-satunya sumber untuk Estimator, RabExporter,
        // ChangeRequestService, dan tampilan alokasi di halaman rate card. Total harus 1.0.
        'role_split' => ['FE' => 0.33, 'BE' => 0.38, 'QA' => 0.14, 'PM' => 0.10, 'DevOps' => 0.05],

        // Mode pengerjaan: impl_multiplier hanya kena porsi implementasi (FE+BE);
        // QA & PM tetap 1.0× — review kode AI & komunikasi klien tidak ikut cepat.
        // range_pct melebar untuk mode AI — variance delivery lebih tinggi.
        'work_modes' => [
            'conservative' => ['label' => 'Konvensional', 'impl_multiplier' => 1.0, 'range_pct' => 15],
            'ai_assisted' => ['label' => 'AI-assisted', 'impl_multiplier' => 0.6, 'range_pct' => 20],
            'vibe' => ['label' => 'Vibe / AI-first', 'impl_multiplier' => 0.4, 'range_pct' => 25],
        ],
    ],

    // FR-16: default isi proposal — bisa dioverride per template via doc_templates.config
    'proposal' => [
        'payment_scheme' => [
            ['label' => 'Down payment (mulai kerja)', 'pct' => 30],
            ['label' => 'Progress (selesai fase inti)', 'pct' => 40],
            ['label' => 'Pelunasan (UAT & serah terima)', 'pct' => 30],
        ],
        'warranty_days' => 30,
    ],

    'llm' => [
        // anthropic | openai | stub — auto: anthropic bila ada ANTHROPIC_API_KEY, openai bila ada OPENAI_API_KEY
        'driver' => env('SPEKTA_LLM_DRIVER', env('ANTHROPIC_API_KEY') ? 'anthropic' : (env('OPENAI_API_KEY') ? 'openai' : 'stub')),

        // Log prompt+response mentah ke storage/logs/llm.log (channel 'llm')
        'log' => env('SPEKTA_LLM_LOG', true),

        // Batas output per panggilan — dokumen terpotong (stop_reason max_tokens) = error, bukan diam-diam
        'max_tokens' => (int) env('SPEKTA_LLM_MAX_TOKENS', 16000),
        // Dokumen spec butuh konsistensi, bukan kreativitas
        'temperature' => (float) env('SPEKTA_LLM_TEMPERATURE', 0.3),

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
