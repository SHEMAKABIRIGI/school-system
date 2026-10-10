<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$pageTitle = 'Registrations';
$perPage = 15;
$statusOptions = ['all', 'pending', 'accepted', 'rejected'];
$searchValue = $_GET['q'] ?? '';
$search = is_string($searchValue) ? trim($searchValue) : '';
$statusValue = $_GET['status'] ?? 'pending';
$statusFilter = is_string($statusValue) ? $statusValue : '';
if (!in_array($statusFilter, $statusOptions, true)) {
    $statusFilter = 'pending';
}
$successMessage = $_SESSION['admin_registrations_success'] ?? '';
unset($_SESSION['admin_registrations_success']);
$errors = [];
$characterLength = static function (string $value): int {
    if (preg_match_all('/./us', $value, $matches) === false) {
        return strlen($value);
    }
    return count($matches[0]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? null;
    $registrationId = filter_var($_POST['registration_id'] ?? '', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $decision = $_POST['decision'] ?? '';
    $adminNote = $_POST['admin_note'] ?? '';
    $adminNote = is_string($adminNote) ? trim($adminNote) : '';

    if (!is_string($token) || !csrf_check($token)) {
        $errors[] = 'Your form expired. Please reload the page and try again.';
    } elseif ($registrationId === false || !in_array($decision, ['accept', 'reject'], true)) {
        $errors[] = 'The application decision is invalid.';
    } elseif ($characterLength($adminNote) > 255) {
        $errors[] = 'The review note must be no longer than 255 characters.';
    } elseif ($decision === 'reject' && $adminNote === '') {
        $errors[] = 'Add a note explaining why this application is being rejected.';
    }

    $classId = null;
    if (!$errors && $decision === 'accept') {
        $classValue = $_POST['class_id'] ?? '';
        $classId = filter_var($classValue, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($classId === false) {
            $errors[] = 'Choose the class for the accepted student.';
        }
    }

    if (!$errors) {
        $pdo = db();
        try {
            $pdo->beginTransaction();

            $registrationStmt = $pdo->prepare(
                'SELECT id, first_name, last_name, gender, date_of_birth, previous_school,
                        guardian_name, guardian_phone, guardian_email, address, status
                   FROM registrations
                  WHERE id = :id
                  FOR UPDATE'
            );
            $registrationStmt->execute([':id' => $registrationId]);
            $registration = $registrationStmt->fetch();

            if (!$registration) {
                throw new DomainException('Application not found.');
            }
            if ($registration['status'] !== 'pending') {
                throw new DomainException('This application has already been reviewed.');
            }

            if ($decision === 'accept') {
                $classStmt = $pdo->prepare('SELECT id FROM classes WHERE id = :id');
                $classStmt->execute([':id' => $classId]);
                if (!$classStmt->fetchColumn()) {
                    throw new DomainException('The selected class is no longer available. Choose another class.');
                }

                $linkedStmt = $pdo->prepare('SELECT id FROM students WHERE registration_id = :registration_id');
                $linkedStmt->execute([':registration_id' => $registrationId]);
                if ($linkedStmt->fetchColumn()) {
                    throw new DomainException('A student record is already linked to this application.');
                }

                // Serialize admissions created from applications before selecting the next number.
                $adminLock = $pdo->query(
                    "SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1 FOR UPDATE"
                )->fetchColumn();
                if (!$adminLock) {
                    throw new RuntimeException('No admin account is available to serialize admission numbers.');
                }

                $admissionStmt = $pdo->query(
                    "SELECT admission_no FROM students WHERE admission_no LIKE 'ADM-%' FOR UPDATE"
                );
                $nextAdmissionNumber = 1;
                foreach ($admissionStmt->fetchAll(PDO::FETCH_COLUMN) as $admissionNo) {
                    if (preg_match('/\AADM-(\d+)\z/i', (string)$admissionNo, $matches) === 1) {
                        $nextAdmissionNumber = max($nextAdmissionNumber, (int)$matches[1] + 1);
                    }
                }
                $admissionNo = 'ADM-' . str_pad((string)$nextAdmissionNumber, 4, '0', STR_PAD_LEFT);

                $insertStudent = $pdo->prepare(
                    'INSERT INTO students (
                        admission_no, first_name, last_name, gender, date_of_birth, class_id,
                        guardian_name, guardian_phone, guardian_email, address, registration_id
                    ) VALUES (
                        :admission_no, :first_name, :last_name, :gender, :date_of_birth, :class_id,
                        :guardian_name, :guardian_phone, :guardian_email, :address, :registration_id
                    )'
                );
                $insertStudent->execute([
                    ':admission_no' => $admissionNo,
                    ':first_name' => $registration['first_name'],
                    ':last_name' => $registration['last_name'],
                    ':gender' => $registration['gender'],
                    ':date_of_birth' => $registration['date_of_birth'],
                    ':class_id' => $classId,
                    ':guardian_name' => $registration['guardian_name'],
                    ':guardian_phone' => $registration['guardian_phone'],
                    ':guardian_email' => $registration['guardian_email'],
                    ':address' => $registration['address'],
                    ':registration_id' => $registrationId,
                ]);

                $updateRegistration = $pdo->prepare(
                    "UPDATE registrations
                        SET status = 'accepted', admin_note = :admin_note,
                            reviewed_by = :reviewed_by, reviewed_at = NOW()
                      WHERE id = :id AND status = 'pending'"
                );
                $updateRegistration->execute([
                    ':admin_note' => $adminNote === '' ? null : $adminNote,
                    ':reviewed_by' => (int)$_SESSION['user_id'],
                    ':id' => $registrationId,
                ]);
                if ($updateRegistration->rowCount() !== 1) {
                    throw new DomainException('The application was already reviewed. No changes were saved.');
                }

                $pdo->commit();
                $_SESSION['admin_registrations_success'] = 'Application accepted. ' . $registration['first_name'] . ' ' . $registration['last_name'] . ' was added as ' . $admissionNo . '.';
            } else {
                $updateRegistration = $pdo->prepare(
                    "UPDATE registrations
                        SET status = 'rejected', admin_note = :admin_note,
                            reviewed_by = :reviewed_by, reviewed_at = NOW()
                      WHERE id = :id AND status = 'pending'"
                );
                $updateRegistration->execute([
                    ':admin_note' => $adminNote,
                    ':reviewed_by' => (int)$_SESSION['user_id'],
                    ':id' => $registrationId,
                ]);
                if ($updateRegistration->rowCount() !== 1) {
                    throw new DomainException('The application was already reviewed. No changes were saved.');
                }
                $pdo->commit();
                $_SESSION['admin_registrations_success'] = 'Application rejected and the review note was saved.';
            }

            redirect(BASE_URL . 'admin/registrations.php?status=' . ($decision === 'accept' ? 'accepted' : 'rejected'));
        } catch (DomainException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $ex->getMessage();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($ex->getMessage());
            $errors[] = $ex->getCode() === '23000'
                ? 'Could not create the student record because its admission number or application link is already in use. No changes were saved.'
                : 'Could not save the application decision. No changes were saved; please try again.';
        } catch (RuntimeException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($ex->getMessage());
            $errors[] = 'Could not save the application decision. No changes were saved; please try again.';
        }
    }
}

$pageValue = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$page = $pageValue === false ? 1 : $pageValue;
$where = [];
$params = [];
if ($statusFilter !== 'all') {
    $where[] = 'r.status = :status';
    $params[':status'] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(r.first_name LIKE :first_name OR r.last_name LIKE :last_name OR r.guardian_name LIKE :guardian_name OR r.class_applied LIKE :class_applied)';
    $like = '%' . $search . '%';
    $params[':first_name'] = $like;
    $params[':last_name'] = $like;
    $params[':guardian_name'] = $like;
    $params[':class_applied'] = $like;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$applications = [];
$classes = [];
$totalApplications = 0;
$totalPages = 0;
$loadError = false;

try {
    $classes = db()->query('SELECT id, name FROM classes ORDER BY name')->fetchAll();
    $countStmt = db()->prepare('SELECT COUNT(*) FROM registrations r' . $whereSql);
    $countStmt->execute($params);
    $totalApplications = (int)$countStmt->fetchColumn();
    $totalPages = (int)ceil($totalApplications / $perPage);
    if ($totalPages > 0 && $page > $totalPages) {
        $page = $totalPages;
    }

    $stmt = db()->prepare(
        'SELECT r.id, r.first_name, r.last_name, r.gender, r.date_of_birth,
                r.previous_school, r.class_applied, r.guardian_name, r.guardian_phone,
                r.guardian_email, r.address, r.status, r.admin_note, r.created_at,
                r.reviewed_at, reviewer.full_name AS reviewer_name,
                s.id AS student_id, s.admission_no, c.name AS assigned_class
           FROM registrations r
           LEFT JOIN users reviewer ON reviewer.id = r.reviewed_by
           LEFT JOIN students s ON s.registration_id = r.id
           LEFT JOIN classes c ON c.id = s.class_id'
        . $whereSql .
        ' ORDER BY r.created_at DESC, r.id DESC
          LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $applications = $stmt->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    $loadError = true;
}

$queryParams = ['status' => $statusFilter];
if ($search !== '') {
    $queryParams['q'] = $search;
}
$pageUrl = static function (int $targetPage) use ($queryParams): string {
    return BASE_URL . 'admin/registrations.php?' . http_build_query($queryParams + ['page' => $targetPage]);
};

include __DIR__ . '/../includes/staff-header.php';
?>

<main class="admin-page registrations-admin">
  <div class="admin-page-heading">
    <div>
      <p class="admin-eyebrow">Admissions</p>
      <h1>Student applications</h1>
      <p>Review applications, select a class for accepted students, and record decisions.</p>
    </div>
  </div>

  <?php if ($successMessage !== ''): ?>
    <div class="admin-alert admin-alert-success" role="status"><?= e($successMessage) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="admin-alert admin-alert-error" role="alert">
      <p>Please review this decision:</p>
      <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <section class="admin-panel" aria-labelledby="application-filter-title">
    <h2 id="application-filter-title">Find applications</h2>
    <form class="admin-filter-form registrations-filter" method="get" action="<?= BASE_URL ?>admin/registrations.php">
      <div class="admin-filter-field">
        <label for="registration-search">Student or guardian name, or requested class</label>
        <input type="search" id="registration-search" name="q" value="<?= e($search) ?>" maxlength="100" placeholder="Search applications">
      </div>
      <div class="admin-filter-field">
        <label for="registration-status">Decision status</label>
        <select id="registration-status" name="status">
          <?php foreach ($statusOptions as $status): ?>
            <option value="<?= e($status) ?>"<?= $statusFilter === $status ? ' selected' : '' ?>><?= $status === 'all' ? 'All applications' : e(ucfirst($status)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-filter-actions">
        <button class="admin-primary-link" type="submit">Apply filters</button>
        <a class="admin-secondary-link" href="<?= BASE_URL ?>admin/registrations.php?status=all">All applications</a>
      </div>
    </form>
  </section>

  <section aria-labelledby="applications-title">
    <div class="admin-panel-heading registrations-list-heading">
      <h2 id="applications-title"><?= $statusFilter === 'all' ? 'All applications' : e(ucfirst($statusFilter) . ' applications') ?></h2>
      <?php if (!$loadError): ?><p><?= $totalApplications ?> <?= $totalApplications === 1 ? 'application' : 'applications' ?></p><?php endif; ?>
    </div>
    <?php if ($loadError): ?>
      <div class="admin-alert admin-alert-error" role="alert">Applications could not be loaded. Please try again later.</div>
    <?php elseif (!$applications): ?>
      <div class="admin-panel admin-empty-state">
        <h3>No <?= $statusFilter === 'all' ? 'applications' : e($statusFilter . ' applications') ?> found</h3>
        <p>Choose another status or try a different search.</p>
      </div>
    <?php else: ?>
      <div class="registration-list">
        <?php foreach ($applications as $application): ?>
          <article class="admin-panel registration-card">
            <header class="registration-card-header">
              <div>
                <p class="admin-eyebrow">Application #<?= (int)$application['id'] ?></p>
                <h3><?= e($application['first_name'] . ' ' . $application['last_name']) ?></h3>
              </div>
              <span class="admin-status admin-status-<?= e($application['status']) ?>"><?= e(ucfirst($application['status'])) ?></span>
            </header>

            <dl class="registration-details">
              <div><dt>Date of birth</dt><dd><?= e(date('F j, Y', strtotime($application['date_of_birth']))) ?></dd></div>
              <div><dt>Gender</dt><dd><?= e(ucfirst($application['gender'])) ?></dd></div>
              <div><dt>Requested class</dt><dd><?= e($application['class_applied']) ?></dd></div>
              <div><dt>Previous school</dt><dd><?= e($application['previous_school'] ?: 'Not provided') ?></dd></div>
              <div><dt>Parent or guardian</dt><dd><?= e($application['guardian_name']) ?></dd></div>
              <div><dt>Guardian phone</dt><dd><a href="tel:<?= e($application['guardian_phone']) ?>"><?= e($application['guardian_phone']) ?></a></dd></div>
              <div><dt>Guardian email</dt><dd>
                <?php if ($application['guardian_email']): ?>
                  <a href="mailto:<?= e($application['guardian_email']) ?>"><?= e($application['guardian_email']) ?></a>
                <?php else: ?>
                  Not provided
                <?php endif; ?>
              </dd></div>
              <div><dt>Address</dt><dd><?= e($application['address'] ?: 'Not provided') ?></dd></div>
              <div><dt>Applied on</dt><dd><?= e(date('F j, Y g:i a', strtotime($application['created_at']))) ?></dd></div>
              <?php if ($application['reviewed_at']): ?>
                <div><dt>Reviewed</dt><dd><?= e(date('F j, Y g:i a', strtotime($application['reviewed_at']))) ?><?= $application['reviewer_name'] ? ' by ' . e($application['reviewer_name']) : '' ?></dd></div>
              <?php endif; ?>
              <?php if ($application['assigned_class']): ?>
                <div><dt>Assigned class</dt><dd><?= e($application['assigned_class']) ?></dd></div>
              <?php endif; ?>
              <?php if ($application['admission_no']): ?>
                <div><dt>Admission number</dt><dd><?= e($application['admission_no']) ?></dd></div>
              <?php endif; ?>
              <?php if ($application['admin_note']): ?>
                <div class="registration-note"><dt>Admin note</dt><dd><?= e($application['admin_note']) ?></dd></div>
              <?php endif; ?>
            </dl>

            <?php if ($application['status'] === 'pending'): ?>
              <div class="registration-decisions">
                <form class="registration-decision-form" method="post" action="<?= BASE_URL ?>admin/registrations.php?status=pending" onsubmit="return confirm('Accept this application and create the student record?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="registration_id" value="<?= (int)$application['id'] ?>">
                  <input type="hidden" name="decision" value="accept">
                  <div class="registration-decision-field">
                    <label for="class-<?= (int)$application['id'] ?>">Class to assign <span aria-hidden="true">*</span></label>
                    <select id="class-<?= (int)$application['id'] ?>" name="class_id" required>
                      <option value="">Choose a class</option>
                      <?php foreach ($classes as $class): ?>
                        <option value="<?= (int)$class['id'] ?>"<?= $class['name'] === $application['class_applied'] ? ' selected' : '' ?>><?= e($class['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="registration-decision-field">
                    <label for="accept-note-<?= (int)$application['id'] ?>">Optional review note</label>
                    <input type="text" id="accept-note-<?= (int)$application['id'] ?>" name="admin_note" maxlength="255">
                  </div>
                  <button class="admin-primary-link" type="submit">Accept application</button>
                </form>

                <form class="registration-decision-form registration-reject-form" method="post" action="<?= BASE_URL ?>admin/registrations.php?status=pending" onsubmit="return confirm('Reject this application? A review note is required.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="registration_id" value="<?= (int)$application['id'] ?>">
                  <input type="hidden" name="decision" value="reject">
                  <div class="registration-decision-field">
                    <label for="reject-note-<?= (int)$application['id'] ?>">Reason for rejection <span aria-hidden="true">*</span></label>
                    <input type="text" id="reject-note-<?= (int)$application['id'] ?>" name="admin_note" maxlength="255" required>
                  </div>
                  <button class="admin-danger-button" type="submit">Reject application</button>
                </form>
              </div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>

      <?php if ($totalPages > 1): ?>
        <nav class="admin-pagination" aria-label="Application list pages">
          <span>Page <?= $page ?> of <?= $totalPages ?></span>
          <div>
            <?php if ($page > 1): ?><a class="admin-secondary-link" href="<?= e($pageUrl($page - 1)) ?>">Previous</a><?php endif; ?>
            <?php if ($page < $totalPages): ?><a class="admin-secondary-link" href="<?= e($pageUrl($page + 1)) ?>">Next</a><?php endif; ?>
          </div>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</main>

<?php include __DIR__ . '/../includes/staff-footer.php'; ?>
