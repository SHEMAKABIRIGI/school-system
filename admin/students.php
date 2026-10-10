<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$pageTitle = 'Students';
$perPage = 20;
$searchValue = $_GET['q'] ?? '';
$search = is_string($searchValue) ? trim($searchValue) : '';
$classId = filter_var($_GET['class_id'] ?? '', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$statusOptions = ['active', 'graduated', 'transferred', 'left'];
$statusValue = $_GET['status'] ?? '';
$statusFilter = is_string($statusValue) ? $statusValue : '';
$errors = [];
$successMessage = $_SESSION['admin_students_success'] ?? '';
unset($_SESSION['admin_students_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? null;
    $studentId = filter_var($_POST['student_id'] ?? '', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $newStatus = $_POST['status'] ?? '';

    if (!is_string($token) || !csrf_check($token)) {
        $errors[] = 'Your form expired. Please try again.';
    } elseif ($studentId === false || !is_string($newStatus) || !in_array($newStatus, $statusOptions, true)) {
        $errors[] = 'The student status update is invalid.';
    } else {
        try {
            $stmt = db()->prepare('UPDATE students SET status = :status WHERE id = :id');
            $stmt->execute([':status' => $newStatus, ':id' => $studentId]);
            if ($stmt->rowCount() === 0) {
                $exists = db()->prepare('SELECT 1 FROM students WHERE id = :id');
                $exists->execute([':id' => $studentId]);
                if (!$exists->fetchColumn()) {
                    $errors[] = 'That student could not be found.';
                } else {
                    $_SESSION['admin_students_success'] = 'Student status is unchanged.';
                }
            } else {
                $_SESSION['admin_students_success'] = 'Student status updated.';
            }

            if (!$errors) {
                redirect(BASE_URL . 'admin/students.php');
            }
        } catch (PDOException $ex) {
            error_log($ex->getMessage());
            $errors[] = 'Could not update the student status. Please try again.';
        }
    }
}

$pageValue = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$page = $pageValue === false ? 1 : $pageValue;
$classId = $classId === false ? null : $classId;
if (!in_array($statusFilter, $statusOptions, true)) {
    $statusFilter = '';
}

$students = [];
$classes = [];
$totalPages = 0;
$totalStudents = 0;
$loadError = false;
$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(s.admission_no LIKE :search_admission OR s.first_name LIKE :search_first OR s.last_name LIKE :search_last)';
    $like = '%' . $search . '%';
    $params[':search_admission'] = $like;
    $params[':search_first'] = $like;
    $params[':search_last'] = $like;
}
if ($classId !== null) {
    $where[] = 's.class_id = :class_id';
    $params[':class_id'] = $classId;
}
if ($statusFilter !== '') {
    $where[] = 's.status = :status';
    $params[':status'] = $statusFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

try {
    $classes = db()->query('SELECT id, name FROM classes ORDER BY name')->fetchAll();
    $countStmt = db()->prepare('SELECT COUNT(*) FROM students s' . $whereSql);
    $countStmt->execute($params);
    $totalStudents = (int)$countStmt->fetchColumn();
    $totalPages = (int)ceil($totalStudents / $perPage);
    if ($totalPages > 0 && $page > $totalPages) {
        $page = $totalPages;
    }

    $stmt = db()->prepare(
        'SELECT s.id, s.admission_no, s.first_name, s.last_name, s.gender, s.status,
                c.name AS class_name
           FROM students s
           JOIN classes c ON c.id = s.class_id'
        . $whereSql .
        ' ORDER BY s.last_name, s.first_name, s.id
          LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $students = $stmt->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $loadError = true;
}

$queryParams = [];
if ($search !== '') {
    $queryParams['q'] = $search;
}
if ($classId !== null) {
    $queryParams['class_id'] = $classId;
}
if ($statusFilter !== '') {
    $queryParams['status'] = $statusFilter;
}
$pageUrl = static function (int $targetPage) use ($queryParams): string {
    return BASE_URL . 'admin/students.php?' . http_build_query($queryParams + ['page' => $targetPage]);
};

include __DIR__ . '/../includes/staff-header.php';
?>

<main class="admin-page">
  <div class="admin-page-heading">
    <div>
      <p class="admin-eyebrow">School records</p>
      <h1>Students</h1>
      <p>Search, review, and maintain student enrolment records.</p>
    </div>
    <a class="admin-primary-link" href="<?= BASE_URL ?>admin/student-form.php">Add student <span aria-hidden="true">+</span></a>
  </div>

  <?php if ($successMessage !== ''): ?>
    <div class="admin-alert admin-alert-success" role="status"><?= e($successMessage) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="admin-alert admin-alert-error" role="alert">
      <?php foreach ($errors as $error): ?><p><?= e($error) ?></p><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <section class="admin-panel" aria-labelledby="student-search-title">
    <h2 id="student-search-title">Find students</h2>
    <form class="admin-filter-form" method="get" action="<?= BASE_URL ?>admin/students.php">
      <div class="admin-filter-field">
        <label for="student-search">Name or admission number</label>
        <input type="search" id="student-search" name="q" value="<?= e($search) ?>" maxlength="100" placeholder="Search students">
      </div>
      <div class="admin-filter-field">
        <label for="student-class">Class</label>
        <select id="student-class" name="class_id">
          <option value="">All classes</option>
          <?php foreach ($classes as $class): ?>
            <option value="<?= (int)$class['id'] ?>"<?= $classId === (int)$class['id'] ? ' selected' : '' ?>><?= e($class['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-filter-field">
        <label for="student-status">Status</label>
        <select id="student-status" name="status">
          <option value="">All statuses</option>
          <?php foreach ($statusOptions as $status): ?>
            <option value="<?= e($status) ?>"<?= $statusFilter === $status ? ' selected' : '' ?>><?= e(ucfirst($status)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-filter-actions">
        <button class="admin-primary-link" type="submit">Apply filters</button>
        <a class="admin-secondary-link" href="<?= BASE_URL ?>admin/students.php">Clear</a>
      </div>
    </form>
  </section>

  <section class="admin-panel admin-records" aria-labelledby="student-records-title">
    <div class="admin-panel-heading">
      <h2 id="student-records-title">Student records</h2>
      <?php if (!$loadError): ?><p><?= $totalStudents ?> <?= $totalStudents === 1 ? 'student' : 'students' ?></p><?php endif; ?>
    </div>
    <?php if ($loadError): ?>
      <div class="admin-alert admin-alert-error" role="alert">Student records could not be loaded. Please try again.</div>
    <?php elseif (!$students): ?>
      <div class="admin-empty-state">
        <h3>No students found</h3>
        <p>Adjust your filters or add a student record.</p>
      </div>
    <?php else: ?>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th scope="col">Admission no.</th>
              <th scope="col">Student</th>
              <th scope="col">Class</th>
              <th scope="col">Gender</th>
              <th scope="col">Status</th>
              <th scope="col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($students as $student): ?>
              <tr>
                <td data-label="Admission no."><?= e($student['admission_no']) ?></td>
                <td data-label="Student"><?= e($student['first_name'] . ' ' . $student['last_name']) ?></td>
                <td data-label="Class"><?= e($student['class_name']) ?></td>
                <td data-label="Gender"><?= e(ucfirst($student['gender'])) ?></td>
                <td data-label="Status"><span class="admin-status admin-status-<?= e($student['status']) ?>"><?= e(ucfirst($student['status'])) ?></span></td>
                <td data-label="Actions">
                  <div class="admin-row-actions">
                    <a class="admin-secondary-link admin-compact-link" href="<?= BASE_URL ?>admin/student-form.php?id=<?= (int)$student['id'] ?>&amp;view=1">View</a>
                    <a class="admin-secondary-link admin-compact-link" href="<?= BASE_URL ?>admin/student-form.php?id=<?= (int)$student['id'] ?>">Edit</a>
                    <form method="post" action="<?= BASE_URL ?>admin/students.php" class="admin-status-form" onsubmit="return confirm('Change this student’s status?');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
                      <label class="visually-hidden" for="status-<?= (int)$student['id'] ?>">Status for <?= e($student['first_name'] . ' ' . $student['last_name']) ?></label>
                      <select id="status-<?= (int)$student['id'] ?>" name="status" aria-label="Set student status">
                        <?php foreach ($statusOptions as $status): ?>
                          <option value="<?= e($status) ?>"<?= $student['status'] === $status ? ' selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button class="admin-secondary-link admin-compact-link" type="submit">Save</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
        <nav class="admin-pagination" aria-label="Student list pages">
          <span>Page <?= $page ?> of <?= $totalPages ?></span>
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
