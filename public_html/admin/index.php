<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';
admin_init();
$user = require_login();

$now = utc_now();
$today = local_midnight_utc(0);
$week = local_midnight_utc(-6);

$s = db_one(
    "SELECT COUNT(*) AS total,
        COALESCE(SUM(created_at >= ?), 0) AS today,
        COALESCE(SUM(created_at >= ?), 0) AS week,
        COALESCE(SUM(status = 'new'), 0) AS awaiting,
        COALESCE(SUM(follow_up_at IS NOT NULL AND follow_up_at <= ? AND status NOT IN ('converted','lost')), 0) AS due
     FROM leads",
    [$today, $week, $now]
);
$sites = db_all(
    "SELECT w.id, w.name, w.domain, w.is_active, COUNT(l.id) AS total,
        COALESCE(SUM(l.created_at >= ?), 0) AS today, COALESCE(SUM(l.created_at >= ?), 0) AS week,
        COALESCE(SUM(l.status = 'new'), 0) AS awaiting, MAX(l.created_at) AS last_at
     FROM websites w LEFT JOIN leads l ON l.website_id = w.id
     GROUP BY w.id, w.name, w.domain, w.is_active ORDER BY total DESC, w.name",
    [$today, $week]
);
$channels = array_column(db_all('SELECT channel, COUNT(*) AS c FROM leads GROUP BY channel'), 'c', 'channel');
$statuses = array_column(db_all('SELECT status, COUNT(*) AS c FROM leads GROUP BY status'), 'c', 'status');
$recent = db_all(
    'SELECT l.public_id, l.name, l.email, l.phone, l.channel, l.status, l.created_at, w.name AS website
     FROM leads l JOIN websites w ON w.id = l.website_id ORDER BY l.id DESC LIMIT 10'
);
$dueList = db_all(
    "SELECT l.public_id, l.name, l.email, l.follow_up_at, w.name AS website FROM leads l JOIN websites w ON w.id = l.website_id
     WHERE l.follow_up_at IS NOT NULL AND l.follow_up_at <= ? AND l.status NOT IN ('converted','lost') ORDER BY l.follow_up_at LIMIT 6",
    [$now]
);
$maxSite = max(1, ...array_map(static fn($r) => (int) $r['total'], $sites ?: [['total' => 1]]));
$total = max(1, (int) $s['total']);

$cards = [
    ['total', 'Total leads', $s['total'], 'leads.php', 'All time, every website'],
    ['today', "Today's leads", $s['today'], 'leads.php?from=' . local_today() . '&to=' . local_today(), 'Since midnight'],
    ['new', 'New leads', $s['week'], 'leads.php?from=' . local_today(-6), 'Received in the last 7 days'],
    ['awaiting', 'Awaiting contact', $s['awaiting'], 'leads.php?status=new', 'Status still “New”'],
    ['due', 'Follow-ups due', $s['due'], 'leads.php?followup=due', 'Due now or overdue'],
];

page_header('Dashboard', 'dashboard');
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Overview</p>
    <h1>Good to see you, <?= e(explode(' ', $user['name'])[0]) ?>.</h1>
  </div>
  <form class="quick-search" method="get" action="leads.php" role="search">
    <input type="search" name="q" placeholder="Search name, email, phone, lead ID…" data-testid="dashboard-search-input">
    <button class="btn btn-primary" data-testid="dashboard-search-button">Search</button>
  </form>
</div>

<section class="stats" data-testid="dashboard-stats">
  <?php foreach ($cards as $i => [$key, $label, $val, $href, $hint]): ?>
    <a class="stat stat-<?= e($key) ?> reveal" href="<?= e($href) ?>" data-delay="<?= $i ?>" data-testid="stat-<?= e($key) ?>">
      <span class="stat-label"><?= e($label) ?></span>
      <span class="stat-value" data-testid="stat-<?= e($key) ?>-value"><?= number_format((int) $val) ?></span>
      <span class="stat-hint"><?= e($hint) ?></span>
    </a>
  <?php endforeach; ?>
</section>

<div class="grid-2">
  <section class="panel" data-testid="website-stats">
    <div class="panel-head"><h2>By website</h2><?php if (is_admin()): ?><a href="websites.php" class="link">Manage websites</a><?php endif; ?></div>
    <?php if (!$sites): ?>
      <p class="empty-text">No websites yet.<?php if (is_admin()): ?> <a href="website_edit.php">Add your first website</a>.<?php endif; ?></p>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Website</th><th class="num">Today</th><th class="num">7 days</th><th class="num">Awaiting</th><th class="num">Total</th><th>Last lead</th></tr></thead>
      <tbody>
      <?php foreach ($sites as $w): ?>
        <tr data-testid="website-stats-row-<?= (int) $w['id'] ?>">
          <td><a href="leads.php?website=<?= (int) $w['id'] ?>" class="strong"><?= e($w['name']) ?></a>
            <?php if (!(int) $w['is_active']): ?><span class="badge badge-muted">inactive</span><?php endif; ?>
            <div class="bar"><span data-w="<?= round((int) $w['total'] / $maxSite * 100) ?>"></span></div></td>
          <td class="num"><?= (int) $w['today'] ?></td>
          <td class="num"><?= (int) $w['week'] ?></td>
          <td class="num"><?= (int) $w['awaiting'] ?></td>
          <td class="num strong"><?= (int) $w['total'] ?></td>
          <td class="muted small"><?= e(time_ago($w['last_at'])) ?: '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>

  <div class="stack-lg">
    <section class="panel" data-testid="pipeline-panel">
      <div class="panel-head"><h2>Pipeline</h2></div>
      <ul class="dist">
        <?php foreach (LH_STATUSES as $k => $label): $c = (int) ($statuses[$k] ?? 0); ?>
          <li><a href="leads.php?status=<?= e($k) ?>"><?= status_badge($k) ?></a><div class="bar bar-<?= e($k) ?>"><span data-w="<?= round($c / $total * 100) ?>"></span></div><span class="num strong"><?= $c ?></span></li>
        <?php endforeach; ?>
      </ul>
      <div class="channel-row">
        <?php foreach (LH_CHANNELS as $k => $label): ?>
          <a href="leads.php?channel=<?= e($k) ?>" class="channel-pill" data-testid="channel-count-<?= e($k) ?>"><?= channel_badge($k) ?> <strong><?= (int) ($channels[$k] ?? 0) ?></strong></a>
        <?php endforeach; ?>
      </div>
    </section>
    <section class="panel" data-testid="due-followups">
      <div class="panel-head"><h2>Follow-ups due</h2><a class="link" href="leads.php?followup=due">View all</a></div>
      <?php if (!$dueList): ?><p class="empty-text">Nothing due. You're all caught up.</p><?php endif; ?>
      <ul class="list">
        <?php foreach ($dueList as $d): ?>
          <li><a href="lead.php?id=<?= e(urlencode($d['public_id'])) ?>"><strong><?= e($d['name'] ?: ($d['email'] ?: $d['public_id'])) ?></strong></a>
            <span class="muted small"><?= e($d['website']) ?> · due <?= e(fmt_dt($d['follow_up_at'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </section>
  </div>
</div>

<section class="panel" data-testid="recent-submissions">
  <div class="panel-head"><h2>Recent submissions</h2><a class="link" href="leads.php">Open inbox</a></div>
  <?php if (!$recent): ?>
    <p class="empty-text">No leads yet. Once a website submits to the API, leads appear here instantly.</p>
  <?php else: ?>
  <div class="table-wrap"><table class="table table-hover table-cards">
    <thead><tr><th>Lead</th><th>Contact</th><th>Website</th><th>Channel</th><th>Status</th><th>Received</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr>
        <td data-label="Lead"><a class="mono" href="lead.php?id=<?= e(urlencode($r['public_id'])) ?>" data-testid="recent-lead-link-<?= e($r['public_id']) ?>"><?= e($r['public_id']) ?></a></td>
        <td data-label="Contact"><strong><?= e($r['name'] ?: '—') ?></strong><div class="muted small"><?= e($r['email'] ?: $r['phone'] ?: '') ?></div></td>
        <td data-label="Website"><?= e($r['website']) ?></td>
        <td data-label="Channel"><?= channel_badge($r['channel']) ?></td>
        <td data-label="Status"><?= status_badge($r['status']) ?></td>
        <td class="muted small" data-label="Received" title="<?= e(fmt_dt($r['created_at'])) ?>"><?= e(time_ago($r['created_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php
page_footer();
