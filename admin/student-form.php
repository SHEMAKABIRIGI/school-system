<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$idRequested = array_key_exists('id', $_GET);
$idValue = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$id = $idValue === false ? null : $idValue;
$viewOnly = $id !== null && isset($_GET['view']) && $_GET['view'] === '1';
$statuses = ['active', 'graduated', 'transferred', 'left'];
$genders = ['male', 'female'];
$values = [
    'admission_no' => '',
    'first_name' => '',
    'last_name' => '',
    'gender' => '',
    'date_of_birth' => '',
    'class_id' => '',
    'guardian_name' => '',
    'guardian_phone' => '',
    'guardian_email' => '',
    'address' => '',
    'status' => 'active',
];
$errors = [];
$classes = [];
$loadError = false;
$notFound = $idRequested && $idValue === false;
if ($notFound) {
    http_response_code(404);
}
$pageTitle = $viewOnly ? 'View student' : ($id === null ? 'Add student' : 'Edit student');
$characterLength = static function (string $value): int {
    if (preg_match_all('/./us', $value, $matches) === false) {
        return strlen($value);
    }
    return count($matches[0]);
};

try {
    $classes = db()->query('SELECT id, name FROM classes ORDER BY name')->fetchAll();

    if ($id !== null) {
        $stmt = db()->prepare(
            'SELECT admission_no, first_name, last_name, gender, date_of_birth,
                    class_id, guardian_name, guardian_phone, guardian_email, address, status
               FROM students
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $student = $stmt->fetch();
        if (!$student) {
            $notFound = true;
            http_response_code(404);
        } elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            foreach ($values as $field => $default) {
                if (array_key_exists($field, $student)) {
                    $values[$field] = (string)($student[$field] ?? '');
                }
            }
        }
    }
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $loadError = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$loadError && !$notFound) {
    $postedId = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $postedId = $postedId === false ? null : $postedId;
    if ($postedId !== $id) {
        $errors[] = 'The student record changed. Please reload the form and try again.';
    }

    $token = $_POST['csrf'] ?? null;
    if (!is_string($token) || !csrf_check($token)) {
        $errors[] = 'Your form expired. Please try again.';
    }

    foreach ($values as $field => $default) {
        $submitted = $_POST[$field] ?? '';
        $values[$field] = is_string($submitted) ? trim($submitted) : '';
    }

    $requiredText = [
        'admission_no' => ['Admission number', 30],
        'first_name' => ['First name', 60],
        'last_name' => ['Last name', 60],
    ];
    foreach ($requiredText as $field => [$label, $limit]) {
        if ($values[$field] === '' || $characterLength($values[$field]) > $limit) {
            $errors[] = $label . ' is required and must be no longer than ' . $limit . ' characters.';
        }
    }
    if (!in_array($values['gender'], $genders, true)) {
        $errors[] = 'Choose a valid gender.';
    }
    if (!in_array($values['status'], $statuses, true)) {
        $errors[] = 'Choose a valid student status.';
    }

    if ($values['date_of_birth'] !== '') {
        $birthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $values['date_of_birth']);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (
            $birthDate === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $birthDate->format('Y-m-d') !== $values['date_of_birth']
        ) {
            $errors[] = 'Enter a valid date of birth or leave the field blank.';
        } elseif ($birthDate > new DateTimeImmutable('today')) {
            $errors[] = 'Date of birth cannot be in the future.';
        }
    }

    $classId = filter_var($values['class_id'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if ($classId === false) {
        $errors[] = 'Choose a valid class.';
    }
    if ($values['guardian_name'] !== '' && $characterLength($values['guardian_name']) > 100) {
        $errors[] = 'Guardian name must be no longer than 100 characters.';
    }
    if (
        $values['guardian_phone'] !== ''
        && (
            $characterLength($values['guardian_phone']) > 20
            || preg_match('/\A[0-9+().\s-]{7,20}\z/', $values['guardian_phone']) !== 1
            || strlen(preg_replace('/[^0-9]/', '', $values['guardian_phone'])) < 7
        )
    ) {
        $errors[] = 'Enter a valid guardian phone number or leave it blank.';
    }
    if (
        $values['guardian_email'] !== ''
        && (
            $characterLength($values['guardian_email']) > 100
            || filter_var($values['guardian_email'], FILTER_VALIDATE_EMAIL) === false
        )
    ) {
        $errors[] = 'Enter a valid guardian email address using no more than 100 characters.';
    }
    if ($values['address'] !== '' && $characterLength($values['address']) > 150) {
        $errors[] = 'Address must be no longer than 150 characters.';
    }

    if (!$errors) {
        try {
            $classCheck = db()->prepare('SELECT 1 FROM classes WHERE id = :id');
            $classCheck->execute([':id' => $classId]);
            if (!$classCheck->fetchColumn()) {
                $errors[] = 'The selected class no longer exists. Choose another class.';
            } else {
                $params = [
                    ':admission_no' => $values['admission_no'],
                    ':first_name' => $values['first_name'],
                    ':last_name' => $values['last_name'],
                    ':gender' => $values['gender'],
                    ':date_of_birth' => $values['date_of_birth'] === '' ? null : $values['date_of_birth'],
                    ':class_id' => $classId,
                    ':guardian_name' => $values['guardian_name'] === '' ? null : $values['guardian_name'],
                    ':guardian_phone' => $values['guardian_phone'] === '' ? null : $values['guardian_phone'],
                    ':guardian_email' => $values['guardian_email'] === '' ? null : $values['guardian_email'],
                    ':address' => $values['address'] === '' ? null : $values['address'],
                    ':status' => $values['status'],
                ];

                if ($id === null) {
                    $stmt = db()->prepare(
                        'INSERT INTO students (
                            admission_no, first_name, last_name, gender, date_of_birth, class_id,
                            guardian_name, guardian_phone, guardian_email, address, status
                        ) VALUES (
                            :admission_no, :first_name, :last_name, :gender, :date_of_birth, :class_id,
                            :guardian_name, :guardian_phone, :guardian_email, :address, :status
                        )'
                    );
                    $stmt->execute($params);
                    $_SESSION['admin_students_success'] = 'Student added.';
                } else {
                    $params[':id'] = $id;
                    $stmt = db()->prepare(
                        'UPDATE students
                            SET admission_no = :admission_no,
                                first_name = :first_name,
                                last_name = :last_name,
                                gender = :gender,
                                date_of_birth = :date_of_birth,
                                class_id = :class_id,
                                guardian_name = :guardian_name,
                                guardian_phone = :guardian_phone,
                                guardian_email = :guardian_email,
                                address = :address,
                                status = :status
                          WHERE id = :id'
                    );
                    $stmt->execute($params);
                    $_SESSION['admin_students_success'] = 'Student record updated.';
                }
                redirect(BASE_URL . 'admin/students.php');
            }
        } catch (PDOException $ex) {
            error_log($ex->getMessage());
            if ($ex->getCode() === '23000') {
                $errors[] = 'That admission number is already in use, or the selected class is no longer available.';
            } else {
                $errors[] = 'Could not save the student record. Please try again.';
            }
        }
    }
}

include __DIR__ . '/../includes/staff-header.php';
?>

<main class="admin-page">
  <div class="admin-page-heading">
    <div>
      <p class="admin-eyebrow">School records</p>
      <h1><?= e($pageTitle) ?></h1>
      <p><?= $viewOnly ? 'Review the student and guardian details.' : 'Keep student information accurate and up to date.' ?></p>
    </div>
    <a class="admin-secondary-link" href="<?= BASE_URL ?>admin/students.php">Back to students</a>
  </div>

  <?php if ($loadError): ?>
    <div class="admin-alert admin-alert-error" role="alert">Student information could not be loaded. Please try again.</div>
  <?php elseif ($notFound): ?>
    <section class="admin-panel admin-empty-state">
      <h2>Student not found</h2>
      <p>This record may have been removed or the link may be incorrect.</p>
    </section>
  <?php else: ?>
    <?php if ($errors): ?>
      <div class="admin-alert admin-alert-error" role="alert">
        <p>Please check the following:</p>
        <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <section class="admin-panel admin-form-panel">
      <?php if ($viewOnly): ?>
        <dl class="admin-detail-grid">
          <div><dt>Admission number</dt><dd><?= e($values['admission_no']) ?></dd></div>
          <div><dt>First name</dt><dd><?= e($values['first_name']) ?></dd></div>
          <div><dt>Last name</dt><dd><?= e($values['last_name']) ?></dd></div>
          <div><dt>Gender</dt><dd><?= e(ucfirst($values['gender'])) ?></dd></div>
          <div><dt>Date of birth</dt><dd><?= e($values['date_of_birth'] !== '' ? date('F j, Y', strtotime($values['date_of_birth'])) : 'Not provided') ?></dd></div>
          <div><dt>Class</dt><dd>
            <?php
              $viewClass = '';
              foreach ($classes as $class) {
                  if ((int)$class['id'] === (int)$values['class_id']) {
                      $viewClass = $class['name'];
                      break;
                  }
              }
            ?>
            <?= e($viewClass !== '' ? $viewClass : 'Not available') ?>
          </dd></div>
          <div><dt>Status</dt><dd><span class="admin-status admin-status-<?= e($values['status']) ?>"><?= e(ucfirst($values['status'])) ?></span></dd></div>
          <div><dt>Guardian name</dt><dd><?= e($values['guardian_name'] !== '' ? $values['guardian_name'] : 'Not provided') ?></dd></div>
          <div><dt>Guardian phone</dt><dd><?= e($values['guardian_phone'] !== '' ? $values['guardian_phone'] : 'Not provided') ?></dd></div>
          <div><dt>Guardian email</dt><dd><?= e($values['guardian_email'] !== '' ? $values['guardian_email'] : 'Not provided') ?></dd></div>
          <div class="admin-detail-wide"><dt>Address</dt><dd><?= e($values['address'] !== '' ? $values['address'] : 'Not provided') ?></dd></div>
        </dl>
        <a class="admin-primary-link" href="<?= BASE_URL ?>admin/student-form.php?id=<?= (int)$id ?>">Edit student</a>
      <?php else: ?>
        <form method="post" action="<?= BASE_URL ?>admin/student-form.php<?= $id === null ? '' : '?id=' . (int)$id ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id === null ? '' : (int)$id ?>">
          <div class="admin-form-grid">
            <div class="admin-form-field">
              <label for="admission-no">Admission number <span aria-hidden="true">*</span></label>
              <input type="text" id="admission-no" name="admission_no" value="<?= e($values['admission_no']) ?>" maxlength="30" required>
            </div>
            <div class="admin-form-field">
              <label for="student-first-name">First name <span aria-hidden="true">*</span></label>
              <input type="text" id="student-first-name" name="first_name" value="<?= e($values['first_name']) ?>" maxlength="60" autocomplete="given-name" required>
            </div>
            <div class="admin-form-field">
              <label for="student-last-name">Last name <span aria-hidden="true">*</span></label>
              <input type="text" id="student-last-name" name="last_name" value="<?= e($values['last_name']) ?>" maxlength="60" autocomplete="family-name" required>
            </div>
            <div class="admin-form-field">
              <label for="student-gender">Gender <span aria-hidden="true">*</span></label>
              <select id="student-gender" name="gender" required>
                <option value="">Choose an option</option>
                <?php foreach ($genders as $gender): ?>
                  <option value="<?= e($gender) ?>"<?= $values['gender'] === $gender ? ' selected' : '' ?>><?= e(ucfirst($gender)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="admin-form-field">
              <label for="student-dob">Date of birth</label>
              <input type="date" id="student-dob" name="date_of_birth" value="<?= e($values['date_of_birth']) ?>" max="<?= e(date('Y-m-d')) ?>">
            </div>
            <div class="admin-form-field">
              <label for="student-class">Class <span aria-hidden="true">*</span></label>
              <select id="student-class" name="class_id" required>
                <option value="">Choose a class</option>
                <?php foreach ($classes as $class): ?>
                  <option value="<?= (int)$class['id'] ?>"<?= (string)$class['id'] === $values['class_id'] ? ' selected' : '' ?>><?= e($class['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="admin-form-field">
              <label for="guardian-name">Guardian name</label>
              <input type="text" id="guardian-name" name="guardian_name" value="<?= e($values['guardian_name']) ?>" maxlength="100">
            </div>
            <div class="admin-form-field">
              <label for="guardian-phone">Guardian phone</label>
              <input type="tel" id="guardian-phone" name="guardian_phone" value="<?= e($values['guardian_phone']) ?>" maxlength="20">
            </div>
            <div class="admin-form-field">
              <label for="guardian-email">Guardian email</label>
              <input type="email" id="guardian-email" name="guardian_email" value="<?= e($values['guardian_email']) ?>" maxlength="100">
            </div>
            <div class="admin-form-field">
              <label for="student-status">Status <span aria-hidden="true">*</span></label>
              <select id="student-status" name="status" required>
                <?php foreach ($statuses as $status): ?>
                  <option value="<?= e($status) ?>"<?= $values['status'] === $status ? ' selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="admin-form-field admin-form-field-wide">
              <label for="student-address">Address</label>
              <input type="text" id="student-address" name="address" value="<?= e($values['address']) ?>" maxlength="150" autocomplete="street-address">
            </div>
          </div>
          <div class="admin-form-actions">
            <button class="admin-primary-link" type="submit"><?= $id === null ? 'Add student' : 'Save changes' ?></button>
            <a class="admin-secondary-link" href="<?= BASE_URL ?>admin/students.php">Cancel</a>
          </div>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>

<?php include __DIR__ . '/../includes/staff-footer.php'; ?>
