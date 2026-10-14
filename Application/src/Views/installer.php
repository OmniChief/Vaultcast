<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/Installer.php';
session_name('vaultcast_setup');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict', 'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$error = '';
$errorStep = 0;
$jsonRequest = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
$completed = \Vaultcast\Database::installed();
$checks = \Vaultcast\Installer::checks();
$optionalChecks = \Vaultcast\Installer::optionalChecks();
$nativeManaged = \Vaultcast\Installer::nativeManaged();
if (!$completed && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('The setup session expired. Reload and try again.');
        \Vaultcast\Installer::install($_POST);
        // Start the normal administrator session for initial indexing and settings.
        require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
        Auth::createSession(Auth::findUser(trim($_POST['username'])));
        $_SESSION = [];
        session_destroy();
        $redirect = \Vaultcast\Database::basePath() . '?setup=library';
        if ($jsonRequest) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'redirect' => $redirect]);
            exit;
        }
        header('Location: ' . $redirect, true, 303);
        exit;
    } catch (Throwable $e) {
        $error = $e instanceof PDOException ? 'MariaDB could not finish setup. Check the server log and database permissions.' : $e->getMessage();
        $errorStep = $e instanceof \Vaultcast\SetupInputException ? $e->step : ($e instanceof PDOException ? 1 : 2);
        if ($e instanceof PDOException) error_log('Vaultcast installer: ' . $e->getMessage());
        if ($jsonRequest) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $error, 'step' => $errorStep]);
            exit;
        }
    }
}
function setup_escape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function setup_value(string $name, string $default = ''): string { return setup_escape($_POST[$name] ?? $default); }
$managed = is_file(dirname(__DIR__, 2) . '/data/setup-database.php');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Set up Vaultcast</title>
<style>
[hidden]{display:none!important}
:root{color-scheme:dark;font-family:Inter,system-ui,sans-serif;background:#0c1119;color:#f0f3f7}*{box-sizing:border-box}body{margin:0;padding:48px 20px;background:radial-gradient(ellipse at 12% 0,#253043,transparent 60%);min-height:100vh}main{max-width:820px;margin:auto}.eyebrow{color:#f0c58b;text-transform:uppercase;letter-spacing:.18em;font-size:.76rem;font-weight:700}h1{font-size:clamp(2.2rem,6vw,3.8rem);letter-spacing:-.055em;margin:10px 0}h2{letter-spacing:-.03em}p,small{color:#b4c0d1;line-height:1.7}.panel,fieldset{border:1px solid #303b4c;background:#131c28;border-radius:20px;padding:28px;margin:24px 0}fieldset{min-width:0}legend{padding:0 8px;font-size:1.25rem;font-weight:650}label{display:block;margin:18px 0 7px;font-size:.9rem}input:not([type=checkbox]){display:block;width:100%;padding:13px;border:1px solid #3d4c61;border-radius:10px;background:#0d1520;color:inherit;font:inherit}input[type=checkbox]{width:18px;height:18px;accent-color:#e9bd7e;vertical-align:middle;margin-right:9px}button,.button{display:inline-block;padding:13px 23px;border:1px solid transparent;border-radius:11px;background:#e9bd7e;color:#17202a;font:inherit;font-weight:650;cursor:pointer;text-decoration:none;min-height:46px}button.secondary{background:transparent;color:inherit;border-color:#47576c}button:disabled{opacity:.45;cursor:not-allowed}a{color:#f0c58b}:focus-visible{outline:3px solid #f0c58b;outline-offset:4px}.error{border:1px solid #a9575b;padding:16px;border-radius:12px;background:#32212a}.checks{list-style:none;padding:0}.checks li{padding:8px 0;border-bottom:1px solid #263447}.good{color:#90d6bb}.bad{color:#ffaeab}.row{display:grid;grid-template-columns:1fr 1fr;gap:18px}.steps{display:flex;gap:8px;padding:0;list-style:none;flex-wrap:wrap}.steps li{padding:8px 14px;color:#aebacf;border:1px solid #35445a;border-radius:999px;font-size:.85rem}.steps [aria-current=step]{color:#f0c58b;border-color:#e9bd7e}.actions{display:flex;gap:12px;justify-content:flex-end}code{color:#f0c58b;overflow-wrap:anywhere}.hint{font-size:.88rem}details{margin:16px 0}summary{cursor:pointer;padding:8px 0}fieldset[hidden]{display:none}@media(max-width:560px){body{padding:24px 14px}.row{grid-template-columns:1fr}.panel,fieldset{padding:18px}.actions{flex-wrap:wrap}}
</style></head><body><main>
<div class="eyebrow">Vaultcast · Your collection, your space</div><h1>A home for your media.</h1><p>Films, shows, music and games. Choose what belongs here; add more whenever you like.</p>
<?php if ($completed): ?>
<section class="panel"><h2>Find and match your media</h2><p>Your site and administrator account are saved. Scan the selected folders to organise titles and episodes, then review matches before opening your library. You can finish this step later without repeating account setup.</p><div class="actions"><button id="scan">Scan my folders</button><a id="review-matches" class="button" hidden href="<?= setup_escape(\Vaultcast\Database::basePath()) ?>#/settings/matching">Review matches</a><a class="button" href="<?= setup_escape(\Vaultcast\Database::basePath()) ?>">Open my library</a></div><p id="scan-status" role="status"></p></section>
<script>document.getElementById('scan').onclick=function(){var b=this,s=document.getElementById('scan-status');b.disabled=true;s.textContent='Reading and matching your media folders…';fetch(<?= json_encode(\Vaultcast\Database::basePath().'api/admin/rescan') ?>,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:'{}'}).then(function(r){return r.json();}).then(function waitForTask(j){if(!j.ok)throw Error(j.error||'Scan failed');if(j.maintenance_job){s.textContent='Scanning in the background. You can manage or cancel this task in Settings > Background tasks.';return new Promise(function(resolve){setTimeout(resolve,1500);}).then(function(){return fetch(<?= json_encode(\Vaultcast\Database::basePath().'api/admin/maintenance/') ?>+encodeURIComponent(j.maintenance_job),{credentials:'same-origin'});}).then(function(r){return r.json();}).then(function(status){if(!status.ok||!status.job)throw Error('Could not read scan status');if(status.job.status==='complete')return status.job.result;if(status.job.status==='failed'||status.job.status==='cancelled')throw Error(status.job.error||status.job.status);return waitForTask(j);});}return j;}).then(function(j){s.textContent='Found '+j.item_count+' items. Review any incorrect title or episode matches, or open your library.';document.getElementById('review-matches').hidden=false;b.textContent='Scan again';b.disabled=false;}).catch(function(e){s.textContent=e.message+' Your account and settings are saved. Retry this scan or continue to the library.';b.disabled=false;});};</script>
<?php else: ?>
<p id="setup-error" class="error" role="alert" tabindex="-1" <?= $error ? '' : 'hidden' ?>><?= setup_escape($error) ?></p>
<section class="panel"><h2>Your server</h2><p class="hint">Required</p><ul class="checks"><?php foreach($checks as $label=>$ok): ?><li class="<?= $ok?'good':'bad' ?>"><?= $ok?'✓':'✗' ?> <?= setup_escape($label) ?><?= $ok?'':' — required' ?></li><?php endforeach ?></ul>
<p class="hint">Optional features (setup works without them)</p><ul class="checks"><?php foreach($optionalChecks as $label=>$ok): ?><li class="<?= $ok?'good':'' ?>"><?= $ok?'✓':'○' ?> <?= setup_escape($label) ?><?= $ok?'':' — not available' ?></li><?php endforeach ?></ul>
<p class="hint">Multiplayer needs Node.js running the included relay; single-player games do not. FFmpeg and ffprobe enable media analysis and offline conversion. PHP cURL enables metadata and player-file downloads. Optional downloads use Transmission with cURL and SimpleXML.</p>
<?php if ($nativeManaged): ?><p class="hint">This server was set up by the Vaultcast Windows installer. To add a missing optional feature, run the installer again and choose Update or repair, then refresh this page.</p>
<?php else: ?><details><summary>Install missing dependencies</summary><p>On Windows, run the Vaultcast installer, or double-click <code>Installers/Windows/x86-64/install.cmd</code>. On Debian/Ubuntu, run <code>bash Installers/Linux/setup.sh</code>. Setup asks for your agreement before installing software. Existing servers can install missing components through their package manager.</p><p>The web app never receives operating-system administrator credentials. After dependency installation, refresh this page.</p></details><?php endif ?></section>
<ol class="steps" aria-label="Setup progress"><li aria-current="step">1 · Your space</li><li>2 · Database</li><li>3 · Libraries</li></ol>
<form method="post" autocomplete="off" id="setup-form"><input type="hidden" name="csrf" value="<?= setup_escape($_SESSION['csrf']) ?>">
<fieldset data-step="0"><legend>Your space</legend><label for="site_name">Site name</label><input id="site_name" name="site_name" value="<?= setup_value('site_name') ?>" placeholder="Choose a name for your library" maxlength="80" required>
<p class="hint">This appears in navigation, the browser tab and the installed app. Change it later in Settings.</p><h2>Create your administrator</h2><div class="row"><div><label for="username">Username</label><input id="username" name="username" value="<?= setup_value('username') ?>" minlength="3" maxlength="80" autocomplete="username" required></div><div><label for="password">Password</label><input id="password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></div></div><label for="password_confirm">Confirm password</label><input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required><p class="hint">Use at least 12 characters. No default accounts or passwords are created.</p></fieldset>
<fieldset data-step="1"><legend>Connect MariaDB</legend>
<?php if($managed): ?><input type="hidden" name="managed_database" value="1"><p>Your native setup launcher created a private database account. Vaultcast will use it automatically; no password needs to be copied into this page.</p>
<?php else: ?>
<div class="row"><div><label for="db_host">Database server</label><input id="db_host" name="db_host" value="<?= setup_value('db_host','127.0.0.1') ?>" required></div><div><label for="db_port">Port</label><input id="db_port" name="db_port" type="number" min="1" max="65535" value="<?= setup_value('db_port','3306') ?>" required></div></div>
<label for="db_name">Database name</label><input id="db_name" name="db_name" value="<?= setup_value('db_name') ?>" placeholder="Choose a new database name, or enter an empty existing database" required><label for="db_user">Database username</label><input id="db_user" name="db_user" value="<?= setup_value('db_user') ?>" required><label for="db_password">Database password</label><input id="db_password" name="db_password" type="password" autocomplete="new-password">
<label><input type="checkbox" name="create_database" value="1" <?= !empty($_POST['create_database'])?'checked':'' ?>>Create a new local database and restricted application account</label><p class="hint">For a fresh local MariaDB installation, enter its administrator username and password and select this option. Vaultcast stores a newly generated application password, not the administrator password. For a remote or existing database, provide an account with access to that database and leave this unchecked.</p>
<?php endif ?></fieldset>
<fieldset data-step="2"><legend>Choose your libraries</legend><p>Enter folders as seen by the server. Nothing is selected in advance. You can skip this and add folders later in Settings → Libraries.</p>
<?php foreach(['movies'=>'Films','tv'=>'TV shows','anime'=>'Anime','music'=>'Music','roms'=>'Games'] as $type=>$label): ?><label><input type="checkbox" name="enable_<?= $type ?>" value="1" <?= !empty($_POST['enable_'.$type])?'checked':'' ?>><?= $label ?></label><input name="path_<?= $type ?>" aria-label="<?= $label ?> folder" value="<?= setup_value('path_'.$type) ?>" placeholder="Full path to a readable folder"><?php endforeach ?></fieldset>
<div class="actions"><button type="button" class="secondary" id="previous" hidden>Back</button><button type="button" id="next" hidden>Continue</button><button type="submit" id="install" <?= in_array(false,$checks,true)?'disabled':'' ?>>Create my library</button></div>
</form>
<script>
(function () {
    var form = document.getElementById('setup-form');
    var panels = form.querySelectorAll('[data-step]');
    var markers = document.querySelectorAll('.steps li');
    var next = document.getElementById('next');
    var previous = document.getElementById('previous');
    var install = document.getElementById('install');
    var error = document.getElementById('setup-error');
    var step = 0, pending = false;
    var canInstall = !install.disabled;
    function show(n) {
        step = n;
        panels.forEach(function (panel, i) {
            panel.hidden = i !== n;
            markers[i].removeAttribute('aria-current');
        });
        markers[n].setAttribute('aria-current', 'step');
        previous.hidden = n === 0;
        next.hidden = n === panels.length - 1;
        install.hidden = n !== panels.length - 1;
    }
    function passwordsMatch() {
        form.password_confirm.setCustomValidity(form.password.value === form.password_confirm.value ? '' : 'Passwords must match.');
        return form.password_confirm.reportValidity();
    }
    next.onclick = function () {
        var inputs = panels[step].querySelectorAll('input');
        for (var i = 0; i < inputs.length; i++) if (!inputs[i].reportValidity()) return;
        if (step === 0 && !passwordsMatch()) return;
        show(step + 1);
    };
    form.password.oninput = form.password_confirm.oninput = function () { form.password_confirm.setCustomValidity(''); };
    previous.onclick = function () { show(step - 1); };
    form.addEventListener('invalid', function (event) {
        var panel = event.target.closest('[data-step]');
        if (panel) show(Number(panel.dataset.step));
    }, true);
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (pending || !canInstall || !passwordsMatch()) return;
        if (step < panels.length - 1) { next.click(); return; }
        pending = true;
        install.disabled = next.disabled = previous.disabled = true;
        install.textContent = 'Creating your library…';
        error.hidden = true;
        fetch(form.action, {
            method: 'POST', credentials: 'same-origin',
            headers: {Accept: 'application/json'}, body: new FormData(form)
        }).then(function (response) {
            return response.json();
        }).then(function (result) {
            if (result.ok && result.redirect) {
                window.location.assign(result.redirect);
                return;
            }
            if (Number.isInteger(result.step) && result.step >= 0 && result.step < panels.length) show(result.step);
            throw new Error(result.error || 'Setup could not finish. Your entries are still here; please try again.');
        }).catch(function (failure) {
            error.textContent = failure instanceof SyntaxError ? 'The server did not return a setup response. Your entries are still here; please retry.' : failure.message;
            error.hidden = false;
            error.focus();
        }).finally(function () {
            pending = false;
            install.disabled = !canInstall;
            next.disabled = previous.disabled = false;
            install.textContent = 'Create my library';
        });
    });
    show(<?= (int)$errorStep ?>);
})();
</script>
<?php endif ?></main></body></html>
