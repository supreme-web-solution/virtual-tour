<?php

/*
|--------------------------------------------------------------------------
| AI generation pricing and limits
|--------------------------------------------------------------------------
|
| Customer credit prices are what a workspace pays per operation. Provider
| costs are what we pay, recorded separately in USD for reconciliation.
| Change prices here (or via env) — never in controllers or Vue.
|
| Provider prices change: verify against each provider's pricing page.
|
*/

return [

    'credits' => [
        // Credits granted to every new workspace.
        'starter_balance' => (int) env('CREDITS_STARTER_BALANCE', 0),
    ],

    'operations' => [
        'scene_plan' => [
            'label' => 'AI scene plan',
            'credits' => (int) env('CREDITS_SCENE_PLAN', 1),
        ],
        'listing_extraction' => [
            'label' => 'AI listing import',
            'credits' => (int) env('CREDITS_LISTING_EXTRACTION', 1),
        ],
        'photo_enhance' => [
            'label' => 'AI photo enhancement',
            'credits' => (int) env('CREDITS_PHOTO_ENHANCE', 3),
        ],
        'photo_upscale' => [
            'label' => 'AI photo upscale (4x)',
            'credits' => (int) env('CREDITS_PHOTO_UPSCALE', 4),
        ],
        'photo_analysis' => [
            'label' => 'AI photo analysis',
            'credits' => (int) env('CREDITS_PHOTO_ANALYSIS', 1),
        ],
        'text_to_panorama' => [
            'label' => 'Text to 360° panorama',
            'credits' => (int) env('CREDITS_TEXT_TO_PANORAMA', 5),
        ],
        'photo_to_panorama' => [
            'label' => 'Photo to 360° panorama',
            'credits' => (int) env('CREDITS_PHOTO_TO_PANORAMA', 5),
        ],
        'video_storyboard' => [
            'label' => 'AI video storyboard',
            'credits' => (int) env('CREDITS_VIDEO_STORYBOARD', 1),
        ],
        'video_clip' => [
            'label' => 'AI video clip',
            // Priced per second of requested clip length.
            'credits_per_second' => (float) env('CREDITS_VIDEO_CLIP_PER_SECOND', 2),
        ],
        'music_track' => [
            'label' => 'AI music track',
            'credits' => (int) env('CREDITS_MUSIC_TRACK', 3),
        ],
    ],

    'providers' => [
        'openai' => [
            'base_url' => 'https://api.openai.com/v1',
            'timeout' => (int) env('OPENAI_TIMEOUT', 90),
            'text_model' => env('OPENAI_TEXT_MODEL') ?: 'gpt-6-luna',
            'vision_model' => env('OPENAI_VISION_MODEL') ?: 'gpt-6.1-terra',
            // Photo editing (enhance, fix lighting, widen). Edits take a minute or two.
            'image_model' => env('OPENAI_IMAGE_MODEL') ?: 'gpt-image-2.6-sunburst',
            'image_timeout' => (int) env('OPENAI_IMAGE_TIMEOUT', 240),
            // USD per million tokens, to record actual cost. Verify on OpenAI's pricing page.
            'image_prices_per_million_tokens' => [
                'gpt-image-2.6-sunburst' => ['input' => 8.0, 'output' => 30.0],
                'gpt-image-2.6-flare' => ['input' => 8.0, 'output' => 30.0],
                'gpt-image-2.5-sunburst' => ['input' => 8.0, 'output' => 30.0],
                'gpt-image-2.5-flare' => ['input' => 8.0, 'output' => 30.0],
            ],
            // USD per million tokens, used to record actual provider cost.
            'prices_per_million_tokens' => [
                'gpt-6-luna' => ['input' => 0.10, 'output' => 0.50],
                'gpt-6.1-terra' => ['input' => 1.00, 'output' => 5.00],
                'gpt-6.2-sol' => ['input' => 2.00, 'output' => 10.00],
            ],
            // Vision inputs are scaled to this width before sending, to control tokens.
            'vision_image_width' => 1024,
            'max_photos_per_analysis' => 20,
        ],

        'skybox' => [
            'base_url' => 'https://backend.blockadelabs.com/api/v1',
            'timeout' => 30,
            'default_style_id' => env('SKYBOX_DEFAULT_STYLE_ID') ?: null,
            // USD per Skybox credit on your plan (null = record credits only).
            'usd_per_credit' => env('SKYBOX_USD_PER_CREDIT') ?: null,
            // Fallback polling when webhooks are not delivered (e.g. local dev).
            'poll_delays_seconds' => [45, 30, 30, 45, 60, 60, 90, 120, 180, 300],
            'max_poll_attempts' => 20,
            'webhook_tolerance_seconds' => 300,
        ],

        /*
         * fal.ai hosts the video and music models; one FAL_KEY covers both.
         * Requests go through fal's queue API: submit, poll, fetch the result.
         * @see https://fal.ai/docs/model-apis/model-endpoints/queue
         */
        'fal' => [
            'queue_url' => 'https://queue.fal.run',
            // Image-to-video: Kling 3.0 Pro (3 to 15 s, optional native sound).
            'video_model' => env('FAL_VIDEO_MODEL') ?: 'fal-ai/kling-video/v3/pro/image-to-video',
            // Instrumental background music with an exact length.
            'music_model' => env('FAL_MUSIC_MODEL') ?: 'fal-ai/elevenlabs/music',
            // Super-resolution (true 4x). Check fal.ai for current model ids and prices.
            'upscale_model' => env('FAL_UPSCALE_MODEL') ?: 'fal-ai/esrgan',
            'upscale_timeout' => (int) env('FAL_UPSCALE_TIMEOUT', 300),
            // The photo is sent at most this wide, so the 4x result stays a manageable size.
            'upscale_input_width' => 1536,
            'usd_per_upscale' => (float) env('FAL_USD_PER_UPSCALE', 0.02),
            // How long a job waits for fal to finish, and how often it checks.
            'video_timeout' => (int) env('FAL_VIDEO_TIMEOUT', 600),
            'music_timeout' => (int) env('FAL_MUSIC_TIMEOUT', 300),
            'poll_seconds' => 5,
            'video_resolution' => '1080p',
            'video_min_seconds' => 3,
            // The model accepts up to 15 s; shorter shots keep property detail stable.
            'video_max_seconds' => 10,
            // USD estimates for reconciliation (check fal.ai pricing; set in .env).
            'usd_per_video_second' => (float) env('FAL_USD_PER_VIDEO_SECOND', 0.17),
            'usd_per_music_track' => (float) env('FAL_USD_PER_MUSIC_TRACK', 0.15),
        ],
    ],

    'video' => [
        'max_sources' => 9,
        'max_duration' => 30,
        'render_timeout' => (int) env('VIDEO_RENDER_TIMEOUT', 1500),
        'temp_path' => env('VIDEO_TEMP_PATH') ?: storage_path('app/private/renders'),
        // Keep failed render folders this long for debugging, then delete.
        'temp_retention_hours' => 24,
    ],

    'limits' => [
        'max_scenes_per_plan' => 12,
        'max_description_length' => 4000,
    ],

];
