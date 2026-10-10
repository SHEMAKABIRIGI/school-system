<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('head');
$pageTitle = 'Head teacher dashboard';

$students = (int)db_value("SELECT COUNT(*) FROM students WHERE status = 'active'");
$teachers = (int)db_value('SELECT COUNT(*) FROM teachers');
$classes  = (int)db_value('SELECT COUNT(*) FROM classes');
$term     = db_value('SELECT name FROM terms WHERE is_current = 1 LIMIT 1');

include __DIR__ . '/../includes/staff-header.php';
?>

<main>
  <h1>Welcome, <?= e($_SESSION['full_name']) ?></h1>
  <div class="grid">
    <a class="tile" href="students.php"><span class="num"><?= $students ?></span><span>Active students</span></a>
    <a class="tile" href="teachers.php"><span class="num"><?= $teachers ?></span><span>Teachers</span></a>
    <a class="tile" href="results-class.php"><span class="num"><?= $classes ?></span><span>Classes</span></a>
    <div class="tile"><span class="num small"><?= e($term ?: '-') ?></span><span>Current term</span></div>
  </div>
</main>

<?php include __DIR__ . '/../includes/staff-footer.php'; ?>
