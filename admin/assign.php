<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$pageTitle = 'Teacher assignments';
$errors = [];
$successMessage = '';
$yearOptions = [];
$classes = [];
$subjects = [];
$teachers = [];
$assignments = [];
$loadError = false;
$yearFilter = null;
$selectedYearProvided = array_key_exists('year_id', $_GET);
$selectedYearInput = $_GET['year_id'] ?? '';
$selectedYear = null;
$allYearsSelected = false;
if ($selectedYearProvided && is_string($selectedYearInput) && $selectedYearInput === '0') {
    $allYearsSelected = true;
} elseif ($selectedYearProvided) {
    $selectedYear = filter_var($selectedYearInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if ($selectedYear === false) {
        $selectedYear = null;
        $errors[] = 'Choose a valid academic year filter.';
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $successMessage = $_SESSION['admin_assignments_success'] ?? '';
    unset($_SESSION['admin_assignments_success']);
}

try {
    $yearOptions = db()->query(
        'SELECT id, name, is_current FROM academic_years ORDER BY start_date DESC, name DESC'
    )->fetchAll();
    $classes = db()->query('SELECT id, name, level FROM classes ORDER BY level, name')->fetchAll();
    $subjects = db()->query('SELECT id, name, code FROM subjects ORDER BY name')->fetchAll();
    $teachers = db()->query(
        "SELECT t.id, t.staff_no, u.full_name
           FROM teachers t
           JOIN users u ON u.id = t.user_id
          WHERE u.role = 'teacher' AND u.is_active = 1
          ORDER BY u.full_name, t.staff_no"
    )->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $loadError = true;
}

$yearIds = array_map(static fn(array $year): int => (int)$year['id'], $yearOptions);
if ($selectedYear !== null && in_array($selectedYear, $yearIds, true)) {
    $yearFilter = $selectedYear;
} elseif ($selectedYear !== null) {
    $errors[] = 'The selected academic year is not available.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$loadError) {
    $token = $_POST['csrf'] ?? null;
    $action = $_POST['action'] ?? '';
    $assignmentId = filter_var($_POST['assignment_id'] ?? '', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if (!is_string($token) || !csrf_check($token)) {
        $errors[] = 'Your form expired. Please try again.';
    } elseif (!is_string($action) || !in_array($action, ['add', 'update', 'delete'], true)) {
        $errors[] = 'The requested assignment operation is invalid.';
    } elseif (in_array($action, ['update', 'delete'], true) && $assignmentId === false) {
        $errors[] = 'Choose a valid teacher assignment.';
    }

    $postedYear = filter_var($_POST['academic_year_id'] ?? '', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if (in_array($action, ['add', 'update'], true)) {
        $teacherId = filter_var($_POST['teacher_id'] ?? '', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($teacherId === false) {
            $errors[] = 'Choose an active teacher.';
        }
        if ($action === 'add') {
            $classId = filter_var($_POST['class_id'] ?? '', FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            $subjectId = filter_var($_POST['subject_id'] ?? '', FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($classId === false) {
                $errors[] = 'Choose a valid class.';
            }
            if ($subjectId === false) {
                $errors[] = 'Choose a valid subject.';
            }
        }
        if ($postedYear === false || !in_array($postedYear, $yearIds, true)) {
            $errors[] = 'Choose a valid academic year.';
        }
    }

    if (!$errors && !$loadError) {
        $pdo = db();
        try {
            if ($action === 'delete') {
                $stmt = $pdo->prepare('DELETE FROM teacher_assignments WHERE id = :id');
                $stmt->execute([':id' => $assignmentId]);
                if ($stmt->rowCount() !== 1) {
                    throw new DomainException('That teacher assignment could not be found.');
                }
                $_SESSION['admin_assignments_success'] = 'Teacher assignment removed.';
            } else {
                $pdo->beginTransaction();

                $teacherCheck = $pdo->prepare(
                    "SELECT t.id
                       FROM teachers t
                       JOIN users u ON u.id = t.user_id
                      WHERE t.id = :id AND u.role = :role AND u.is_active = 1
                      FOR UPDATE"
                );
                $teacherCheck->execute([':id' => $teacherId, ':role' => 'teacher']);
                if (!$teacherCheck->fetchColumn()) {
                    throw new DomainException('The selected teacher account is unavailable or inactive. Choose an active teacher.');
                }

                if ($action === 'add') {
                    $classCheck = $pdo->prepare('SELECT id FROM classes WHERE id = :id');
                    $classCheck->execute([':id' => $classId]);
                    if (!$classCheck->fetchColumn()) {
                        throw new DomainException('The selected class is no longer available.');
                    }
                    $subjectCheck = $pdo->prepare('SELECT id FROM subjects WHERE id = :id');
                    $subjectCheck->execute([':id' => $subjectId]);
                    if (!$subjectCheck->fetchColumn()) {
                        throw new DomainException('The selected subject is no longer available.');
                    }
                    $yearCheck = $pdo->prepare('SELECT id FROM academic_years WHERE id = :id');
                    $yearCheck->execute([':id' => $postedYear]);
                    if (!$yearCheck->fetchColumn()) {
                        throw new DomainException('The selected academic year is no longer available.');
                    }

                    $stmt = $pdo->prepare(
                        'INSERT INTO teacher_assignments (teacher_id, class_id, subject_id, academic_year_id)
                         VALUES (:teacher_id, :class_id, :subject_id, :academic_year_id)'
                    );
                    $stmt->execute([
                        ':teacher_id' => $teacherId,
                        ':class_id' => $classId,
                        ':subject_id' => $subjectId,
                        ':academic_year_id' => $postedYear,
                    ]);
                    $successMessage = 'Teacher assigned to the class and subject.';
                } else {
                    $yearCheck = $pdo->prepare('SELECT id FROM academic_years WHERE id = :id');
                    $yearCheck->execute([':id' => $postedYear]);
                    if (!$yearCheck->fetchColumn()) {
                        throw new DomainException('The selected academic year is no longer available.');
                    }
                    $stmt = $pdo->prepare(
                        'UPDATE teacher_assignments
                            SET teacher_id = :teacher_id
                          WHERE id = :id AND academic_year_id = :academic_year_id'
                    );
                    $stmt->execute([
                        ':teacher_id' => $teacherId,
                        ':id' => $assignmentId,
                        ':academic_year_id' => $postedYear,
                    ]);
                    if ($stmt->rowCount() === 0) {
                        $exists = $pdo->prepare(
                            'SELECT 1 FROM teacher_assignments WHERE id = :id AND academic_year_id = :academic_year_id'
                        );
                        $exists->execute([
                            ':id' => $assignmentId,
                            ':academic_year_id' => $postedYear,
                        ]);
                        if (!$exists->fetchColumn()) {
                            throw new DomainException('That teacher assignment could not be found for the selected academic year.');
                        }
                        $successMessage = 'The assignment already has that teacher.';
                    } else {
                        $successMessage = 'Teacher assignment updated.';
                    }
                }

                $pdo->commit();
                $_SESSION['admin_assignments_success'] = $successMessage;
            }
            redirect(BASE_URL . 'admin/assign.php' . ($postedYear !== false ? '?year_id=' . (int)$postedYear : ''));
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
            $errors[] = $ex->getCode() === '23000'
                ? 'A teacher is already assigned to that class and subject for this academic year.'
                : 'Could not save the teacher assignment. Please try again.';
        }
    }
}

if ($yearFilter === null && !$selectedYearProvided) {
    foreach ($yearOptions as $year) {
        if ((int)$year['is_current'] === 1) {
            $yearFilter = (int)$year['id'];
            break;
        }
    }
}

if (!$loadError) {
    try {
        $query = 'SELECT ta.id, ta.teacher_id, ta.class_id, ta.subject_id, ta.academic_year_id,
                         t.staff_no, u.full_name AS teacher_name, u.is_active AS teacher_active,
                         c.name AS class_name, s.name AS subject_name, s.code AS subject_code,
                         ay.name AS year_name
                    FROM teacher_assignments ta
                    JOIN teachers t ON t.id = ta.teacher_id
                    JOIN users u ON u.id = t.user_id
                    JOIN classes c ON c.id = ta.class_id
                    JOIN subjects s ON s.id = ta.subject_id
                    JOIN academic_years ay ON ay.id = ta.academic_year_id';
        if ($yearFilter !== null) {
            $query .= ' WHERE ta.academic_year_id = :year_id';
        }
        $query .= ' ORDER BY ay.start_date DESC, c.name, s.name, u.full_name';
        $stmt = db()->prepare($query);
        if ($yearFilter !== null) {
            $stmt->execute([':year_id' => $yearFilter]);
        } else {
            $stmt->execute();
        }
        $assignments = $stmt->fetchAll();
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        $loadError = true;
    }
}

$defaultYearId = $yearFilter;
include __DIR__ . '/../includes/staff-header.php';
?>

<main class="admin-page">
  <div class="admin-page-heading">
    <div>
      <p class="admin-eyebrow">Teaching setup</p>
      <h1>Assign teachers</h1>
      <p>Choose which teacher teaches each subject in each class and academic year.</p>
    </div>
    <a class="admin-secondary-link" href="<?= BASE_URL ?>admin/classes.php">Manage classes</a>
  </div>

  <?php if ($successMessage !== ''): ?>
    <div class="admin-alert admin-alert-success" role="status"><?= e($successMessage) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="admin-alert admin-alert-error" role="alert">
      <?php foreach ($errors as $error): ?><p><?= e($error) ?></p><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($loadError): ?>
    <div class="admin-alert admin-alert-error" role="alert">Assignment data could not be loaded. Please try again.</div>
  <?php elseif (!$yearOptions || !$classes || !$subjects || !$teachers): ?>
    <section class="admin-panel">
      <h2>Setup needed</h2>
      <p>Before assigning teachers, make sure the system has at least one academic year, class, subject, and active teacher account.</p>
      <div class="admin-form-actions">
        <a class="admin-secondary-link" href="<?= BASE_URL ?>admin/classes.php">Manage classes</a>
        <a class="admin-secondary-link" href="<?= BASE_URL ?>admin/teachers.php">Manage teachers</a>
      </div>
    </section>
  <?php else: ?>
    <section class="admin-panel admin-form-panel" aria-labelledby="new-assignment-title">
      <h2 id="new-assignment-title">Create assignment</h2>
      <form method="post" action="<?= BASE_URL ?>admin/assign.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="admin-form-grid">
          <div class="admin-form-field">
            <label for="assignment-year">Academic year <span aria-hidden="true">*</span></label>
            <select id="assignment-year" name="academic_year_id" required>
              <option value="">Choose an academic year</option>
              <?php foreach ($yearOptions as $year): ?>
                <option value="<?= (int)$year['id'] ?>"<?= $defaultYearId === (int)$year['id'] ? ' selected' : '' ?>>
                  <?= e($year['name']) ?><?= (int)$year['is_current'] === 1 ? ' (Current)' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="admin-form-field">
            <label for="assignment-class">Class <span aria-hidden="true">*</span></label>
            <select id="assignment-class" name="class_id" required>
              <option value="">Choose a class</option>
              <?php foreach ($classes as $class): ?>
                <option value="<?= (int)$class['id'] ?>"><?= e($class['name']) ?> (<?= e($class['level']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="admin-form-field">
            <label for="assignment-subject">Subject <span aria-hidden="true">*</span></label>
            <select id="assignment-subject" name="subject_id" required>
              <option value="">Choose a subject</option>
              <?php foreach ($subjects as $subject): ?>
                <option value="<?= (int)$subject['id'] ?>"><?= e($subject['name']) ?> (<?= e($subject['code']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="admin-form-field">
            <label for="assignment-teacher">Teacher <span aria-hidden="true">*</span></label>
            <select id="assignment-teacher" name="teacher_id" required>
              <option value="">Choose a teacher</option>
              <?php foreach ($teachers as $teacher): ?>
                <option value="<?= (int)$teacher['id'] ?>"><?= e($teacher['full_name']) ?> (<?= e($teacher['staff_no']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <p>Only one teacher can be assigned to a class and subject in the same academic year.</p>
        <div class="admin-form-actions">
          <button class="admin-primary-link" type="submit">Assign teacher</button>
        </div>
      </form>
    </section>

    <section class="admin-panel" aria-labelledby="assignment-list-title">
      <div class="admin-panel-heading">
        <h2 id="assignment-list-title">Current assignments</h2>
        <p><?= count($assignments) ?> <?= count($assignments) === 1 ? 'assignment' : 'assignments' ?></p>
      </div>
      <form class="admin-filter-form" method="get" action="<?= BASE_URL ?>admin/assign.php">
        <div class="admin-filter-field">
          <label for="year-filter">Academic year</label>
          <select id="year-filter" name="year_id">
            <option value="0"<?= $allYearsSelected ? ' selected' : '' ?>>All academic years</option>
            <?php foreach ($yearOptions as $year): ?>
              <option value="<?= (int)$year['id'] ?>"<?= $yearFilter === (int)$year['id'] ? ' selected' : '' ?>>
                <?= e($year['name']) ?><?= (int)$year['is_current'] === 1 ? ' (Current)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="admin-filter-actions">
          <button class="admin-primary-link" type="submit">Show assignments</button>
        </div>
      </form>

      <?php if (!$assignments): ?>
        <div class="admin-empty-state">
          <h3>No assignments found</h3>
          <p>Create an assignment above or choose another academic year.</p>
        </div>
      <?php else: ?>
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr>
                <th scope="col">Academic year</th>
                <th scope="col">Class</th>
                <th scope="col">Subject</th>
                <th scope="col">Teacher</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($assignments as $assignment): ?>
                <tr>
                  <td data-label="Academic year"><?= e($assignment['year_name']) ?></td>
                  <td data-label="Class"><?= e($assignment['class_name']) ?></td>
                  <td data-label="Subject"><?= e($assignment['subject_name']) ?> (<?= e($assignment['subject_code']) ?>)</td>
                  <td data-label="Teacher">
                    <form id="assignment-<?= (int)$assignment['id'] ?>" method="post" action="<?= BASE_URL ?>admin/assign.php">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="assignment_id" value="<?= (int)$assignment['id'] ?>">
                      <input type="hidden" name="academic_year_id" value="<?= (int)$assignment['academic_year_id'] ?>">
                    </form>
                    <label class="visually-hidden" for="teacher-<?= (int)$assignment['id'] ?>">Teacher for <?= e($assignment['class_name'] . ' ' . $assignment['subject_name']) ?></label>
                    <select form="assignment-<?= (int)$assignment['id'] ?>" id="teacher-<?= (int)$assignment['id'] ?>" name="teacher_id" required>
                      <?php if ((int)$assignment['teacher_active'] !== 1): ?>
                        <option value="<?= (int)$assignment['teacher_id'] ?>" selected><?= e($assignment['teacher_name']) ?> (inactive)</option>
                      <?php endif; ?>
                      <?php foreach ($teachers as $teacher): ?>
                        <option value="<?= (int)$teacher['id'] ?>"<?= (int)$teacher['id'] === (int)$assignment['teacher_id'] ? ' selected' : '' ?>>
                          <?= e($teacher['full_name']) ?> (<?= e($teacher['staff_no']) ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td data-label="Actions" class="admin-row-actions">
                    <button class="admin-primary-link admin-compact-link" type="submit" form="assignment-<?= (int)$assignment['id'] ?>">Save teacher</button>
                    <form method="post" action="<?= BASE_URL ?>admin/assign.php" onsubmit="return confirm('Remove this teacher assignment?');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="assignment_id" value="<?= (int)$assignment['id'] ?>">
                      <input type="hidden" name="academic_year_id" value="<?= (int)$assignment['academic_year_id'] ?>">
                      <button class="admin-danger-button admin-compact-link" type="submit">Remove</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>

<?php include __DIR__ . '/../includes/staff-footer.php'; ?>
