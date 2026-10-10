<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'News story';
$article = null;
$loadError = false;
$articleId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($articleId !== false && $articleId !== null) {
    try {
        $stmt = db()->prepare(
            'SELECT id, title, summary, body, image, published_at
               FROM news
              WHERE id = :id AND is_published = 1 AND published_at <= NOW()
              LIMIT 1'
        );
        $stmt->execute([':id' => $articleId]);
        $article = $stmt->fetch() ?: null;
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        $loadError = true;
        http_response_code(500);
    }
}

if (!$loadError && !$article) {
    http_response_code(404);
}
if ($article) {
    $pageTitle = $article['title'];
}

include __DIR__ . '/includes/header.php';
?>

<main class="news-detail-page">
  <div class="news-detail-wrap">
    <a class="news-back-link" href="<?= BASE_URL ?>news.php"><span aria-hidden="true">&larr;</span> All news</a>

    <?php if ($loadError): ?>
      <section class="news-detail-message" role="alert">
        <p class="news-kicker">Something went wrong</p>
        <h1>This story is unavailable right now.</h1>
        <p>Please try again later or return to the news page.</p>
        <a class="news-detail-button" href="<?= BASE_URL ?>news.php">Back to news</a>
      </section>
    <?php elseif (!$article): ?>
      <section class="news-detail-message">
        <p class="news-kicker">Story not found</p>
        <h1>We could not find that news story.</h1>
        <p>It may have been unpublished or the link may be out of date.</p>
        <a class="news-detail-button" href="<?= BASE_URL ?>news.php">Browse latest news</a>
      </section>
    <?php else: ?>
      <?php
        $imagePath = trim((string)($article['image'] ?? ''));
        $hasImage = preg_match('~\Auploads/news/[A-Za-z0-9._-]+\z~D', $imagePath) === 1;
      ?>
      <article class="news-article">
        <?php if ($hasImage): ?>
          <div class="news-article-image">
            <img src="<?= BASE_URL . e($imagePath) ?>" alt="" fetchpriority="high">
          </div>
        <?php endif; ?>
        <header class="news-article-header">
          <p class="news-kicker">School news</p>
          <time class="news-date" datetime="<?= e(date('Y-m-d', strtotime($article['published_at']))) ?>">
            <?= e(date('F j, Y', strtotime($article['published_at']))) ?>
          </time>
          <h1><?= e($article['title']) ?></h1>
          <?php if (!empty($article['summary'])): ?>
            <p class="news-article-summary"><?= e($article['summary']) ?></p>
          <?php endif; ?>
        </header>
        <div class="news-article-body"><?= nl2br(e($article['body'])) ?></div>
      </article>
      <a class="news-back-link news-back-bottom" href="<?= BASE_URL ?>news.php"><span aria-hidden="true">&larr;</span> Back to all news</a>
    <?php endif; ?>
  </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
