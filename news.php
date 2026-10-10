<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'News and updates';
$perPage = 6;
$requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$page = $requestedPage === false ? 1 : $requestedPage;
$newsItems = [];
$totalPages = 0;
$loadError = false;

try {
    $totalItems = (int) db_value(
        'SELECT COUNT(*) FROM news WHERE is_published = 1 AND published_at <= NOW()'
    );
    $totalPages = (int) ceil($totalItems / $perPage);
    if ($totalPages > 0 && $page > $totalPages) {
        $page = $totalPages;
    }

    $stmt = db()->prepare(
        'SELECT id, title, summary, image, published_at
           FROM news
          WHERE is_published = 1 AND published_at <= NOW()
          ORDER BY published_at DESC, id DESC
          LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $newsItems = $stmt->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $loadError = true;
}

include __DIR__ . '/includes/header.php';
?>

<main class="news-page">
  <section class="news-hero">
    <div class="news-hero-inner">
      <p class="news-eyebrow">From our school</p>
      <h1>News &amp; updates</h1>
      <p>Stories, announcements, and moments from the SDMS community.</p>
    </div>
    <span class="news-hero-mark" aria-hidden="true">N</span>
  </section>

  <section class="news-content" aria-labelledby="news-list-title">
    <div class="news-section-heading">
      <div>
        <p class="news-kicker">Stay in the loop</p>
        <h2 id="news-list-title">The latest from SDMS</h2>
      </div>
      <?php if (!$loadError && $totalItems > 0): ?>
        <p class="news-count"><?= $totalItems ?> <?= $totalItems === 1 ? 'story' : 'stories' ?></p>
      <?php endif; ?>
    </div>

    <?php if ($loadError): ?>
      <div class="alert alert-error" role="alert">
        News is temporarily unavailable. Please try again later.
      </div>
    <?php elseif (!$newsItems): ?>
      <div class="news-empty">
        <span aria-hidden="true">N</span>
        <h3>No news to share just yet</h3>
        <p>Please check back soon for school updates and stories.</p>
      </div>
    <?php else: ?>
      <div class="news-grid">
        <?php foreach ($newsItems as $index => $item): ?>
          <?php
            $imagePath = trim((string)($item['image'] ?? ''));
            $hasImage = preg_match('~\Auploads/news/[A-Za-z0-9._-]+\z~D', $imagePath) === 1;
          ?>
          <article class="news-card<?= $index === 0 && $page === 1 ? ' news-card-featured' : '' ?>">
            <a class="news-card-image<?= $hasImage ? ' has-image' : '' ?>" href="<?= BASE_URL ?>news-detail.php?id=<?= (int)$item['id'] ?>" tabindex="-1" aria-hidden="true">
              <?php if ($hasImage): ?>
                <img src="<?= BASE_URL . e($imagePath) ?>" alt="" loading="lazy">
              <?php else: ?>
                <span class="news-card-art" aria-hidden="true">SDMS</span>
              <?php endif; ?>
            </a>
            <div class="news-card-body">
              <time class="news-date" datetime="<?= e(date('Y-m-d', strtotime($item['published_at']))) ?>">
                <?= e(date('F j, Y', strtotime($item['published_at']))) ?>
              </time>
              <h3><a href="<?= BASE_URL ?>news-detail.php?id=<?= (int)$item['id'] ?>"><?= e($item['title']) ?></a></h3>
              <?php if (!empty($item['summary'])): ?>
                <p><?= e($item['summary']) ?></p>
              <?php endif; ?>
              <a class="news-read-link" href="<?= BASE_URL ?>news-detail.php?id=<?= (int)$item['id'] ?>">
                Read story <span aria-hidden="true">&rarr;</span>
              </a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <?php if ($totalPages > 1): ?>
        <nav class="news-pagination" aria-label="News pages">
          <?php if ($page > 1): ?>
            <a class="news-page-link" href="<?= BASE_URL ?>news.php?page=<?= $page - 1 ?>">
              <span aria-hidden="true">&larr;</span> Newer stories
            </a>
          <?php else: ?>
            <span></span>
          <?php endif; ?>
          <span class="news-page-status">Page <?= $page ?> of <?= $totalPages ?></span>
          <?php if ($page < $totalPages): ?>
            <a class="news-page-link" href="<?= BASE_URL ?>news.php?page=<?= $page + 1 ?>">
              Older stories <span aria-hidden="true">&rarr;</span>
            </a>
          <?php endif; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="news-contact">
    <div>
      <p class="news-kicker">Have a question?</p>
      <h2>We are happy to help.</h2>
    </div>
    <a class="news-contact-link" href="<?= BASE_URL ?>contact.php">Contact our school <span aria-hidden="true">&rarr;</span></a>
  </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
