<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

$pageTitle = $pageTitle ?? 'Accommodation Portal';
$bodyClass = $bodyClass ?? '';
$noContainer = $noContainer ?? false;
$role = $_SESSION['role'] ?? null;
$currentPage = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

// Main navigation for the signed-in role, or for guests. Each entry is [file, label].
$navItems = match ($role) {
    'student' => [['index.php', 'Rooms'], ['apply_room.php', 'Apply for Room'], ['student_dashboard.php', 'My Dashboard']],
    'admin'   => [['index.php', 'Rooms'], ['admin_dashboard.php', 'Admin Portal'], ['reports.php', 'Reports']],
    default   => [['index.php', 'Rooms'], ['login.php', 'Student Login'], ['admin_login.php', 'Admin Login']],
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?> | <?= e(KYU_NAME) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="style.css" rel="stylesheet">
  <script>
    // Hides any image that fails to load (for example the university logo if kyu.ac.ke is unreachable).
    document.addEventListener('error', function (event) {
      if (event.target instanceof HTMLImageElement) {
        event.target.classList.add('img-failed');
      }
    }, true);
  </script>
</head>
<body class="<?= e($bodyClass) ?>">
<a class="visually-hidden-focusable" href="#main">Skip to main content</a>

<nav class="navbar navbar-expand-lg navbar-dark bg-kyu sticky-top shadow-sm">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
      <img src="<?= e(KYU_LOGO) ?>" alt="Kirinyaga University logo" class="brand-logo">
      <span class="lh-sm">
        <span class="d-block fw-bold"><?= e(KYU_NAME) ?></span>
        <span class="brand-sub">Smart Accommodation &amp; Facilities</span>
      </span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav me-auto">
        <?php foreach ($navItems as [$href, $linkLabel]): ?>
          <?php $active = $href === $currentPage; ?>
          <li class="nav-item">
            <a class="nav-link<?= $active ? ' active' : '' ?>" href="<?= e($href) ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= e($linkLabel) ?></a>
          </li>
        <?php endforeach; ?>
      </ul>

      <ul class="navbar-nav align-items-lg-center gap-lg-2">
        <?php if ($role): ?>
          <li class="nav-item">
            <span class="navbar-text me-lg-2">
              <?= e($_SESSION['full_name'] ?? '') ?>
              <span class="badge text-bg-warning ms-1"><?= $role === 'admin' ? 'Administrator' : 'Student' ?></span>
            </span>
          </li>
          <li class="nav-item"><a class="btn btn-outline-light btn-sm" href="logout.php">Logout</a></li>
        <?php else: ?>
          <li class="nav-item"><a class="btn btn-warning btn-sm<?= $currentPage === 'register.php' ? ' active' : '' ?>" href="register.php">Register</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<?php if ($noContainer): ?>
<main id="main">
<?php else: ?>
<main id="main" class="container py-4">
<?php render_flash(); ?>
<?php endif; ?>
