<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'Contact us';
$values = [
    'name' => '',
    'email' => '',
    'phone' => '',
    'subject' => '',
    'message' => '',
];
$errors = [];
$successMessage = $_SESSION['contact_success'] ?? '';
unset($_SESSION['contact_success']);
$characterLength = static function (string $value): int {
    if (preg_match_all('/./us', $value, $matches) === false) {
        return strlen($value);
    }
    return count($matches[0]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $field => $default) {
        $submitted = $_POST[$field] ?? '';
        $values[$field] = is_string($submitted) ? trim($submitted) : '';
    }

    $token = $_POST['csrf'] ?? null;
    if (!is_string($token) || !csrf_check($token)) {
        $errors[] = 'Your form expired. Please try again.';
    }

    if ($values['name'] === '' || $characterLength($values['name']) > 100) {
        $errors[] = 'Enter your name using no more than 100 characters.';
    }
    if (
        $values['email'] === ''
        || $characterLength($values['email']) > 100
        || filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false
    ) {
        $errors[] = 'Enter a valid email address using no more than 100 characters.';
    }
    if (
        $values['phone'] !== ''
        && (
            $characterLength($values['phone']) > 20
            || preg_match('/\A[0-9+().\s-]{7,20}\z/', $values['phone']) !== 1
            || strlen(preg_replace('/[^0-9]/', '', $values['phone'])) < 7
        )
    ) {
        $errors[] = 'Enter a valid phone number or leave the phone field blank.';
    }
    if ($values['subject'] === '' || $characterLength($values['subject']) > 150) {
        $errors[] = 'Enter a subject using no more than 150 characters.';
    }
    if ($values['message'] === '' || $characterLength($values['message']) > 5000) {
        $errors[] = 'Enter a message using no more than 5,000 characters.';
    }

    if (!$errors) {
        try {
            $stmt = db()->prepare(
                'INSERT INTO messages (name, email, phone, subject, message)
                 VALUES (:name, :email, :phone, :subject, :message)'
            );
            $stmt->execute([
                ':name' => $values['name'],
                ':email' => $values['email'],
                ':phone' => $values['phone'] === '' ? null : $values['phone'],
                ':subject' => $values['subject'],
                ':message' => $values['message'],
            ]);
            $_SESSION['contact_success'] = 'Thank you for your message. The school has received it.';
            redirect(BASE_URL . 'contact.php');
        } catch (PDOException $ex) {
            error_log($ex->getMessage());
            $errors[] = 'We could not send your message right now. Please try again later.';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<main class="contact-page">
  <section class="contact-hero">
    <div class="contact-hero-inner">
      <p class="contact-eyebrow">We are here to help</p>
      <h1>Let us start a conversation.</h1>
      <p>Questions about SDMS or applying to our school? Send us a message and our team will be glad to help.</p>
    </div>
    <span class="contact-hero-mark" aria-hidden="true">@</span>
  </section>

  <section class="contact-content" aria-labelledby="contact-form-title">
    <div class="contact-intro">
      <p class="contact-kicker">Get in touch</p>
      <h2>How can we help?</h2>
      <p>Complete the form and your message will be sent to the school office.</p>
      <div class="contact-note">
        <span class="contact-note-mark" aria-hidden="true">i</span>
        <p>Fields marked <span aria-hidden="true">*</span> are required. We will use your details only to respond to your enquiry.</p>
      </div>
    </div>

    <div class="contact-form-wrap">
      <h2 id="contact-form-title">Send us a message</h2>
      <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success" role="status"><?= e($successMessage) ?></div>
      <?php endif; ?>
      <?php if ($errors): ?>
        <div class="alert alert-error" role="alert">
          <p>Please check the following:</p>
          <ul>
            <?php foreach ($errors as $error): ?>
              <li><?= e($error) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form class="contact-form" method="post" action="<?= BASE_URL ?>contact.php" data-validate>
        <?= csrf_field() ?>
        <div class="contact-form-grid">
          <div class="contact-field">
            <label for="contact-name">Your name <span aria-hidden="true">*</span></label>
            <input type="text" id="contact-name" name="name" value="<?= e($values['name']) ?>" maxlength="100" autocomplete="name" required>
          </div>
          <div class="contact-field">
            <label for="contact-email">Email address <span aria-hidden="true">*</span></label>
            <input type="email" id="contact-email" name="email" value="<?= e($values['email']) ?>" maxlength="100" autocomplete="email" required>
          </div>
          <div class="contact-field">
            <label for="contact-phone">Phone number <span class="contact-optional">(optional)</span></label>
            <input type="tel" id="contact-phone" name="phone" value="<?= e($values['phone']) ?>" maxlength="20" title="Use at least 7 digits and no more than 20 characters." autocomplete="tel">
          </div>
          <div class="contact-field">
            <label for="contact-subject">Subject <span aria-hidden="true">*</span></label>
            <input type="text" id="contact-subject" name="subject" value="<?= e($values['subject']) ?>" maxlength="150" required>
          </div>
          <div class="contact-field contact-field-message">
            <label for="contact-message">Your message <span aria-hidden="true">*</span></label>
            <textarea id="contact-message" name="message" rows="6" maxlength="5000" required><?= e($values['message']) ?></textarea>
            <span class="contact-field-hint">Maximum 5,000 characters.</span>
          </div>
        </div>
        <button class="contact-submit" type="submit">Send message <span aria-hidden="true">&rarr;</span></button>
      </form>
    </div>
  </section>
</main>

<script src="<?= BASE_URL ?>assets/js/validation.js?v=<?= filemtime(__DIR__ . '/assets/js/validation.js') ?>" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
