<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'Student application';
$values = [
    'first_name' => '',
    'last_name' => '',
    'gender' => '',
    'date_of_birth' => '',
    'previous_school' => '',
    'class_applied' => '',
    'guardian_name' => '',
    'guardian_phone' => '',
    'guardian_email' => '',
    'address' => '',
];
$errors = [];
$successMessage = $_SESSION['registration_success'] ?? '';
unset($_SESSION['registration_success']);
$classLevels = [];
$classLevelsUnavailable = false;
try {
    $classLevels = db()->query('SELECT DISTINCT level FROM classes ORDER BY level')->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $classLevelsUnavailable = true;
}
$characterLength = static function (string $value): int {
    if (preg_match_all('/./us', $value, $matches) === false) {
        return strlen($value);
    }
    return count($matches[0]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $field => $default) {
        $submitted = $_POST[$field] ?? '';
        $values[$field] = is_string($submitted) ? trim($submitted) : '';
    }

    $token = $_POST['csrf'] ?? null;
    if (!is_string($token) || !csrf_check($token)) {
        $errors[] = 'Your form expired. Please reload the page and try again.';
    }
    if ($classLevelsUnavailable) {
        $errors[] = 'We cannot load the available classes right now. Please try again later.';
    }

    $requiredNames = [
        'first_name' => ['First name', 60],
        'last_name' => ['Last name', 60],
        'guardian_name' => ['Parent or guardian name', 100],
    ];
    foreach ($requiredNames as $field => [$label, $limit]) {
        if ($values[$field] === '' || $characterLength($values[$field]) > $limit) {
            $errors[] = $label . ' is required and must be no longer than ' . $limit . ' characters.';
        }
    }

    if (!in_array($values['gender'], ['male', 'female'], true)) {
        $errors[] = 'Choose a valid gender.';
    }

    $dateOfBirth = DateTimeImmutable::createFromFormat('!Y-m-d', $values['date_of_birth']);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (
        $dateOfBirth === false
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $dateOfBirth->format('Y-m-d') !== $values['date_of_birth']
    ) {
        $errors[] = 'Enter a valid date of birth.';
    } elseif ($dateOfBirth > new DateTimeImmutable('today')) {
        $errors[] = 'Date of birth cannot be in the future.';
    }

    if ($values['previous_school'] !== '' && $characterLength($values['previous_school']) > 120) {
        $errors[] = 'Previous school must be no longer than 120 characters.';
    }
    if ($values['class_applied'] === '' || !in_array($values['class_applied'], $classLevels, true)) {
        $errors[] = 'Choose a requested class level from the available options.';
    }
    if (
        $values['guardian_phone'] === ''
        || $characterLength($values['guardian_phone']) > 20
        || preg_match('/\A[0-9+().\s-]{7,20}\z/', $values['guardian_phone']) !== 1
        || strlen(preg_replace('/[^0-9]/', '', $values['guardian_phone'])) < 7
    ) {
        $errors[] = 'Enter a valid parent or guardian phone number (7 to 20 characters).';
    }
    if (
        $values['guardian_email'] !== ''
        && (
            $characterLength($values['guardian_email']) > 100
            || filter_var($values['guardian_email'], FILTER_VALIDATE_EMAIL) === false
        )
    ) {
        $errors[] = 'Enter a valid parent or guardian email address using no more than 100 characters.';
    }
    if ($values['address'] !== '' && $characterLength($values['address']) > 150) {
        $errors[] = 'Address must be no longer than 150 characters.';
    }

    if (!$errors) {
        try {
            $stmt = db()->prepare(
                'INSERT INTO registrations (
                    first_name, last_name, gender, date_of_birth, previous_school,
                    class_applied, guardian_name, guardian_phone, guardian_email, address
                ) VALUES (
                    :first_name, :last_name, :gender, :date_of_birth, :previous_school,
                    :class_applied, :guardian_name, :guardian_phone, :guardian_email, :address
                )'
            );
            $stmt->execute([
                ':first_name' => $values['first_name'],
                ':last_name' => $values['last_name'],
                ':gender' => $values['gender'],
                ':date_of_birth' => $values['date_of_birth'],
                ':previous_school' => $values['previous_school'] === '' ? null : $values['previous_school'],
                ':class_applied' => $values['class_applied'],
                ':guardian_name' => $values['guardian_name'],
                ':guardian_phone' => $values['guardian_phone'],
                ':guardian_email' => $values['guardian_email'] === '' ? null : $values['guardian_email'],
                ':address' => $values['address'] === '' ? null : $values['address'],
            ]);
            $_SESSION['registration_success'] = 'Your application has been received and is pending review. Please wait for an administrator to review it and contact the parent or guardian with a decision. Thank you for applying to ' . SITE_NAME . '.';
            redirect(BASE_URL . 'register.php');
        } catch (PDOException $ex) {
            error_log($ex->getMessage());
            $errors[] = 'We could not submit your application right now. Please try again later.';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<main class="registration-page">
  <section class="registration-hero">
    <div class="registration-hero-inner">
      <p class="registration-eyebrow">Join our school community</p>
      <h1>Start your application.</h1>
      <p>Share a few details with us. Our school team will review the application and follow up with the parent or guardian.</p>
    </div>
    <span class="registration-hero-mark" aria-hidden="true">01</span>
  </section>

  <section class="registration-content" aria-labelledby="registration-form-title">
    <div class="registration-intro">
      <p class="registration-kicker">Student application</p>
      <h2>Tell us about the student.</h2>
      <p>Fields marked <span aria-hidden="true">*</span> are required. The requested class helps us understand which year is being applied for; placement is reviewed by the school.</p>
      <div class="registration-status-note">
        <span class="registration-status-icon" aria-hidden="true">i</span>
        <p>After you submit this form, the application is recorded as <strong>pending</strong> until the school reviews it.</p>
      </div>
    </div>

    <div class="registration-form-wrap">
      <h2 id="registration-form-title">Application details</h2>
      <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success" role="status"><?= e($successMessage) ?></div>
      <?php endif; ?>
      <?php if ($errors): ?>
        <div class="alert alert-error" role="alert">
          <p>Please check the following:</p>
          <ul>
            <?php foreach ($errors as $error): ?>
              <li><?= e($error) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form class="registration-form" method="post" action="<?= BASE_URL ?>register.php" data-validate>
        <?= csrf_field() ?>
        <fieldset class="registration-fieldset">
          <legend>Student information</legend>
          <div class="registration-grid">
            <div class="registration-field">
              <label for="first-name">First name <span aria-hidden="true">*</span></label>
              <input type="text" id="first-name" name="first_name" value="<?= e($values['first_name']) ?>" maxlength="60" autocomplete="given-name" required>
            </div>
            <div class="registration-field">
              <label for="last-name">Last name <span aria-hidden="true">*</span></label>
              <input type="text" id="last-name" name="last_name" value="<?= e($values['last_name']) ?>" maxlength="60" autocomplete="family-name" required>
            </div>
            <div class="registration-field">
              <label for="gender">Gender <span aria-hidden="true">*</span></label>
              <select id="gender" name="gender" required>
                <option value="">Choose an option</option>
                <option value="female"<?= $values['gender'] === 'female' ? ' selected' : '' ?>>Female</option>
                <option value="male"<?= $values['gender'] === 'male' ? ' selected' : '' ?>>Male</option>
              </select>
            </div>
            <div class="registration-field">
              <label for="date-of-birth">Date of birth <span aria-hidden="true">*</span></label>
              <input type="date" id="date-of-birth" name="date_of_birth" value="<?= e($values['date_of_birth']) ?>" max="<?= e(date('Y-m-d')) ?>" autocomplete="bday" required>
            </div>
            <div class="registration-field">
              <label for="class-applied">Class applied for <span aria-hidden="true">*</span></label>
              <select id="class-applied" name="class_applied" required<?= $classLevelsUnavailable || !$classLevels ? ' disabled' : '' ?>>
                <option value="">Choose a class level</option>
                <?php foreach ($classLevels as $classLevel): ?>
                  <option value="<?= e($classLevel) ?>"<?= $values['class_applied'] === $classLevel ? ' selected' : '' ?>><?= e($classLevel) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="registration-field-hint">Choose from the class levels currently offered by the school.</span>
              <?php if ($classLevelsUnavailable): ?>
                <span class="registration-field-hint">Available class levels could not be loaded. Please try again later.</span>
              <?php elseif (!$classLevels): ?>
                <span class="registration-field-hint">There are no class levels available for applications right now. Please contact the school.</span>
              <?php endif; ?>
            </div>
            <div class="registration-field">
              <label for="previous-school">Previous school <span class="registration-optional">(optional)</span></label>
              <input type="text" id="previous-school" name="previous_school" value="<?= e($values['previous_school']) ?>" maxlength="120" autocomplete="organization">
            </div>
          </div>
        </fieldset>

        <fieldset class="registration-fieldset">
          <legend>Parent or guardian</legend>
          <div class="registration-grid">
            <div class="registration-field">
              <label for="guardian-name">Full name <span aria-hidden="true">*</span></label>
              <input type="text" id="guardian-name" name="guardian_name" value="<?= e($values['guardian_name']) ?>" maxlength="100" autocomplete="name" required>
            </div>
            <div class="registration-field">
              <label for="guardian-phone">Phone number <span aria-hidden="true">*</span></label>
              <input type="tel" id="guardian-phone" name="guardian_phone" value="<?= e($values['guardian_phone']) ?>" maxlength="20" title="Use at least 7 digits and no more than 20 characters." autocomplete="tel" required>
            </div>
            <div class="registration-field">
              <label for="guardian-email">Email address <span class="registration-optional">(optional)</span></label>
              <input type="email" id="guardian-email" name="guardian_email" value="<?= e($values['guardian_email']) ?>" maxlength="100" autocomplete="email">
            </div>
            <div class="registration-field">
              <label for="address">Home address <span class="registration-optional">(optional)</span></label>
              <input type="text" id="address" name="address" value="<?= e($values['address']) ?>" maxlength="150" autocomplete="street-address">
            </div>
          </div>
        </fieldset>

        <p class="registration-privacy">The details you provide will be used by the school to review this application and contact the parent or guardian.</p>
        <button class="registration-submit" type="submit"<?= $classLevelsUnavailable || !$classLevels ? ' disabled' : '' ?>>Submit application <span aria-hidden="true">&rarr;</span></button>
      </form>
    </div>
  </section>
</main>

<script src="<?= BASE_URL ?>assets/js/validation.js?v=<?= filemtime(__DIR__ . '/assets/js/validation.js') ?>" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
