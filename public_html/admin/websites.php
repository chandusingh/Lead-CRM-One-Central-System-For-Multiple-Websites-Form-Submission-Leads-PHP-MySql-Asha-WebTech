<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';
admin_init();
$user = require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    $id = (int) post_str('id');
    if (post_str('action') === 'toggle' && $id) {
        db_exec('UPDATE websites SET is_active = 1 - is_active, updated_at = ? WHERE id = ?', [utc_now(), $id]);
        $w = db_one('SELECT name, is_active FROM websites WHERE id = ?', [$id]);
        if ($w) {
            log_event('info', 'website.toggled', ['website_id' => $id, 'active' => (int) $w['is_active'], 'user_id' => (int) $user['id']]);
            flash('success', $w['name'] . ((int) $w['is_active'] ? ' activated.' : ' deactivated. Its API key will be rejected until reactivated.'));
        }
    }
    redirect('websites.php');
}

$sites = db_all(
    'SELECT w.*, COUNT(l.id) AS lead_count, MAX(l.created_at) AS last_at FROM websites w LEFT JOIN leads l ON l.website_id = w.id
     GROUP BY w.id ORDER BY w.is_active DESC, w.name'
);

page_header('Websites', 'websites');
?>
<div class="page-head">
  <div><p class="eyebrow">Sources</p><h1>Websites</h1></div>
  <a class="btn btn-primary" href="website_edit.php" data-testid="add-website-button">+ Add website</a>
</div>

<section class="panel panel-flush">
<?php if (!$sites): ?>
  <div class="empty" data-testid="websites-empty"><h2>No websites yet</h2><p class="muted">Add a website to get its API key and integration prompt.</p><a class="btn btn-primary" href="website_edit.php">Add website</a></div>
<?php else: ?>
  <div class="table-wrap"><table class="table table-cards" data-testid="websites-table">
    <thead><tr><th>Website</th><th>Public API key</th><th class="num">Leads</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sites as $w): ?>
      <tr data-testid="website-row-<?= (int) $w['id'] ?>">
        <td data-label="Website"><strong><?= e($w['name']) ?></strong><div class="muted small"><?= e($w['domain']) ?></div></td>
        <td data-label="API key"><?php if (!empty($w['api_key'])): ?><code id="k<?= (int) $w['id'] ?>" class="key-inline"><?= e($w['api_key']) ?></code> <button type="button" class="btn btn-sm" data-copy="#k<?= (int) $w['id'] ?>">Copy</button>
          <?php else: ?><span class="muted small">hidden · <a href="website_key.php?id=<?= (int) $w['id'] ?>">generate new</a></span><?php endif; ?></td>
        <td class="num" data-label="Leads"><a href="leads.php?website=<?= (int) $w['id'] ?>"><?= (int) $w['lead_count'] ?></a></td>
        <td data-label="Status"><?= (int) $w['is_active'] ? '<span class="badge status-converted">Active</span>' : '<span class="badge badge-muted">Inactive</span>' ?></td>
        <td class="actions" data-label="">
          <a class="btn btn-sm" href="website_key.php?id=<?= (int) $w['id'] ?>" data-testid="website-integration-link-<?= (int) $w['id'] ?>">API &amp; setup</a>
          <a class="btn btn-sm" href="website_edit.php?id=<?= (int) $w['id'] ?>" data-testid="website-edit-link-<?= (int) $w['id'] ?>">Edit</a>
          <form method="post" action="websites.php" class="inline" data-confirm="<?= (int) $w['is_active'] ? 'Deactivate this website? Its submissions will be rejected.' : 'Activate this website?' ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
            <button class="btn btn-sm <?= (int) $w['is_active'] ? 'btn-danger-ghost' : '' ?>" data-testid="website-toggle-<?= (int) $w['id'] ?>"><?= (int) $w['is_active'] ? 'Deactivate' : 'Activate' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</section>
<?php
page_footer();
