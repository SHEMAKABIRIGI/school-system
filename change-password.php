<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$pageTitle = 'Change password';
$userId = filter_var($_SESSION['user_id'] ?? '', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$errors = [];
$successMessage = '';
$loadError = false;
$notFound = false;
$currentPassword = '';
$newPassword = '';
$passwordConfirm = '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $successMessage = $_SESSION['change_password_success'] ?? '';
    unset($_SESSION['change_password_success']);
}

if ($userId === false) {
    $notFound = true;
    http_response_code(404);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$notFound) {
    $token = $_POST['csrf'] ?? null;
    if (!is_string($token) || !csrf_check($token)) {
        $errors[] = 'Your form expired. Please try again.';
    }

    $currentInput = $_POST['current_password'] ?? '';
    $newInput = $_POST['new_password'] ?? '';
    $confirmInput = $_POST['password_confirm'] ?? '';
    $currentPassword = is_string($currentInput) ? $currentInput : '';
    $newPassword = is_string($newInput) ? $newInput : '';
    $passwordConfirm = is_string($confirmInput) ? $confirmInput : '';

    if ($currentPassword === '' || strlen($currentPassword) > 255) {
        $errors[] = 'Enter your current password.';
    }
    if (strlen($newPassword) < 12 || strlen($newPassword) > 72) {
        $errors[] = 'Your new password must be between 12 and 72 characters.';
    }
    if ($newPassword !== '' && !hash_equals($newPassword, $passwordConfirm)) {
        $errors[] = 'The new password confirmation does not match.';
    }
    if ($currentPassword !== '' && $newPassword !== '' && hash_equals($currentPassword, $newPassword)) {
        $errors[] = 'Choose a new password that is different from your current password.';
    }

    if (!$errors) {
        try {
            $pdo = db();
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();
            if (!$user) {
                $notFound = true;
                http_response_code(404);
            } elseif (!password_verify($currentPassword, $user['password_hash'])) {
                $errors[] = 'Your current password is incorrect.';
            } else {
                $update = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
                $update->execute([
                    ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                    ':id' => $userId,
                ]);
                session_regenerate_id(true);
                $_SESSION['change_password_success'] = 'Your password has been changed.';
                redirect(BASE_URL . 'change-password.php');
            }
        } catch (PDOException $ex) {
            error_log($ex->getMessage());
            $errors[] = 'Your password could not be changed. Please try again.';
        }
    }
}

include __DIR__ . '/includes/staff-header.php';
?>

<main class="admin-page">
  <div class="admin-page-heading">
    <div>
      <p class="admin-eyebrow">Account security</p>
      <h1>Change password</h1>
      <p>Verify your current password before choosing a new one.</p>
    </div>
    <a class="admin-secondary-link" href="<?= BASE_URL ?>profile.php">Back to profile</a>
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
    <div class="admin-alert admin-alert-error" role="alert">The password service is temporarily unavailable.</div>
  <?php elseif ($notFound): ?>
    <div class="admin-alert admin-alert-error" role="alert">Your account could not be found. Please sign in again.</div>
  <?php else: ?>
    <section class="admin-panel admin-form-panel" aria-labelledby="password-form-title">
      <h2 id="password-form-title">Set a new password</h2>
      <form method="post" action="<?= BASE_URL ?>change-password.php" autocomplete="on">
        <?= csrf_field() ?>
        <div class="admin-form-grid">
          <div class="admin-form-field admin-form-field-wide">
            <label for="current-password">Current password <span aria-hidden="true">*</span></label>
            <input type="password" id="current-password" name="current_password" maxlength="255" autocomplete="current-password" required>
          </div>
          <div class="admin-form-field">
            <label for="new-password">New password <span aria-hidden="true">*</span></label>
            <input type="password" id="new-password" name="new_password" minlength="12" maxlength="72" autocomplete="new-password" required>
            <small>Use at least 12 characters.</small>
          </div>
          <div class="admin-form-field">
            <label for="password-confirm">Confirm new password <span aria-hidden="true">*</span></label>
            <input type="password" id="password-confirm" name="password_confirm" minlength="12" maxlength="72" autocomplete="new-password" required>
          </div>
        </div>
        <div class="admin-form-actions">
          <button class="admin-primary-link" type="submit">Change password</button>
          <a class="admin-secondary-link" href="<?= BASE_URL ?>profile.php">Cancel</a>
        </div>
      </form>
    </section>
  <?php endif; ?>
</main>

<?php include __DIR__ . '/includes/staff-footer.php'; ?>
