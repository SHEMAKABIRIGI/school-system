<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('admin');
$pageTitle = 'Admin dashboard';

$students = (int)db_value("SELECT COUNT(*) FROM students WHERE status = 'active'");
$teachers = (int)db_value('SELECT COUNT(*) FROM teachers');
$pending  = (int)db_value("SELECT COUNT(*) FROM registrations WHERE status = 'pending'");
$unread   = (int)db_value('SELECT COUNT(*) FROM messages WHERE is_read = 0');

include __DIR__ . '/../includes/staff-header.php';
?>

<main>
  <h1>Welcome, <?= e($_SESSION['full_name']) ?></h1>
  <div class="grid">
    <a class="tile" href="students.php"><span class="num"><?= $students ?></span><span>Active students</span></a>
    <a class="tile" href="teachers.php"><span class="num"><?= $teachers ?></span><span>Teachers</span></a>
    <a class="tile" href="registrations.php"><span class="num"><?= $pending ?></span><span>Pending registrations</span></a>
    <a class="tile" href="messages.php"><span class="num"><?= $unread ?></span><span>Unread messages</span></a>
  </div>
</main>

<?php include __DIR__ . '/../includes/staff-footer.php'; ?>
