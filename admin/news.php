<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$pageTitle = 'News manager';
$uploadDirectory = __DIR__ . '/../uploads/news';
$relativeUploadDirectory = 'uploads/news';
$maxImageSize = 5 * 1024 * 1024;
$allowedImageTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];
$errors = [];
$successMessage = '';
$newsItem = null;
$items = [];
$statusOptions = ['all', 'published', 'draft', 'scheduled'];
$statusValue = $_GET['status'] ?? 'all';
$statusFilter = is_string($statusValue) && in_array($statusValue, $statusOptions, true)
    ? $statusValue
    : 'all';
$searchValue = $_GET['q'] ?? '';
$search = is_string($searchValue) ? trim($searchValue) : '';
$perPage = 15;
$requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$page = $requestedPage === false ? 1 : $requestedPage;
$totalItems = 0;
$totalPages = 0;
$loadError = false;
$values = [
    'title' => '',
    'summary' => '',
    'body' => '',
    'published_at' => date('Y-m-d\TH:i'),
    'is_published' => false,
];
$characterLength = static function (string $value): int {
    if (preg_match_all('/./us', $value, $matches) === false) {
        return strlen($value);
    }
    return count($matches[0]);
};

$requestedId = array_key_exists('id', $_GET);
$idValue = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$id = $idValue === false ? null : $idValue;
$notFound = $requestedId && $id === null;
if ($notFound) {
    http_response_code(404);
}

$safeImagePath = static function (?string $path): ?string {
    if ($path === null || preg_match('~\Auploads/news/[A-Fa-f0-9]{32}\.(?:jpg|png|gif|webp)\z~D', $path) !== 1) {
        return null;
    }
    return $path;
};
$removeStoredImage = static function (?string $path) use ($safeImagePath): void {
    $safePath = $safeImagePath($path);
    if ($safePath === null) {
        return;
    }
    $fullPath = __DIR__ . '/../' . $safePath;
    if (is_file($fullPath) && !unlink($fullPath)) {
        error_log('Could not remove news image: ' . $fullPath);
    }
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $successMessage = $_SESSION['admin_news_success'] ?? '';
    unset($_SESSION['admin_news_success']);
}

if (!$notFound && $id !== null) {
    try {
        $stmt = db()->prepare(
            'SELECT id, title, summary, body, image, is_published, published_at,
                    (published_at <= NOW()) AS publication_date_reached
               FROM news
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $newsItem = $stmt->fetch();
        if (!$newsItem) {
            $notFound = true;
            http_response_code(404);
        } else {
            $values = [
                'title' => (string)$newsItem['title'],
                'summary' => (string)($newsItem['summary'] ?? ''),
                'body' => (string)$newsItem['body'],
                'published_at' => date('Y-m-d\TH:i', strtotime($newsItem['published_at'])),
                'is_published' => (bool)$newsItem['is_published'],
            ];
        }
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        $loadError = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$loadError && !$notFound) {
    $token = $_POST['csrf'] ?? null;
    $action = $_POST['action'] ?? 'save';
    $postedIdValue = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $postedId = $postedIdValue === false ? null : $postedIdValue;

    if (!is_string($token) || !csrf_check($token)) {
        $errors[] = 'Your form expired. Please reload the page and try again.';
    }
    if ($postedId !== $id) {
        $errors[] = 'The news story changed. Please reload the page and try again.';
    }
    if (!is_string($action) || !in_array($action, ['save', 'delete'], true)) {
        $errors[] = 'The requested news operation is invalid.';
    }

    if ($action === 'save') {
        foreach (['title', 'summary', 'body', 'published_at'] as $field) {
            $submitted = $_POST[$field] ?? '';
            $values[$field] = is_string($submitted) ? trim($submitted) : '';
        }
        $values['is_published'] = isset($_POST['is_published']) && $_POST['is_published'] === '1';

        if ($values['title'] === '' || $characterLength($values['title']) > 150) {
            $errors[] = 'Title is required and must be no longer than 150 characters.';
        }
        if ($values['summary'] !== '' && $characterLength($values['summary']) > 255) {
            $errors[] = 'Summary must be no longer than 255 characters.';
        }
        if ($values['body'] === '') {
            $errors[] = 'Story text is required.';
        }
        $publishedAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $values['published_at']);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (
            $publishedAt === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $publishedAt->format('Y-m-d\TH:i') !== $values['published_at']
        ) {
            $errors[] = 'Choose a valid publication date and time.';
        }
    }

    $uploadedImage = null;
    $upload = $_FILES['image'] ?? null;
    if ($action === 'save' && is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $uploadError = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!is_int($uploadError) || $uploadError !== UPLOAD_ERR_OK) {
            $errors[] = 'The image upload did not complete. Please choose the image again.';
        } elseif (
            !isset($upload['tmp_name'], $upload['size'])
            || !is_string($upload['tmp_name'])
            || !is_int($upload['size'])
            || $upload['size'] < 1
            || $upload['size'] > $maxImageSize
            || !is_uploaded_file($upload['tmp_name'])
        ) {
            $errors[] = 'Choose an image no larger than 5 MB.';
        } else {
            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $fileInfo->file($upload['tmp_name']);
            if (!is_string($mimeType) || !isset($allowedImageTypes[$mimeType])) {
                $errors[] = 'Upload a JPEG, PNG, GIF, or WebP image.';
            } else {
                $uploadedImage = $relativeUploadDirectory . '/' . bin2hex(random_bytes(16)) . '.' . $allowedImageTypes[$mimeType];
            }
        }
    } elseif ($action === 'save' && $upload !== null && !is_array($upload)) {
        $errors[] = 'The selected image upload is invalid.';
    }

    $removeImageValue = $_POST['remove_image'] ?? '';
    $removeImage = is_string($removeImageValue) && $removeImageValue === '1';
    if ($action === 'save' && $uploadedImage !== null && $removeImage) {
        $errors[] = 'Choose either a new image or remove the current image, not both.';
    }

    if (!$errors && $action === 'delete' && $id !== null && $newsItem) {
        try {
            $stmt = db()->prepare('DELETE FROM news WHERE id = :id');
            $stmt->execute([':id' => $id]);
            if ($stmt->rowCount() !== 1) {
                throw new DomainException('That news story could not be found.');
            }
            $removeStoredImage($newsItem['image']);
            $_SESSION['admin_news_success'] = 'News story deleted.';
            redirect(BASE_URL . 'admin/news.php');
        } catch (DomainException $ex) {
            $errors[] = $ex->getMessage();
        } catch (PDOException $ex) {
            error_log($ex->getMessage());
            $errors[] = 'Could not delete the news story. Please try again.';
        }
    } elseif (!$errors && $action === 'save') {
        if ($uploadedImage !== null && (!is_dir($uploadDirectory) || !is_writable($uploadDirectory))) {
            $errors[] = 'The news image folder is not writable. Please contact the site administrator.';
        }
        if (!$errors && $uploadedImage !== null && !move_uploaded_file($upload['tmp_name'], __DIR__ . '/../' . $uploadedImage)) {
            $errors[] = 'The image could not be saved. Please try again.';
        }

        if (!$errors) {
            $oldImage = $newsItem['image'] ?? null;
            $imageValue = $uploadedImage ?? ($removeImage ? null : $oldImage);
            $timestamp = $publishedAt->format('Y-m-d H:i:s');
            try {
                if ($id === null) {
                    $stmt = db()->prepare(
                        'INSERT INTO news (title, summary, body, image, is_published, published_at, created_by)
                         VALUES (:title, :summary, :body, :image, :is_published, :published_at, :created_by)'
                    );
                    $stmt->execute([
                        ':title' => $values['title'],
                        ':summary' => $values['summary'] === '' ? null : $values['summary'],
                        ':body' => $values['body'],
                        ':image' => $imageValue,
                        ':is_published' => $values['is_published'] ? 1 : 0,
                        ':published_at' => $timestamp,
                        ':created_by' => (int)$_SESSION['user_id'],
                    ]);
                    $_SESSION['admin_news_success'] = 'News story created.';
                } else {
                    $stmt = db()->prepare(
                        'UPDATE news
                            SET title = :title, summary = :summary, body = :body, image = :image,
                                is_published = :is_published, published_at = :published_at
                          WHERE id = :id'
                    );
                    $stmt->execute([
                        ':title' => $values['title'],
                        ':summary' => $values['summary'] === '' ? null : $values['summary'],
                        ':body' => $values['body'],
                        ':image' => $imageValue,
                        ':is_published' => $values['is_published'] ? 1 : 0,
                        ':published_at' => $timestamp,
                        ':id' => $id,
                    ]);
                    $_SESSION['admin_news_success'] = 'News story updated.';
                }
                if (($uploadedImage !== null || $removeImage) && $oldImage !== null && $oldImage !== $imageValue) {
                    $removeStoredImage($oldImage);
                }
                redirect(BASE_URL . 'admin/news.php');
            } catch (PDOException $ex) {
                if ($uploadedImage !== null) {
                    $removeStoredImage($uploadedImage);
                }
                error_log($ex->getMessage());
                $errors[] = 'Could not save the news story. Please try again.';
            }
        }
    }
}

if (!$notFound && !$loadError) {
    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = '(title LIKE :title OR summary LIKE :summary OR body LIKE :body)';
        $like = '%' . $search . '%';
        $params[':title'] = $like;
        $params[':summary'] = $like;
        $params[':body'] = $like;
    }
    if ($statusFilter === 'published') {
        $where[] = 'is_published = 1 AND published_at <= NOW()';
    } elseif ($statusFilter === 'scheduled') {
        $where[] = 'is_published = 1 AND published_at > NOW()';
    } elseif ($statusFilter === 'draft') {
        $where[] = 'is_published = 0';
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    try {
        $countStmt = db()->prepare('SELECT COUNT(*) FROM news' . $whereSql);
        $countStmt->execute($params);
        $totalItems = (int)$countStmt->fetchColumn();
        $totalPages = (int)ceil($totalItems / $perPage);
        if ($totalPages > 0 && $page > $totalPages) {
            $page = $totalPages;
        }
        $stmt = db()->prepare(
            "SELECT id, title, summary, image, is_published, published_at, created_at,
                    CASE
                      WHEN is_published = 0 THEN 'Draft'
                      WHEN published_at > NOW() THEN 'Scheduled'
                      ELSE 'Published'
                    END AS publication_status
               FROM news"
            . $whereSql .
            ' ORDER BY published_at DESC, id DESC
              LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll();
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        $loadError = true;
    }
}

$pageUrl = static function (int $targetPage) use ($search, $statusFilter): string {
    $params = ['page' => $targetPage, 'status' => $statusFilter];
    if ($search !== '') {
        $params['q'] = $search;
    }
    return BASE_URL . 'admin/news.php?' . http_build_query($params);
};

$statusLabel = static function (array $item): string {
    return (string)$item['publication_status'];
};
$statusClass = static function (string $status): string {
    return $status === 'Published' ? 'accepted' : ($status === 'Draft' ? 'pending' : 'transferred');
};

if ($id !== null && $newsItem) {
    $pageTitle = 'Edit news story';
} elseif ($id === null) {
    $pageTitle = 'News manager';
}
if ($notFound) {
    $pageTitle = 'News story not found';
}
include __DIR__ . '/../includes/staff-header.php';
?>

<main class="admin-page">
  <div class="admin-page-heading">
    <div>
      <p class="admin-eyebrow">School website</p>
      <h1><?= e($pageTitle) ?></h1>
      <p>Create, schedule, and maintain news stories shown on the public website.</p>
    </div>
    <?php if ($id !== null): ?>
      <a class="admin-secondary-link" href="<?= BASE_URL ?>admin/news.php">Back to news</a>
    <?php endif; ?>
  </div>

  <?php if ($successMessage !== ''): ?>
    <div class="admin-alert admin-alert-success" role="status"><?= e($successMessage) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="admin-alert admin-alert-error" role="alert">
      <?php foreach ($errors as $error): ?><p><?= e($error) ?></p><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($loadError): ?>
    <div class="admin-alert admin-alert-error" role="alert">News stories could not be loaded. Please try again.</div>
  <?php elseif ($notFound): ?>
    <section class="admin-panel admin-empty-state">
      <h2>News story not found</h2>
      <p>The requested story does not exist.</p>
    </section>
  <?php else: ?>
    <section class="admin-panel admin-form-panel" aria-labelledby="news-form-title">
      <h2 id="news-form-title"><?= $id === null ? 'Create a news story' : 'Story details' ?></h2>
      <form method="post" action="<?= BASE_URL ?>admin/news.php<?= $id === null ? '' : '?id=' . $id ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id === null ? '' : (int)$id ?>">
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= $maxImageSize ?>">
        <div class="admin-form-grid">
          <div class="admin-form-field admin-form-field-wide">
            <label for="news-title">Title <span aria-hidden="true">*</span></label>
            <input type="text" id="news-title" name="title" value="<?= e($values['title']) ?>" maxlength="150" required>
          </div>
          <div class="admin-form-field admin-form-field-wide">
            <label for="news-summary">Summary <span>(optional)</span></label>
            <input type="text" id="news-summary" name="summary" value="<?= e($values['summary']) ?>" maxlength="255">
            <small>A short description shown on the news list.</small>
          </div>
          <div class="admin-form-field admin-form-field-wide">
            <label for="news-body">Story <span aria-hidden="true">*</span></label>
            <textarea id="news-body" name="body" rows="10" required><?= e($values['body']) ?></textarea>
            <small>Story text is displayed as plain text on the public site.</small>
          </div>
          <div class="admin-form-field">
            <label for="published-at">Publication date and time <span aria-hidden="true">*</span></label>
            <input type="datetime-local" id="published-at" name="published_at" value="<?= e($values['published_at']) ?>" required>
          </div>
          <div class="admin-form-field">
            <label for="news-image">News image <span>(optional)</span></label>
            <input type="file" id="news-image" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
            <small>JPEG, PNG, GIF, or WebP; maximum size 5 MB.</small>
          </div>
          <?php if ($id !== null && $newsItem && $safeImagePath($newsItem['image']) !== null): ?>
            <div class="admin-form-field admin-form-field-wide">
              <p>Current image:</p>
              <img src="<?= BASE_URL . e($safeImagePath($newsItem['image'])) ?>" alt="" style="display:block;max-width:min(100%,24rem);height:auto;border-radius:8px;">
              <label><input type="checkbox" name="remove_image" value="1"> Remove current image</label>
            </div>
          <?php endif; ?>
          <div class="admin-form-field admin-form-field-wide">
            <label>
              <input type="checkbox" name="is_published" value="1"<?= $values['is_published'] ? ' checked' : '' ?>>
              Publish this story (a future publication date schedules it)
            </label>
          </div>
        </div>
        <div class="admin-form-actions">
          <button class="admin-primary-link" type="submit" name="action" value="save"><?= $id === null ? 'Save story' : 'Save changes' ?></button>
          <?php if ($id !== null && (bool)$newsItem['is_published'] && (bool)$newsItem['publication_date_reached']): ?>
            <a class="admin-secondary-link" href="<?= BASE_URL ?>news-detail.php?id=<?= (int)$id ?>" target="_blank" rel="noopener">Preview public story</a>
          <?php endif; ?>
          <?php if ($id === null): ?>
            <a class="admin-secondary-link" href="<?= BASE_URL ?>admin/news.php">Clear form</a>
          <?php endif; ?>
        </div>
      </form>
      <?php if ($id !== null): ?>
        <form method="post" action="<?= BASE_URL ?>admin/news.php?id=<?= (int)$id ?>" onsubmit="return confirm('Delete this news story and its uploaded image?');">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$id ?>">
          <input type="hidden" name="action" value="delete">
          <div class="admin-form-actions">
            <button class="admin-danger-button" type="submit">Delete story</button>
          </div>
        </form>
      <?php endif; ?>
    </section>

    <section class="admin-panel" aria-labelledby="news-list-title">
      <div class="admin-panel-heading">
        <h2 id="news-list-title">News stories</h2>
        <p><?= $totalItems ?> <?= $totalItems === 1 ? 'story' : 'stories' ?></p>
      </div>
      <form class="admin-filter-form" method="get" action="<?= BASE_URL ?>admin/news.php">
        <div class="admin-filter-field">
          <label for="news-search">Search news</label>
          <input type="search" id="news-search" name="q" value="<?= e($search) ?>" maxlength="100" placeholder="Search title or story">
        </div>
        <div class="admin-filter-field">
          <label for="news-status">Status</label>
          <select id="news-status" name="status">
            <?php foreach ($statusOptions as $option): ?>
              <option value="<?= e($option) ?>"<?= $statusFilter === $option ? ' selected' : '' ?>><?= e(ucfirst($option)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="admin-filter-actions">
          <button class="admin-primary-link" type="submit">Apply filters</button>
          <a class="admin-secondary-link" href="<?= BASE_URL ?>admin/news.php">Clear</a>
        </div>
      </form>

      <?php if (!$items): ?>
        <div class="admin-empty-state">
          <h3>No news stories found</h3>
          <p>Adjust the search or status filter, or create a new story above.</p>
        </div>
      <?php else: ?>
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr>
                <th scope="col">Story</th>
                <th scope="col">Publication date</th>
                <th scope="col">Status</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $item): ?>
                <?php $status = $statusLabel($item); ?>
                <tr>
                  <td data-label="Story">
                    <strong><?= e($item['title']) ?></strong>
                    <?php if ($item['summary']): ?><br><span><?= e($item['summary']) ?></span><?php endif; ?>
                  </td>
                  <td data-label="Publication date"><?= e(date('F j, Y g:i a', strtotime($item['published_at']))) ?></td>
                  <td data-label="Status"><span class="admin-status admin-status-<?= e($statusClass($status)) ?>"><?= e($status) ?></span></td>
                  <td data-label="Actions" class="admin-row-actions">
                    <a class="admin-secondary-link admin-compact-link" href="<?= BASE_URL ?>admin/news.php?id=<?= (int)$item['id'] ?>">Edit</a>
                    <?php if ($status === 'Published'): ?>
                      <a class="admin-secondary-link admin-compact-link" href="<?= BASE_URL ?>news-detail.php?id=<?= (int)$item['id'] ?>" target="_blank" rel="noopener">View</a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($totalPages > 1): ?>
          <nav class="admin-pagination" aria-label="News list pages">
            <p>Page <?= $page ?> of <?= $totalPages ?></p>
            <div>
              <?php if ($page > 1): ?><a class="admin-secondary-link" href="<?= e($pageUrl($page - 1)) ?>">Previous</a><?php endif; ?>
              <?php if ($page < $totalPages): ?><a class="admin-secondary-link" href="<?= e($pageUrl($page + 1)) ?>">Next</a><?php endif; ?>
            </div>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>

<?php include __DIR__ . '/../includes/staff-footer.php'; ?>
