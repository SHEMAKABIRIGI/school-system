<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('teacher');
$pageTitle = 'Teacher dashboard';

// Only the classes and subjects assigned to THIS teacher, current year
$stmt = db()->prepare(
    'SELECT c.name AS class_name, s.name AS subject_name
       FROM teacher_assignments ta
       JOIN teachers t ON t.id = ta.teacher_id
       JOIN classes  c ON c.id = ta.class_id
       JOIN subjects s ON s.id = ta.subject_id
      WHERE t.user_id = :uid
        AND ta.academic_year_id = (SELECT id FROM academic_years WHERE is_current = 1 LIMIT 1)
      ORDER BY c.name, s.name'
);
$stmt->execute([':uid' => $_SESSION['user_id']]);
$assignments = $stmt->fetchAll();

include __DIR__ . '/../includes/staff-header.php';
?>

<main>
  <h1>Welcome, <?= e($_SESSION['full_name']) ?></h1>

  <div class="card">
    <h2>My classes and subjects</h2>
    <?php if (!$assignments): ?>
      <p>No classes have been assigned to you yet. Please ask the admin.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Class</th><th>Subject</th></tr></thead>
          <tbody>
          <?php foreach ($assignments as $a): ?>
            <tr><td><?= e($a['class_name']) ?></td><td><?= e($a['subject_name']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</main>

<?php include __DIR__ . '/../includes/staff-footer.php'; ?>
