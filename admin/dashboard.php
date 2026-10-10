<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$pageTitle = 'Admin dashboard';
$stats = [
    'students' => 0,
    'teachers' => 0,
    'classes' => 0,
    'subjects' => 0,
    'pending_registrations' => 0,
    'unread_messages' => 0,
    'academic_year' => null,
    'current_term' => null,
];
$pendingRegistrations = [];
$unreadMessages = [];
$loadError = false;

try {
    $stats = db()->query(
        "SELECT
            (SELECT COUNT(*) FROM students WHERE status = 'active') AS students,
            (SELECT COUNT(*)
               FROM teachers t
               JOIN users u ON u.id = t.user_id
              WHERE u.is_active = 1) AS teachers,
            (SELECT COUNT(*) FROM classes) AS classes,
            (SELECT COUNT(*) FROM subjects) AS subjects,
            (SELECT COUNT(*) FROM registrations WHERE status = 'pending') AS pending_registrations,
            (SELECT COUNT(*) FROM messages WHERE is_read = 0) AS unread_messages,
            (SELECT name FROM academic_years WHERE is_current = 1 ORDER BY id DESC LIMIT 1) AS academic_year,
            (SELECT name FROM terms WHERE is_current = 1 ORDER BY id DESC LIMIT 1) AS current_term"
    )->fetch();

    $pendingRegistrations = db()->query(
        "SELECT id, first_name, last_name, class_applied, created_at
           FROM registrations
          WHERE status = 'pending'
          ORDER BY created_at DESC, id DESC
          LIMIT 5"
    )->fetchAll();

    $unreadMessages = db()->query(
        'SELECT id, name, subject, created_at
           FROM messages
          WHERE is_read = 0
          ORDER BY created_at DESC, id DESC
          LIMIT 5'
    )->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $loadError = true;
}

$quickLinks = [
    ['student-form.php', 'Add student', 'Create a student record'],
    ['teacher-form.php', 'Add teacher', 'Create a staff account'],
    ['classes.php', 'Manage classes', 'Set up class names and levels'],
    ['assign.php', 'Assign teachers', 'Connect teachers to subjects and classes'],
    ['news.php', 'Create news', 'Post or schedule a school update'],
    ['gallery.php', 'Upload photos', 'Add photos to the school gallery'],
];

include __DIR__ . '/../includes/staff-header.php';
?>

<main class="admin-page admin-dashboard">
  <section class="admin-dashboard-welcome" aria-labelledby="dashboard-title">
    <div>
      <p class="admin-eyebrow">Administration overview</p>
      <h1 id="dashboard-title">Welcome, <?= e($_SESSION['full_name'] ?? 'Admin') ?></h1>
      <p>Here is what is happening across <?= e(SITE_NAME) ?> today.</p>
    </div>
    <div class="admin-dashboard-term">
      <span>Current academic period</span>
      <strong><?= e($stats['academic_year'] ?: 'Year not set') ?></strong>
      <span><?= e($stats['current_term'] ?: 'Term not set') ?></span>
    </div>
  </section>

  <?php if ($loadError): ?>
    <div class="admin-alert admin-alert-error" role="alert">Dashboard information could not be loaded. Please try again later.</div>
  <?php else: ?>
    <section class="admin-dashboard-stats" aria-label="School summary">
      <a class="admin-stat-card" href="<?= BASE_URL ?>admin/students.php">
        <span class="admin-stat-label">Active students</span>
        <strong class="admin-stat-value"><?= (int)$stats['students'] ?></strong>
        <span class="admin-stat-link">View students <span aria-hidden="true">&rarr;</span></span>
      </a>
      <a class="admin-stat-card" href="<?= BASE_URL ?>admin/teachers.php">
        <span class="admin-stat-label">Active teachers</span>
        <strong class="admin-stat-value"><?= (int)$stats['teachers'] ?></strong>
        <span class="admin-stat-link">View teachers <span aria-hidden="true">&rarr;</span></span>
      </a>
      <a class="admin-stat-card" href="<?= BASE_URL ?>admin/classes.php">
        <span class="admin-stat-label">Classes</span>
        <strong class="admin-stat-value"><?= (int)$stats['classes'] ?></strong>
        <span class="admin-stat-link">Manage classes <span aria-hidden="true">&rarr;</span></span>
      </a>
      <a class="admin-stat-card" href="<?= BASE_URL ?>admin/assign.php">
        <span class="admin-stat-label">Subjects</span>
        <strong class="admin-stat-value"><?= (int)$stats['subjects'] ?></strong>
        <span class="admin-stat-link">Teacher assignments <span aria-hidden="true">&rarr;</span></span>
      </a>
      <a class="admin-stat-card admin-stat-attention" href="<?= BASE_URL ?>admin/registrations.php">
        <span class="admin-stat-label">Pending applications</span>
        <strong class="admin-stat-value"><?= (int)$stats['pending_registrations'] ?></strong>
        <span class="admin-stat-link">Review applications <span aria-hidden="true">&rarr;</span></span>
      </a>
      <a class="admin-stat-card admin-stat-attention" href="<?= BASE_URL ?>admin/messages.php">
        <span class="admin-stat-label">Unread messages</span>
        <strong class="admin-stat-value"><?= (int)$stats['unread_messages'] ?></strong>
        <span class="admin-stat-link">Open inbox <span aria-hidden="true">&rarr;</span></span>
      </a>
    </section>

    <section class="admin-dashboard-columns">
      <div class="admin-panel admin-dashboard-panel">
        <div class="admin-panel-heading">
          <div>
            <p class="admin-eyebrow">Needs your attention</p>
            <h2>Pending applications</h2>
          </div>
          <a class="admin-secondary-link admin-compact-link" href="<?= BASE_URL ?>admin/registrations.php">View all</a>
        </div>
        <?php if (!$pendingRegistrations): ?>
          <div class="admin-dashboard-empty">
            <strong>All caught up</strong>
            <span>There are no pending applications to review.</span>
          </div>
        <?php else: ?>
          <ul class="admin-dashboard-list">
            <?php foreach ($pendingRegistrations as $registration): ?>
              <li>
                <a href="<?= BASE_URL ?>admin/registrations.php?status=pending">
                  <span>
                    <strong><?= e($registration['first_name'] . ' ' . $registration['last_name']) ?></strong>
                    <small>Requested level: <?= e($registration['class_applied']) ?></small>
                  </span>
                  <time datetime="<?= e(date('Y-m-d\TH:i:s', strtotime($registration['created_at']))) ?>">
                    <?= e(date('M j', strtotime($registration['created_at']))) ?>
                  </time>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <div class="admin-panel admin-dashboard-panel">
        <div class="admin-panel-heading">
          <div>
            <p class="admin-eyebrow">Recent enquiries</p>
            <h2>Unread messages</h2>
          </div>
          <a class="admin-secondary-link admin-compact-link" href="<?= BASE_URL ?>admin/messages.php">Open inbox</a>
        </div>
        <?php if (!$unreadMessages): ?>
          <div class="admin-dashboard-empty">
            <strong>No unread messages</strong>
            <span>New contact form submissions will appear here.</span>
          </div>
        <?php else: ?>
          <ul class="admin-dashboard-list">
            <?php foreach ($unreadMessages as $message): ?>
              <li>
                <a href="<?= BASE_URL ?>admin/messages.php?status=unread">
                  <span>
                    <strong><?= e($message['subject']) ?></strong>
                    <small>From <?= e($message['name']) ?></small>
                  </span>
                  <time datetime="<?= e(date('Y-m-d\TH:i:s', strtotime($message['created_at']))) ?>">
                    <?= e(date('M j', strtotime($message['created_at']))) ?>
                  </time>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </section>

    <section class="admin-panel admin-dashboard-quick-links" aria-labelledby="quick-links-title">
      <div class="admin-panel-heading">
        <div>
          <p class="admin-eyebrow">Shortcuts</p>
          <h2 id="quick-links-title">Quick actions</h2>
        </div>
      </div>
      <div class="admin-quick-link-grid">
        <?php foreach ($quickLinks as [$url, $title, $description]): ?>
          <a href="<?= BASE_URL ?>admin/<?= e($url) ?>">
            <strong><?= e($title) ?></strong>
            <span><?= e($description) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</main>

<?php include __DIR__ . '/../includes/staff-footer.php'; ?>
