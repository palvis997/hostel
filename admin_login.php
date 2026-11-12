<?php
$pageTitle = 'Administrator Login';
$bodyClass = 'admin-login';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (!empty($_SESSION['user_id'])) {
    redirect(($_SESSION['role'] ?? '') === 'admin' ? 'admin_dashboard.php' : 'student_dashboard.php');
}

$error = '';
$identifier = $_COOKIE['remember_admin'] ?? '';
$identifier = is_string($identifier) ? substr($identifier, 0, 120) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = post('identifier');
    $password = (string)($_POST['password'] ?? '');

    if (!csrf_ok()) {
        $error = 'Your form session has expired. Please try again.';
    } elseif ($identifier === '' || $password === '') {
        $error = 'Enter your staff email or ID, and your password.';
    } else {
        try {
            $stmt = $conn->prepare("SELECT user_id, full_name, password_hash FROM users WHERE (reg_no = ? OR email = ?) AND role = 'admin' LIMIT 1");
            $key = strtoupper($identifier);
            $stmt->bind_param('ss', $key, $identifier);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['user_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = 'admin';
                $_SESSION['last_active'] = time();

                $cookie = ['expires' => time() + 60 * 60 * 24 * 7, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax'];
                if (!empty($_POST['remember'])) {
                    setcookie('remember_admin', $identifier, $cookie);
                } else {
                    setcookie('remember_admin', '', ['expires' => time() - 3600, 'path' => '/']);
                }
                redirect('admin_dashboard.php');
            }
            $error = 'Invalid administrator credentials.';
        } catch (mysqli_sql_exception $ex) {
            error_log($ex->getMessage());
            $error = 'Login is unavailable right now. Please try again.';
        }
    }
}

require __DIR__ . '/header.php';
?>
<div class="auth-wrap">
  <div class="auth-split row g-0">
    <div class="col-lg-6 auth-hero-col">
      <section class="kyu-hero auth-hero">
        <?php render_image('auth-evening-admin.jpg', 'Campus walkway lit by evening lamps', 'kyu-hero-img', false); ?>
        <div class="kyu-hero-overlay" aria-hidden="true"></div>
        <div class="kyu-hero-content auth-hero-content">
          <p class="auth-eyebrow mb-2"><?= e(KYU_NAME) ?></p>
          <h2 class="kyu-hero-title">Staff Access Only</h2>
          <p class="kyu-hero-subtitle mb-0">Approve allocations, record fee payments, manage hostel inventory and track maintenance.</p>
        </div>
      </section>
    </div>

    <div class="col-lg-6 auth-card-col">
      <div class="auth-form">
        <span class="role-pill role-admin">Administrator login</span>
        <h1 class="h3 fw-bold mt-3 mb-1">Warden sign in</h1>
        <p class="text-muted mb-4">Use your staff email address or staff ID.</p>

        <?php if ($error !== ''): ?>
          <div class="alert alert-danger py-2"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="admin_login.php" data-validate novalidate>
          <?= csrf_field() ?>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="identifier">Staff email or ID</label>
            <div class="input-group has-validation">
              <span class="input-group-text"><i class="bi bi-person-badge" aria-hidden="true"></i></span>
              <input id="identifier" name="identifier" class="form-control form-control-lg" placeholder="warden@kyu.ac.ke" value="<?= e($identifier) ?>" data-rule="required" autocomplete="username" autofocus>
              <div class="invalid-feedback">Enter your staff email or ID.</div>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="password">Password</label>
            <div class="input-group has-validation">
              <span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span>
              <input id="password" type="password" name="password" class="form-control form-control-lg" data-rule="required" autocomplete="current-password">
              <div class="invalid-feedback">Enter your password.</div>
            </div>
          </div>

          <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox" name="remember" id="remember" <?= $identifier !== '' ? 'checked' : '' ?>>
            <label class="form-check-label" for="remember">Remember my ID on this device</label>
          </div>

          <button class="btn btn-admin btn-lg w-100">Administrator sign in</button>
          <p class="text-center small text-muted mt-4 mb-0">Are you a student? <a href="login.php">Student login</a></p>
        </form>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
