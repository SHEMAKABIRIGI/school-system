<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$pageTitle = 'Users and roles';
$perPage = 20;
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$searchValue = $_GET['q'] ?? '';
$search = is_string($searchValue) ? trim($searchValue) : '';
$roleValue = $_GET['role'] ?? '';
$roleFilter = is_string($roleValue) && in_array($roleValue, ['admin', 'teacher', 'head'], true)
    ? $roleValue
    : '';
$pageValue = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$page = $pageValue === false ? 1 : $pageValue;
$successMessage = $_SESSION['admin_users_success'] ?? '';
unset($_SESSION['admin_users_success']);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $actionValue = $_POST['action'] ?? '';
    $action = is_string($actionValue) ? $actionValue : '';
    $id = filter_var($_POST['user_id'] ?? '', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $token = $_POST['csrf'] ?? null;

    if ($id === false) {
        $errors[] = 'Choose a valid user account.';
    } elseif ($id === $currentUserId) {
        $errors[] = 'You cannot change your own account here.';
    }
    if (!is_string($token) || !csrf_check($token)) {
        $errors[] = 'Your form expired. Please try again.';
    }
    if (!in_array($action, ['change_role', 'change_status'], true)) {
        $errors[] = 'The requested user operation is invalid.';
    }

    $newRole = $_POST['role'] ?? '';
    $newStatus = $_POST['is_active'] ?? '';
    if ($action === 'change_role' && (!is_string($newRole) || !in_array($newRole, ['admin', 'head'], true))) {
        $errors[] = 'Choose a valid role. Teacher accounts are managed from the Teachers page.';
    }
    if ($action === 'change_status' && (!is_string($newStatus) || !in_array($newStatus, ['0', '1'], true))) {
        $errors[] = 'Choose a valid account status.';
    }

    if (!$errors) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $userStmt = $pdo->prepare(
                'SELECT role, is_active FROM users WHERE id = :id FOR UPDATE'
            );
            $userStmt->execute([':id' => $id]);
            $targetUser = $userStmt->fetch();
            if (!$targetUser) {
                throw new DomainException('That user account could not be found.');
            }

            if ($action === 'change_role') {
                if ($targetUser['role'] === 'teacher') {
                    throw new DomainException('Teacher roles are managed from the Teachers page.');
                }
                if ($targetUser['role'] === $newRole) {
                    throw new DomainException('That user already has the selected role.');
                }
                if ($targetUser['role'] === 'admin' && (int)$targetUser['is_active'] === 1 && $newRole !== 'admin') {
                    $otherAdmins = $pdo->prepare(
                        "SELECT id FROM users
                          WHERE role = 'admin' AND is_active = 1 AND id <> :id
                          FOR UPDATE"
                    );
                    $otherAdmins->execute([':id' => $id]);
                    if ($otherAdmins->fetchColumn() === false) {
                        throw new DomainException('The last active administrator cannot be changed to another role.');
                    }
                }

                $updateStmt = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
                $updateStmt->execute([':role' => $newRole, ':id' => $id]);
                $successMessage = 'User role updated.';
            } else {
                $active = (int)$newStatus;
                if ((int)$targetUser['is_active'] === $active) {
                    throw new DomainException('That user account already has the selected status.');
                }
                if (
                    $active === 0
                    && $targetUser['role'] === 'admin'
                    && (int)$targetUser['is_active'] === 1
                ) {
                    $otherAdmins = $pdo->prepare(
                        "SELECT id FROM users
                          WHERE role = 'admin' AND is_active = 1 AND id <> :id
                          FOR UPDATE"
                    );
                    $otherAdmins->execute([':id' => $id]);
                    if ($otherAdmins->fetchColumn() === false) {
                        throw new DomainException('The last active administrator cannot be deactivated.');
                    }
                }

                $updateStmt = $pdo->prepare('UPDATE users SET is_active = :is_active WHERE id = :id');
                $updateStmt->execute([':is_active' => $active, ':id' => $id]);
                $successMessage = $active === 1 ? 'User account activated.' : 'User account deactivated.';
            }

            $pdo->commit();
            $_SESSION['admin_users_success'] = $successMessage;
            redirect(BASE_URL . 'admin/users.php');
        } catch (DomainException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $ex->getMessage();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($ex->getMessage());
            $errors[] = 'The user account could not be updated. Please try again.';
        }
    }
}

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(full_name LIKE :full_name OR username LIKE :username OR email LIKE :email)';
    $like = '%' . $search . '%';
    $params[':full_name'] = $like;
    $params[':username'] = $like;
    $params[':email'] = $like;
}
if ($roleFilter !== '') {
    $where[] = 'role = :role';
    $params[':role'] = $roleFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$users = [];
$totalUsers = 0;
$totalPages = 0;
$loadError = false;

try {
    $countStmt = db()->prepare('SELECT COUNT(*) FROM users' . $whereSql);
    $countStmt->execute($params);
    $totalUsers = (int)$countStmt->fetchColumn();
    $totalPages = (int)ceil($totalUsers / $perPage);
    if ($totalPages > 0 && $page > $totalPages) {
        $page = $totalPages;
    }

    $stmt = db()->prepare(
        'SELECT id, full_name, username, email, role, is_active, created_at, last_login
           FROM users'
        . $whereSql .
        ' ORDER BY full_name, id
          LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $users = $stmt->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $loadError = true;
}

$pageUrl = static function (int $targetPage) use ($search, $roleFilter): string {
    $params = ['page' => $targetPage];
    if ($search !== '') {
        $params['q'] = $search;
    }
    if ($roleFilter !== '') {
        $params['role'] = $roleFilter;
    }
    return BASE_URL . 'admin/users.php?' . http_build_query($params);
};

include __DIR__ . '/../includes/staff-header.php';
?>

<main class="admin-page">
  <div class="admin-page-heading">
    <div>
      <p class="admin-eyebrow">Account administration</p>
      <h1>Users and roles</h1>
      <p>Search accounts, manage administrator and head-teacher roles, and control account access.</p>
    </div>
  </div>

  <?php if ($successMessage !== ''): ?>
    <div class="admin-alert admin-alert-success" role="status"><?= e($successMessage) ?></div>
  <?php endif; ?>
  <?php foreach ($errors as $error): ?>
    <div class="admin-alert admin-alert-error" role="alert"><?= e($error) ?></div>
  <?php endforeach; ?>

  <section class="admin-panel" aria-labelledby="user-search-title">
    <h2 id="user-search-title">Find accounts</h2>
    <form class="admin-filter-form" method="get" action="<?= BASE_URL ?>admin/users.php">
      <div class="admin-filter-field">
        <label for="user-search">Name, username, or email</label>
        <input type="search" id="user-search" name="q" value="<?= e($search) ?>" maxlength="100" placeholder="Search accounts">
      </div>
      <div class="admin-filter-field">
        <label for="user-role-filter">Role</label>
        <select id="user-role-filter" name="role">
          <option value="">All roles</option>
          <?php foreach (['admin' => 'Administrator', 'teacher' => 'Teacher', 'head' => 'Head teacher'] as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= $roleFilter === $value ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-filter-actions">
        <button class="admin-primary-link" type="submit">Search</button>
        <a class="admin-secondary-link" href="<?= BASE_URL ?>admin/users.php">Clear</a>
      </div>
    </form>
  </section>

  <section class="admin-panel admin-records" aria-labelledby="user-records-title">
    <div class="admin-panel-heading">
      <h2 id="user-records-title">User accounts</h2>
      <?php if (!$loadError): ?><p><?= $totalUsers ?> <?= $totalUsers === 1 ? 'account' : 'accounts' ?></p><?php endif; ?>
    </div>
    <?php if ($loadError): ?>
      <div class="admin-alert admin-alert-error" role="alert">User accounts could not be loaded. Please try again.</div>
    <?php elseif (!$users): ?>
      <div class="admin-empty-state">
        <h3>No accounts found</h3>
        <p>Adjust your search or role filter.</p>
      </div>
    <?php else: ?>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th scope="col">User</th>
              <th scope="col">Role</th>
              <th scope="col">Account</th>
              <th scope="col">Last login</th>
              <th scope="col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $user): ?>
              <tr>
                <td data-label="User">
                  <strong><?= e($user['full_name']) ?></strong><br>
                  <span>@<?= e($user['username']) ?></span><br>
                  <span><?= e($user['email']) ?></span>
                  <?php if ((int)$user['id'] === $currentUserId): ?><br><span>(You)</span><?php endif; ?>
                </td>
                <td data-label="Role">
                  <?php if ($user['role'] === 'teacher'): ?>
                    <span>Teacher</span>
                  <?php else: ?>
                    <form method="post" action="<?= BASE_URL ?>admin/users.php" class="admin-status-form">
                      <?= csrf_field() ?>
                      <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                      <input type="hidden" name="action" value="change_role">
                      <select name="role" aria-label="Role for <?= e($user['full_name']) ?>"<?= (int)$user['id'] === $currentUserId ? ' disabled' : '' ?>>
                        <option value="admin"<?= $user['role'] === 'admin' ? ' selected' : '' ?>>Administrator</option>
                        <option value="head"<?= $user['role'] === 'head' ? ' selected' : '' ?>>Head teacher</option>
                      </select>
                      <?php if ((int)$user['id'] !== $currentUserId): ?>
                        <button class="admin-secondary-link admin-compact-link" type="submit">Save role</button>
                      <?php endif; ?>
                    </form>
                  <?php endif; ?>
                </td>
                <td data-label="Account">
                  <span class="admin-status admin-status-<?= (int)$user['is_active'] === 1 ? 'active' : 'left' ?>">
                    <?= (int)$user['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                  </span>
                </td>
                <td data-label="Last login"><?= e($user['last_login'] ?: 'Never') ?></td>
                <td data-label="Actions" class="admin-row-actions">
                  <?php if ((int)$user['id'] === $currentUserId): ?>
                    <span>Own account protected</span>
                  <?php else: ?>
                    <form method="post" action="<?= BASE_URL ?>admin/users.php" class="admin-status-form">
                      <?= csrf_field() ?>
                      <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                      <input type="hidden" name="action" value="change_status">
                      <input type="hidden" name="is_active" value="<?= (int)$user['is_active'] === 1 ? '0' : '1' ?>">
                      <button class="admin-secondary-link admin-compact-link" type="submit">
                        <?= (int)$user['is_active'] === 1 ? 'Deactivate account' : 'Activate account' ?>
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($totalPages > 1): ?>
        <nav class="admin-pagination" aria-label="User account pages">
          <p>Page <?= $page ?> of <?= $totalPages ?></p>
          <div>
            <?php if ($page > 1): ?>
              <a class="admin-secondary-link" href="<?= e($pageUrl($page - 1)) ?>">Previous</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
              <a class="admin-secondary-link" href="<?= e($pageUrl($page + 1)) ?>">Next</a>
            <?php endif; ?>
          </div>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>
  <p class="admin-help-text">Teacher accounts and their staff records are managed on the Teachers page. Your own account cannot be changed here.</p>
</main>

<?php include __DIR__ . '/../includes/staff-footer.php'; ?>
