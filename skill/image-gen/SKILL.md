---
name: image-gen
description: Generate images from a text prompt, or edit/combine reference images, via the self-hosted OpenRouter image relay. Use when the user asks to create, draw, render, illustrate, or edit an image.
---

# Image generation via relay

Use the `relay.sh` script in this skill's folder (the same folder as this SKILL.md). It reads the relay URL and token from `relay.env` next to it (or from `IMAGE_RELAY_URL` / `IMAGE_RELAY_TOKEN` if set), calls the relay, and saves the images as files. Run it with `bash`, using its full path.

Never print, echo, or cat the token or `relay.env`, and never put the token in a command line.

## Generate

```bash
bash /path/to/image-gen/relay.sh generate "A watercolor fox in a snowy forest" --aspect 16:9
```

Options:
- `--aspect R`: one of 1:1, 2:3, 3:2, 3:4, 4:3, 4:5, 5:4, 9:16, 16:9, 21:9
- `--n N`: number of images (1-4)
- `--model M`: only if the user asks for a specific model; run `relay.sh models` to list the allowed ones
- `--out DIR`: where to save (default: `/mnt/user-data/outputs` when it exists, otherwise the current directory)

Output lines look like `saved: /path/abc123.jpg  (512345 bytes, relay file: abc123.jpg)`. Show or attach the saved files to the user. Keep the `relay file` names for follow-up edits.

## Waiting for results

Generation can take minutes, so the relay runs it as a background job. `relay.sh generate` submits the job and checks back every ~30 seconds (the interval the relay asks for) for up to 90 seconds. If the image is ready you get the `saved:` lines as usual.

If it prints `pending: job <id> ...`, the job is still running on the relay and is not lost. Wait the stated number of seconds (e.g. `sleep 30`), then run:

```bash
bash /path/to/image-gen/relay.sh result <id>
```

While waiting, the script may say the job is "being checked" (the relay is still reviewing the request) or "accepted, generating". Repeat until it prints `saved:` lines or an error. Do not resubmit the prompt while a job is pending: that would generate (and bill) a second image. `--wait SECONDS` changes how long one call waits (default 90).

## Edit or use reference images

Pass the user's image files with `--image` (repeatable, up to 4 in total; PNG, JPEG, WebP or GIF, max 10 MB each):

```bash
bash /path/to/image-gen/relay.sh generate "Make the sky purple, keep everything else the same" --image /path/to/photo.jpg
```

To refine an image generated earlier in the conversation, pass its relay file name with `--ref` (faster than re-uploading; relay files expire after 24 hours):

```bash
bash /path/to/image-gen/relay.sh generate "Add a small moon in the top right" --ref abc123.jpg
```

`--ref` also accepts an `https://` image URL.

## Prompting

Write a specific prompt that covers the subject, style, composition, lighting and colors. For edits, say what to change and what to keep.

## Errors

The script prints `error: HTTP <code> <code_name>: <message>`:
- `rate_limited`: wait the stated seconds, then retry once.
- `model_not_allowed`, `invalid_aspect_ratio`, `invalid_n`: fix the option and retry.
- `invalid_input_images` / 413: the image is not a supported type or is too large; convert or downscale it.
- `upstream_error` (e.g. "did not respond in time"): the relay already retried on its side. Tell the user, then resubmit once; if it fails again, stop and report it.
- `request_rejected`: the relay did not accept the request (it gives no reason). Tell the user it was rejected; do not guess at the cause, rewrite or disguise the prompt or images to get around it, or resubmit.
- `prompt_rejected`: the relay's content policy blocked the prompt. Tell the user; do not reword the prompt to get around the filter.
- `image_rejected`: a reference image was blocked by the content policy. Tell the user; do not retry with the same image or try to disguise it.
- `no_image`: no image was produced; rephrase the prompt.
- `job_not_found` / `job_lost`: the job id expired or the server aborted it; submit the prompt again.
- `could not reach`: network access to the relay domain is blocked. Tell the user to allow the domain under Settings → Capabilities (network egress).
- `unauthorized`: the token is missing, wrong or expired. Tell the user; do not try to find another token.
