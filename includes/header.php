<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$pageTitle = $pageTitle ?? SITE_NAME;
$current = basename($_SERVER['SCRIPT_NAME']);
$links = [
    'index.php'    => 'Home',
    'about.php'    => 'About',
    'news.php'     => 'News',
    'gallery.php'  => 'Gallery',
    'contact.php'  => 'Contact',
    'register.php' => 'Register',
    'login.php'    => 'Login',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?> - <?= e(SITE_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/responsive.css">
</head>
<body>
<header class="site-header">
  <div class="bar">
    <a class="brand" href="<?= BASE_URL ?>index.php"><?= e(SITE_NAME) ?></a>
    <button class="menu-btn" type="button" aria-label="Open menu" aria-expanded="false">Menu</button>
  </div>
  <nav class="nav" aria-label="Main menu">
    <?php foreach ($links as $file => $label): ?>
      <a href="<?= BASE_URL . $file ?>"<?= $current === $file ? ' class="active"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
</header>
