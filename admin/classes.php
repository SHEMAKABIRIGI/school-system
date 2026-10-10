<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$pageTitle = 'Classes';
$errors = [];
$successMessage = '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $successMessage = $_SESSION['admin_classes_success'] ?? '';
    unset($_SESSION['admin_classes_success']);
}
$nameValue = '';
$levelValue = '';
$editedValues = [];
$classes = [];
$loadError = false;
$characterLength = static function (string $value): int {
    if (preg_match_all('/./us', $value, $matches) === false) {
        return strlen($value);
    }
    return count($matches[0]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? null;
    $action = $_POST['action'] ?? '';
    $classId = filter_var($_POST['class_id'] ?? '', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if (!is_string($token) || !csrf_check($token)) {
        $errors[] = 'Your form expired. Please try again.';
    } elseif (!is_string($action) || !in_array($action, ['add', 'update', 'delete'], true)) {
        $errors[] = 'The requested class operation is invalid.';
    } elseif (in_array($action, ['update', 'delete'], true) && $classId === false) {
        $errors[] = 'Choose a valid class record.';
    }

    if (in_array($action, ['add', 'update'], true)) {
        $nameInput = $_POST['name'] ?? '';
        $levelInput = $_POST['level'] ?? '';
        $nameValue = is_string($nameInput) ? trim($nameInput) : '';
        $levelValue = is_string($levelInput) ? trim($levelInput) : '';
        if ($action === 'update' && $classId !== false) {
            $editedValues[(int)$classId] = [
                'name' => $nameValue,
                'level' => $levelValue,
            ];
        }

        if ($nameValue === '' || $characterLength($nameValue) > 30) {
            $errors[] = 'Class name is required and must be no longer than 30 characters.';
        }
        if ($levelValue === '' || $characterLength($levelValue) > 20) {
            $errors[] = 'Class level is required and must be no longer than 20 characters.';
        }
    }

    if (!$errors) {
        try {
            $pdo = db();
            if ($action === 'add') {
                $stmt = $pdo->prepare('INSERT INTO classes (name, level) VALUES (:name, :level)');
                $stmt->execute([':name' => $nameValue, ':level' => $levelValue]);
                $_SESSION['admin_classes_success'] = 'Class added.';
            } elseif ($action === 'update') {
                $stmt = $pdo->prepare('UPDATE classes SET name = :name, level = :level WHERE id = :id');
                $stmt->execute([
                    ':name' => $nameValue,
                    ':level' => $levelValue,
                    ':id' => $classId,
                ]);
                if ($stmt->rowCount() === 0) {
                    $exists = $pdo->prepare('SELECT 1 FROM classes WHERE id = :id');
                    $exists->execute([':id' => $classId]);
                    if (!$exists->fetchColumn()) {
                        $errors[] = 'That class could not be found.';
                    } else {
                        $_SESSION['admin_classes_success'] = 'Class details are unchanged.';
                    }
                } else {
                    $_SESSION['admin_classes_success'] = 'Class details updated.';
                }
            } else {
                $pdo->beginTransaction();
                $classStmt = $pdo->prepare('SELECT id FROM classes WHERE id = :id FOR UPDATE');
                $classStmt->execute([':id' => $classId]);
                if (!$classStmt->fetchColumn()) {
                    throw new DomainException('That class could not be found.');
                }

                $studentStmt = $pdo->prepare('SELECT COUNT(*) FROM students WHERE class_id = :id');
                $studentStmt->execute([':id' => $classId]);
                if ((int)$studentStmt->fetchColumn() > 0) {
                    throw new DomainException('This class has students. Move those students to another class before deleting it.');
                }

                $assignmentStmt = $pdo->prepare('SELECT COUNT(*) FROM teacher_assignments WHERE class_id = :id');
                $assignmentStmt->execute([':id' => $classId]);
                if ((int)$assignmentStmt->fetchColumn() > 0) {
                    throw new DomainException('This class has teacher assignments. Remove those assignments before deleting it.');
                }

                $deleteStmt = $pdo->prepare('DELETE FROM classes WHERE id = :id');
                $deleteStmt->execute([':id' => $classId]);
                if ($deleteStmt->rowCount() !== 1) {
                    throw new DomainException('The class could not be deleted.');
                }
                $pdo->commit();
                $_SESSION['admin_classes_success'] = 'Class deleted.';
            }

            if (!$errors) {
                redirect(BASE_URL . 'admin/classes.php');
            }
        } catch (DomainException $ex) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $ex->getMessage();
        } catch (PDOException $ex) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($ex->getMessage());
            $errors[] = $ex->getCode() === '23000'
                ? 'A class with that name already exists.'
                : 'Could not save the class details. Please try again.';
        }
    }
}

try {
    $classes = db()->query(
        'SELECT c.id, c.name, c.level,
                (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id) AS student_count,
                (SELECT COUNT(*) FROM teacher_assignments ta WHERE ta.class_id = c.id) AS assignment_count
           FROM classes c
          ORDER BY c.level, c.name'
    )->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $loadError = true;
}

include __DIR__ . '/../includes/staff-header.php';
?>

<main class="admin-page">
  <div class="admin-page-heading">
    <div>
      <p class="admin-eyebrow">School structure</p>
      <h1>Classes</h1>
      <p>Create and maintain the classes used for student enrolment and teacher assignments.</p>
    </div>
    <a class="admin-secondary-link" href="<?= BASE_URL ?>admin/assign.php">Assign teachers</a>
  </div>

  <?php if ($successMessage !== ''): ?>
    <div class="admin-alert admin-alert-success" role="status"><?= e($successMessage) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="admin-alert admin-alert-error" role="alert">
      <?php foreach ($errors as $error): ?><p><?= e($error) ?></p><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <section class="admin-panel admin-form-panel" aria-labelledby="add-class-title">
    <h2 id="add-class-title">Add a class</h2>
    <form method="post" action="<?= BASE_URL ?>admin/classes.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <div class="admin-form-grid">
        <div class="admin-form-field">
          <label for="new-class-name">Class name <span aria-hidden="true">*</span></label>
          <input type="text" id="new-class-name" name="name" value="<?= e($nameValue) ?>" maxlength="30" placeholder="For example, S1 A" required>
        </div>
        <div class="admin-form-field">
          <label for="new-class-level">Level <span aria-hidden="true">*</span></label>
          <input type="text" id="new-class-level" name="level" value="<?= e($levelValue) ?>" maxlength="20" placeholder="For example, S1" required>
        </div>
      </div>
      <div class="admin-form-actions">
        <button class="admin-primary-link" type="submit">Add class</button>
      </div>
    </form>
  </section>

  <section class="admin-panel admin-records" aria-labelledby="class-records-title">
    <div class="admin-panel-heading">
      <h2 id="class-records-title">Class records</h2>
      <?php if (!$loadError): ?><p><?= count($classes) ?> <?= count($classes) === 1 ? 'class' : 'classes' ?></p><?php endif; ?>
    </div>
    <?php if ($loadError): ?>
      <div class="admin-alert admin-alert-error" role="alert">Class records could not be loaded. Please try again.</div>
    <?php elseif (!$classes): ?>
      <div class="admin-empty-state">
        <h3>No classes yet</h3>
        <p>Add a class to make it available for student enrolment and teacher assignments.</p>
      </div>
    <?php else: ?>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th scope="col">Class name</th>
              <th scope="col">Level</th>
              <th scope="col">Students</th>
              <th scope="col">Teacher assignments</th>
              <th scope="col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($classes as $class): ?>
              <tr>
                <td data-label="Class name">
                  <form id="update-class-<?= (int)$class['id'] ?>" method="post" action="<?= BASE_URL ?>admin/classes.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="class_id" value="<?= (int)$class['id'] ?>">
                  </form>
                  <label class="visually-hidden" for="class-name-<?= (int)$class['id'] ?>">Class name</label>
                  <input form="update-class-<?= (int)$class['id'] ?>" id="class-name-<?= (int)$class['id'] ?>" type="text" name="name" value="<?= e($editedValues[(int)$class['id']]['name'] ?? $class['name']) ?>" maxlength="30" required>
                </td>
                <td data-label="Level">
                  <label class="visually-hidden" for="class-level-<?= (int)$class['id'] ?>">Class level</label>
                  <input form="update-class-<?= (int)$class['id'] ?>" id="class-level-<?= (int)$class['id'] ?>" type="text" name="level" value="<?= e($editedValues[(int)$class['id']]['level'] ?? $class['level']) ?>" maxlength="20" required>
                </td>
                <td data-label="Students"><?= (int)$class['student_count'] ?></td>
                <td data-label="Teacher assignments"><?= (int)$class['assignment_count'] ?></td>
                <td data-label="Actions" class="admin-row-actions">
                  <button class="admin-primary-link admin-compact-link" type="submit" form="update-class-<?= (int)$class['id'] ?>">Save</button>
                  <form method="post" action="<?= BASE_URL ?>admin/classes.php" onsubmit="return confirm('Delete this class? Classes with students or teacher assignments cannot be deleted.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="class_id" value="<?= (int)$class['id'] ?>">
                    <button class="admin-danger-button admin-compact-link" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</main>

<?php include __DIR__ . '/../includes/staff-footer.php'; ?>
