<?php
declare(strict_types=1);

/**
 * Manual test page for the image relay: submit a prompt (plus up to 4 reference images), wait for the render and
 * show the pictures with the raw JSON. Pending jobs are kept in the browser's localStorage and resume after a refresh.
 *
 * The browser talks straight to the relay endpoints with the token as a Bearer header.
 * Do not leave this page deployed publicly: it is a debugging tool.
 */

const MAX_FILES = 4;

// The browser calls the relay directly (same origin). A PHP proxy calling back into this server would need a second
// worker while the first waits, and hangs the whole app when workers are scarce.
$relayPath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

// The form and its script are only ever sent to a caller holding a valid token (checked by the relay's own authenticate()).
if (isset($_GET['ui'])) {
    if (!function_exists('authenticate')) { // only served through index.php, which owns the token check
        http_response_code(404);
        exit;
    }
    authenticate($config); // terminates with 401/403 on a bad token
    header('Cache-Control: no-store');
    ?>
<form id="f">
  <div class="row">
    <div><label for="model">Model</label><select id="model"><option value="">Loading models...</option></select></div>
    <div><label for="aspect">Aspect ratio (optional)</label><input type="text" id="aspect" placeholder="1:1"></div>
    <div id="qwrap" hidden><label for="quality">Quality (local models)</label><select id="quality"></select></div>
  </div>
  <label for="prompt">Prompt</label>
  <textarea id="prompt" required></textarea>
  <label>Reference images (up to 4)</label>
  <div class="row">
    <?php for ($i = 1; $i <= MAX_FILES; $i++): ?>
      <div><input type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="file"></div>
    <?php endfor; ?>
  </div>
  <button type="submit">Generate</button>
</form>
<div id="jobs"></div>

<script>
(function () {
  var BASE = <?= json_encode($relayPath) ?>;
  var KEY = 'relaytest.jobs';
  var jobsEl = document.getElementById('jobs');
  var timers = {};

  function load() { try { return JSON.parse(localStorage.getItem(KEY)) || []; } catch (e) { return []; } }
  function save(list) { try { localStorage.setItem(KEY, JSON.stringify(list.slice(0, 20))); } catch (e) {} }
  function update(uid, patch) {
    var list = load();
    list.forEach(function (j) { if (j.uid === uid) Object.assign(j, patch); });
    save(list);
    render();
  }
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }
  function pretty(v) { return typeof v === 'string' ? v : JSON.stringify(v, null, 2); }

  // Parses a response as JSON when possible; always keeps the raw text.
  function readJson(r) {
    return r.text().then(function (t) {
      var j = null; try { j = JSON.parse(t); } catch (e) {}
      return { status: r.status, json: j, raw: t };
    });
  }

  function render() {
    jobsEl.textContent = '';
    load().forEach(function (j) {
      var box = el('div', 'job');
      var h = el('h3', null, (j.job_id ? 'Job ' + j.job_id : 'Submission failed') + ' ');
      var st = el('span', 'st-' + j.status, '[' + j.status + ']');
      h.appendChild(st);
      var rm = el('button', 's', 'dismiss');
      rm.type = 'button';
      rm.style.float = 'right';
      rm.onclick = function () { stop(j.uid); save(load().filter(function (x) { return x.uid !== j.uid; })); render(); };
      h.appendChild(rm);
      box.appendChild(h);
      box.appendChild(el('div', 'mut', new Date(j.started).toLocaleString() + ' · ' + j.prompt.slice(0, 120)
        + (j.note ? ' · ' + j.note : '')));
      if (j.images && j.images.length) {
        var imgs = el('div', 'imgs');
        j.images.forEach(function (f) {
          var im = el('img'); im.alt = f; imgs.appendChild(im);
          fetch(BASE + '/images/' + encodeURIComponent(f), { headers: { Authorization: 'Bearer ' + j.token } })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
            .then(function (b) { im.src = URL.createObjectURL(b); })
            .catch(function (e) { im.replaceWith(el('div', 'st-error', 'Image ' + f + ' failed to load: ' + e.message)); });
        });
        box.appendChild(imgs);
      }
      if (j.raw !== undefined) box.appendChild(el('pre', null, pretty(j.raw)));
      jobsEl.appendChild(box);
    });
  }

  function stop(uid) { if (timers[uid]) { clearTimeout(timers[uid]); delete timers[uid]; } }

  function imageFiles(json) {
    var out = [];
    ((json && json.images) || []).forEach(function (im) {
      var m = /([a-f0-9]{32}\.(?:png|jpg|webp|gif))(?:[?#].*)?$/.exec((im && im.url) || '');
      if (m) out.push(m[1]);
    });
    return out;
  }

  function poll(job) {
    stop(job.uid);
    fetch(BASE + '/jobs/' + job.job_id, { headers: { Authorization: 'Bearer ' + job.token } })
      .then(readJson)
      .then(function (r) {
        var j = r.json, wait = 5;
        if (r.status === 200 && j && (j.status === 'running' || j.status === 'screening')) {
          wait = Math.max(2, Math.min(60, j.check_back_seconds || 30));
          update(job.uid, { status: j.status, note: 'elapsed ' + j.elapsed_seconds + 's, next check in ' + wait + 's' });
        } else if (j && ['done', 'failed', 'rejected'].indexOf(j.status) >= 0) {
          update(job.uid, { status: j.status, note: 'HTTP ' + r.status, raw: j, images: imageFiles(j) });
          return;
        } else { // 404 job_not_found, 401, proxy errors, non-JSON...: stop and show it verbatim
          update(job.uid, { status: 'error', note: 'HTTP ' + r.status, raw: j || r.raw });
          return;
        }
        timers[job.uid] = setTimeout(function () { poll(job); }, wait * 1000);
      })
      .catch(function (e) { // network blip: keep waiting, do not lose the job
        update(job.uid, { note: 'poll failed (' + e.message + '), retrying' });
        timers[job.uid] = setTimeout(function () { poll(job); }, 5000);
      });
  }

  // Models come from GET /models (what this token may use); local models also get their own quality choices.
  (function () {
    var sel = document.getElementById('model'), qsel = document.getElementById('quality'), qwrap = document.getElementById('qwrap');
    var info = {};
    function opt(parent, value, text) { var o = document.createElement('option'); o.value = value; o.textContent = text; parent.appendChild(o); }
    function syncQuality() {
      var m = info[sel.value || sel.dataset.def]; // only local models have an entry; OpenRouter models ignore quality
      qsel.innerHTML = '';
      qwrap.hidden = !(m && m.quality && m.quality.length);
      if (qwrap.hidden) return;
      m.quality.forEach(function (q) { opt(qsel, q, q); });
      if (m.default_quality) qsel.value = m.default_quality;
    }
    sel.addEventListener('change', syncQuality);
    fetch(BASE + '/models', { headers: { Authorization: 'Bearer ' + window.RELAY_TOKEN }, cache: 'no-store' })
      .then(readJson)
      .then(function (r) {
        var j = r.json;
        if (!j || !j.ok) throw new Error('HTTP ' + r.status);
        sel.innerHTML = '';
        sel.dataset.def = j.default_model || '';
        info = j.local_model_info || {};
        var list = Array.isArray(j.models) ? j.models : [j.default_model];
        opt(sel, '', 'Default (' + (j.default_model || '?') + ')');
        list.forEach(function (m) { if (m) opt(sel, m, m + (info[m] ? ' (local)' : '')); });
        syncQuality();
      })
      .catch(function (e) { sel.innerHTML = ''; opt(sel, '', 'Default (could not load models: ' + e.message + ')'); });
  })();

  document.getElementById('f').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var token = window.RELAY_TOKEN;
    var prompt = document.getElementById('prompt').value;
    var fd = new FormData();
    fd.append('async', '1');
    fd.append('prompt', prompt);
    fd.append('model', document.getElementById('model').value);
    if (!document.getElementById('qwrap').hidden) fd.append('quality', document.getElementById('quality').value);
    fd.append('aspect_ratio', document.getElementById('aspect').value.trim());
    document.querySelectorAll('.file').forEach(function (inp) {
      if (inp.files[0]) fd.append('image[]', inp.files[0]);
    });
    var job = { uid: String(Date.now()) + Math.random().toString(36).slice(2, 6), token: token, prompt: prompt,
                started: Date.now(), status: 'submitting' };
    var list = load(); list.unshift(job); save(list); render();
    fetch(BASE + '/generate', { method: 'POST', body: fd, headers: { Authorization: 'Bearer ' + token } })
      .then(readJson)
      .then(function (r) {
        if (r.json && r.json.job_id && (r.status === 202 || r.status === 200)) {
          job.job_id = r.json.job_id;
          update(job.uid, { job_id: job.job_id, status: 'running', raw: r.json });
          poll(job);
        } else { // synchronous error (bad token, validation, rate limit...): raw JSON as returned
          update(job.uid, { status: 'error', note: 'HTTP ' + r.status, raw: r.json || r.raw });
        }
      })
      .catch(function (e) { update(job.uid, { status: 'error', raw: 'Network error: ' + e.message }); });
  });

  // Restore: resume every job that was still waiting when the page was closed or refreshed.
  var list = load();
  list.forEach(function (j) {
    if (j.status === 'submitting') { // the page went away before the submit answered: nothing to resume
      j.status = 'error'; j.note = 'page reloaded before the server answered';
    }
  });
  save(list);
  render();
  list.forEach(function (j) { if (j.job_id && (j.status === 'running' || j.status === 'screening')) poll(j); });
})();
</script>
<?php
    exit;
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Relay test</title>
<style>
  :root { color-scheme: light dark; --bg:#fff; --fg:#1b1b1f; --mut:#666; --line:#d0d0d8; --card:#f5f5f8; --bad:#b3261e; --ok:#1b7a3a; }
  @media (prefers-color-scheme: dark) { :root { --bg:#17171b; --fg:#e8e8ee; --mut:#999; --line:#35353d; --card:#202026; --bad:#ff8a80; --ok:#6fd58d; } }
  body { background:var(--bg); color:var(--fg); font:15px/1.45 system-ui,sans-serif; max-width:900px; margin:0 auto; padding:16px; }
  label { display:block; margin:10px 0 3px; font-weight:600; }
  input[type=text], input[type=password], textarea, select { width:100%; box-sizing:border-box; padding:7px; background:var(--card); color:var(--fg); border:1px solid var(--line); border-radius:6px; font:inherit; }
  textarea { min-height:110px; }
  .row { display:flex; gap:10px; flex-wrap:wrap; } .row > div { flex:1; min-width:200px; }
  button { margin-top:14px; padding:8px 18px; font:inherit; border-radius:6px; border:1px solid var(--line); background:var(--fg); color:var(--bg); cursor:pointer; }
  #bar { display:flex; justify-content:space-between; align-items:center; }
  #login { max-width:420px; }
  .err { color:var(--bad); margin-top:8px; }
  button.s { margin:0; padding:2px 10px; background:none; color:var(--fg); }
  .job { border:1px solid var(--line); background:var(--card); border-radius:8px; padding:12px; margin-top:16px; }
  .job h3 { margin:0 0 4px; font-size:15px; } .mut { color:var(--mut); font-size:13px; }
  .st-failed, .st-rejected, .st-error { color:var(--bad); } .st-done { color:var(--ok); }
  pre { background:var(--bg); border:1px solid var(--line); border-radius:6px; padding:8px; overflow:auto; max-height:320px; font-size:12px; white-space:pre-wrap; word-break:break-word; }
  .imgs img { max-width:100%; border-radius:6px; margin-top:8px; display:block; }
</style>
</head>
<body>
<h1>Relay test</h1>
<div id="bar"><h1>Relay test</h1><button type="button" class="s" id="logout" hidden>Clear token (log out)</button></div>
<form id="login" hidden>
  <label for="token">Token</label>
  <input type="password" id="token" autocomplete="off" required>
  <button type="submit">Continue</button>
  <div class="err" id="err"></div>
</form>
<div id="app"></div>

<script>
(function () {
  var BASE = <?= json_encode($relayPath) ?>;
  var TOKEN_KEY = 'relaytest.token', JOBS_KEY = 'relaytest.jobs';
  var login = document.getElementById('login'), err = document.getElementById('err');
  var logout = document.getElementById('logout'), app = document.getElementById('app');

  function store(k, v) { try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (e) {} }
  function stored(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }

  function showLogin(msg) {
    login.hidden = false; logout.hidden = true; app.textContent = '';
    err.textContent = msg || '';
  }

  // Asks the server for the page body; only a valid token gets it.
  function enter(token, quiet) {
    err.textContent = '';
    return fetch(location.pathname + '?ui=1', { headers: { Authorization: 'Bearer ' + token }, cache: 'no-store' })
      .then(function (r) {
        if (r.status === 401 || r.status === 403) {
          store(TOKEN_KEY, null);
          showLogin(quiet ? '' : 'Invalid or expired token.');
          return;
        }
        if (!r.ok) { showLogin('Unexpected response: HTTP ' + r.status); return; }
        return r.text().then(function (html) {
          if (stored(TOKEN_KEY) !== token) store(JOBS_KEY, null); // jobs belong to the token that made them
          store(TOKEN_KEY, token);
          var t = document.createElement('template');
          t.innerHTML = html;
          var code = [];
          t.content.querySelectorAll('script').forEach(function (s) { code.push(s.textContent); s.remove(); });
          app.textContent = '';
          app.appendChild(t.content);
          window.RELAY_TOKEN = token;
          login.hidden = true; logout.hidden = false;
          code.forEach(function (c) { var s = document.createElement('script'); s.textContent = c; document.body.appendChild(s); });
        });
      })
      .catch(function (e) { showLogin('Could not reach the server: ' + e.message); });
  }

  login.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var t = document.getElementById('token').value.trim();
    document.getElementById('token').value = '';
    if (t) enter(t, false);
  });
  logout.addEventListener('click', function () {
    store(TOKEN_KEY, null); store(JOBS_KEY, null); // the token is also kept with each saved job
    location.reload();
  });

  var t = stored(TOKEN_KEY);
  if (t) enter(t, true); else showLogin();
})();
</script>
</body>
</html>
