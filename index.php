<?php
$pageTitle = 'Welcome';
include __DIR__ . '/includes/header.php';
?>

<main class="home-page">
  <section class="home-hero">
    <div class="home-hero-inner">
      <div class="home-hero-copy">
        <p class="home-eyebrow">Welcome to <?= e(SITE_NAME) ?></p>
        <h1>A place to learn, grow, and shape what comes next.</h1>
        <p class="home-intro">
          Discover a supportive school community where curiosity is encouraged,
          potential is nurtured, and every learner can take their next step.
        </p>
        <div class="home-actions">
          <a class="home-button home-button-primary" href="<?= BASE_URL ?>about.php">Discover our school</a>
          <a class="home-button home-button-secondary" href="<?= BASE_URL ?>register.php">Start an application <span aria-hidden="true">&rarr;</span></a>
        </div>
        <p class="home-note">A welcoming community for every learner.</p>
      </div>

      <div class="home-art" aria-hidden="true">
        <div class="home-art-orbit home-art-orbit-one"></div>
        <div class="home-art-orbit home-art-orbit-two"></div>
        <div class="home-art-sun"></div>
        <div class="home-art-book">
          <span></span><span></span><span></span>
        </div>
        <div class="home-art-star home-art-star-one"></div>
        <div class="home-art-star home-art-star-two"></div>
      </div>
    </div>
    <a class="home-scroll-link" href="#welcome">Explore <span aria-hidden="true">&darr;</span></a>
  </section>

  <section class="home-welcome" id="welcome">
    <div class="home-section-heading">
      <p class="home-kicker">Learning with purpose</p>
      <h2>More than a place to study</h2>
      <p>
        School is where knowledge meets confidence, friendships take root, and
        young people build the skills to move forward.
      </p>
    </div>

    <div class="home-pillars">
      <article class="home-pillar">
        <span class="home-pillar-number">01</span>
        <h3>Keep curious</h3>
        <p>Ask questions, explore new ideas, and make learning part of every day.</p>
        <span class="home-pillar-mark" aria-hidden="true">✳</span>
      </article>
      <article class="home-pillar">
        <span class="home-pillar-number">02</span>
        <h3>Grow together</h3>
        <p>Build respect, resilience, and a sense of belonging in a caring community.</p>
        <span class="home-pillar-mark" aria-hidden="true">↗</span>
      </article>
      <article class="home-pillar">
        <span class="home-pillar-number">03</span>
        <h3>Look ahead</h3>
        <p>Develop the confidence and foundations to pursue your ambitions.</p>
        <span class="home-pillar-mark" aria-hidden="true">◎</span>
      </article>
    </div>
  </section>

  <section class="home-next-step">
    <div>
      <p class="home-kicker">Your next step</p>
      <h2>Get to know our school.</h2>
      <p>Explore school information, read the latest updates, or get in touch with us.</p>
    </div>
    <div class="home-next-links">
      <a href="<?= BASE_URL ?>news.php">Latest news <span aria-hidden="true">&rarr;</span></a>
      <a href="<?= BASE_URL ?>gallery.php">School gallery <span aria-hidden="true">&rarr;</span></a>
      <a href="<?= BASE_URL ?>contact.php">Contact us <span aria-hidden="true">&rarr;</span></a>
    </div>
  </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
