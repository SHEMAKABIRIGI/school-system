<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$role = $_SESSION['role'] ?? '';
$pageTitle = $pageTitle ?? 'Dashboard';
$script = $_SERVER['SCRIPT_NAME'];

$menus = [
    'admin' => [
        ['admin/dashboard.php', 'Dashboard'],
        ['admin/students.php', 'Students'],
        ['admin/teachers.php', 'Teachers'],
        ['admin/classes.php', 'Classes'],
        ['admin/assign.php', 'Assign teachers'],
        ['admin/registrations.php', 'Registrations'],
        ['admin/news.php', 'News'],
        ['admin/gallery.php', 'Gallery'],
        ['admin/messages.php', 'Messages'],
        ['admin/users.php', 'Users'],
        ['admin/settings.php', 'Settings'],
    ],
    'teacher' => [
        ['teacher/dashboard.php', 'Dashboard'],
        ['teacher/classes.php', 'My classes'],
        ['teacher/marks-enter.php', 'Enter marks'],
        ['teacher/results.php', 'Results'],
    ],
    'head' => [
        ['head/dashboard.php', 'Dashboard'],
        ['head/results-class.php', 'By class'],
        ['head/results-subject.php', 'By subject'],
        ['head/teachers.php', 'Teachers'],
        ['head/students.php', 'Students'],
        ['head/reports.php', 'Reports'],
    ],
];
$menu = $menus[$role] ?? [];
$roleNames = ['admin' => 'Admin', 'teacher' => 'Teacher', 'head' => 'Head teacher'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?> - <?= e(SITE_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/dashboard.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/responsive.css">
</head>
<body class="staff">
<header class="site-header">
  <div class="bar">
    <a class="brand" href="<?= e(dashboard_url($role)) ?>"><?= e(SITE_NAME) ?></a>
    <button class="menu-btn" type="button" aria-label="Open menu" aria-expanded="false">Menu</button>
  </div>
  <nav class="nav" aria-label="Staff menu">
    <?php foreach ($menu as [$path, $label]): ?>
      <a href="<?= BASE_URL . $path ?>"<?= substr($script, -strlen($path)) === $path ? ' class="active"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
    <span class="nav-sep"></span>
    <a href="<?= BASE_URL ?>profile.php">Profile</a>
    <a href="<?= BASE_URL ?>change-password.php">Password</a>
    <a href="<?= BASE_URL ?>logout.php">Logout</a>
  </nav>
  <div class="user-chip"><?= e($_SESSION['full_name'] ?? '') ?> &middot; <?= e($roleNames[$role] ?? '') ?></div>
</header>
