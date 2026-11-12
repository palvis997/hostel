<?php
$pageTitle = 'Home';
$noContainer = true;
require_once __DIR__ . '/header.php';

$role = $_SESSION['role'] ?? null;
$loggedIn = $role !== null;

// Homepage carousel: daytime campus photography, cropped to 16:9.
$slides = [
    [
        'image' => 'campus-modern-students.jpg',
        'alt'   => 'Modern campus building with columns and students walking outside',
        'title' => 'Welcome to Kirinyaga University Hostels',
        'text'  => 'Modern, secure, and comfortable accommodation for all students.',
        'cta'   => ['href' => '#rooms', 'label' => 'Browse rooms'],
    ],
    [
        'image' => 'campus-lawn-building.jpg',
        'alt'   => 'Campus building beside a green lawn and a paved walkway in daylight',
        'title' => 'Modern, Secure & Comfortable Student Accommodation',
        'text'  => 'Well-kept hostel blocks in a quiet, green campus setting.',
        'cta'   => ['href' => '#hostels', 'label' => 'View hostel blocks'],
    ],
    [
        'image' => 'campus-bright-block.jpg',
        'alt'   => 'Long campus block with green window frames under a bright blue sky',
        'title' => 'Seamless Online Room Booking',
        'text'  => 'Apply for a room, compare hostels and track your allocation in real time.',
        'cta'   => $loggedIn
            ? ['href' => $role === 'admin' ? 'admin_dashboard.php' : 'student_dashboard.php', 'label' => 'Go to my dashboard']
            : ['href' => 'login.php', 'label' => 'Student login'],
    ],
];

try {
    $rooms = fetch_rooms($conn);
    $hostels = fetch_hostel_summaries($conn, $rooms);
    $studentGender = $role === 'student' ? current_user_gender($conn, (int)$_SESSION['user_id']) : '';
    $roomsLoaded = true;
} catch (mysqli_sql_exception $ex) {
    error_log($ex->getMessage());
    $rooms = [];
    $hostels = [];
    $studentGender = '';
    $roomsLoaded = false;
}

$totalBeds = (int)array_sum(array_column($hostels, 'total_beds'));
$freeBeds = (int)array_sum(array_column($hostels, 'free_beds'));
$hostelCount = count($hostels);

$steps = [
    ['bi-search', 'Browse', 'See live room availability across every hostel block.'],
    ['bi-pencil-square', 'Apply', 'Submit one application per semester in a few clicks.'],
    ['bi-check2-circle', 'Get allocated', "Track your warden's decision and your fee balance online."],
    ['bi-tools', 'Report issues', 'File plumbing, electrical or furniture tickets and follow their progress.'],
];
?>
<div id="kyuCampusCarousel" class="carousel slide carousel-fade kyu-carousel" data-bs-ride="carousel" data-bs-interval="5000" data-bs-pause="hover" aria-label="Kirinyaga University highlights">
  <div class="carousel-indicators">
    <?php foreach ($slides as $i => $slide): ?>
      <button type="button" data-bs-target="#kyuCampusCarousel" data-bs-slide-to="<?= $i ?>"<?= $i === 0 ? ' class="active" aria-current="true"' : '' ?> aria-label="Show slide <?= $i + 1 ?>"></button>
    <?php endforeach; ?>
  </div>

  <div class="carousel-inner">
    <?php foreach ($slides as $i => $slide): ?>
      <div class="carousel-item kyu-hero<?= $i === 0 ? ' active' : '' ?>">
        <?php render_image($slide['image'], $slide['alt'], 'kyu-hero-img', $i > 0); ?>
        <div class="kyu-hero-overlay" aria-hidden="true"></div>
        <div class="carousel-caption kyu-caption">
          <h2 class="kyu-caption-title"><?= e($slide['title']) ?></h2>
          <p class="kyu-caption-text"><?= e($slide['text']) ?></p>
          <a class="btn btn-warning btn-lg mt-3" href="<?= e($slide['cta']['href']) ?>"><?= e($slide['cta']['label']) ?></a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <button class="carousel-control-prev" type="button" data-bs-target="#kyuCampusCarousel" data-bs-slide="prev">
    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
    <span class="visually-hidden">Previous slide</span>
  </button>
  <button class="carousel-control-next" type="button" data-bs-target="#kyuCampusCarousel" data-bs-slide="next">
    <span class="carousel-control-next-icon" aria-hidden="true"></span>
    <span class="visually-hidden">Next slide</span>
  </button>
</div>

<div class="container py-4">
  <?php render_flash(); ?>

  <div class="row g-3 mb-5">
    <div class="col-md-4"><div class="card stat-card p-3 h-100"><div class="small text-uppercase text-muted">Hostel blocks</div><div class="display-6"><?= $hostelCount ?></div></div></div>
    <div class="col-md-4"><div class="card stat-card p-3 h-100"><div class="small text-uppercase text-muted">Total beds</div><div class="display-6"><?= $totalBeds ?></div></div></div>
    <div class="col-md-4"><div class="card stat-card p-3 h-100"><div class="small text-uppercase text-muted">Beds available now</div><div class="display-6"><?= $freeBeds ?></div></div></div>
  </div>

  <section id="hostels" class="mb-5">
    <h2 class="h3 section-title mb-4">Hostel blocks</h2>
    <div class="row g-4">
      <?php foreach ($hostels as $hostel): ?>
        <?php
          $action = hostel_action($hostel, $role, $studentGender);
          $badge = availability((int)$hostel['free_beds']);
        ?>
        <div class="col-md-6 col-xl-4">
          <article class="hostel-tilt">
            <div class="card hostel-card">
              <div class="hostel-card-media">
                <?php render_image((string)$hostel['image'], 'Exterior of ' . $hostel['name'], 'hostel-card-img'); ?>
                <span class="price-tag"><?= e(money_short($hostel['fee_per_semester'])) ?> <small>/ semester</small></span>
              </div>
              <div class="card-body d-flex flex-column">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                  <h3 class="h5 mb-0"><?= e($hostel['name']) ?></h3>
                  <span class="badge rounded-pill text-bg-<?= e($badge['class']) ?>"><?= e($badge['label']) ?></span>
                </div>
                <p class="small text-muted mb-2"><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= e($hostel['location']) ?></p>
                <p class="small mb-3"><?= e(hostel_gender_label((string)$hostel['gender'])) ?> &middot; <?= (int)$hostel['room_count'] ?> rooms &middot; <?= (int)$hostel['total_beds'] ?> beds</p>
                <div class="mt-auto">
                  <?php if ($action['disabled']): ?>
                    <button type="button" class="btn btn-outline-secondary w-100" disabled><?= e($action['label']) ?></button>
                  <?php else: ?>
                    <a class="btn btn-primary w-100" href="<?= e($action['href']) ?>"><?= e($action['label']) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </article>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section id="rooms" class="mb-5">
    <?php render_banner('banner-courtyard.jpg', 'Campus courtyard with tall trees under a blue sky', 'Browse Available Rooms', 'Search by room number, filter by hostel, or show only rooms with free beds.'); ?>
    <?php if ($roomsLoaded): ?>
      <?php render_rooms_table($rooms, $role === 'student'); ?>
    <?php else: ?>
      <div class="alert alert-danger">Room availability could not be loaded right now. Please refresh the page.</div>
    <?php endif; ?>
  </section>

  <section class="mb-4">
    <h2 class="h3 section-title mb-4">How it works</h2>
    <div class="row g-3">
      <?php foreach ($steps as [$icon, $stepTitle, $stepText]): ?>
        <div class="col-6 col-lg-3">
          <div class="card feature h-100 p-3 text-center">
            <div class="feature-icon"><i class="bi <?= e($icon) ?>" aria-hidden="true"></i></div>
            <h3 class="h6 fw-bold"><?= e($stepTitle) ?></h3>
            <p class="small text-muted mb-0"><?= e($stepText) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>
<?php require __DIR__ . '/footer.php'; ?>
