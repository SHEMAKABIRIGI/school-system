<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$pageTitle = 'School settings';
$errors = [];
$successMessage = '';
$loadError = false;
$years = [];
$terms = [];
$grades = [];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $successMessage = $_SESSION['admin_settings_success'] ?? '';
    unset($_SESSION['admin_settings_success']);
}

$validDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $dateErrors = DateTimeImmutable::getLastErrors();
    return $date !== false
        && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
};
$positiveId = static function ($value): int|false {
    return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? null;
    $actionValue = $_POST['action'] ?? '';
    $action = is_string($actionValue) ? $actionValue : '';
    $allowedActions = [
        'add_year', 'update_year', 'set_current_year', 'delete_year',
        'add_term', 'update_term', 'set_current_term', 'delete_term',
        'add_grade', 'update_grade', 'delete_grade',
    ];
    if (!is_string($token) || !csrf_check($token)) {
        $errors[] = 'Your form expired. Please try again.';
    }
    if (!in_array($action, $allowedActions, true)) {
        $errors[] = 'The requested settings operation is invalid.';
    }

    $pdo = null;
    try {
        $pdo = db();
        if (!$errors && in_array($action, ['add_year', 'update_year'], true)) {
            $yearId = $action === 'update_year' ? $positiveId($_POST['year_id'] ?? '') : null;
            $nameInput = $_POST['name'] ?? '';
            $startInput = $_POST['start_date'] ?? '';
            $endInput = $_POST['end_date'] ?? '';
            $name = is_string($nameInput) ? trim($nameInput) : '';
            $startDate = is_string($startInput) ? trim($startInput) : '';
            $endDate = is_string($endInput) ? trim($endInput) : '';
            if ($action === 'update_year' && $yearId === false) {
                throw new DomainException('Choose a valid academic year.');
            }
            if ($name === '' || strlen($name) > 20) {
                throw new DomainException('Academic year name is required and must be no longer than 20 characters.');
            }
            if (!$validDate($startDate) || !$validDate($endDate) || $startDate > $endDate) {
                throw new DomainException('Enter valid academic year dates, with the end date on or after the start date.');
            }

            if ($action === 'add_year') {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    'INSERT INTO academic_years (name, start_date, end_date, is_current)
                     VALUES (:name, :start_date, :end_date, 0)'
                );
                $stmt->execute([':name' => $name, ':start_date' => $startDate, ':end_date' => $endDate]);
                $newYearId = (int)$pdo->lastInsertId();
                if (isset($_POST['is_current']) && $_POST['is_current'] === '1') {
                    $pdo->exec('UPDATE academic_years SET is_current = 0');
                    $setYear = $pdo->prepare('UPDATE academic_years SET is_current = 1 WHERE id = :id');
                    $setYear->execute([':id' => $newYearId]);
                    $clearTerms = $pdo->prepare('UPDATE terms SET is_current = 0 WHERE academic_year_id <> :id');
                    $clearTerms->execute([':id' => $newYearId]);
                    $pdo->commit();
                }
                $_SESSION['admin_settings_success'] = 'Academic year added.';
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE academic_years SET name = :name, start_date = :start_date, end_date = :end_date
                      WHERE id = :id'
                );
                $stmt->execute([
                    ':name' => $name,
                    ':start_date' => $startDate,
                    ':end_date' => $endDate,
                    ':id' => $yearId,
                ]);
                if ($stmt->rowCount() === 0) {
                    $exists = $pdo->prepare('SELECT 1 FROM academic_years WHERE id = :id');
                    $exists->execute([':id' => $yearId]);
                    if (!$exists->fetchColumn()) {
                        throw new DomainException('That academic year could not be found.');
                    }
                }
                $_SESSION['admin_settings_success'] = 'Academic year details saved.';
            }
        } elseif (!$errors && $action === 'set_current_year') {
            $yearId = $positiveId($_POST['year_id'] ?? '');
            if ($yearId === false) {
                throw new DomainException('Choose a valid academic year.');
            }
            $pdo->beginTransaction();
            $check = $pdo->prepare('SELECT id FROM academic_years WHERE id = :id FOR UPDATE');
            $check->execute([':id' => $yearId]);
            if (!$check->fetchColumn()) {
                throw new DomainException('That academic year could not be found.');
            }
            $pdo->exec('UPDATE academic_years SET is_current = 0');
            $setYear = $pdo->prepare('UPDATE academic_years SET is_current = 1 WHERE id = :id');
            $setYear->execute([':id' => $yearId]);
            $clearTerms = $pdo->prepare('UPDATE terms SET is_current = 0 WHERE academic_year_id <> :id');
            $clearTerms->execute([':id' => $yearId]);
            $pdo->commit();
            $_SESSION['admin_settings_success'] = 'Current academic year updated.';
        } elseif (!$errors && $action === 'delete_year') {
            $yearId = $positiveId($_POST['year_id'] ?? '');
            if ($yearId === false) {
                throw new DomainException('Choose a valid academic year.');
            }
            $pdo->beginTransaction();
            $check = $pdo->prepare('SELECT is_current FROM academic_years WHERE id = :id FOR UPDATE');
            $check->execute([':id' => $yearId]);
            $year = $check->fetch();
            if (!$year) {
                throw new DomainException('That academic year could not be found.');
            }
            if ((int)$year['is_current'] === 1) {
                throw new DomainException('Choose another current academic year before deleting this one.');
            }
            $assignmentCheck = $pdo->prepare('SELECT 1 FROM teacher_assignments WHERE academic_year_id = :id LIMIT 1');
            $assignmentCheck->execute([':id' => $yearId]);
            if ($assignmentCheck->fetchColumn()) {
                throw new DomainException('This academic year has teacher assignments. Remove those assignments before deleting it.');
            }
            $marksCheck = $pdo->prepare(
                'SELECT 1 FROM marks m
                   JOIN terms t ON t.id = m.term_id
                  WHERE t.academic_year_id = :id LIMIT 1'
            );
            $marksCheck->execute([':id' => $yearId]);
            if ($marksCheck->fetchColumn()) {
                throw new DomainException('This academic year has recorded marks. Its terms cannot be deleted.');
            }
            $delete = $pdo->prepare('DELETE FROM academic_years WHERE id = :id');
            $delete->execute([':id' => $yearId]);
            $pdo->commit();
            $_SESSION['admin_settings_success'] = 'Academic year deleted.';
        } elseif (!$errors && in_array($action, ['add_term', 'update_term'], true)) {
            $termId = $action === 'update_term' ? $positiveId($_POST['term_id'] ?? '') : null;
            $yearId = $positiveId($_POST['academic_year_id'] ?? '');
            $nameInput = $_POST['name'] ?? '';
            $startInput = $_POST['start_date'] ?? '';
            $endInput = $_POST['end_date'] ?? '';
            $name = is_string($nameInput) ? trim($nameInput) : '';
            $startDate = is_string($startInput) ? trim($startInput) : '';
            $endDate = is_string($endInput) ? trim($endInput) : '';
            if (($action === 'update_term' && $termId === false) || $yearId === false) {
                throw new DomainException('Choose a valid term and academic year.');
            }
            if ($name === '' || strlen($name) > 30) {
                throw new DomainException('Term name is required and must be no longer than 30 characters.');
            }
            if (!$validDate($startDate) || !$validDate($endDate) || $startDate > $endDate) {
                throw new DomainException('Enter valid term dates, with the end date on or after the start date.');
            }
            $yearCheck = $pdo->prepare('SELECT id FROM academic_years WHERE id = :id');
            $yearCheck->execute([':id' => $yearId]);
            if (!$yearCheck->fetchColumn()) {
                throw new DomainException('The selected academic year is not available.');
            }

            if ($action === 'add_term') {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    'INSERT INTO terms (academic_year_id, name, start_date, end_date, is_current)
                     VALUES (:academic_year_id, :name, :start_date, :end_date, 0)'
                );
                $stmt->execute([
                    ':academic_year_id' => $yearId,
                    ':name' => $name,
                    ':start_date' => $startDate,
                    ':end_date' => $endDate,
                ]);
                if (isset($_POST['is_current']) && $_POST['is_current'] === '1') {
                    $newTermId = (int)$pdo->lastInsertId();
                    $pdo->exec('UPDATE academic_years SET is_current = 0');
                    $pdo->exec('UPDATE terms SET is_current = 0');
                    $setYear = $pdo->prepare('UPDATE academic_years SET is_current = 1 WHERE id = :id');
                    $setYear->execute([':id' => $yearId]);
                    $setTerm = $pdo->prepare('UPDATE terms SET is_current = 1 WHERE id = :id');
                    $setTerm->execute([':id' => $newTermId]);
                }
                $_SESSION['admin_settings_success'] = 'Term added.';
            } else {
                $pdo->beginTransaction();
                $existingTermStmt = $pdo->prepare(
                    'SELECT academic_year_id, is_current FROM terms WHERE id = :id FOR UPDATE'
                );
                $existingTermStmt->execute([':id' => $termId]);
                $existingTerm = $existingTermStmt->fetch();
                if (!$existingTerm) {
                    throw new DomainException('That term could not be found.');
                }
                $stmt = $pdo->prepare(
                    'UPDATE terms
                        SET academic_year_id = :academic_year_id, name = :name,
                            start_date = :start_date, end_date = :end_date
                      WHERE id = :id'
                );
                $stmt->execute([
                    ':academic_year_id' => $yearId,
                    ':name' => $name,
                    ':start_date' => $startDate,
                    ':end_date' => $endDate,
                    ':id' => $termId,
                ]);
                if ((int)$existingTerm['is_current'] === 1 && (int)$existingTerm['academic_year_id'] !== $yearId) {
                    $pdo->exec('UPDATE academic_years SET is_current = 0');
                    $pdo->exec('UPDATE terms SET is_current = 0');
                    $setYear = $pdo->prepare('UPDATE academic_years SET is_current = 1 WHERE id = :id');
                    $setYear->execute([':id' => $yearId]);
                    $setTerm = $pdo->prepare('UPDATE terms SET is_current = 1 WHERE id = :id');
                    $setTerm->execute([':id' => $termId]);
                }
                $_SESSION['admin_settings_success'] = 'Term details saved.';
            }
        } elseif (!$errors && $action === 'set_current_term') {
            $termId = $positiveId($_POST['term_id'] ?? '');
            if ($termId === false) {
                throw new DomainException('Choose a valid term.');
            }
            $pdo->beginTransaction();
            $check = $pdo->prepare(
                'SELECT t.id, t.academic_year_id FROM terms t WHERE t.id = :id FOR UPDATE'
            );
            $check->execute([':id' => $termId]);
            $term = $check->fetch();
            if (!$term) {
                throw new DomainException('That term could not be found.');
            }
            $pdo->exec('UPDATE academic_years SET is_current = 0');
            $pdo->exec('UPDATE terms SET is_current = 0');
            $setYear = $pdo->prepare('UPDATE academic_years SET is_current = 1 WHERE id = :id');
            $setYear->execute([':id' => $term['academic_year_id']]);
            $setTerm = $pdo->prepare('UPDATE terms SET is_current = 1 WHERE id = :id');
            $setTerm->execute([':id' => $termId]);
            $pdo->commit();
            $_SESSION['admin_settings_success'] = 'Current term and academic year updated.';
        } elseif (!$errors && $action === 'delete_term') {
            $termId = $positiveId($_POST['term_id'] ?? '');
            if ($termId === false) {
                throw new DomainException('Choose a valid term.');
            }
            $pdo->beginTransaction();
            $check = $pdo->prepare('SELECT is_current FROM terms WHERE id = :id FOR UPDATE');
            $check->execute([':id' => $termId]);
            $term = $check->fetch();
            if (!$term) {
                throw new DomainException('That term could not be found.');
            }
            if ((int)$term['is_current'] === 1) {
                throw new DomainException('Choose another current term before deleting this one.');
            }
            $marksCheck = $pdo->prepare('SELECT 1 FROM marks WHERE term_id = :id LIMIT 1');
            $marksCheck->execute([':id' => $termId]);
            if ($marksCheck->fetchColumn()) {
                throw new DomainException('This term has recorded marks and cannot be deleted.');
            }
            $delete = $pdo->prepare('DELETE FROM terms WHERE id = :id');
            $delete->execute([':id' => $termId]);
            $pdo->commit();
            $_SESSION['admin_settings_success'] = 'Term deleted.';
        } elseif (!$errors && in_array($action, ['add_grade', 'update_grade'], true)) {
            $gradeId = $action === 'update_grade' ? $positiveId($_POST['grade_id'] ?? '') : null;
            $gradeInput = $_POST['grade'] ?? '';
            $remarkInput = $_POST['remark'] ?? '';
            $percentInput = $_POST['min_percent'] ?? '';
            $grade = is_string($gradeInput) ? strtoupper(trim($gradeInput)) : '';
            $remark = is_string($remarkInput) ? trim($remarkInput) : '';
            $percent = is_string($percentInput) ? trim($percentInput) : '';
            if ($action === 'update_grade' && $gradeId === false) {
                throw new DomainException('Choose a valid grade record.');
            }
            if (preg_match('/\A[A-Z0-9]{1,3}\z/', $grade) !== 1) {
                throw new DomainException('Grade must be 1 to 3 letters or numbers.');
            }
            if ($remark !== '' && strlen($remark) > 30) {
                throw new DomainException('Grade remark must be no longer than 30 characters.');
            }
            if (!is_numeric($percent) || (float)$percent < 0 || (float)$percent > 100 || !preg_match('/\A\d{1,3}(?:\.\d{1,2})?\z/', $percent)) {
                throw new DomainException('Minimum percentage must be a number from 0 to 100, with up to two decimal places.');
            }
            if ($action === 'add_grade') {
                $stmt = $pdo->prepare(
                    'INSERT INTO grade_scale (grade, min_percent, remark) VALUES (:grade, :min_percent, :remark)'
                );
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE grade_scale SET grade = :grade, min_percent = :min_percent, remark = :remark WHERE id = :id'
                );
            }
            $params = [':grade' => $grade, ':min_percent' => $percent, ':remark' => $remark === '' ? null : $remark];
            if ($action === 'update_grade') {
                $params[':id'] = $gradeId;
            }
            $stmt->execute($params);
            $_SESSION['admin_settings_success'] = $action === 'add_grade' ? 'Grade added.' : 'Grade details saved.';
        } elseif (!$errors && $action === 'delete_grade') {
            $gradeId = $positiveId($_POST['grade_id'] ?? '');
            if ($gradeId === false) {
                throw new DomainException('Choose a valid grade record.');
            }
            $delete = $pdo->prepare('DELETE FROM grade_scale WHERE id = :id');
            $delete->execute([':id' => $gradeId]);
            if ($delete->rowCount() !== 1) {
                throw new DomainException('That grade record could not be found.');
            }
            $_SESSION['admin_settings_success'] = 'Grade deleted.';
        }

        if (!$errors) {
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            redirect(BASE_URL . 'admin/settings.php');
        }
    } catch (DomainException $ex) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = $ex->getMessage();
    } catch (PDOException $ex) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($ex->getMessage());
        $errors[] = $ex->getCode() === '23000'
            ? 'That name, grade, or minimum percentage is already in use.'
            : 'The settings could not be saved. Please try again.';
    }
}

try {
    $years = db()->query(
        'SELECT id, name, start_date, end_date, is_current
           FROM academic_years
          ORDER BY start_date DESC, name DESC'
    )->fetchAll();
    $terms = db()->query(
        'SELECT t.id, t.academic_year_id, t.name, t.start_date, t.end_date, t.is_current,
                ay.name AS year_name
           FROM terms t
           JOIN academic_years ay ON ay.id = t.academic_year_id
          ORDER BY ay.start_date DESC, t.start_date, t.id'
    )->fetchAll();
    $grades = db()->query(
        'SELECT id, grade, min_percent, remark
           FROM grade_scale
          ORDER BY min_percent DESC, grade'
    )->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $loadError = true;
}

include __DIR__ . '/../includes/staff-header.php';
?>

<main class="admin-page admin-settings-page">
  <div class="admin-page-heading">
    <div>
      <p class="admin-eyebrow">System configuration</p>
      <h1>School settings</h1>
      <p>Maintain academic years, terms, the current school period, and the grading scale.</p>
    </div>
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
    <div class="admin-alert admin-alert-error" role="alert">School settings could not be loaded. Please try again.</div>
  <?php else: ?>
    <section class="admin-panel admin-form-panel" aria-labelledby="add-year-title">
      <h2 id="add-year-title">Add academic year</h2>
      <form method="post" action="<?= BASE_URL ?>admin/settings.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_year">
        <div class="admin-form-grid">
          <div class="admin-form-field">
            <label for="new-year-name">Year name <span aria-hidden="true">*</span></label>
            <input type="text" id="new-year-name" name="name" maxlength="20" placeholder="For example, 2027" required>
          </div>
          <div class="admin-form-field">
            <label for="new-year-start">Start date <span aria-hidden="true">*</span></label>
            <input type="date" id="new-year-start" name="start_date" required>
          </div>
          <div class="admin-form-field">
            <label for="new-year-end">End date <span aria-hidden="true">*</span></label>
            <input type="date" id="new-year-end" name="end_date" required>
          </div>
          <div class="admin-form-field">
            <label><input type="checkbox" name="is_current" value="1"> Set as current year</label>
          </div>
        </div>
        <div class="admin-form-actions">
          <button class="admin-primary-link" type="submit">Add academic year</button>
        </div>
      </form>
    </section>

    <section class="admin-panel admin-records" aria-labelledby="years-title">
      <div class="admin-panel-heading"><h2 id="years-title">Academic years</h2></div>
      <?php if (!$years): ?>
        <div class="admin-empty-state"><h3>No academic years</h3><p>Add an academic year to configure terms.</p></div>
      <?php else: ?>
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead><tr><th scope="col">Year</th><th scope="col">Start</th><th scope="col">End</th><th scope="col">Current</th><th scope="col">Actions</th></tr></thead>
            <tbody>
              <?php foreach ($years as $year): ?>
                <tr>
                  <td data-label="Year">
                    <?php $yearFormId = 'year-edit-' . (int)$year['id']; ?>
                    <label class="visually-hidden" for="year-name-<?= (int)$year['id'] ?>">Year name</label>
                    <input form="<?= e($yearFormId) ?>" id="year-name-<?= (int)$year['id'] ?>" type="text" name="name" maxlength="20" value="<?= e($year['name']) ?>" required>
                  </td>
                  <td data-label="Start"><input form="<?= e($yearFormId) ?>" type="date" name="start_date" value="<?= e($year['start_date']) ?>" required></td>
                  <td data-label="End"><input form="<?= e($yearFormId) ?>" type="date" name="end_date" value="<?= e($year['end_date']) ?>" required></td>
                  <td data-label="Current">
                    <?php if ((int)$year['is_current'] === 1): ?>
                      <span class="admin-status admin-status-active">Current</span>
                    <?php else: ?>
                      <form method="post" action="<?= BASE_URL ?>admin/settings.php" class="admin-settings-action-form">
                        <?= csrf_field() ?><input type="hidden" name="action" value="set_current_year">
                        <input type="hidden" name="year_id" value="<?= (int)$year['id'] ?>">
                        <button class="admin-secondary-link admin-compact-link" type="submit">Set current</button>
                      </form>
                    <?php endif; ?>
                  </td>
                  <td data-label="Actions" class="admin-row-actions">
                    <form id="<?= e($yearFormId) ?>" method="post" action="<?= BASE_URL ?>admin/settings.php" class="admin-settings-action-form">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="update_year">
                      <input type="hidden" name="year_id" value="<?= (int)$year['id'] ?>">
                      <button class="admin-secondary-link admin-compact-link" type="submit">Save</button>
                    </form>
                    <form method="post" action="<?= BASE_URL ?>admin/settings.php" class="admin-settings-action-form" onsubmit="return confirm('Delete this academic year and its terms? This is only allowed when it has no assignments or marks.');">
                      <?= csrf_field() ?><input type="hidden" name="action" value="delete_year">
                      <input type="hidden" name="year_id" value="<?= (int)$year['id'] ?>">
                      <button class="admin-secondary-link admin-compact-link" type="submit">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="admin-panel admin-form-panel" aria-labelledby="add-term-title">
      <h2 id="add-term-title">Add term</h2>
      <?php if (!$years): ?>
        <p>Add an academic year before creating terms.</p>
      <?php else: ?>
        <form method="post" action="<?= BASE_URL ?>admin/settings.php">
          <?= csrf_field() ?><input type="hidden" name="action" value="add_term">
          <div class="admin-form-grid">
            <div class="admin-form-field">
              <label for="new-term-year">Academic year <span aria-hidden="true">*</span></label>
              <select id="new-term-year" name="academic_year_id" required>
                <?php foreach ($years as $year): ?>
                  <option value="<?= (int)$year['id'] ?>"<?= (int)$year['is_current'] === 1 ? ' selected' : '' ?>><?= e($year['name']) ?><?= (int)$year['is_current'] === 1 ? ' (Current)' : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="admin-form-field">
              <label for="new-term-name">Term name <span aria-hidden="true">*</span></label>
              <input type="text" id="new-term-name" name="name" maxlength="30" placeholder="For example, Term 1" required>
            </div>
            <div class="admin-form-field">
              <label for="new-term-start">Start date <span aria-hidden="true">*</span></label>
              <input type="date" id="new-term-start" name="start_date" required>
            </div>
            <div class="admin-form-field">
              <label for="new-term-end">End date <span aria-hidden="true">*</span></label>
              <input type="date" id="new-term-end" name="end_date" required>
            </div>
            <div class="admin-form-field">
              <label><input type="checkbox" name="is_current" value="1"> Set as current term and year</label>
            </div>
          </div>
          <div class="admin-form-actions"><button class="admin-primary-link" type="submit">Add term</button></div>
        </form>
      <?php endif; ?>
    </section>

    <section class="admin-panel admin-records" aria-labelledby="terms-title">
      <div class="admin-panel-heading"><h2 id="terms-title">Terms</h2></div>
      <?php if (!$terms): ?>
        <div class="admin-empty-state"><h3>No terms</h3><p>Add a term under an academic year.</p></div>
      <?php else: ?>
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead><tr><th scope="col">Term</th><th scope="col">Academic year</th><th scope="col">Start</th><th scope="col">End</th><th scope="col">Current</th><th scope="col">Actions</th></tr></thead>
            <tbody>
              <?php foreach ($terms as $term): ?>
                <tr>
                  <td data-label="Term">
                    <?php $termFormId = 'term-edit-' . (int)$term['id']; ?>
                    <label class="visually-hidden" for="term-name-<?= (int)$term['id'] ?>">Term name</label>
                    <input form="<?= e($termFormId) ?>" id="term-name-<?= (int)$term['id'] ?>" type="text" name="name" maxlength="30" value="<?= e($term['name']) ?>" required>
                  </td>
                  <td data-label="Academic year">
                    <select form="<?= e($termFormId) ?>" name="academic_year_id" aria-label="Academic year for <?= e($term['name']) ?>" required>
                      <?php foreach ($years as $year): ?>
                        <option value="<?= (int)$year['id'] ?>"<?= (int)$term['academic_year_id'] === (int)$year['id'] ? ' selected' : '' ?>><?= e($year['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td data-label="Start"><input form="<?= e($termFormId) ?>" type="date" name="start_date" value="<?= e($term['start_date']) ?>" required></td>
                  <td data-label="End"><input form="<?= e($termFormId) ?>" type="date" name="end_date" value="<?= e($term['end_date']) ?>" required></td>
                  <td data-label="Current">
                    <?php if ((int)$term['is_current'] === 1): ?>
                      <span class="admin-status admin-status-active">Current</span>
                    <?php else: ?>
                      <form method="post" action="<?= BASE_URL ?>admin/settings.php" class="admin-settings-action-form">
                        <?= csrf_field() ?><input type="hidden" name="action" value="set_current_term">
                        <input type="hidden" name="term_id" value="<?= (int)$term['id'] ?>">
                        <button class="admin-secondary-link admin-compact-link" type="submit">Set current</button>
                      </form>
                    <?php endif; ?>
                  </td>
                  <td data-label="Actions" class="admin-row-actions">
                    <form id="<?= e($termFormId) ?>" method="post" action="<?= BASE_URL ?>admin/settings.php" class="admin-settings-action-form">
                      <?= csrf_field() ?><input type="hidden" name="action" value="update_term">
                      <input type="hidden" name="term_id" value="<?= (int)$term['id'] ?>">
                      <button class="admin-secondary-link admin-compact-link" type="submit">Save</button>
                    </form>
                    <form method="post" action="<?= BASE_URL ?>admin/settings.php" class="admin-settings-action-form" onsubmit="return confirm('Delete this term? Terms with recorded marks cannot be deleted.');">
                      <?= csrf_field() ?><input type="hidden" name="action" value="delete_term">
                      <input type="hidden" name="term_id" value="<?= (int)$term['id'] ?>">
                      <button class="admin-secondary-link admin-compact-link" type="submit">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="admin-panel admin-form-panel" aria-labelledby="add-grade-title">
      <h2 id="add-grade-title">Add grade</h2>
      <form method="post" action="<?= BASE_URL ?>admin/settings.php">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_grade">
        <div class="admin-form-grid">
          <div class="admin-form-field">
            <label for="new-grade">Grade <span aria-hidden="true">*</span></label>
            <input type="text" id="new-grade" name="grade" maxlength="3" pattern="[A-Za-z0-9]{1,3}" required>
          </div>
          <div class="admin-form-field">
            <label for="new-grade-percent">Minimum percentage <span aria-hidden="true">*</span></label>
            <input type="number" id="new-grade-percent" name="min_percent" min="0" max="100" step="0.01" required>
          </div>
          <div class="admin-form-field">
            <label for="new-grade-remark">Remark</label>
            <input type="text" id="new-grade-remark" name="remark" maxlength="30">
          </div>
        </div>
        <div class="admin-form-actions"><button class="admin-primary-link" type="submit">Add grade</button></div>
      </form>
    </section>

    <section class="admin-panel admin-records" aria-labelledby="grades-title">
      <div class="admin-panel-heading"><h2 id="grades-title">Grade scale</h2></div>
      <?php if (!$grades): ?>
        <div class="admin-empty-state"><h3>No grades configured</h3><p>Add percentage thresholds used to calculate letter grades.</p></div>
      <?php else: ?>
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead><tr><th scope="col">Grade</th><th scope="col">Minimum percentage</th><th scope="col">Remark</th><th scope="col">Actions</th></tr></thead>
            <tbody>
              <?php foreach ($grades as $grade): ?>
                <tr>
                  <td data-label="Grade">
                    <?php $gradeFormId = 'grade-edit-' . (int)$grade['id']; ?>
                    <label class="visually-hidden" for="grade-name-<?= (int)$grade['id'] ?>">Grade</label>
                    <input form="<?= e($gradeFormId) ?>" id="grade-name-<?= (int)$grade['id'] ?>" type="text" name="grade" maxlength="3" pattern="[A-Za-z0-9]{1,3}" value="<?= e($grade['grade']) ?>" required>
                  </td>
                  <td data-label="Minimum percentage"><input form="<?= e($gradeFormId) ?>" type="number" name="min_percent" min="0" max="100" step="0.01" value="<?= e($grade['min_percent']) ?>" required></td>
                  <td data-label="Remark"><input form="<?= e($gradeFormId) ?>" type="text" name="remark" maxlength="30" value="<?= e($grade['remark']) ?>"></td>
                  <td data-label="Actions" class="admin-row-actions">
                    <form id="<?= e($gradeFormId) ?>" method="post" action="<?= BASE_URL ?>admin/settings.php" class="admin-settings-action-form">
                      <?= csrf_field() ?><input type="hidden" name="action" value="update_grade">
                      <input type="hidden" name="grade_id" value="<?= (int)$grade['id'] ?>">
                      <button class="admin-secondary-link admin-compact-link" type="submit">Save</button>
                    </form>
                    <form method="post" action="<?= BASE_URL ?>admin/settings.php" class="admin-settings-action-form" onsubmit="return confirm('Delete this grade from the scale?');">
                      <?= csrf_field() ?><input type="hidden" name="action" value="delete_grade">
                      <input type="hidden" name="grade_id" value="<?= (int)$grade['id'] ?>">
                      <button class="admin-secondary-link admin-compact-link" type="submit">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <p class="admin-help-text">Each minimum percentage must be unique. Grade results use the highest minimum threshold not exceeding the student’s percentage.</p>
    </section>
  <?php endif; ?>
</main>

<?php include __DIR__ . '/../includes/staff-footer.php'; ?>
