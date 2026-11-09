<?php
declare(strict_types=1);

/**
 * Manual test page for the speech relay (/speech): submit text, wait for the job and play the mp3 together with the
 * raw JSON. Pending jobs are kept in the browser's localStorage and resume after a refresh. Served as /live_test_page_89.
 *
 * The browser talks straight to the relay endpoints with the token as a Bearer header.
 * Do not leave this page deployed publicly: it is a debugging tool.
 */

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
    <div><label for="voice">Voice (name or ElevenLabs voice ID)</label><input type="text" id="voice" autocomplete="off"></div>
  </div>
  <label for="input">Text <span class="mut" id="count"></span></label>
  <textarea id="input" required placeholder="[cheerfully] Hello there! [whispering] This is a test."></textarea>
  <div class="row">
    <div><label for="eleven">ElevenLabs options (optional JSON)</label><input type="text" id="eleven" placeholder='{"seed":1,"language_code":"en"}' autocomplete="off"></div>
    <div><label for="speed">Speed (optional; not for Eleven v4)</label><input type="text" id="speed" placeholder="1.0" autocomplete="off"></div>
  </div>
  <label><input type="checkbox" id="async" checked> Async (return a job id and poll)</label>
  <button type="submit">Generate speech</button>
</form>
<div id="jobs"></div>

<script>
(function () {
  var BASE = <?= json_encode($relayPath) ?>;
  var KEY = 'relaytest89.jobs';
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
      var h = el('h3', null, (j.job_id ? 'Job ' + j.job_id : (j.sync ? 'Request' : 'Submission failed')) + ' ');
      var st = el('span', 'st-' + j.status, '[' + j.status + ']');
      h.appendChild(st);
      var rm = el('button', 's', 'dismiss');
      rm.type = 'button';
      rm.style.float = 'right';
      rm.onclick = function () { stop(j.uid); save(load().filter(function (x) { return x.uid !== j.uid; })); render(); };
      h.appendChild(rm);
      box.appendChild(h);
      box.appendChild(el('div', 'mut', new Date(j.started).toLocaleString() + ' · ' + j.prompt.slice(0, 120) + (j.sync ? ' · sync' : '')
        + (j.note ? ' · ' + j.note : '')));
      (j.files || []).forEach(function (f) {
        var au = el('audio'); au.controls = true; box.appendChild(au);
        var dl = el('a', null, 'download ' + f); dl.download = f; dl.hidden = true; box.appendChild(dl);
        fetch(BASE + '/audio/' + encodeURIComponent(f), { headers: { Authorization: 'Bearer ' + j.token } })
          .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
          .then(function (b) { au.src = dl.href = URL.createObjectURL(b); dl.hidden = false; })
          .catch(function (e) { au.replaceWith(el('div', 'st-error', 'Audio ' + f + ' failed to load: ' + e.message)); });
      });
      if (j.raw !== undefined) box.appendChild(el('pre', null, pretty(j.raw)));
      jobsEl.appendChild(box);
    });
  }

  function stop(uid) { if (timers[uid]) { clearTimeout(timers[uid]); delete timers[uid]; } }

  function audioFiles(json) {
    var m = /([a-f0-9]{32}\.mp3)(?:[?#].*)?$/.exec((json && json.audio && json.audio.url) || '');
    return m ? [m[1]] : [];
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
          update(job.uid, { status: j.status, note: 'HTTP ' + r.status, raw: j, files: audioFiles(j) });
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

  var $ = function (id) { return document.getElementById(id); };

  // Defaults and model suggestions from /models.
  fetch(BASE + '/models', { headers: { Authorization: 'Bearer ' + window.RELAY_TOKEN } }).then(readJson).then(function (r) {
    var sp = r.json && r.json.speech;
    if (!sp) { $('count').textContent = '(speech is not enabled on this relay)'; return; }
    $('voice').placeholder = sp.default_voice;
    var sel = $('model'); sel.innerHTML = ''; sel.dataset.def = sp.default_model;
    var d = el('option'); d.value = ''; d.textContent = 'Default (' + sp.default_model + ')'; sel.appendChild(d);
    (Array.isArray(sp.models) ? sp.models : []).forEach(function (m) { var o = el('option'); o.value = m; o.textContent = m; sel.appendChild(o); });
    window.SPEECH_LIMITS = sp.max_input_length || {};
    countChars();
  }).catch(function (e) { $('model').innerHTML = '<option value="">Default (could not load models: ' + e.message + ')</option>'; });

  function countChars() {
    var lim = window.SPEECH_LIMITS || {}, m = $('model').value || $('model').dataset.def;
    var max = lim[m] || lim['default'];
    $('count').textContent = '(' + $('input').value.length + (max ? ' / ' + max : '') + ' characters)';
  }
  $('input').addEventListener('input', countChars);
  $('model').addEventListener('change', countChars);

  $('f').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var token = window.RELAY_TOKEN;
    var text = $('input').value, async = $('async').checked;
    var body = { input: text, async: async };
    if ($('model').value) body.model = $('model').value;
    if ($('voice').value.trim()) body.voice = $('voice').value.trim();
    if ($('speed').value.trim()) body.speed = Number($('speed').value);
    if ($('eleven').value.trim()) {
      try { body.elevenlabs = JSON.parse($('eleven').value); }
      catch (e) { alert('ElevenLabs options must be valid JSON: ' + e.message); return; }
    }
    var job = { uid: String(Date.now()) + Math.random().toString(36).slice(2, 6), token: token, prompt: text,
                started: Date.now(), status: 'submitting', sync: !async };
    var list = load(); list.unshift(job); save(list); render();
    fetch(BASE + '/speech', { method: 'POST', body: JSON.stringify(body),
        headers: { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' } })
      .then(readJson)
      .then(function (r) {
        if (r.json && r.json.job_id && (r.status === 202 || r.status === 200)) {
          job.job_id = r.json.job_id;
          update(job.uid, { job_id: job.job_id, status: 'running', raw: r.json });
          poll(job);
        } else if (r.json && r.json.ok && r.json.audio) { // synchronous result
          update(job.uid, { status: 'done', note: 'HTTP ' + r.status, raw: r.json, files: audioFiles(r.json) });
        } else { // validation error, bad token, rate limit, upstream failure...: raw JSON as returned
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
<title>Speech relay test</title>
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
  audio { width:100%; margin-top:8px; display:block; } .job a { font-size:13px; }
</style>
</head>
<body>
<div id="bar"><h1>Speech relay test</h1><button type="button" class="s" id="logout" hidden>Clear token (log out)</button></div>
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
  var TOKEN_KEY = 'relaytest89.token', JOBS_KEY = 'relaytest89.jobs';
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
