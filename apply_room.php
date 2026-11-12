<?php
$pageTitle = 'Apply for a Room';
require_once __DIR__ . '/auth_check.php';
require_login('student');

$uid = (int)$_SESSION['user_id'];
$errors = [];

try {
    $profile = fetch_user_profile($conn, $uid);
    $gender = (string)($profile['gender'] ?? '');
    $emailOk = is_kyu_email((string)($profile['email'] ?? ''));

    $activeStmt = $conn->prepare("SELECT COUNT(*) AS n FROM applications WHERE user_id = ? AND status IN ('Pending', 'Approved')");
    $activeStmt->bind_param('i', $uid);
    $activeStmt->execute();
    $hasActive = (int)$activeStmt->get_result()->fetch_assoc()['n'] > 0;
} catch (mysqli_sql_exception $ex) {
    error_log($ex->getMessage());
    exit('Something went wrong. Please try again later.');
}

$hostelFilter = (int)($_GET['hostel'] ?? 0);
$selectedRoom = (int)($_GET['room_id'] ?? 0);
$semesterChoice = '';
$paymentMethod = '';
$paymentReference = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedRoom = (int)($_POST['room_id'] ?? 0);
    $semesterChoice = post('semester');
    $paymentMethod = post('payment_method');
    $paymentReference = strtoupper(post('payment_reference'));

    if (!csrf_ok()) {
        $errors[] = 'Your form session has expired. Please try again.';
    }
    if (!$emailOk) {
        $errors[] = KYU_EMAIL_MESSAGE . '.';
    }
    if ($hasActive) {
        $errors[] = 'You already have a pending or approved application.';
    }
    if ($selectedRoom <= 0) {
        $errors[] = 'Please choose a room.';
    }
    if (!in_array($semesterChoice, SEMESTERS, true)) {
        $errors[] = 'Please select a valid semester.';
    }
    if (!in_array($paymentMethod, PAYMENT_METHODS, true)) {
        $errors[] = 'Please choose how you paid.';
    }
    if (!valid_payment_reference($paymentMethod, $paymentReference)) {
        $errors[] = $paymentMethod === MPESA_METHOD
            ? 'An M-Pesa transaction code is exactly 10 letters and numbers, for example QHK78923KL.'
            : 'Enter the payment reference: 6 to 20 letters, numbers, hyphens or slashes.';
    }
    if (empty($_POST['terms'])) {
        $errors[] = 'You must accept the hostel rules.';
    }

    if (!$errors) {
        try {
            $conn->begin_transaction();

            // One transaction code cannot back two active applications.
            $duplicateStmt = $conn->prepare("SELECT COUNT(*) AS n FROM applications WHERE payment_reference = ? AND status IN ('Pending', 'Approved')");
            $duplicateStmt->bind_param('s', $paymentReference);
            $duplicateStmt->execute();
            if ((int)$duplicateStmt->get_result()->fetch_assoc()['n'] > 0) {
                $errors[] = 'This payment reference is already attached to another active application.';
            }

            $roomStmt = $conn->prepare("SELECT r.capacity, r.status, h.gender, h.fee_per_semester,
                    (SELECT COUNT(*) FROM applications a WHERE a.room_id = r.room_id AND a.status = 'Approved') AS occupied
                FROM rooms r
                JOIN hostels h ON h.hostel_id = r.hostel_id
                WHERE r.room_id = ?");
            $roomStmt->bind_param('i', $selectedRoom);
            $roomStmt->execute();
            $room = $roomStmt->get_result()->fetch_assoc();

            if (!$room) {
                $errors[] = 'The selected room does not exist.';
            } elseif ($room['status'] !== 'Available' || (int)$room['occupied'] >= (int)$room['capacity']) {
                $errors[] = 'That room is no longer available.';
            } elseif (!eligible_for((string)$room['gender'], $gender)) {
                $errors[] = 'That hostel is not open to your gender.';
            }

            if ($errors) {
                $conn->rollback();
            } else {
                $fee = (float)$room['fee_per_semester'];
                $insert = $conn->prepare("INSERT INTO applications
                    (user_id, room_id, semester, status, amount_due, payment_method, payment_reference, payment_status)
                    VALUES (?, ?, ?, 'Pending', ?, ?, ?, 'Unverified')");
                $insert->bind_param('iisdss', $uid, $selectedRoom, $semesterChoice, $fee, $paymentMethod, $paymentReference);
                $insert->execute();
                $conn->commit();
                set_flash('success', 'Application submitted. The warden will verify your payment and review it shortly.');
                redirect('student_dashboard.php');
            }
        } catch (mysqli_sql_exception $ex) {
            $conn->rollback();
            error_log($ex->getMessage());
            $errors[] = 'Could not submit your application. Please try again.';
        }
    }
}

try {
    $allRooms = fetch_rooms($conn);
} catch (mysqli_sql_exception $ex) {
    error_log($ex->getMessage());
    $allRooms = [];
}

// Only rooms in hostels open to this student's gender, optionally limited to one hostel.
$rooms = array_values(array_filter($allRooms, static function (array $candidate) use ($gender, $hostelFilter): bool {
    if (!eligible_for((string)$candidate['gender'], $gender)) {
        return false;
    }
    return $hostelFilter === 0 || (int)$candidate['hostel_id'] === $hostelFilter;
}));

$filterName = '';
$openCount = 0;
foreach ($rooms as $candidate) {
    if (room_free_beds($candidate) > 0) {
        $openCount++;
    }
    if ($hostelFilter !== 0 && (int)$candidate['hostel_id'] === $hostelFilter) {
        $filterName = (string)$candidate['hostel_name'];
    }
}

$formAction = 'apply_room.php' . ($hostelFilter !== 0 ? '?hostel=' . $hostelFilter : '');
$canSubmit = $emailOk && !$hasActive;

require __DIR__ . '/header.php';
?>
<?php render_banner('banner-atrium.jpg', 'Bright atrium with a glass dome and curved balconies', 'Find & Book Your Ideal Campus Room', 'Choose a room, add your payment evidence and submit your application in minutes.'); ?>

<?php if (!$emailOk): ?>
  <div class="alert alert-danger" role="alert">
    <strong>Your account email is not accepted.</strong> <?= e(KYU_EMAIL_MESSAGE) ?>. Contact the warden office to correct it before you apply.
  </div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
  <div class="alert alert-danger py-2" role="alert"><?= e($error) ?></div>
<?php endforeach; ?>
<?php if ($hasActive): ?>
  <div class="alert alert-info">You already have an active application. Cancel it from your dashboard to apply for another room.</div>
<?php endif; ?>

<form method="post" action="<?= e($formAction) ?>" class="row g-4" data-validate data-room-form novalidate>
  <?= csrf_field() ?>

  <div class="col-lg-8">
    <div class="mb-3">
      <h2 class="h4 section-title mb-2">Choose your room</h2>
      <p class="small text-muted mb-0">
        <?= $filterName !== '' ? 'Showing rooms in ' . e($filterName) . '. ' : '' ?>
        <?= $openCount ?> of <?= count($rooms) ?> rooms have free beds.
        <?php if ($hostelFilter !== 0): ?><a href="apply_room.php">Show all hostels</a><?php endif; ?>
      </p>
    </div>

    <div id="roomError" class="alert alert-danger py-2 d-none" role="alert">Please choose a room before submitting.</div>
    <?php if (!$rooms): ?>
      <div class="alert alert-warning">No rooms are open to you in this selection.</div>
    <?php endif; ?>

    <div class="row row-cols-1 row-cols-md-2 g-3">
      <?php foreach ($rooms as $room): ?>
        <?php
          $roomId = (int)$room['room_id'];
          $capacity = (int)$room['capacity'];
          $free = room_free_beds($room);
          $bookable = $free > 0;
          $badge = availability($free);
          $inputId = 'room-' . $roomId;
        ?>
        <div class="col">
          <input type="radio" class="room-input visually-hidden" name="room_id" id="<?= e($inputId) ?>" value="<?= $roomId ?>"
            data-hostel="<?= e($room['hostel_name']) ?>"
            data-room="<?= e($room['room_number']) ?>"
            data-capacity="<?= $capacity ?>"
            data-free="<?= $free ?>"
            data-fee="<?= e(number_format((float)$room['fee_per_semester'], 2, '.', '')) ?>"
            <?= $bookable ? '' : 'disabled' ?> <?= ($bookable && $selectedRoom === $roomId) ? 'checked' : '' ?>>
          <label class="room-card<?= $bookable ? '' : ' is-disabled' ?>" for="<?= e($inputId) ?>">
            <span class="room-card-media">
              <?php render_image(room_photo_for($capacity), $capacity . '-bed room interior', 'room-card-img'); ?>
              <span class="badge rounded-pill text-bg-<?= e($badge['class']) ?> room-card-status"><?= e($badge['label']) ?></span>
            </span>
            <span class="room-card-body">
              <span class="d-flex justify-content-between align-items-center mb-1">
                <span class="fw-bold">Room <?= e($room['room_number']) ?></span>
                <span class="small text-muted"><?= $capacity ?>-bed</span>
              </span>
              <span class="small text-muted mb-2"><?= e($room['hostel_name']) ?></span>
              <span class="amenity-list mb-3"><?php render_amenity_badges((string)$room['amenities']); ?></span>
              <span class="d-flex justify-content-between align-items-center mt-auto">
                <span class="fw-bold text-success"><?= e(money_short($room['fee_per_semester'])) ?> <small class="fw-normal text-muted">/ semester</small></span>
                <span class="small fw-semibold <?= $bookable ? 'text-success' : 'text-muted' ?>"><?= $bookable ? 'Select' : 'Unavailable' ?></span>
              </span>
            </span>
          </label>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="col-lg-4">
    <aside class="card fee-summary p-4" aria-live="polite">
      <h2 class="h5 fw-bold mb-3">Live fee breakdown</h2>
      <dl class="fee-list mb-3">
        <div><dt>Hostel</dt><dd data-field="hostel">Not selected</dd></div>
        <div><dt>Room</dt><dd data-field="room">-</dd></div>
        <div><dt>Bed type</dt><dd data-field="beds">-</dd></div>
        <div><dt>Semester</dt><dd data-field="semester">Not selected</dd></div>
        <div><dt>Semester fee</dt><dd data-field="fee">KES 0.00</dd></div>
        <div><dt>Paid so far</dt><dd data-field="paid">KES 0.00</dd></div>
      </dl>
      <div class="fee-total d-flex justify-content-between align-items-center mb-4">
        <span class="fw-semibold">Balance after approval</span>
        <span class="fs-5 fw-bold text-success" data-field="balance">KES 0.00</span>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold" for="semester">Semester</label>
        <div class="input-group has-validation">
          <span class="input-group-text"><i class="bi bi-calendar-check" aria-hidden="true"></i></span>
          <select id="semester" name="semester" class="form-select" data-rule="select">
            <option value="">Choose a semester</option>
            <?php foreach (SEMESTERS as $semesterOption): ?>
              <option value="<?= e($semesterOption) ?>" <?= $semesterChoice === $semesterOption ? 'selected' : '' ?>><?= e($semesterOption) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="invalid-feedback">Please select a semester.</div>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold" for="payment_method">Payment method</label>
        <div class="input-group has-validation">
          <span class="input-group-text"><i class="bi bi-wallet2" aria-hidden="true"></i></span>
          <select id="payment_method" name="payment_method" class="form-select" data-rule="select">
            <option value="">Choose how you paid</option>
            <?php foreach (PAYMENT_METHODS as $methodOption): ?>
              <option value="<?= e($methodOption) ?>" <?= $paymentMethod === $methodOption ? 'selected' : '' ?>><?= e($methodOption) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="invalid-feedback">Choose how you paid.</div>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold" for="payment_reference">Payment reference / M-Pesa transaction code</label>
        <div class="input-group has-validation">
          <span class="input-group-text"><i class="bi bi-receipt" aria-hidden="true"></i></span>
          <input id="payment_reference" name="payment_reference" class="form-control text-uppercase" placeholder="e.g. QHK78923KL" maxlength="20" value="<?= e($paymentReference) ?>" data-rule="paycode" data-method="#payment_method" autocomplete="off">
          <div class="invalid-feedback">M-Pesa codes are 10 letters and numbers. Other references are 6 to 20 characters.</div>
        </div>
        <div class="form-text">Enter the code from your payment confirmation message.</div>
      </div>

      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="terms" id="terms" data-rule="checked" <?= empty($_POST['terms']) ? '' : 'checked' ?>>
        <label class="form-check-label small" for="terms">I agree to follow the hostel rules and regulations.</label>
        <div class="invalid-feedback">You must accept the hostel rules.</div>
      </div>

      <p class="small text-muted mb-4">Your balance is confirmed when the warden verifies your payment and approves the application. Payments are recorded on your fee statement.</p>
      <button class="btn btn-primary btn-lg w-100" <?= $canSubmit ? '' : 'disabled' ?>>Submit application</button>
    </aside>
  </div>
</form>
<?php require __DIR__ . '/footer.php'; ?>
