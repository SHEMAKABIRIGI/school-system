<?php
$pageTitle = 'About our school';
include __DIR__ . '/includes/header.php';
?>

<main class="about-page">
  <section class="about-hero">
    <div class="about-hero-inner">
      <div class="about-hero-copy">
        <p class="about-eyebrow">Get to know us</p>
        <h1>Learning that opens doors.</h1>
        <p>
          At <?= e(SITE_NAME) ?>, education is about more than what happens in a
          lesson. It is about building knowledge, confidence, and the ability to
          shape a bright future.
        </p>
        <div class="about-hero-actions">
          <a class="about-button about-button-primary" href="<?= BASE_URL ?>register.php">Apply to our school</a>
          <a class="about-text-link" href="<?= BASE_URL ?>contact.php">Talk to us <span aria-hidden="true">&rarr;</span></a>
        </div>
      </div>
      <div class="about-hero-visual" aria-hidden="true">
        <div class="about-visual-ring"></div>
        <div class="about-visual-card">
          <span class="about-visual-label">A foundation for</span>
          <strong>What comes next</strong>
          <span class="about-visual-lines"></span>
          <span class="about-visual-dot"></span>
        </div>
        <span class="about-visual-spark about-visual-spark-one"></span>
        <span class="about-visual-spark about-visual-spark-two"></span>
      </div>
    </div>
    <div class="about-hero-bottom" aria-hidden="true">
      <span>Curiosity</span><span>Effort</span><span>Possibility</span>
    </div>
  </section>

  <section class="about-purpose" aria-labelledby="about-purpose-title">
    <div class="about-section-intro">
      <p class="about-kicker">Our purpose</p>
      <h2 id="about-purpose-title">A strong start for every learner.</h2>
    </div>
    <div class="about-purpose-copy">
      <p>
        Every learner deserves the opportunity to discover what they can do.
        We believe a good education combines a strong academic foundation with
        encouragement to think, ask questions, and keep growing.
      </p>
      <p>
        Our aim is to help students develop the knowledge and personal qualities
        to make thoughtful choices and take their next steps with confidence.
      </p>
    </div>
  </section>

  <section class="about-pillars" aria-labelledby="about-pillars-title">
    <div class="about-pillars-heading">
      <p class="about-kicker">What matters</p>
      <h2 id="about-pillars-title">An education built around growth.</h2>
      <p>Small steps, steady support, and a sense of purpose make a difference.</p>
    </div>
    <div class="about-feature-grid">
      <article class="about-feature">
        <span class="about-feature-number">01 / LEARNING</span>
        <span class="about-feature-icon about-feature-icon-book" aria-hidden="true">A</span>
        <h3>Strong foundations</h3>
        <p>Build understanding, practice new skills, and take pride in progress.</p>
      </article>
      <article class="about-feature">
        <span class="about-feature-number">02 / CHARACTER</span>
        <span class="about-feature-icon about-feature-icon-growth" aria-hidden="true">+</span>
        <h3>Confidence to grow</h3>
        <p>Meet challenges with curiosity, persistence, and belief in what is possible.</p>
      </article>
      <article class="about-feature">
        <span class="about-feature-number">03 / COMMUNITY</span>
        <span class="about-feature-icon about-feature-icon-people" aria-hidden="true">o</span>
        <h3>Respect for one another</h3>
        <p>Make room for different perspectives and help create a welcoming community.</p>
      </article>
    </div>
  </section>

  <section class="about-journey" aria-labelledby="about-journey-title">
    <div class="about-journey-heading">
      <p class="about-kicker">The learner journey</p>
      <h2 id="about-journey-title">Progress, one step at a time.</h2>
      <p>Learning is a journey of discovery, practice, and growing independence.</p>
    </div>
    <ol class="about-journey-steps">
      <li>
        <span class="about-step-number">01</span>
        <div><h3>Discover</h3><p>Explore ideas and find new interests.</p></div>
      </li>
      <li>
        <span class="about-step-number">02</span>
        <div><h3>Develop</h3><p>Strengthen knowledge through practice and perseverance.</p></div>
      </li>
      <li>
        <span class="about-step-number">03</span>
        <div><h3>Move forward</h3><p>Use what you have learned to prepare for what comes next.</p></div>
      </li>
    </ol>
  </section>

  <section class="about-cta">
    <div>
      <p class="about-kicker">Take the next step</p>
      <h2>We would be glad to hear from you.</h2>
      <p>Find out more about applying or send us a message with your questions.</p>
    </div>
    <div class="about-cta-actions">
      <a class="about-button about-button-light" href="<?= BASE_URL ?>register.php">Start an application <span aria-hidden="true">&rarr;</span></a>
      <a class="about-cta-link" href="<?= BASE_URL ?>contact.php">Contact our school</a>
    </div>
  </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
