# OpenRouter Image Relay (PHP)

Small PHP API that lets a Claude skill generate images through OpenRouter without holding your OpenRouter key.

## Setup
1. PHP 8.1+ with the `curl` and `mbstring` extensions (Ubuntu: `apt install php-cli php-curl php-mbstring`).
2. Copy `config.example.php` to `config.php` and edit it (or set the `OPENROUTER_API_KEY` env var). Set models, limits, output mode.
3. Create a token: `php bin/make-token.php claude-skill` and paste the printed entry into `security.tokens`.
4. Run locally: `php -S 127.0.0.1:8080 index.php`, or deploy on Apache (`.htaccess` included) or nginx (see below).
5. Put it behind HTTPS in production and set `require_https` to `true`.

## nginx
nginx has no `.htaccess`, and its paths can't be relative to the app, so generate the server block from wherever the app is installed:
```
bin/nginx-conf.sh relay.example.com | sudo tee /etc/nginx/sites-available/relay
sudo ln -s ../sites-available/relay /etc/nginx/sites-enabled/ && sudo nginx -t && sudo systemctl reload nginx
```
Optional: a URL prefix (`bin/nginx-conf.sh example.com /relay`), `--http` for plain HTTP (local or behind a TLS proxy), and env overrides `PHP_FPM`, `SSL_CERT`, `SSL_KEY`, `MAX_BODY`. The PHP-FPM socket is detected from `/run/php`. Every request goes to `index.php` and no files are served from disk, so `config.php`, `storage/` and `bin/` are never exposed. PHP-FPM needs write access to `storage/` and the `OPENROUTER_API_KEY` env var (`env[...]` in the pool config) or the key in `config.php`.

## API
| Route | Auth | Description |
|---|---|---|
| `GET /health` | no | liveness |
| `GET /models` | yes | allowed models/options |
| `POST /generate` | yes | JSON `{prompt, model?, n?, aspect_ratio?, response_mode?, input_images?}` or `multipart/form-data` with the same fields plus image files |
| `GET /images/{file}` | yes | download saved image |
| `POST /speech` | yes | text to speech (mp3): JSON `{input, model?, voice?, response_mode?, speed?, elevenlabs?}` |
| `GET /audio/{file}` | yes | download saved speech |
| `GET /howto` | no | human-readable usage guide (how to generate images and speech) |
| `GET /history` | viewer token | unadvertised history viewer: prompts, results, cost and screening verdicts. Needs a separate viewer token (`php bin/make-token.php "viewer" --viewer`), set in `security.viewer_tokens`. Sign-in can be protected with Cloudflare Turnstile (`turnstile` in `config.php`) |
| `GET /live_test_page_99` | token entered in page | manual test page for image generation (`test.php`) |
| `GET /live_test_page_89` | token entered in page | manual test page for speech (`test_speech.php`) |

`/live_test_page_*` are debugging tools: remove or block them (and `/howto`, if you don't want it public) on a public deployment. The paths are hardcoded in `index.php`.

Security: bearer tokens stored only as SHA-256 hashes, constant-time comparison, per-token rate limit / model restrictions / expiry, optional IP allowlist, auth-failure delay, images served only through authenticated route, `config.php` and `storage/` blocked from the web.

Test:
```
curl -X POST http://127.0.0.1:8080/generate -H "Authorization: Bearer imgr_..." -H "Content-Type: application/json" -d '{"prompt":"a red bicycle","response_mode":"base64"}'
```

### Reference images (editing / variations)
Send up to `max_input_images` reference images in any of these forms; they are combined in one request:
- **File upload** (`multipart/form-data`): any file field, e.g. `-F image=@photo.jpg` or repeated `-F 'image[]=@a.png' -F 'image[]=@b.png'`.
- **`input_images` array** (JSON, or a repeated form field): `data:image/...;base64,` URLs, raw base64, `https://` URLs (fetched by OpenRouter; disable with `allow_input_image_urls`), or a previously generated relay image by file name or download URL, for iterative edits.

Uploaded and inline images are checked against the actual bytes (PNG, JPEG, WebP, GIF only), limited to `max_input_image_bytes`, and re-encoded as data URLs with the correct MIME type. PHP also limits uploads with `upload_max_filesize` / `post_max_size` (defaults 2M / 8M); raise them (e.g. `php -d upload_max_filesize=10M -d post_max_size=40M -S ...`, or in `php.ini`) to match. Oversized requests return HTTP 413.

```
curl -X POST http://127.0.0.1:8080/generate -H "Authorization: Bearer imgr_..." -F prompt="make the sky purple" -F image=@photo.jpg
```

## Claude skill
`skill/image-gen/` holds the skill: `SKILL.md` plus `relay.sh`, which calls the relay and saves the images without exposing the token.
- **Claude Code:** copy the folder to `~/.claude/skills/image-gen/` and set `IMAGE_RELAY_URL` / `IMAGE_RELAY_TOKEN` in the `env` section of `~/.claude/settings.json`.
- **Claude web / desktop:** copy `relay.env.example` to `relay.env` in the folder and fill in a dedicated token (with `rate_limit`, `allowed_models` and `expires`). Then zip the folder (`zip -r image-gen.zip image-gen`), upload it under Settings → Capabilities → Skills, and allow network egress to the relay's domain.

## Async jobs

`POST /generate` with `"async": true` (or form field `async=1`) validates the request, then returns `202` with `job_id`, `check_back_seconds` (default 30, `jobs.check_back_seconds` in `config.php`) and `poll_url`, and keeps generating after the connection closes. Poll `GET /jobs/{job_id}` with the same token: `status` is `screening` (only when `moderation.second_opinion` or `moderation.image_check` is on: the request is accepted at once and checked inside the job), `running`, `rejected` (the content checks refused it; generic message only), `done` (the normal `/generate` result is merged in) or `failed` (with `error`). Polls are not rate-limited; job files live in `storage/jobs/` and are purged with `retention_hours`. Without `async` the endpoint behaves as before (synchronous). Needs PHP-FPM or mod_php/`php -S`; the skill's `relay.sh` uses async mode.

## Local image generation (mlx-serve, ComfyUI middleware)

If the requested `model` is listed under one of `local_images.endpoints` (`config.php`), `/generate` is sent to that endpoint's OpenAI-compatible `POST {base_url}/images/generations` instead of OpenRouter; any other model goes to OpenRouter exactly as before (OpenRouter settings, retries and timeouts are not touched). Different models can live on different servers or ports. Local models are allowed automatically (per-token `allowed_models` still apply) and are listed in `GET /models` (`local_models`, plus `local_model_info` with each model's quality levels, reference-image support and image cap).

Each endpoint has `base_url`, optional `api_key`, `models`, and may override any shared `local_images` key:

| Key | Meaning |
|---|---|
| `timeout` | seconds the relay waits for one image (set it a little above the server's own limit) |
| `retries` | extra tries after a timeout/5xx; default `0` because a retry queues a second slow job |
| `busy_wait_seconds` | a server answering HTTP 429 "busy" (the ComfyUI middleware runs one job at a time) is waited for: the request or async job pauses for the server's `retry_after` and resubmits, up to this many seconds per image, then fails with 503 `upstream_busy`. There is no relay-side queue, so waiting requests are not ordered; sync requests hold their connection while waiting, so use async |
| `check_back_seconds` | poll interval suggested for async jobs on this endpoint |
| `max_images_per_request` | cap on `n` for this endpoint (images are generated one after another) |
| `quality` / `default_quality` | request field `quality` to diffusion `steps` (`good` 8, `quality` 12, `super_quality` 20) |
| `input_images` | `true` = accepts reference images, sent as base64 `image` (one) or `images` (several); otherwise they are rejected. https links are rejected for local models |
| `omit_size_with_refs` | with references, `size` is only sent when the client passes `aspect_ratio` (the server sizes the output from the references) |
| `sizes` / `default_size` | `aspect_ratio` to `size` |

`quality` is only used for local models; OpenRouter models ignore it. Slow endpoints (the Qwen/ComfyUI one takes 6 to 13 minutes with two references) should be used with `"async": true`; the job then gets a time limit derived from the endpoint's own `timeout`. Override the first endpoint with `LOCAL_IMAGES_URL` / `LOCAL_IMAGES_KEY`.

## Speech (text to speech)

`POST /speech` calls OpenRouter's `/audio/speech` and always returns mp3. Defaults (`speech` in `config.php`): model `elevenlabs/eleven-v4`, voice `george`. `voice` is a premade ElevenLabs name (`sarah`, `adam`, `alice`, ...) or a voice ID. `response_mode` is `url` (saved under `storage/audio`, fetched via `GET /audio/{file}` with the same token), `base64` or `both`. Eleven v4 supports audio tags in the text, e.g. `[whispering]`, and rejects non-default `speed`. `elevenlabs` may carry `seed`, `language_code`, `previous_text`, `next_text`, `voice_settings` (`stability`, `similarity_boost`, `style`, ...) etc.; the allowed keys are listed in `speech.allowed_elevenlabs_options`. Input limits per model are in `speech.max_input_length`. Add `"async": true` to get `202` + a `job_id` at once and poll `GET /jobs/{id}` like image jobs (`status` `running`, then `done` with the same body as a synchronous response, or `failed`); the upstream request then uses `speech.async_timeout` (default 900 s) instead of `speech.timeout` (120 s), so slow or long texts don't depend on the client connection. It counts against the rate limit, skips the keyword prompt filter unless both `moderation.enabled` and `speech.moderation` are true (the latter is off by default), and is recorded in `/history` like images (text, model, voice, status and an audio player; a copy of the mp3 is kept for `history.retention_days`). Cost shows as n/a because OpenRouter's speech response carries no usage data.

```
curl -X POST http://127.0.0.1:8080/speech -H "Authorization: Bearer imgr_..." -H "Content-Type: application/json" -d '{"input":"[cheerfully] Hello there!","voice":"sarah"}'
```
