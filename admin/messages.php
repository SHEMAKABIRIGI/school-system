<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$pageTitle = 'Contact messages';
$perPage = 15;
$statusOptions = ['all', 'unread', 'read'];
$statusValue = $_GET['status'] ?? 'unread';
$statusFilter = is_string($statusValue) && in_array($statusValue, $statusOptions, true)
    ? $statusValue
    : 'unread';
$searchValue = $_GET['q'] ?? '';
$search = is_string($searchValue) ? trim($searchValue) : '';
$errors = [];
$successMessage = '';
$pageValue = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$page = $pageValue === false ? 1 : $pageValue;
$messages = [];
$totalMessages = 0;
$totalPages = 0;
$unreadMessages = 0;
$loadError = false;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $successMessage = $_SESSION['admin_messages_success'] ?? '';
    unset($_SESSION['admin_messages_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? null;
    $messageId = filter_var($_POST['message_id'] ?? '', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $newReadValue = $_POST['is_read'] ?? '';

    if (!is_string($token) || !csrf_check($token)) {
        $errors[] = 'Your form expired. Please reload the page and try again.';
    } elseif ($messageId === false || !is_string($newReadValue) || !in_array($newReadValue, ['0', '1'], true)) {
        $errors[] = 'The message status update is invalid.';
    } else {
        try {
            $stmt = db()->prepare('UPDATE messages SET is_read = :is_read WHERE id = :id');
            $stmt->execute([
                ':is_read' => (int)$newReadValue,
                ':id' => $messageId,
            ]);
            if ($stmt->rowCount() === 0) {
                $exists = db()->prepare('SELECT is_read FROM messages WHERE id = :id');
                $exists->execute([':id' => $messageId]);
                $currentReadValue = $exists->fetchColumn();
                if ($currentReadValue === false) {
                    $errors[] = 'That message could not be found.';
                } else {
                    $_SESSION['admin_messages_success'] = 'Message status is unchanged.';
                }
            } else {
                $_SESSION['admin_messages_success'] = (int)$newReadValue === 1
                    ? 'Message marked as read.'
                    : 'Message marked as unread.';
            }
            if (!$errors) {
                $redirectParams = ['status' => $statusFilter];
                if ($search !== '') {
                    $redirectParams['q'] = $search;
                }
                redirect(BASE_URL . 'admin/messages.php?' . http_build_query($redirectParams));
            }
        } catch (PDOException $ex) {
            error_log($ex->getMessage());
            $errors[] = 'Could not update the message status. Please try again.';
        }
    }
}

$where = [];
$params = [];
if ($statusFilter === 'unread') {
    $where[] = 'is_read = 0';
} elseif ($statusFilter === 'read') {
    $where[] = 'is_read = 1';
}
if ($search !== '') {
    $where[] = '(name LIKE :name OR email LIKE :email OR subject LIKE :subject OR message LIKE :message)';
    $like = '%' . $search . '%';
    $params = [
        ':name' => $like,
        ':email' => $like,
        ':subject' => $like,
        ':message' => $like,
    ];
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

try {
    $unreadMessages = (int)db_value('SELECT COUNT(*) FROM messages WHERE is_read = 0');
    $countStmt = db()->prepare('SELECT COUNT(*) FROM messages' . $whereSql);
    $countStmt->execute($params);
    $totalMessages = (int)$countStmt->fetchColumn();
    $totalPages = (int)ceil($totalMessages / $perPage);
    if ($totalPages > 0 && $page > $totalPages) {
        $page = $totalPages;
    }

    $stmt = db()->prepare(
        'SELECT id, name, email, phone, subject, message, is_read, created_at
           FROM messages'
        . $whereSql .
        ' ORDER BY is_read ASC, created_at DESC, id DESC
          LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $messages = $stmt->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $loadError = true;
}

$pageUrl = static function (int $targetPage) use ($search, $statusFilter): string {
    $params = ['page' => $targetPage, 'status' => $statusFilter];
    if ($search !== '') {
        $params['q'] = $search;
    }
    return BASE_URL . 'admin/messages.php?' . http_build_query($params);
};

include __DIR__ . '/../includes/staff-header.php';
?>

<main class="admin-page">
  <div class="admin-page-heading">
    <div>
      <p class="admin-eyebrow">School inbox</p>
      <h1>Contact messages</h1>
      <p>Review enquiries submitted through the public contact form.</p>
    </div>
    <span class="admin-status admin-status-pending"><?= $unreadMessages ?> unread</span>
  </div>

  <?php if ($successMessage !== ''): ?>
    <div class="admin-alert admin-alert-success" role="status"><?= e($successMessage) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="admin-alert admin-alert-error" role="alert">
      <?php foreach ($errors as $error): ?><p><?= e($error) ?></p><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <section class="admin-panel" aria-labelledby="message-search-title">
    <h2 id="message-search-title">Find messages</h2>
    <form class="admin-filter-form" method="get" action="<?= BASE_URL ?>admin/messages.php">
      <div class="admin-filter-field">
        <label for="message-search">Search sender, email, subject, or message</label>
        <input type="search" id="message-search" name="q" value="<?= e($search) ?>" maxlength="100" placeholder="Search messages">
      </div>
      <div class="admin-filter-field">
        <label for="message-status">Status</label>
        <select id="message-status" name="status">
          <?php foreach ($statusOptions as $option): ?>
            <option value="<?= e($option) ?>"<?= $statusFilter === $option ? ' selected' : '' ?>>
              <?= e(ucfirst($option)) ?><?= $option === 'unread' ? ' (' . $unreadMessages . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-filter-actions">
        <button class="admin-primary-link" type="submit">Apply filters</button>
        <a class="admin-secondary-link" href="<?= BASE_URL ?>admin/messages.php">Clear</a>
      </div>
    </form>
  </section>

  <section aria-labelledby="message-list-title">
    <div class="admin-panel-heading">
      <h2 id="message-list-title"><?= e(ucfirst($statusFilter)) ?> messages</h2>
      <?php if (!$loadError): ?><p><?= $totalMessages ?> <?= $totalMessages === 1 ? 'message' : 'messages' ?></p><?php endif; ?>
    </div>
    <?php if ($loadError): ?>
      <div class="admin-alert admin-alert-error" role="alert">Messages could not be loaded. Please try again later.</div>
    <?php elseif (!$messages): ?>
      <div class="admin-panel admin-empty-state">
        <h3>No <?= e($statusFilter === 'all' ? '' : $statusFilter . ' ') ?>messages found</h3>
        <p>Choose another status or try a different search.</p>
      </div>
    <?php else: ?>
      <div class="registration-list">
        <?php foreach ($messages as $message): ?>
          <article class="admin-panel registration-card">
            <header class="registration-card-header">
              <div>
                <p class="admin-eyebrow">
                  Message #<?= (int)$message['id'] ?> ·
                  <?= e(date('F j, Y g:i a', strtotime($message['created_at']))) ?>
                </p>
                <h3><?= e($message['subject']) ?></h3>
              </div>
              <span class="admin-status admin-status-<?= (int)$message['is_read'] === 1 ? 'accepted' : 'pending' ?>">
                <?= (int)$message['is_read'] === 1 ? 'Read' : 'Unread' ?>
              </span>
            </header>

            <dl class="registration-details">
              <div><dt>From</dt><dd><?= e($message['name']) ?></dd></div>
              <div><dt>Email</dt><dd><a href="mailto:<?= e($message['email']) ?>"><?= e($message['email']) ?></a></dd></div>
              <div><dt>Phone</dt><dd>
                <?php if ($message['phone']): ?>
                  <a href="tel:<?= e($message['phone']) ?>"><?= e($message['phone']) ?></a>
                <?php else: ?>
                  Not provided
                <?php endif; ?>
              </dd></div>
              <div class="registration-note"><dt>Message</dt><dd><?= e($message['message']) ?></dd></div>
            </dl>

            <form class="admin-form-actions" method="post" action="<?= BASE_URL ?>admin/messages.php?<?= e(http_build_query(array_filter([
                'status' => $statusFilter,
                'q' => $search !== '' ? $search : null,
                'page' => $page,
            ], static fn($value): bool => $value !== null))) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="message_id" value="<?= (int)$message['id'] ?>">
              <input type="hidden" name="is_read" value="<?= (int)$message['is_read'] === 1 ? '0' : '1' ?>">
              <button class="<?= (int)$message['is_read'] === 1 ? 'admin-secondary-link' : 'admin-primary-link' ?>" type="submit">
                Mark as <?= (int)$message['is_read'] === 1 ? 'unread' : 'read' ?>
              </button>
            </form>
          </article>
        <?php endforeach; ?>
      </div>

      <?php if ($totalPages > 1): ?>
        <nav class="admin-pagination" aria-label="Message list pages">
          <p>Page <?= $page ?> of <?= $totalPages ?></p>
          <div>
            <?php if ($page > 1): ?><a class="admin-secondary-link" href="<?= e($pageUrl($page - 1)) ?>">Previous</a><?php endif; ?>
            <?php if ($page < $totalPages): ?><a class="admin-secondary-link" href="<?= e($pageUrl($page + 1)) ?>">Next</a><?php endif; ?>
          </div>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</main>

<?php include __DIR__ . '/../includes/staff-footer.php'; ?>
