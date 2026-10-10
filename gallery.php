<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'School gallery';
$perPage = 12;
$requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$page = $requestedPage === false ? 1 : $requestedPage;
$photos = [];
$totalPhotos = 0;
$totalPages = 0;
$loadError = false;

try {
    $totalPhotos = (int) db_value('SELECT COUNT(*) FROM gallery');
    $totalPages = (int) ceil($totalPhotos / $perPage);
    if ($totalPages > 0 && $page > $totalPages) {
        $page = $totalPages;
    }

    $stmt = db()->prepare(
        'SELECT title, caption, image_path, created_at
           FROM gallery
          ORDER BY created_at DESC, id DESC
          LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $photos = $stmt->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $loadError = true;
}

include __DIR__ . '/includes/header.php';
?>

<main class="gallery-page">
  <section class="gallery-hero">
    <div class="gallery-hero-inner">
      <p class="gallery-eyebrow">A glimpse of school life</p>
      <h1>Moments worth sharing.</h1>
      <p>Take a look at the people, activities, and everyday moments that make up our school community.</p>
    </div>
    <div class="gallery-hero-art" aria-hidden="true">
      <span class="gallery-art-frame gallery-art-frame-one"></span>
      <span class="gallery-art-frame gallery-art-frame-two"></span>
      <span class="gallery-art-sun"></span>
    </div>
  </section>

  <section class="gallery-content" aria-labelledby="gallery-title">
    <div class="gallery-section-heading">
      <div>
        <p class="gallery-kicker">School life in pictures</p>
        <h2 id="gallery-title">Our gallery</h2>
      </div>
      <?php if (!$loadError && $totalPhotos > 0): ?>
        <p class="gallery-count"><?= $totalPhotos ?> <?= $totalPhotos === 1 ? 'photo' : 'photos' ?></p>
      <?php endif; ?>
    </div>

    <?php if ($loadError): ?>
      <div class="alert alert-error" role="alert">
        The gallery is temporarily unavailable. Please try again later.
      </div>
    <?php elseif (!$photos): ?>
      <div class="gallery-empty">
        <span class="gallery-empty-icon" aria-hidden="true">+</span>
        <h3>New moments are on the way</h3>
        <p>There are no gallery photos to show just yet. Please check back soon.</p>
      </div>
    <?php else: ?>
      <div class="gallery-grid">
        <?php foreach ($photos as $photo): ?>
          <?php
            $imagePath = trim((string)$photo['image_path']);
            $hasImage = preg_match('~\Auploads/gallery/[A-Za-z0-9_-]+\.(?:jpe?g|png|webp)\z~iD', $imagePath) === 1;
          ?>
          <figure class="gallery-card">
            <?php if ($hasImage): ?>
              <a class="gallery-photo" href="<?= BASE_URL . e($imagePath) ?>" target="_blank" rel="noopener" aria-label="Open photo: <?= e($photo['title']) ?>">
                <img src="<?= BASE_URL . e($imagePath) ?>" alt="<?= e($photo['title']) ?>" loading="lazy">
                <span class="gallery-photo-open" aria-hidden="true">View photo</span>
              </a>
            <?php else: ?>
              <div class="gallery-photo gallery-photo-placeholder" aria-hidden="true">
                <span><?= e(SITE_NAME) ?></span>
              </div>
            <?php endif; ?>
            <figcaption class="gallery-caption">
              <h3><?= e($photo['title']) ?></h3>
              <?php if (!empty($photo['caption'])): ?>
                <p><?= e($photo['caption']) ?></p>
              <?php endif; ?>
              <time datetime="<?= e(date('Y-m-d', strtotime($photo['created_at']))) ?>">
                <?= e(date('F j, Y', strtotime($photo['created_at']))) ?>
              </time>
            </figcaption>
          </figure>
        <?php endforeach; ?>
      </div>

      <?php if ($totalPages > 1): ?>
        <nav class="gallery-pagination" aria-label="Gallery pages">
          <?php if ($page > 1): ?>
            <a class="gallery-page-link" href="<?= BASE_URL ?>gallery.php?page=<?= $page - 1 ?>">
              <span aria-hidden="true">&larr;</span> Newer photos
            </a>
          <?php else: ?>
            <span></span>
          <?php endif; ?>
          <span class="gallery-page-status">Page <?= $page ?> of <?= $totalPages ?></span>
          <?php if ($page < $totalPages): ?>
            <a class="gallery-page-link" href="<?= BASE_URL ?>gallery.php?page=<?= $page + 1 ?>">
              Older photos <span aria-hidden="true">&rarr;</span>
            </a>
          <?php endif; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="gallery-contact">
    <div>
      <p class="gallery-kicker">Want to know more?</p>
      <h2>We would love to hear from you.</h2>
    </div>
    <a href="<?= BASE_URL ?>contact.php">Contact SDMS <span aria-hidden="true">&rarr;</span></a>
  </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
