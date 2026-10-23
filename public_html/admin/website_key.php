<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';
admin_init();
$user = require_admin();

$id = (int) get_str('id');
$site = db_one('SELECT * FROM websites WHERE id = ?', [$id]);
if (!$site) {
    flash('error', 'Website not found.');
    redirect('websites.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    if (post_str('action') === 'rotate') {
        [$key, $hash, $prefix] = lh_generate_api_key();
        db_exec('UPDATE websites SET api_key = ?, api_key_hash = ?, api_key_prefix = ?, key_rotated_at = ?, updated_at = ? WHERE id = ?', [$key, $hash, $prefix, utc_now(), utc_now(), $id]);
        log_event('info', 'website.key_rotated', ['website_id' => $id, 'user_id' => (int) $user['id']]);
        flash('success', 'New API key generated. The previous key stopped working immediately.');
    }
    redirect('website_key.php?id=' . $id);
}

$apiKey = (string) ($site['api_key'] ?? '');
$hasKey = $apiKey !== '';
$endpoint = api_endpoint_url();
$keyShown = $hasKey ? $apiKey : 'YOUR_API_KEY';

$prompt = <<<TXT
Integrate our CRM lead capture on this website's form(s).

On form submit (preventDefault, use fetch), POST to:
{$endpoint}
Header: Content-Type: text/plain
Body (JSON): {"key":"{$keyShown}","fields":{<every form field as "name":"value">},"source_url":location.href,"page_title":document.title}

Send ALL fields the form has, using their own names (no renaming, nothing hardcoded).
Success = response JSON {"ok":true}. Show the user a thank-you; on failure show an error. Works from any domain.
TXT;

$curl = <<<TXT
curl -X POST '{$endpoint}' \\
  -H 'Content-Type: application/json' \\
  -d '{"key":"{$keyShown}","fields":{"name":"Asha","email":"asha@example.com","any_field":"any value"}}'
TXT;

page_header('API & setup · ' . $site['name'], 'websites');
?>
<a class="back-link" href="websites.php">&larr; Websites</a>
<div class="page-head">
  <div><p class="eyebrow">API</p><h1><?= e($site['name']) ?></h1>
    <p class="muted"><?= e($site['domain']) ?> · <?= (int) $site['is_active'] ? 'Active' : 'Inactive' ?></p></div>
  <a class="btn" href="website_edit.php?id=<?= $id ?>">Edit website</a>
</div>

<?php if (!$hasKey): ?>
<div class="flash flash-info" data-testid="legacy-key-note">
  This website was created before keys were stored in readable form, so its current key cannot be displayed. Click <strong>Generate new key</strong> once — from then on the key is always visible here. (Pages using the old key must be updated.)
</div>
<?php endif; ?>

<div class="grid-2">
  <section class="panel">
    <div class="panel-head"><h2>Credentials</h2></div>
    <dl class="meta">
      <dt>API endpoint</dt><dd><code id="endpoint" data-testid="api-endpoint"><?= e($endpoint) ?></code> <button type="button" class="btn btn-sm" data-copy="#endpoint">Copy</button></dd>
      <dt>Method</dt><dd><code>POST</code> · JSON or text/plain JSON</dd>
      <dt>Public API key</dt>
      <dd><?php if ($hasKey): ?><code id="api-key" data-testid="api-key"><?= e($apiKey) ?></code> <button type="button" class="btn btn-sm" data-copy="#api-key" data-testid="copy-api-key-button">Copy</button>
        <?php else: ?><span class="muted">Not available — generate a new key</span><?php endif; ?></dd>
      <dt>Last rotated</dt><dd><?= $site['key_rotated_at'] ? e(fmt_dt($site['key_rotated_at'])) : 'Never' ?></dd>
    </dl>
  </section>
  <section class="panel danger-zone">
    <div class="panel-head"><h2><?= $hasKey ? 'Rotate API key' : 'Generate new key' ?></h2></div>
    <p class="small">Creates a new key and <strong>immediately invalidates</strong> the current one. Update every page that uses the old key.</p>
    <form method="post" action="website_key.php?id=<?= $id ?>" data-confirm="Generate a new API key? The current key will stop working immediately.">
      <?= csrf_field() ?><input type="hidden" name="action" value="rotate">
      <button class="btn btn-danger" data-testid="rotate-key-button"><?= $hasKey ? 'Rotate key' : 'Generate new key' ?></button>
    </form>
  </section>
</div>

<section class="panel" data-testid="integration-prompt">
  <div class="panel-head"><h2>Prompt for developers / AI</h2><button type="button" class="btn btn-sm btn-primary" data-copy="#prompt-text" data-testid="copy-prompt-button">Copy prompt</button></div>
  <p class="small muted">Send this to whoever builds the other website. It already contains this website's endpoint and key.</p>
  <pre class="code" id="prompt-text"><?= e($prompt) ?></pre>
</section>

<section class="panel">
  <div class="panel-head"><h2>Test with cURL</h2><button type="button" class="btn btn-sm" data-copy="#curl-text">Copy</button></div>
  <pre class="code" id="curl-text"><?= e($curl) ?></pre>
  <p class="small muted">Response: <code>{"ok":true,"lead_id":"LH-…"}</code>. Optional extras: <code>channel</code> (form / whatsapp / email), <code>idempotency_key</code>.</p>
</section>
<?php
page_footer();
