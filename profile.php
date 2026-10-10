<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$pageTitle = 'My profile';
$userId = filter_var($_SESSION['user_id'] ?? '', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$values = [
    'full_name' => '',
    'username' => '',
    'email' => '',
    'phone' => '',
];
$errors = [];
$successMessage = '';
$loadError = false;
$notFound = false;
$characterLength = static function (string $value): int {
    if (preg_match_all('/./us', $value, $matches) === false) {
        return strlen($value);
    }
    return count($matches[0]);
};

if ($userId === false) {
    $notFound = true;
    http_response_code(404);
} else {
    try {
        $stmt = db()->prepare(
            'SELECT full_name, username, email, phone, role, created_at
               FROM users
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) {
            $notFound = true;
            http_response_code(404);
        } else {
            foreach ($values as $field => $default) {
                $values[$field] = (string)($user[$field] ?? '');
            }
        }
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        $loadError = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$loadError && !$notFound) {
    $token = $_POST['csrf'] ?? null;
    if (!is_string($token) || !csrf_check($token)) {
        $errors[] = 'Your form expired. Please try again.';
    }

    foreach ($values as $field => $default) {
        $submitted = $_POST[$field] ?? '';
        $values[$field] = is_string($submitted) ? trim($submitted) : '';
    }

    if ($values['full_name'] === '' || $characterLength($values['full_name']) > 100) {
        $errors[] = 'Full name is required and must be no longer than 100 characters.';
    }
    if (
        preg_match('/\A[A-Za-z0-9._-]{3,50}\z/', $values['username']) !== 1
    ) {
        $errors[] = 'Username must be 3 to 50 characters and use only letters, numbers, dots, underscores, or hyphens.';
    }
    if (
        $values['email'] === ''
        || $characterLength($values['email']) > 100
        || filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false
    ) {
        $errors[] = 'Enter a valid email address of no more than 100 characters.';
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

    if (!$errors) {
        try {
            $exists = db()->prepare(
                'SELECT id FROM users
                  WHERE (username = :username OR email = :email) AND id <> :id
                  LIMIT 1'
            );
            $exists->execute([
                ':username' => $values['username'],
                ':email' => $values['email'],
                ':id' => $userId,
            ]);
            if ($exists->fetchColumn()) {
                $errors[] = 'That username or email address is already in use.';
            } else {
                $update = db()->prepare(
                    'UPDATE users
                        SET full_name = :full_name, username = :username, email = :email, phone = :phone
                      WHERE id = :id'
                );
                $update->execute([
                    ':full_name' => $values['full_name'],
                    ':username' => $values['username'],
                    ':email' => $values['email'],
                    ':phone' => $values['phone'] === '' ? null : $values['phone'],
                    ':id' => $userId,
                ]);
                $_SESSION['full_name'] = $values['full_name'];
                $successMessage = 'Your profile has been updated.';
            }
        } catch (PDOException $ex) {
            error_log($ex->getMessage());
            $errors[] = $ex->getCode() === '23000'
                ? 'That username or email address is already in use.'
                : 'Your profile could not be updated. Please try again.';
        }
    }
}

include __DIR__ . '/includes/staff-header.php';
?>

<main class="admin-page">
  <div class="admin-page-heading">
    <div>
      <p class="admin-eyebrow">Account</p>
      <h1>My profile</h1>
      <p>Update your personal contact details and sign-in username.</p>
    </div>
    <a class="admin-secondary-link" href="<?= BASE_URL ?>change-password.php">Change password</a>
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
    <div class="admin-alert admin-alert-error" role="alert">Your profile could not be loaded. Please try again.</div>
  <?php elseif ($notFound): ?>
    <div class="admin-alert admin-alert-error" role="alert">Your account could not be found. Please sign in again.</div>
  <?php else: ?>
    <section class="admin-panel admin-form-panel" aria-labelledby="profile-details-title">
      <h2 id="profile-details-title">Profile details</h2>
      <form method="post" action="<?= BASE_URL ?>profile.php">
        <?= csrf_field() ?>
        <div class="admin-form-grid">
          <div class="admin-form-field">
            <label for="profile-full-name">Full name <span aria-hidden="true">*</span></label>
            <input type="text" id="profile-full-name" name="full_name" value="<?= e($values['full_name']) ?>" maxlength="100" autocomplete="name" required>
          </div>
          <div class="admin-form-field">
            <label for="profile-username">Username <span aria-hidden="true">*</span></label>
            <input type="text" id="profile-username" name="username" value="<?= e($values['username']) ?>" minlength="3" maxlength="50" pattern="[A-Za-z0-9._\-]{3,50}" autocomplete="username" required>
            <small>Use 3–50 letters, numbers, dots, underscores, or hyphens.</small>
          </div>
          <div class="admin-form-field">
            <label for="profile-email">Email address <span aria-hidden="true">*</span></label>
            <input type="email" id="profile-email" name="email" value="<?= e($values['email']) ?>" maxlength="100" autocomplete="email" required>
          </div>
          <div class="admin-form-field">
            <label for="profile-phone">Phone</label>
            <input type="tel" id="profile-phone" name="phone" value="<?= e($values['phone']) ?>" maxlength="20" autocomplete="tel">
          </div>
        </div>
        <div class="admin-form-actions">
          <button class="admin-primary-link" type="submit">Save profile</button>
          <a class="admin-secondary-link" href="<?= e(dashboard_url($_SESSION['role'] ?? '')) ?>">Back to dashboard</a>
        </div>
      </form>
    </section>
    <p class="admin-help-text">Your role and account access are managed by an administrator. Password changes are handled separately.</p>
  <?php endif; ?>
</main>

<?php include __DIR__ . '/includes/staff-footer.php'; ?>
