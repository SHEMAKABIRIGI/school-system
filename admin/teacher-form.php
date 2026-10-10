<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$idValue = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$id = $idValue === false ? null : $idValue;
$notFound = array_key_exists('id', $_GET) && $id === null;
$loadError = false;
$values = [
    'full_name' => '',
    'username' => '',
    'email' => '',
    'phone' => '',
    'staff_no' => '',
    'gender' => '',
    'qualification' => '',
    'hire_date' => '',
];
$errors = [];
$teacher = null;
$characterLength = static function (string $value): int {
    if (preg_match_all('/./us', $value, $matches) === false) {
        return strlen($value);
    }
    return count($matches[0]);
};

if ($notFound) {
    http_response_code(404);
} elseif ($id !== null) {
    try {
        $stmt = db()->prepare(
            'SELECT t.id, t.user_id, t.staff_no, t.gender, t.qualification, t.hire_date,
                    u.full_name, u.username, u.email, u.phone
               FROM teachers t
               JOIN users u ON u.id = t.user_id
              WHERE t.id = :id AND u.role = :role
              LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':role' => 'teacher']);
        $teacher = $stmt->fetch();
        if (!$teacher) {
            $notFound = true;
            http_response_code(404);
        } else {
            foreach ($values as $field => $default) {
                $values[$field] = (string)($teacher[$field] ?? '');
            }
        }
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        $loadError = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$loadError && !$notFound) {
    $postedId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $postedId = $postedId === false ? null : $postedId;
    $action = $_POST['action'] ?? 'save';
    $token = $_POST['csrf'] ?? null;

    if ($postedId !== $id) {
        $errors[] = 'The teacher record changed. Please reload the page and try again.';
    }
    if (!is_string($token) || !csrf_check($token)) {
        $errors[] = 'Your form expired. Please try again.';
    }
    if (!is_string($action) || !in_array($action, ['save', 'reset_password'], true)) {
        $errors[] = 'The requested teacher operation is invalid.';
    }

    if ($action === 'save') {
        foreach ($values as $field => $default) {
            $submitted = $_POST[$field] ?? '';
            $values[$field] = is_string($submitted) ? trim($submitted) : '';
        }

        $requiredFields = [
            'full_name' => ['Full name', 100],
            'username' => ['Username', 50],
            'email' => ['Email address', 100],
            'staff_no' => ['Staff number', 30],
        ];
        foreach ($requiredFields as $field => [$label, $limit]) {
            if ($values[$field] === '' || $characterLength($values[$field]) > $limit) {
                $errors[] = $label . ' is required and must be no longer than ' . $limit . ' characters.';
            }
        }
        if (
            $values['username'] !== ''
            && preg_match('/\A[A-Za-z0-9._-]{3,50}\z/', $values['username']) !== 1
        ) {
            $errors[] = 'Username must be 3 to 50 characters and use only letters, numbers, dots, underscores, or hyphens.';
        }
        if (
            $values['email'] !== ''
            && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false
        ) {
            $errors[] = 'Enter a valid email address.';
        }
        if (
            $values['phone'] !== ''
            && (
                $characterLength($values['phone']) > 20
                || preg_match('/\A[0-9+().\s-]{7,20}\z/', $values['phone']) !== 1
                || strlen(preg_replace('/[^0-9]/', '', $values['phone'])) < 7
            )
        ) {
            $errors[] = 'Enter a valid phone number or leave the field blank.';
        }
        if (!in_array($values['gender'], ['', 'male', 'female'], true)) {
            $errors[] = 'Choose a valid gender or leave the field blank.';
        }
        if ($values['qualification'] !== '' && $characterLength($values['qualification']) > 100) {
            $errors[] = 'Qualification must be no longer than 100 characters.';
        }
        if ($values['hire_date'] !== '') {
            $hireDate = DateTimeImmutable::createFromFormat('!Y-m-d', $values['hire_date']);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (
                $hireDate === false
                || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
                || $hireDate->format('Y-m-d') !== $values['hire_date']
            ) {
                $errors[] = 'Enter a valid hire date or leave the field blank.';
            }
        }

        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        if ($id === null) {
            if (!is_string($password) || strlen($password) < 12 || strlen($password) > 255) {
                $errors[] = 'Set an initial password of at least 12 characters.';
            } elseif (!is_string($passwordConfirm) || !hash_equals($password, $passwordConfirm)) {
                $errors[] = 'The password confirmation does not match.';
            }
        }

        if (!$errors) {
            $pdo = db();
            try {
                $pdo->beginTransaction();

                $userExists = $pdo->prepare(
                    'SELECT id FROM users WHERE (username = :username OR email = :email) AND id <> :user_id LIMIT 1'
                );
                $userExists->execute([
                    ':username' => $values['username'],
                    ':email' => $values['email'],
                    ':user_id' => $teacher ? (int)$teacher['user_id'] : 0,
                ]);
                if ($userExists->fetchColumn()) {
                    throw new DomainException('That username or email address is already in use.');
                }

                $staffExists = $pdo->prepare(
                    'SELECT id FROM teachers WHERE staff_no = :staff_no AND id <> :teacher_id LIMIT 1'
                );
                $staffExists->execute([
                    ':staff_no' => $values['staff_no'],
                    ':teacher_id' => $id ?? 0,
                ]);
                if ($staffExists->fetchColumn()) {
                    throw new DomainException('That staff number is already in use.');
                }

                if ($id === null) {
                    $insertUser = $pdo->prepare(
                        'INSERT INTO users (full_name, username, email, phone, password_hash, role)
                         VALUES (:full_name, :username, :email, :phone, :password_hash, :role)'
                    );
                    $insertUser->execute([
                        ':full_name' => $values['full_name'],
                        ':username' => $values['username'],
                        ':email' => $values['email'],
                        ':phone' => $values['phone'] === '' ? null : $values['phone'],
                        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        ':role' => 'teacher',
                    ]);
                    $userId = (int)$pdo->lastInsertId();

                    $insertTeacher = $pdo->prepare(
                        'INSERT INTO teachers (user_id, staff_no, gender, qualification, hire_date)
                         VALUES (:user_id, :staff_no, :gender, :qualification, :hire_date)'
                    );
                    $insertTeacher->execute([
                        ':user_id' => $userId,
                        ':staff_no' => $values['staff_no'],
                        ':gender' => $values['gender'] === '' ? null : $values['gender'],
                        ':qualification' => $values['qualification'] === '' ? null : $values['qualification'],
                        ':hire_date' => $values['hire_date'] === '' ? null : $values['hire_date'],
                    ]);
                    $successMessage = 'Teacher account and staff record created.';
                } else {
                    $updateUser = $pdo->prepare(
                        'UPDATE users
                            SET full_name = :full_name, username = :username, email = :email, phone = :phone
                          WHERE id = :id AND role = :role'
                    );
                    $updateUser->execute([
                        ':full_name' => $values['full_name'],
                        ':username' => $values['username'],
                        ':email' => $values['email'],
                        ':phone' => $values['phone'] === '' ? null : $values['phone'],
                        ':id' => (int)$teacher['user_id'],
                        ':role' => 'teacher',
                    ]);
                    if ($updateUser->rowCount() === 0) {
                        $checkUser = $pdo->prepare('SELECT 1 FROM users WHERE id = :id AND role = :role');
                        $checkUser->execute([':id' => (int)$teacher['user_id'], ':role' => 'teacher']);
                        if (!$checkUser->fetchColumn()) {
                            throw new DomainException('The teacher account could not be found.');
                        }
                    }

                    $updateTeacher = $pdo->prepare(
                        'UPDATE teachers
                            SET staff_no = :staff_no, gender = :gender,
                                qualification = :qualification, hire_date = :hire_date
                          WHERE id = :id'
                    );
                    $updateTeacher->execute([
                        ':staff_no' => $values['staff_no'],
                        ':gender' => $values['gender'] === '' ? null : $values['gender'],
                        ':qualification' => $values['qualification'] === '' ? null : $values['qualification'],
                        ':hire_date' => $values['hire_date'] === '' ? null : $values['hire_date'],
                        ':id' => $id,
                    ]);
                    $successMessage = 'Teacher details updated.';
                }

                $pdo->commit();
                $_SESSION['admin_teachers_success'] = $successMessage;
                redirect(BASE_URL . 'admin/teachers.php');
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
                    ? 'The username, email address, or staff number is already in use.'
                    : 'Could not save the teacher details. Please try again.';
            }
        }
    } elseif ($action === 'reset_password' && !$errors) {
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        if ($id === null || !$teacher) {
            $errors[] = 'A saved teacher account is required before resetting its password.';
        } elseif (!is_string($password) || strlen($password) < 12 || strlen($password) > 255) {
            $errors[] = 'Set a new password of at least 12 characters.';
        } elseif (!is_string($passwordConfirm) || !hash_equals($password, $passwordConfirm)) {
            $errors[] = 'The password confirmation does not match.';
        } else {
            try {
                $stmt = db()->prepare(
                    'UPDATE users SET password_hash = :password_hash WHERE id = :id AND role = :role'
                );
                $stmt->execute([
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ':id' => (int)$teacher['user_id'],
                    ':role' => 'teacher',
                ]);
                if ($stmt->rowCount() !== 1) {
                    throw new DomainException('The teacher account could not be found.');
                }
                $_SESSION['admin_teacher_form_success'] = 'Teacher password reset. Share the new password with the teacher securely.';
                redirect(BASE_URL . 'admin/teacher-form.php?id=' . $id);
            } catch (DomainException $ex) {
                $errors[] = $ex->getMessage();
            } catch (PDOException $ex) {
                error_log($ex->getMessage());
                $errors[] = 'Could not reset the teacher password. Please try again.';
            }
        }
    }
}

$successMessage = $_SESSION['admin_teacher_form_success'] ?? '';
unset($_SESSION['admin_teacher_form_success']);
$pageTitle = $id === null ? 'Add teacher' : 'Edit teacher';
if ($notFound) {
    $pageTitle = 'Teacher not found';
}
include __DIR__ . '/../includes/staff-header.php';
?>

<main class="admin-page">
  <div class="admin-page-heading">
    <div>
      <p class="admin-eyebrow">Staff records</p>
      <h1><?= e($pageTitle) ?></h1>
      <p><?= $id === null ? 'Create a teacher login and staff record.' : 'Update the teacher account, staff details, or password.' ?></p>
    </div>
    <a class="admin-secondary-link" href="<?= BASE_URL ?>admin/teachers.php">Back to teachers</a>
  </div>

  <?php if ($successMessage !== ''): ?>
    <div class="admin-alert admin-alert-success" role="status"><?= e($successMessage) ?></div>
  <?php endif; ?>
  <?php if ($loadError): ?>
    <div class="admin-alert admin-alert-error" role="alert">Teacher details could not be loaded. Please try again.</div>
  <?php elseif ($notFound): ?>
    <section class="admin-panel admin-empty-state">
      <h2>Teacher not found</h2>
      <p>The requested teacher record does not exist.</p>
    </section>
  <?php else: ?>
    <?php if ($errors): ?>
      <div class="admin-alert admin-alert-error" role="alert">
        <?php foreach ($errors as $error): ?><p><?= e($error) ?></p><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <section class="admin-panel admin-form-panel" aria-labelledby="teacher-form-title">
      <h2 id="teacher-form-title">Teacher and account details</h2>
      <form method="post" action="<?= BASE_URL ?>admin/teacher-form.php<?= $id === null ? '' : '?id=' . $id ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $id === null ? '' : (int)$id ?>">
        <div class="admin-form-grid">
          <div class="admin-form-field">
            <label for="full-name">Full name <span aria-hidden="true">*</span></label>
            <input type="text" id="full-name" name="full_name" value="<?= e($values['full_name']) ?>" maxlength="100" autocomplete="name" required>
          </div>
          <div class="admin-form-field">
            <label for="staff-no">Staff number <span aria-hidden="true">*</span></label>
            <input type="text" id="staff-no" name="staff_no" value="<?= e($values['staff_no']) ?>" maxlength="30" required>
          </div>
          <div class="admin-form-field">
            <label for="username">Username <span aria-hidden="true">*</span></label>
            <input type="text" id="username" name="username" value="<?= e($values['username']) ?>" minlength="3" maxlength="50" pattern="[A-Za-z0-9._\-]{3,50}" autocomplete="username" required>
          </div>
          <div class="admin-form-field">
            <label for="email">Email address <span aria-hidden="true">*</span></label>
            <input type="email" id="email" name="email" value="<?= e($values['email']) ?>" maxlength="100" autocomplete="email" required>
          </div>
          <div class="admin-form-field">
            <label for="phone">Phone <span>(optional)</span></label>
            <input type="tel" id="phone" name="phone" value="<?= e($values['phone']) ?>" maxlength="20" autocomplete="tel">
          </div>
          <div class="admin-form-field">
            <label for="gender">Gender <span>(optional)</span></label>
            <select id="gender" name="gender">
              <option value="">Not specified</option>
              <option value="female"<?= $values['gender'] === 'female' ? ' selected' : '' ?>>Female</option>
              <option value="male"<?= $values['gender'] === 'male' ? ' selected' : '' ?>>Male</option>
            </select>
          </div>
          <div class="admin-form-field">
            <label for="qualification">Qualification <span>(optional)</span></label>
            <input type="text" id="qualification" name="qualification" value="<?= e($values['qualification']) ?>" maxlength="100">
          </div>
          <div class="admin-form-field">
            <label for="hire-date">Hire date <span>(optional)</span></label>
            <input type="date" id="hire-date" name="hire_date" value="<?= e($values['hire_date']) ?>">
          </div>
          <?php if ($id === null): ?>
            <div class="admin-form-field">
              <label for="password">Initial password <span aria-hidden="true">*</span></label>
              <input type="password" id="password" name="password" minlength="12" maxlength="255" autocomplete="new-password" required>
              <small>Use at least 12 characters. Provide the password to the teacher securely.</small>
            </div>
            <div class="admin-form-field">
              <label for="password-confirm">Confirm initial password <span aria-hidden="true">*</span></label>
              <input type="password" id="password-confirm" name="password_confirm" minlength="12" maxlength="255" autocomplete="new-password" required>
            </div>
          <?php endif; ?>
        </div>
        <div class="admin-form-actions">
          <button class="admin-primary-link" type="submit"><?= $id === null ? 'Create teacher' : 'Save changes' ?></button>
          <a class="admin-secondary-link" href="<?= BASE_URL ?>admin/teachers.php">Cancel</a>
        </div>
      </form>
    </section>

    <?php if ($id !== null && $teacher): ?>
      <section class="admin-panel admin-form-panel" aria-labelledby="reset-password-title">
        <h2 id="reset-password-title">Reset teacher password</h2>
        <p>Choose a new password and share it with the teacher securely.</p>
        <form method="post" action="<?= BASE_URL ?>admin/teacher-form.php?id=<?= $id ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reset_password">
          <input type="hidden" name="id" value="<?= $id ?>">
          <div class="admin-form-grid">
            <div class="admin-form-field">
              <label for="reset-password">New password <span aria-hidden="true">*</span></label>
              <input type="password" id="reset-password" name="password" minlength="12" maxlength="255" autocomplete="new-password" required>
            </div>
            <div class="admin-form-field">
              <label for="reset-password-confirm">Confirm new password <span aria-hidden="true">*</span></label>
              <input type="password" id="reset-password-confirm" name="password_confirm" minlength="12" maxlength="255" autocomplete="new-password" required>
            </div>
          </div>
          <div class="admin-form-actions">
            <button class="admin-primary-link" type="submit">Reset password</button>
          </div>
        </form>
      </section>
    <?php endif; ?>
  <?php endif; ?>
</main>

<?php include __DIR__ . '/../includes/staff-footer.php'; ?>
