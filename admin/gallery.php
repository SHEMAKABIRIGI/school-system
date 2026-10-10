<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$pageTitle = 'Gallery manager';
$uploadDirectory = __DIR__ . '/../uploads/gallery';
$relativeUploadDirectory = 'uploads/gallery';
$maxImageSize = 5 * 1024 * 1024;
$allowedImageTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];
$errors = [];
$successMessage = '';
$photo = null;
$photos = [];
$perPage = 18;
$searchValue = $_GET['q'] ?? '';
$search = is_string($searchValue) ? trim($searchValue) : '';
$requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$page = $requestedPage === false ? 1 : $requestedPage;
$totalPhotos = 0;
$totalPages = 0;
$loadError = false;
$values = [
    'title' => '',
    'caption' => '',
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
    if ($path === null || preg_match('~\Auploads/gallery/[A-Za-z0-9_-]+\.(?:jpe?g|png|webp)\z~iD', $path) !== 1) {
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
        error_log('Could not remove gallery image: ' . $fullPath);
    }
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $successMessage = $_SESSION['admin_gallery_success'] ?? '';
    unset($_SESSION['admin_gallery_success']);
}

if (!$notFound && $id !== null) {
    try {
        $stmt = db()->prepare(
            'SELECT id, title, caption, image_path, created_at
               FROM gallery
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $photo = $stmt->fetch();
        if (!$photo) {
            $notFound = true;
            http_response_code(404);
        } else {
            $values = [
                'title' => (string)$photo['title'],
                'caption' => (string)($photo['caption'] ?? ''),
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
        $errors[] = 'The gallery photo changed. Please reload the page and try again.';
    }
    if (!is_string($action) || !in_array($action, ['save', 'delete'], true)) {
        $errors[] = 'The requested gallery operation is invalid.';
    }

    if ($action === 'save') {
        foreach (['title', 'caption'] as $field) {
            $submitted = $_POST[$field] ?? '';
            $values[$field] = is_string($submitted) ? trim($submitted) : '';
        }
        if ($values['title'] === '' || $characterLength($values['title']) > 120) {
            $errors[] = 'Photo title is required and must be no longer than 120 characters.';
        }
        if ($values['caption'] !== '' && $characterLength($values['caption']) > 255) {
            $errors[] = 'Caption must be no longer than 255 characters.';
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
            $imageInfo = getimagesize($upload['tmp_name']);
            if (
                !is_string($mimeType)
                || !isset($allowedImageTypes[$mimeType])
                || $imageInfo === false
                || ($imageInfo['mime'] ?? '') !== $mimeType
            ) {
                $errors[] = 'Upload a valid JPEG, PNG, or WebP image.';
            } else {
                $uploadedImage = $relativeUploadDirectory . '/' . bin2hex(random_bytes(16)) . '.' . $allowedImageTypes[$mimeType];
            }
        }
    } elseif ($action === 'save' && $upload !== null && !is_array($upload)) {
        $errors[] = 'The selected image upload is invalid.';
    }

    if (!$errors && $action === 'delete' && $id !== null && $photo) {
        try {
            $stmt = db()->prepare('DELETE FROM gallery WHERE id = :id');
            $stmt->execute([':id' => $id]);
            if ($stmt->rowCount() !== 1) {
                throw new DomainException('That gallery photo could not be found.');
            }
            $removeStoredImage($photo['image_path']);
            $_SESSION['admin_gallery_success'] = 'Gallery photo deleted.';
            redirect(BASE_URL . 'admin/gallery.php');
        } catch (DomainException $ex) {
            $errors[] = $ex->getMessage();
        } catch (PDOException $ex) {
            error_log($ex->getMessage());
            $errors[] = 'Could not delete the gallery photo. Please try again.';
        }
    } elseif (!$errors && $action === 'save') {
        if (
            $uploadedImage !== null
            && (!is_dir($uploadDirectory) || !is_writable($uploadDirectory))
        ) {
            $errors[] = 'The gallery image folder is not writable. Please contact the site administrator.';
        }
        if (
            !$errors
            && $uploadedImage !== null
            && !move_uploaded_file($upload['tmp_name'], __DIR__ . '/../' . $uploadedImage)
        ) {
            $errors[] = 'The image could not be saved. Please try again.';
        }

        if (!$errors) {
            $oldImage = $photo['image_path'] ?? null;
            $imageValue = $uploadedImage ?? $oldImage;
            try {
                if ($id === null) {
                    if ($imageValue === null) {
                        throw new DomainException('Choose a photo to upload.');
                    }
                    $stmt = db()->prepare(
                        'INSERT INTO gallery (title, caption, image_path, uploaded_by)
                         VALUES (:title, :caption, :image_path, :uploaded_by)'
                    );
                    $stmt->execute([
                        ':title' => $values['title'],
                        ':caption' => $values['caption'] === '' ? null : $values['caption'],
                        ':image_path' => $imageValue,
                        ':uploaded_by' => (int)$_SESSION['user_id'],
                    ]);
                    $_SESSION['admin_gallery_success'] = 'Photo added to the gallery.';
                } else {
                    $stmt = db()->prepare(
                        'UPDATE gallery
                            SET title = :title, caption = :caption, image_path = :image_path
                          WHERE id = :id'
                    );
                    $stmt->execute([
                        ':title' => $values['title'],
                        ':caption' => $values['caption'] === '' ? null : $values['caption'],
                        ':image_path' => $imageValue,
                        ':id' => $id,
                    ]);
                    $_SESSION['admin_gallery_success'] = 'Gallery photo updated.';
                }
                if ($uploadedImage !== null && $oldImage !== null && $oldImage !== $imageValue) {
                    $removeStoredImage($oldImage);
                }
                redirect(BASE_URL . 'admin/gallery.php');
            } catch (DomainException $ex) {
                if ($uploadedImage !== null) {
                    $removeStoredImage($uploadedImage);
                }
                $errors[] = $ex->getMessage();
            } catch (PDOException $ex) {
                if ($uploadedImage !== null) {
                    $removeStoredImage($uploadedImage);
                }
                error_log($ex->getMessage());
                $errors[] = 'Could not save the gallery photo. Please try again.';
            }
        }
    }
}

if (!$notFound && !$loadError) {
    $whereSql = '';
    $params = [];
    if ($search !== '') {
        $whereSql = ' WHERE title LIKE :title OR caption LIKE :caption';
        $like = '%' . $search . '%';
        $params = [':title' => $like, ':caption' => $like];
    }
    try {
        $countStmt = db()->prepare('SELECT COUNT(*) FROM gallery' . $whereSql);
        $countStmt->execute($params);
        $totalPhotos = (int)$countStmt->fetchColumn();
        $totalPages = (int)ceil($totalPhotos / $perPage);
        if ($totalPages > 0 && $page > $totalPages) {
            $page = $totalPages;
        }

        $stmt = db()->prepare(
            'SELECT id, title, caption, image_path, created_at
               FROM gallery'
            . $whereSql .
            ' ORDER BY created_at DESC, id DESC
              LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $photos = $stmt->fetchAll();
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        $loadError = true;
    }
}

$pageUrl = static function (int $targetPage) use ($search): string {
    $params = ['page' => $targetPage];
    if ($search !== '') {
        $params['q'] = $search;
    }
    return BASE_URL . 'admin/gallery.php?' . http_build_query($params);
};

if ($id !== null && $photo) {
    $pageTitle = 'Edit gallery photo';
}
if ($notFound) {
    $pageTitle = 'Gallery photo not found';
}
include __DIR__ . '/../includes/staff-header.php';
?>

<main class="admin-page">
  <div class="admin-page-heading">
    <div>
      <p class="admin-eyebrow">School website</p>
      <h1><?= e($pageTitle) ?></h1>
      <p>Upload and maintain photos shown in the public school gallery.</p>
    </div>
    <?php if ($id !== null): ?>
      <a class="admin-secondary-link" href="<?= BASE_URL ?>admin/gallery.php">Back to gallery</a>
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
    <div class="admin-alert admin-alert-error" role="alert">Gallery photos could not be loaded. Please try again.</div>
  <?php elseif ($notFound): ?>
    <section class="admin-panel admin-empty-state">
      <h2>Gallery photo not found</h2>
      <p>The requested photo does not exist.</p>
    </section>
  <?php else: ?>
    <section class="admin-panel admin-form-panel" aria-labelledby="gallery-form-title">
      <h2 id="gallery-form-title"><?= $id === null ? 'Add a photo' : 'Photo details' ?></h2>
      <form method="post" action="<?= BASE_URL ?>admin/gallery.php<?= $id === null ? '' : '?id=' . $id ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id === null ? '' : (int)$id ?>">
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= $maxImageSize ?>">
        <div class="admin-form-grid">
          <div class="admin-form-field admin-form-field-wide">
            <label for="photo-title">Photo title <span aria-hidden="true">*</span></label>
            <input type="text" id="photo-title" name="title" value="<?= e($values['title']) ?>" maxlength="120" required>
          </div>
          <div class="admin-form-field admin-form-field-wide">
            <label for="photo-caption">Caption <span>(optional)</span></label>
            <input type="text" id="photo-caption" name="caption" value="<?= e($values['caption']) ?>" maxlength="255">
          </div>
          <div class="admin-form-field admin-form-field-wide">
            <label for="gallery-image">Photo <span><?= $id === null ? '(required)' : '(optional; select to replace current photo)' ?></span></label>
            <input type="file" id="gallery-image" name="image" accept="image/jpeg,image/png,image/webp"<?= $id === null ? ' required' : '' ?>>
            <small>JPEG, PNG, or WebP; maximum size 5 MB.</small>
          </div>
          <?php if ($id !== null && $photo && $safeImagePath($photo['image_path']) !== null): ?>
            <div class="admin-form-field admin-form-field-wide">
              <p>Current photo:</p>
              <img src="<?= BASE_URL . e($safeImagePath($photo['image_path'])) ?>" alt="<?= e($photo['title']) ?>" style="display:block;max-width:min(100%,32rem);max-height:24rem;object-fit:contain;border-radius:8px;">
            </div>
          <?php endif; ?>
        </div>
        <div class="admin-form-actions">
          <button class="admin-primary-link" type="submit" name="action" value="save"><?= $id === null ? 'Upload photo' : 'Save changes' ?></button>
          <?php if ($id === null): ?>
            <a class="admin-secondary-link" href="<?= BASE_URL ?>admin/gallery.php">Clear form</a>
          <?php endif; ?>
        </div>
      </form>
      <?php if ($id !== null): ?>
        <form method="post" action="<?= BASE_URL ?>admin/gallery.php?id=<?= (int)$id ?>" onsubmit="return confirm('Delete this gallery photo and its uploaded image?');">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$id ?>">
          <input type="hidden" name="action" value="delete">
          <div class="admin-form-actions">
            <button class="admin-danger-button" type="submit">Delete photo</button>
          </div>
        </form>
      <?php endif; ?>
    </section>

    <section class="admin-panel" aria-labelledby="gallery-list-title">
      <div class="admin-panel-heading">
        <h2 id="gallery-list-title">Gallery photos</h2>
        <p><?= $totalPhotos ?> <?= $totalPhotos === 1 ? 'photo' : 'photos' ?></p>
      </div>
      <form class="admin-filter-form" method="get" action="<?= BASE_URL ?>admin/gallery.php">
        <div class="admin-filter-field">
          <label for="gallery-search">Search title or caption</label>
          <input type="search" id="gallery-search" name="q" value="<?= e($search) ?>" maxlength="100" placeholder="Search gallery">
        </div>
        <div class="admin-filter-actions">
          <button class="admin-primary-link" type="submit">Search</button>
          <a class="admin-secondary-link" href="<?= BASE_URL ?>admin/gallery.php">Clear</a>
        </div>
      </form>

      <?php if (!$photos): ?>
        <div class="admin-empty-state">
          <h3>No gallery photos found</h3>
          <p>Upload a photo above or change the search.</p>
        </div>
      <?php else: ?>
        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr>
                <th scope="col">Photo</th>
                <th scope="col">Title and caption</th>
                <th scope="col">Uploaded</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($photos as $item): ?>
                <?php $listImage = $safeImagePath($item['image_path']); ?>
                <tr>
                  <td data-label="Photo">
                    <?php if ($listImage !== null): ?>
                      <img src="<?= BASE_URL . e($listImage) ?>" alt="" loading="lazy" style="display:block;width:5rem;height:4rem;object-fit:cover;border-radius:6px;">
                    <?php else: ?>
                      Image unavailable
                    <?php endif; ?>
                  </td>
                  <td data-label="Title and caption">
                    <strong><?= e($item['title']) ?></strong>
                    <?php if ($item['caption']): ?><br><span><?= e($item['caption']) ?></span><?php endif; ?>
                  </td>
                  <td data-label="Uploaded"><?= e(date('F j, Y', strtotime($item['created_at']))) ?></td>
                  <td data-label="Actions" class="admin-row-actions">
                    <a class="admin-secondary-link admin-compact-link" href="<?= BASE_URL ?>admin/gallery.php?id=<?= (int)$item['id'] ?>">Edit</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($totalPages > 1): ?>
          <nav class="admin-pagination" aria-label="Gallery list pages">
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
