#!/usr/bin/env bash
# Client for the image relay. Never prints the token.
#
#   relay.sh models
#   relay.sh generate "<prompt>" [--model M] [--n N] [--aspect 16:9]
#                     [--image FILE]... [--ref RELAY_FILE_OR_URL]... [--out DIR] [--wait SECONDS]
#   relay.sh result <job_id> [--wait SECONDS] [--out DIR]
#
# Settings: IMAGE_RELAY_URL / IMAGE_RELAY_TOKEN from the environment, otherwise
# from relay.env next to this script.
set -euo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
if [ -z "${IMAGE_RELAY_URL:-}" ] || [ -z "${IMAGE_RELAY_TOKEN:-}" ]; then
    if [ -f "$here/relay.env" ]; then
        set -a
        # shellcheck disable=SC1091
        . "$here/relay.env"
        set +a
    fi
fi
if [ -z "${IMAGE_RELAY_URL:-}" ] || [ -z "${IMAGE_RELAY_TOKEN:-}" ]; then
    echo "error: IMAGE_RELAY_URL / IMAGE_RELAY_TOKEN not set and no relay.env next to $0" >&2
    exit 2
fi
export IMAGE_RELAY_URL="${IMAGE_RELAY_URL%/}" IMAGE_RELAY_TOKEN

# Header via a file descriptor so the token never appears in the process list.
auth() { printf 'Authorization: Bearer %s' "$IMAGE_RELAY_TOKEN"; }

# Seconds one invocation may spend waiting for a job before handing back "pending".
wait=${IMAGE_RELAY_WAIT:-90}

# finish RESPONSE_FILE HTTP_CODE OUT_DIR WAIT: prints errors, polls running jobs, saves finished images.
finish() {
python3 - "$1" "$2" "$3" "$4" <<'PY'
import json, os, sys, time, urllib.request, urllib.error

path, code, out, wait = sys.argv[1], sys.argv[2], sys.argv[3], float(sys.argv[4])
token = os.environ["IMAGE_RELAY_TOKEN"]
base = os.environ["IMAGE_RELAY_URL"]

def load(raw, code):
    try:
        return json.loads(raw)
    except Exception:
        print(f"error: HTTP {code}, non-JSON response: {raw[:300]!r}", file=sys.stderr)
        sys.exit(1)

j = load(open(path, errors="replace").read(), code)
deadline = time.time() + wait
while j.get("ok") and j.get("status") in ("running", "screening"):
    job = j["job_id"]
    cb = max(1, int(j.get("check_back_seconds", 30)))
    left = deadline - time.time()
    # "screening" = the relay is still checking the request; "running" = accepted and generating.
    state = "being checked" if j["status"] == "screening" else "accepted, generating"
    if left <= 0:
        print(f"pending: job {job} is still in progress ({state}). "
              f"Check back in {cb} seconds with: relay.sh result {job}")
        sys.exit(0)
    print(f"job {job} {state}; checking again in {min(cb, int(left) or 1)}s ...", flush=True)
    time.sleep(min(cb, max(1, left)))
    req = urllib.request.Request(f"{base}/jobs/{job}", headers={"Authorization": f"Bearer {token}"})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            code, raw = r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        code, raw = e.code, e.read().decode("utf-8", "replace")
    except Exception as e:  # transient network trouble: keep the job, try again next round
        print(f"warning: poll failed ({e}); will retry", file=sys.stderr)
        continue
    j = load(raw, code)

if not j.get("ok"):
    code = j.get("http_status", code)  # a failed job reports the original status, not the poll's 200
    e = j.get("error", {})
    extra = f" (retry after {e['retry_after']}s)" if "retry_after" in e else ""
    allowed = f" allowed: {e['allowed']}" if "allowed" in e else ""
    said = f"\nmodel_text: {e['model_text']}" if e.get("model_text") else ""
    said += f"\nopenrouter: {e['upstream_message']}" if e.get("upstream_message") else ""
    print(f"error: HTTP {code} {e.get('code')}: {e.get('message')}{extra}{allowed}{said}", file=sys.stderr)
    sys.exit(1)

for img in j.get("images", []):
    url, name = img.get("url"), img.get("file")
    if not url:
        continue
    req = urllib.request.Request(url, headers={"Authorization": f"Bearer {token}"} if name else {})
    for attempt in range(6):
        try:
            data = urllib.request.urlopen(req, timeout=120).read()
            break
        except urllib.error.HTTPError as e:
            if e.code != 429 or attempt == 5:
                print(f"error: could not download {url}: HTTP {e.code}. The image is ready; "
                      f"retry with: relay.sh result {j.get('job_id', '<job_id>')}", file=sys.stderr)
                sys.exit(1)
            time.sleep(min(30, int(e.headers.get("Retry-After", 5)) + 1))
    dest = os.path.join(out, name or os.path.basename(url.split("?")[0]) or "image.png")
    with open(dest, "wb") as f:
        f.write(data)
    print(f"saved: {os.path.abspath(dest)}  ({len(data)} bytes, relay file: {name or '-'})")
print(f"model: {j.get('model')}")
if j.get("text"):
    print(f"model text: {j['text']}")
PY
}

cmd=${1:-}
shift || true

case "$cmd" in
models)
    curl -sS --max-time 30 -H @<(auth) "$IMAGE_RELAY_URL/models"
    echo
    ;;

generate)
    [ $# -ge 1 ] || { echo "usage: $0 generate \"<prompt>\" [options]" >&2; exit 2; }
    form=(--form-string "prompt=$1")
    shift
    out=""
    while [ $# -gt 0 ]; do
        case "$1" in
            --model)  form+=(--form-string "model=$2"); shift 2 ;;
            --n)      form+=(--form-string "n=$2"); shift 2 ;;
            --aspect) form+=(--form-string "aspect_ratio=$2"); shift 2 ;;
            --image)  [ -f "$2" ] || { echo "error: no such file: $2" >&2; exit 2; }
                      form+=(-F "image[]=@$2"); shift 2 ;;
            --ref)    form+=(--form-string "input_images[]=$2"); shift 2 ;;
            --out)    out=$2; shift 2 ;;
            --wait)   wait=$2; shift 2 ;;
            *) echo "error: unknown option $1" >&2; exit 2 ;;
        esac
    done
    if [ -z "$out" ]; then
        # Claude apps share files from /mnt/user-data/outputs; elsewhere use the current dir.
        if [ -d /mnt/user-data/outputs ] && [ -w /mnt/user-data/outputs ]; then out=/mnt/user-data/outputs; else out=.; fi
    fi
    mkdir -p "$out"

    resp=$(mktemp)
    trap 'rm -f "$resp"' EXIT
    # async=1: the relay answers at once with a job id; the polling below fetches the result.
    code=$(curl -sS --max-time 120 -o "$resp" -w '%{http_code}' -H @<(auth) \
        --form-string "response_mode=url" --form-string "async=1" "${form[@]}" "$IMAGE_RELAY_URL/generate") || {
        echo "error: could not reach $IMAGE_RELAY_URL (network blocked or server down)" >&2; exit 1; }
    finish "$resp" "$code" "$out" "$wait"
    ;;

result)
    [ $# -ge 1 ] || { echo "usage: $0 result <job_id> [--wait SECONDS] [--out DIR]" >&2; exit 2; }
    job=$1
    shift
    out=""
    while [ $# -gt 0 ]; do
        case "$1" in
            --wait) wait=$2; shift 2 ;;
            --out)  out=$2; shift 2 ;;
            *) echo "error: unknown option $1" >&2; exit 2 ;;
        esac
    done
    if [ -z "$out" ]; then
        if [ -d /mnt/user-data/outputs ] && [ -w /mnt/user-data/outputs ]; then out=/mnt/user-data/outputs; else out=.; fi
    fi
    mkdir -p "$out"
    resp=$(mktemp)
    trap 'rm -f "$resp"' EXIT
    code=$(curl -sS --max-time 60 -o "$resp" -w '%{http_code}' -H @<(auth) "$IMAGE_RELAY_URL/jobs/$job") || {
        echo "error: could not reach $IMAGE_RELAY_URL (network blocked or server down)" >&2; exit 1; }
    finish "$resp" "$code" "$out" "$wait"
    ;;

*)
    sed -n '2,10p' "$0" | sed 's/^# \{0,1\}//'
    exit 2
    ;;
esac
