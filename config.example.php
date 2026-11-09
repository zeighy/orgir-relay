<?php
/**
 * Configuration for the OpenRouter image relay.
 * Secrets can be supplied via environment variables instead of editing this file.
 */
return [
    // ---- OpenRouter -------------------------------------------------------
    'openrouter' => [
        'api_key'  => getenv('OPENROUTER_API_KEY') ?: 'sk-or-REPLACE_ME',
        'base_url' => 'https://openrouter.ai/api/v1',
        // Optional attribution headers shown on OpenRouter dashboards.
        'site_url' => '',
        'app_name' => 'Claude Image Relay',
        'timeout'  => 300,   // seconds for one upstream request (image models can take minutes)
        // Extra tries after an upstream timeout / 5xx before failing (0 = off). A timed-out request
        // may still be billed by OpenRouter, so keep this small.
        'retries'  => 1,
        'verify_ssl' => true,
    ],

    // ---- Image generation defaults ---------------------------------------
    'images' => [
        'default_model' => 'google/gemini-2.5-flash-image',
        // Whitelist of models callers may request. Empty array = allow any.
        'allowed_models' => [
            'google/gemini-2.5-flash-image',
            'openai/gpt-5-image-mini',
            'openai/gpt-5-image',
        ],
        'max_prompt_length' => 4000,
        'max_images_per_request' => 4,   // upper bound for the "n" parameter
        // Optional aspect ratio passed to models that support it (e.g. "1:1", "16:9"). null = model default.
        'default_aspect_ratio' => null,
        'allowed_aspect_ratios' => ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'],
        // Allow callers to pass reference images for editing: multipart file uploads,
        // data URLs / raw base64, https URLs, or names of images this relay generated.
        'allow_input_images' => true,
        'max_input_images' => 4,
        // Max decoded size per uploaded / inline reference image. Uploads are also capped by
        // PHP's upload_max_filesize and post_max_size (defaults 2M / 8M), so raise those too.
        'max_input_image_bytes' => 10 * 1024 * 1024,
        // Pass https URLs straight to the model (OpenRouter fetches them). false = reject them.
        'allow_input_image_urls' => true,
    ],

    // ---- Local image generation (mlx-serve, OpenAI-compatible) --------------
    // When the requested "model" is listed under one of the endpoints, /generate is served by
    // POST {base_url}/images/generations on that endpoint instead of OpenRouter, so several servers (or ports)
    // can each host different models. Local models are allowed automatically (still subject to per-token
    // allowed_models) and appear in GET /models. Reference images are rejected for a local model unless its endpoint sets 'input_images' => true.
    // Everything except 'enabled' and 'endpoints' is a shared default that an endpoint may override (timeout, retries,
    // check_back_seconds, busy_wait_seconds, max_images_per_request, sizes, quality, input_images, omit_size_with_refs, verify_ssl).
    'local_images' => [
        'enabled' => true,
        'endpoints' => [
            [
                'name' => 'mlx-1',
                'base_url' => getenv('LOCAL_IMAGES_URL') ?: 'http://127.0.0.1:11234/v1',
                'api_key' => getenv('LOCAL_IMAGES_KEY') ?: '',   // optional Bearer token
                'models' => ['Runpod/FLUX.2-klein-4B-mflux-4bit'],
            ],
            [
                'name' => 'qwen',
                'base_url' => 'http://127.0.0.1:11226/v1',   // middleware in front of ComfyUI
                'models' => ['qwen-image-2.1-uncensored'],
                'input_images' => true,        // accepts reference images: "image" (one) / "images" (several), base64
                // Measured on the test Mac: 2 references, 1376x768: 4 steps ~330 s, 8 ~356 s, 12 ~500 s, 20 ~780 s.
                // The middleware waits 900 s, so the relay waits a little longer; use async (poll) for these.
                'timeout' => 960,
                'busy_wait_seconds' => 1800,   // another render can run 14 min; allow waiting for it (and a bit more)
                'check_back_seconds' => 60,    // suggested poll interval for async jobs on this endpoint
                'max_images_per_request' => 1, // each image takes minutes (generated one after another); "n" above this is rejected
                // Steps: 8 is the sharp-but-fast default; 12 gives a single clean subject; 20 gives the best pose (~13 min).
                'default_quality' => 'good',
                'quality' => ['good' => 8, 'quality' => 12, 'super_quality' => 20],
            ],
        ],
        'timeout' => 600,
        // aspect_ratio -> "WxH" sent as "size" (multiples of 16). Unlisted ratios fall back to default_size.
        'default_size' => '1024x1024',
        'sizes' => [
            '1:1' => '1024x1024', '2:3' => '832x1248', '3:2' => '1248x832', '3:4' => '896x1152', '4:3' => '1152x896',
            '4:5' => '896x1120', '5:4' => '1120x896', '9:16' => '768x1344', '16:9' => '1344x768', '21:9' => '1536x656',
        ],
        'verify_ssl' => true,
        // Retries after an upstream timeout/5xx for a local endpoint (OpenRouter's own 'retries' is separate). Off by
        // default: a retry queues a second job on the GPU and doubles the wait.
        'retries' => 0,
        // A local server that answers HTTP 429 "busy" (the ComfyUI middleware runs one job at a time) is waited for:
        // the request (or async job) pauses for the server's retry_after (5-120 s) and resubmits, up to this many
        // seconds per image, then fails with 503 upstream_busy. There is no relay-side queue and no ordering between
        // waiting requests. 0 = fail at once. Async jobs wait inside the job; sync requests hold the connection.
        'busy_wait_seconds' => 600,
        // Reference images set the output size, so 'size' is only sent when the client passes an aspect_ratio.
        'omit_size_with_refs' => true,
        // "quality" request field (local models only; ignored for OpenRouter models) -> diffusion steps.
        // Time grows linearly with steps (about 14 s/step at 1024x1024 on the test Mac).
        'default_quality' => 'good',
        'quality' => ['good' => 8, 'quality' => 12, 'super_quality' => 20],
    ],

    // ---- Speech (text-to-speech) ---------------------------------------------
    // POST /speech -> OpenRouter /audio/speech. Output is always mp3 (44.1 kHz, 128 kbps).
    'speech' => [
        'enabled' => true,
        'default_model' => 'elevenlabs/eleven-v4',
        'default_voice' => 'george',
        // Run the keyword prompt filter ('moderation' below) on speech input too. Off by default; it only has an
        // effect while 'moderation.enabled' is true, and a token with "moderation": false still skips it.
        'moderation' => false,
        // Seconds for the upstream request: synchronous calls, and async jobs (which can wait much longer
        // because the client is not holding the connection open).
        'timeout' => 120,
        'async_timeout' => 900,
        // Whitelist of speech models callers may request. Empty array = allow any.
        'allowed_models' => [
            'elevenlabs/eleven-v4',
            'elevenlabs/eleven-v4-turbo',
            'elevenlabs/eleven-v3',
            'elevenlabs/eleven-multilingual-v2',
            'elevenlabs/eleven-flash-v2.5',
        ],
        // Characters per request, by model (OpenRouter's limits); 'default' applies to unlisted models.
        'max_input_length' => [
            'default' => 5000,
            'elevenlabs/eleven-v4' => 10000,
            'elevenlabs/eleven-v4-turbo' => 10000,
            'elevenlabs/eleven-multilingual-v2' => 10000,
            'elevenlabs/eleven-flash-v2.5' => 40000,
        ],
        // Keys callers may pass in "elevenlabs" (sent as provider.options.elevenlabs). Unlisted keys are dropped.
        'allowed_elevenlabs_options' => ['seed', 'previous_text', 'next_text', 'language_code',
            'apply_text_normalization', 'apply_language_text_normalization', 'voice_settings'],
    ],

    // ---- Output handling --------------------------------------------------
    'output' => [
        // Directory where generated images are saved (must be writable).
        'storage_dir' => __DIR__ . '/storage/images',
        // Directory where generated speech (mp3) is saved; same retention as images.
        'audio_dir' => __DIR__ . '/storage/audio',
        // Default response mode: "url" (saved file + authenticated download link),
        // "base64" (inline in JSON), or "both".
        'default_mode' => 'url',
        // Delete saved images older than this many hours (0 = never).
        'retention_hours' => 24,
        // Public base URL of this app, used to build download links.
        // Leave empty to auto-detect from the request.
        'public_base_url' => '',
    ],

    // ---- Security ---------------------------------------------------------
    'security' => [
        /*
         * API tokens. Keys are a label; "hash" is sha256 of the token.
         * Generate with:  php bin/make-token.php "my-label"
         * and paste the printed entry here. Raw tokens are never stored.
         *
         * Per-token options (all optional):
         *   enabled        bool   disable without deleting
         *   rate_limit     int    requests per minute (overrides default)
         *   allowed_models array  restrict this token to specific models
         *   expires        string any strtotime()-parsable date
         *   moderation     bool   false = skip the prompt content filter for this token
         */
        'tokens' => [
            // 'claude-skill' => [
            //     'hash' => '<sha256 hex>',
            //     'enabled' => true,
            //     'rate_limit' => 20,
            // ],
        ],
        // Viewer tokens for the (unadvertised) /history page. Separate from API tokens: they cannot
        // call the API and API tokens cannot open the history. Same entry format as "tokens";
        // create with:  php bin/make-token.php "viewer" --viewer
        'viewer_tokens' => [],
        'default_rate_limit' => 30,           // requests / minute / token
        'rate_limit_dir' => __DIR__ . '/storage/ratelimit',
        // Optional IP allowlist (exact IPs). Empty = any IP.
        'ip_allowlist' => [],
        // Require HTTPS (honours X-Forwarded-Proto). Disable for local dev.
        'require_https' => false,
        // Delay (ms) added after a failed auth attempt to slow brute forcing.
        'failed_auth_delay_ms' => 300,
    ],

    // ---- Async jobs ---------------------------------------------------------
    // POST /generate with "async": true returns 202 + a job id immediately; clients poll GET /jobs/{id}.
    'jobs' => [
        // Seconds the client is told to wait before each check.
        'check_back_seconds' => 30,
    ],

    // ---- Cloudflare Turnstile on the /history login ---------------------------
    // Both keys set = captcha required before a viewer token is checked. Empty = disabled.
    // Get keys at https://dash.cloudflare.com/?to=/:account/turnstile
    'turnstile' => [
        'site_key'   => getenv('TURNSTILE_SITE_KEY') ?: '',
        'secret_key' => getenv('TURNSTILE_SECRET_KEY') ?: '',
    ],

    // ---- Generation history (/history, needs a viewer token) ------------------
    'history' => [
        'enabled' => true,
        'retention_days' => 30,   // prompts, results and reference copies are purged after this (0 = never)
        'page_size' => 25,
    ],

    // ---- Prompt content filter ----------------------------------------------
    // Rejects prompts for sexual / explicit content before anything is sent to OpenRouter (no cost).
    // Off by default. Test your rules with:  php bin/check-prompt.php "some prompt"
    'moderation' => [
        'enabled' => false,
        // What the client is told. The matched rule is only written to relay.log and /history.
        'message' => 'The prompt was rejected by the content policy.',
        // Async requests with a slow check enabled (second_opinion / image_check) are accepted at once and checked inside
        // the background job; a rejection then shows up as job status "rejected" with only this generic text.
        'async_message' => 'The request could not be accepted.',

        // Whole-word keywords (case-insensitive; multi-word phrases allowed). Also matched after the
        // obfuscation handling in "normalize" below, so "p0rn", "p.o.r.n" and "pooorn" are caught.
        // Whole-word matching avoids hits inside innocent words ("Essex", "class").
        'keywords' => [
            'porn', 'porno', 'pornographic', 'pornography', 'xxx', 'nsfw', 'hentai', 'erotic', 'erotica',
            'sex', 'sexual', 'sexually', 'nude', 'nudes', 'nudity', 'naked', 'topless', 'bottomless',
            'nipple', 'nipples', 'genital', 'genitals', 'genitalia', 'penis', 'vagina', 'vulva', 'pussy', 'cock',
            'boobs', 'tits', 'anal', 'cum', 'cumshot', 'creampie', 'blowjob', 'handjob', 'orgasm', 'orgy',
            'masturbate', 'masturbating', 'masturbation', 'fetish', 'bdsm', 'bondage', 'dildo',
            'unclothed', 'undressed', 'sexually explicit', 'sex scene', 'explicit content', 'adult content', 'lolicon', 'loli', 'shotacon',
            // A few common non-English terms (add the languages your users write in).
            'desnuda', 'desnudo', 'desnudas', 'desnudos', 'sexo', 'pornografia', 'erotico', 'erotica',
            'nackt', 'nackte', 'nackter', 'nacktes', 'erotische', 'erotisch', 'erotique', 'sans vetements', 'sin ropa',
        ],

        // Substring matches on the text with ALL spaces/punctuation removed. Catches "s e x", "p-o-r-n" and
        // other heavy spacing tricks, but also hits inside longer words, so keep this list short and distinctive.
        'strict_keywords' => ['porn', 'hentai', 'blowjob'],

        // Your own PCRE patterns (include delimiters and flags). Applied to the original prompt and to every
        // normalised form. Example: '/\bmasturbat\w*/iu', '/\b(?:having|have)\s+sex\b/iu'
        'regex' => [
            '/\bmasturbat\w*/iu',
            '/\bsex(?:y|ual|ually)?\s+(?:pose|act|scene|position|content)s?\b/iu',
        ],

        // Harmless phrases removed from the prompt before matching, to cut false positives.
        'allow' => ['breast cancer', 'breast stroke', 'chicken breast', 'turkey breast', 'cock-a-doodle', 'rooster cock',
            'naked mole', 'peacock', 'sussex', 'essex', 'middlesex', 'sex education'],

        // Bypass handling applied before matching. Each can be turned off.
        'normalize' => [
            'homoglyphs' => true,   // Cyrillic/Greek look-alikes, full-width letters, accents, zero-width characters
            'leet' => true,         // 0->o, 1->i/l, 3->e, 4->a, 5->s, 7->t, @->a, $->s, !->i ...
            'separators' => true,   // "p o r n", "p.o.r.n", "p_o_r_n" joined into one word
            'elongation' => true,   // "pooorn", "sexxxx": repeated letters
            'suffixes' => true,     // keyword + s / es / ed / ing / er / ers
        ],

        // Optional second opinion from TypeSafe's Jev decision model on OpenRouter (typesafe/jev-1.13).
        // Runs only when the keyword check passed, so obvious hits cost nothing. Jev answers a yes/no question
        // with a probability; at or above "threshold" the prompt counts as not safe and is rejected. Costs a
        // fraction of a cent per prompt (billed to your OpenRouter key) and adds about a second of latency.
        'second_opinion' => [
            'enabled' => false,
            'model' => 'typesafe/jev-1.13',   // or '~typesafe/jev-latest'
            'threshold' => 0.5,               // lower = stricter (more rejections), higher = more lenient
            'timeout' => 10,                  // seconds; no retries
            'on_error' => 'allow',            // if the check fails/times out: 'allow' (fail open) or 'reject' (fail closed)
            // The yes/no question Jev answers about `prompt`. Reword it to change what counts as unsafe.
            'question' => 'The text in `prompt` asks for an image containing sexual or sexually explicit content: nudity, sexual acts, erotica, fetish material, or sexualised depictions of anyone.',
        ],

        // Optional inspection of reference images with Cloudflare's Clef decision model (multimodal, via OpenRouter's
        // Decisions API). Every uploaded/inline reference image is checked before anything is generated; the first
        // "not safe" image rejects the request (error code image_rejected). Runs after the prompt checks pass.
        // Needs the model to be allowed for your OpenRouter key/workspace. Costs a small fraction of a cent per image.
        'image_check' => [
            'enabled' => false,
            'model' => 'cloudflare/clef-flash',   // fast 9B (default); 'cloudflare/clef' is the larger 27B
            'threshold' => 0.5,                   // probability of "explicit" at or above which the image is rejected
            'timeout' => 20,
            'on_error' => 'allow',                // check failed/timed out: 'allow' or 'reject' (fail closed)
            // Several reference images are checked in ONE request (cheaper, faster). Clef's input window is shared, so each
            // image is shrunk to its share of max_batch_bytes (needs GD or Imagick; otherwise they are checked one by one).
            // If the set is flagged, each image is then checked alone to find out which one(s) to quarantine.
            'batch' => true,
            'max_batch_bytes' => 180000,          // total for all images in one request (about 45 KB each for 4 images)
            'url_images' => 'reject',             // https links can't be inspected: 'reject' (safe) or 'allow' (unchecked)
            'max_check_bytes' => 180000,          // the model's input window is small; bigger images are shrunk (needs GD or Imagick)
            // A rejected image is kept as evidence: encrypted, never shown in /history (only a "withheld" box with its
            // file id), and retrievable only on the server with  php bin/quarantine.php  (list / show / export / delete).
            'quarantine' => [
                'enabled' => true,
                'retention_days' => 90,           // 0 = keep forever
                'key' => '',                      // encryption secret; empty = auto-generated storage/quarantine/.key (back it up!)
                // If no cipher (OpenSSL/libsodium) or key is available, store the raw bytes instead, locked with mode 000
                // and handed to root when PHP runs as root (otherwise only the mode applies). false = don't keep the image then.
                'allow_plaintext' => true,
                'cipher' => 'auto',               // 'none' forces the plaintext fallback (for testing / when you prefer it)
            ],
            'message' => 'A reference image was rejected by the content policy.',
            // Optional: send the check somewhere else instead of OpenRouter's Decisions API (same request format).
            // 'url' => 'https://openrouter.ai/api/alpha/decisions', 'api_key' => '...',
            // Tested 2026-10-08 on clef-flash and clef: swimwear / sculptures / classical art score <= 0.06, explicit images 0.8-0.99.
            // The minors clause is deliberately not covered by any exception.
            'question' => 'The image shows visible genitals, a visible anus, an exposed female nipple in a sexual context, or an explicit sex act or masturbation, or is pornographic or fetish content, or sexualises a child or anyone who looks like a minor. It does NOT count if the person is only wearing swimwear, underwear or lingerie, is topless without being sexual, or is shown in artistic or non-sexual nudity such as a museum sculpture, a classical painting or a well-known artwork with no visible genitals.',
        ],
    ],

    // ---- Misc -------------------------------------------------------------
    'debug' => false,                           // include upstream error detail in responses
    'log_file' => __DIR__ . '/storage/relay.log', // set to null to disable logging
];
