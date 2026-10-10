<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

// Already logged in: go straight to the dashboard of that role.
if (!empty($_SESSION['user_id'])) {
    redirect(dashboard_url($_SESSION['role'] ?? ''));
}

$error  = '';
$notice = isset($_GET['timeout']) ? 'Your session expired. Please log in again.' : '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Your form expired. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Please enter your username and password.';
        } else {
            try {
                $stmt = db()->prepare(
                    'SELECT id, full_name, password_hash, role
                       FROM users
                      WHERE username = :u AND is_active = 1
                      LIMIT 1'
                );
                $stmt->execute([':u' => $username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password_hash'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id']       = (int)$user['id'];
                    $_SESSION['role']          = $user['role'];
                    $_SESSION['full_name']     = $user['full_name'];
                    $_SESSION['last_activity'] = time();

                    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = :id')
                        ->execute([':id' => $user['id']]);

                    redirect(dashboard_url($user['role']));
                }
                $error = 'Wrong username or password.';
            } catch (PDOException $ex) {
                error_log($ex->getMessage());
                $error = 'Could not reach the database. Check that MySQL is running and that includes/config.php is correct.';
            }
        }
    }
}

$pageTitle = 'Staff login';
include __DIR__ . '/includes/header.php';
?>

<main>
  <div class="card login-box">
    <h1>Staff login</h1>

    <?php if ($notice): ?><div class="alert alert-info"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error):  ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <form method="post" action="login.php" autocomplete="off">
      <?= csrf_field() ?>
      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" value="<?= e($username) ?>" required autocomplete="off" autocapitalize="none" spellcheck="false">
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="new-password">
      </div>
      <button class="btn" type="submit">Log in</button>
    </form>
  </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
