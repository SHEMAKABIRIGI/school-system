<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$pageTitle = 'Teachers';
$perPage = 20;
$searchValue = $_GET['q'] ?? '';
$search = is_string($searchValue) ? trim($searchValue) : '';
$pageValue = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$page = $pageValue === false ? 1 : $pageValue;
$successMessage = $_SESSION['admin_teachers_success'] ?? '';
unset($_SESSION['admin_teachers_success']);

$teachers = [];
$totalTeachers = 0;
$totalPages = 0;
$loadError = false;
$whereSql = '';
$params = [];
if ($search !== '') {
    $whereSql = ' WHERE u.full_name LIKE :full_name
                    OR u.username LIKE :username
                    OR u.email LIKE :email
                    OR t.staff_no LIKE :staff_no';
    $like = '%' . $search . '%';
    $params = [
        ':full_name' => $like,
        ':username' => $like,
        ':email' => $like,
        ':staff_no' => $like,
    ];
}

try {
    $countStmt = db()->prepare(
        'SELECT COUNT(*)
           FROM teachers t
           JOIN users u ON u.id = t.user_id
        ' . $whereSql
    );
    $countStmt->execute($params);
    $totalTeachers = (int)$countStmt->fetchColumn();
    $totalPages = (int)ceil($totalTeachers / $perPage);
    if ($totalPages > 0 && $page > $totalPages) {
        $page = $totalPages;
    }

    $stmt = db()->prepare(
        'SELECT t.id, t.staff_no, t.gender, t.qualification, t.hire_date,
                u.full_name, u.username, u.email, u.phone, u.is_active
           FROM teachers t
           JOIN users u ON u.id = t.user_id'
        . $whereSql .
        ' ORDER BY u.full_name, t.id
          LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $teachers = $stmt->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $loadError = true;
}

$pageUrl = static function (int $targetPage) use ($search): string {
    $params = ['page' => $targetPage];
    if ($search !== '') {
        $params['q'] = $search;
    }
    return BASE_URL . 'admin/teachers.php?' . http_build_query($params);
};

include __DIR__ . '/../includes/staff-header.php';
?>

<main class="admin-page">
  <div class="admin-page-heading">
    <div>
      <p class="admin-eyebrow">Staff records</p>
      <h1>Teachers</h1>
      <p>Manage teacher accounts and staff details.</p>
    </div>
    <a class="admin-primary-link" href="<?= BASE_URL ?>admin/teacher-form.php">Add teacher <span aria-hidden="true">+</span></a>
  </div>

  <?php if ($successMessage !== ''): ?>
    <div class="admin-alert admin-alert-success" role="status"><?= e($successMessage) ?></div>
  <?php endif; ?>

  <section class="admin-panel" aria-labelledby="teacher-search-title">
    <h2 id="teacher-search-title">Find teachers</h2>
    <form class="admin-filter-form" method="get" action="<?= BASE_URL ?>admin/teachers.php">
      <div class="admin-filter-field">
        <label for="teacher-search">Name, username, email, or staff number</label>
        <input type="search" id="teacher-search" name="q" value="<?= e($search) ?>" maxlength="100" placeholder="Search teachers">
      </div>
      <div class="admin-filter-actions">
        <button class="admin-primary-link" type="submit">Search</button>
        <a class="admin-secondary-link" href="<?= BASE_URL ?>admin/teachers.php">Clear</a>
      </div>
    </form>
  </section>

  <section class="admin-panel admin-records" aria-labelledby="teacher-records-title">
    <div class="admin-panel-heading">
      <h2 id="teacher-records-title">Teacher records</h2>
      <?php if (!$loadError): ?><p><?= $totalTeachers ?> <?= $totalTeachers === 1 ? 'teacher' : 'teachers' ?></p><?php endif; ?>
    </div>
    <?php if ($loadError): ?>
      <div class="admin-alert admin-alert-error" role="alert">Teacher records could not be loaded. Please try again.</div>
    <?php elseif (!$teachers): ?>
      <div class="admin-empty-state">
        <h3>No teachers found</h3>
        <p>Adjust your search or add a teacher record.</p>
      </div>
    <?php else: ?>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th scope="col">Staff no.</th>
              <th scope="col">Teacher</th>
              <th scope="col">Email</th>
              <th scope="col">Phone</th>
              <th scope="col">Account</th>
              <th scope="col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($teachers as $teacher): ?>
              <tr>
                <td data-label="Staff no."><?= e($teacher['staff_no']) ?></td>
                <td data-label="Teacher">
                  <strong><?= e($teacher['full_name']) ?></strong><br>
                  <span>@<?= e($teacher['username']) ?></span>
                </td>
                <td data-label="Email"><?= e($teacher['email']) ?></td>
                <td data-label="Phone"><?= e($teacher['phone'] ?: 'Not provided') ?></td>
                <td data-label="Account">
                  <span class="admin-status admin-status-<?= (int)$teacher['is_active'] === 1 ? 'active' : 'left' ?>">
                    <?= (int)$teacher['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                  </span>
                </td>
                <td data-label="Actions" class="admin-row-actions">
                  <a class="admin-secondary-link admin-compact-link" href="<?= BASE_URL ?>admin/teacher-form.php?id=<?= (int)$teacher['id'] ?>">Edit details / reset password</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($totalPages > 1): ?>
        <nav class="admin-pagination" aria-label="Teacher list pages">
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
</main>

<?php include __DIR__ . '/../includes/staff-footer.php'; ?>
