<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';
admin_init();
$user = require_admin();

$id = (int) get_str('id');
$site = $id ? db_one('SELECT * FROM websites WHERE id = ?', [$id]) : null;
if ($id && !$site) {
    flash('error', 'Website not found.');
    redirect('websites.php');
}
$v = [
    'name' => $site['name'] ?? '',
    'domain' => $site['domain'] ?? '',
    'is_active' => (int) ($site['is_active'] ?? 1),
];
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    $v['name'] = post_str('name', 150);
    $domain = strtolower(post_str('domain', 190));
    $domain = preg_replace('#^https?://#', '', $domain);
    $v['domain'] = rtrim((string) preg_replace('#/.*$#', '', (string) $domain), '.');
    $v['is_active'] = isset($_POST['is_active']) ? 1 : 0;

    if ($v['name'] === '') $errors[] = 'Website name is required.';
    if ($v['domain'] === '' || !preg_match('/^[a-z0-9.\-]+(:\d+)?$/', $v['domain'])) $errors[] = 'Enter a valid domain, e.g. example.com';

    if (!$errors) {
        if ($site) {
            db_exec('UPDATE websites SET name = ?, domain = ?, is_active = ?, updated_at = ? WHERE id = ?', [
                $v['name'], $v['domain'], $v['is_active'], utc_now(), $id,
            ]);
            log_event('info', 'website.updated', ['website_id' => $id, 'user_id' => (int) $user['id']]);
            flash('success', 'Website saved.');
            redirect('websites.php');
        }
        [$key, $hash, $prefix] = lh_generate_api_key();
        db_exec('INSERT INTO websites (name, domain, allowed_origins, allow_no_origin, api_key, api_key_prefix, api_key_hash, is_active, created_at, updated_at) VALUES (?, ?, \'\', 1, ?, ?, ?, ?, ?, ?)', [
            $v['name'], $v['domain'], $key, $prefix, $hash, $v['is_active'], utc_now(), utc_now(),
        ]);
        $newId = (int) db()->lastInsertId();
        log_event('info', 'website.created', ['website_id' => $newId, 'user_id' => (int) $user['id']]);
        flash('success', 'Website created. Copy the API key and integration prompt below.');
        redirect('website_key.php?id=' . $newId);
    }
}

page_header($site ? 'Edit website' : 'Add website', 'websites');
?>
<a class="back-link" href="websites.php">&larr; Websites</a>
<div class="page-head"><div><p class="eyebrow"><?= $site ? 'Edit' : 'New source' ?></p><h1><?= $site ? e($site['name']) : 'Add a website' ?></h1></div></div>

<?php if ($errors): ?>
  <div class="flash flash-error" role="alert" data-testid="website-form-errors"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="lead-layout">
  <form method="post" action="website_edit.php<?= $site ? '?id=' . $id : '' ?>" class="panel stack" data-testid="website-form">
    <?= csrf_field() ?>
    <label>Website name<input name="name" value="<?= e($v['name']) ?>" required maxlength="150" placeholder="Bright Smile Dental" data-testid="website-name-input"></label>
    <label>Domain<input name="domain" value="<?= e($v['domain']) ?>" required maxlength="190" placeholder="brightsmile.com" data-testid="website-domain-input"></label>
    <label class="check"><input type="checkbox" name="is_active" value="1" <?= $v['is_active'] ? 'checked' : '' ?> data-testid="website-active-checkbox"> Active</label>
    <div class="row-gap">
      <button class="btn btn-primary" data-testid="website-save-button"><?= $site ? 'Save changes' : 'Create website &amp; API key' ?></button>
      <a class="btn" href="websites.php">Cancel</a>
    </div>
  </form>
  <aside class="panel help">
    <h2>How it works</h2>
    <p class="small">Every website gets its own public API key. Any form on any page can post to the API endpoint with that key — no origin allow-list to maintain.</p>
    <p class="small">There are no fixed form fields: whatever the form sends is stored and shown under this website in the Leads inbox.</p>
  </aside>
</div>
<?php
page_footer();
