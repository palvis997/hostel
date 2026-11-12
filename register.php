<?php
$pageTitle = 'Student Registration';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (!empty($_SESSION['user_id'])) {
    redirect(($_SESSION['role'] ?? '') === 'admin' ? 'admin_dashboard.php' : 'student_dashboard.php');
}

$errors = [];
$old = ['reg_no' => '', 'full_name' => '', 'email' => '', 'phone' => '', 'gender' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $errors[] = 'Your form session has expired. Please try again.';
    } else {
        foreach (array_keys($old) as $field) {
            $old[$field] = post($field);
        }
        $old['reg_no'] = strtoupper($old['reg_no']);
        $old['email'] = strtolower($old['email']);
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');

        if (!is_valid_reg_no($old['reg_no'])) {
            $errors[] = 'Registration number must look like CT100/G/12345/24.';
        }
        if (!preg_match("/^[A-Za-z][A-Za-z .'-]{2,99}$/", $old['full_name'])) {
            $errors[] = 'Enter a valid full name (letters only, 3 to 100 characters).';
        }
        if (!is_kyu_email($old['email'])) {
            $errors[] = KYU_EMAIL_MESSAGE . '.';
        }
        if (!is_valid_phone($old['phone'])) {
            $errors[] = 'Phone number must be exactly 10 digits.';
        }
        if (!in_array($old['gender'], ['Male', 'Female'], true)) {
            $errors[] = 'Please select your gender.';
        }
        if (!is_strong_password($password)) {
            $errors[] = 'Password needs at least 8 characters, including a letter and a digit.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        if (!$errors) {
            try {
                $check = $conn->prepare('SELECT user_id FROM users WHERE reg_no = ? OR email = ?');
                $check->bind_param('ss', $old['reg_no'], $old['email']);
                $check->execute();
                if ($check->get_result()->num_rows > 0) {
                    $errors[] = 'An account with that registration number or email already exists.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $insert = $conn->prepare("INSERT INTO users (reg_no, full_name, email, phone, gender, password_hash, role) VALUES (?, ?, ?, ?, ?, ?, 'student')");
                    $insert->bind_param('ssssss', $old['reg_no'], $old['full_name'], $old['email'], $old['phone'], $old['gender'], $hash);
                    $insert->execute();
                    set_flash('success', 'Account created. You can now sign in.');
                    redirect('login.php');
                }
            } catch (mysqli_sql_exception $ex) {
                error_log($ex->getMessage());
                $errors[] = 'Could not create your account. Please try again.';
            }
        }
    }
}

require __DIR__ . '/header.php';
?>
<div class="auth-wrap">
  <div class="auth-split row g-0">
    <div class="col-lg-5 auth-hero-col">
      <section class="kyu-hero auth-hero">
        <?php render_image('auth-evening-register.jpg', 'Campus walkway lit by evening lamps', 'kyu-hero-img', false); ?>
        <div class="kyu-hero-overlay" aria-hidden="true"></div>
        <div class="kyu-hero-content auth-hero-content">
          <p class="auth-eyebrow mb-2"><?= e(KYU_NAME) ?></p>
          <h2 class="kyu-hero-title">Your Campus Home Awaits</h2>
          <p class="kyu-hero-subtitle mb-0">Create your student account to find a room, apply online and follow your allocation.</p>
        </div>
      </section>
    </div>

    <div class="col-lg-7 auth-card-col">
      <div class="auth-form">
        <span class="role-pill role-student">Student registration</span>
        <h1 class="h3 fw-bold mt-3 mb-1">Create your account</h1>
        <p class="text-muted mb-4">Use your official registration number and your Kirinyaga University email.</p>

        <?php foreach ($errors as $error): ?>
          <div class="alert alert-danger py-2" role="alert"><?= e($error) ?></div>
        <?php endforeach; ?>

        <form method="post" action="register.php" data-validate novalidate>
          <?= csrf_field() ?>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="reg_no">Registration number</label>
            <div class="input-group has-validation">
              <span class="input-group-text"><i class="bi bi-person-badge" aria-hidden="true"></i></span>
              <input id="reg_no" name="reg_no" class="form-control" placeholder="CT100/G/12345/24" value="<?= e($old['reg_no']) ?>" data-rule="reg">
              <div class="invalid-feedback">Format: CT100/G/12345/24</div>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="full_name">Full name</label>
            <div class="input-group has-validation">
              <span class="input-group-text"><i class="bi bi-person" aria-hidden="true"></i></span>
              <input id="full_name" name="full_name" class="form-control" value="<?= e($old['full_name']) ?>" data-rule="name">
              <div class="invalid-feedback">Enter your full name (letters only).</div>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="email">Kirinyaga University email</label>
            <div class="input-group has-validation">
              <span class="input-group-text"><i class="bi bi-envelope" aria-hidden="true"></i></span>
              <input id="email" type="email" name="email" class="form-control" value="<?= e($old['email']) ?>" data-rule="kyuemail" autocomplete="email" placeholder="name@student.kyu.ac.ke">
              <div class="invalid-feedback"><?= e(KYU_EMAIL_MESSAGE) ?>.</div>
            </div>
            <div class="form-text">Use an address ending with @student.kyu.ac.ke or @kyu.ac.ke.</div>
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold" for="phone">Phone (10 digits)</label>
              <div class="input-group has-validation">
                <span class="input-group-text"><i class="bi bi-telephone" aria-hidden="true"></i></span>
                <input id="phone" name="phone" class="form-control" inputmode="numeric" maxlength="10" value="<?= e($old['phone']) ?>" data-rule="phone">
                <div class="invalid-feedback">Phone must be exactly 10 digits.</div>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold" for="gender">Gender</label>
              <div class="input-group has-validation">
                <span class="input-group-text"><i class="bi bi-gender-ambiguous" aria-hidden="true"></i></span>
                <select id="gender" name="gender" class="form-select" data-rule="select">
                  <option value="">Select gender</option>
                  <option <?= $old['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                  <option <?= $old['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                </select>
                <div class="invalid-feedback">Please select your gender.</div>
              </div>
            </div>
          </div>

          <div class="row g-3 mt-0">
            <div class="col-md-6">
              <label class="form-label fw-semibold" for="password">Password</label>
              <div class="input-group has-validation">
                <span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span>
                <input id="password" type="password" name="password" class="form-control" data-rule="password" autocomplete="new-password">
                <div class="invalid-feedback">At least 8 characters, with a letter and a digit.</div>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold" for="confirm">Confirm password</label>
              <div class="input-group has-validation">
                <span class="input-group-text"><i class="bi bi-shield-lock" aria-hidden="true"></i></span>
                <input id="confirm" type="password" name="confirm" class="form-control" data-rule="match" data-match="#password" autocomplete="new-password">
                <div class="invalid-feedback">Passwords do not match.</div>
              </div>
            </div>
          </div>

          <button class="btn btn-primary btn-lg w-100 mt-4">Create account</button>
          <p class="text-center mt-4 mb-0">Already registered? <a href="login.php">Sign in</a></p>
        </form>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
