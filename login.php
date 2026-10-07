<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
// TODO: check username and password_verify(), then set $_SESSION['user_id'] and $_SESSION['role']
// Then redirect: admin -> admin/dashboard.php, teacher -> teacher/dashboard.php, head -> head/dashboard.php
include 'includes/header.php';
?>

<main>
  <h1>Staff login</h1>
  <!-- TODO: login form -->
</main>

<?php include 'includes/footer.php'; ?>
