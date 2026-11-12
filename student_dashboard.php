<?php
$pageTitle = 'My Dashboard';
require_once __DIR__ . '/auth_check.php';
require_login('student');

$uid = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    try {
        if (!csrf_ok()) {
            set_flash('danger', 'Your form session has expired. Please try again.');
        } elseif ($action === 'submit_ticket') {
            $category = post('category');
            $description = post('description');
            if (!in_array($category, TICKET_CATEGORIES, true)) {
                set_flash('danger', 'Select a valid category.');
            } elseif (mb_strlen($description) < 10 || mb_strlen($description) > 500) {
                set_flash('danger', 'Describe the problem in 10 to 500 characters.');
            } else {
                $roomStmt = $conn->prepare("SELECT room_id FROM applications WHERE user_id = ? AND status = 'Approved' LIMIT 1");
                $roomStmt->bind_param('i', $uid);
                $roomStmt->execute();
                $row = $roomStmt->get_result()->fetch_assoc();
                $roomId = $row ? (int)$row['room_id'] : null;

                $insert = $conn->prepare('INSERT INTO maintenance_tickets (user_id, room_id, category, description) VALUES (?, ?, ?, ?)');
                $insert->bind_param('iiss', $uid, $roomId, $category, $description);
                $insert->execute();
                set_flash('success', 'Maintenance ticket submitted.');
            }
        } elseif ($action === 'cancel_application') {
            $applicationId = (int)post('application_id');
            $cancel = $conn->prepare("DELETE FROM applications WHERE application_id = ? AND user_id = ? AND status = 'Pending'");
            $cancel->bind_param('ii', $applicationId, $uid);
            $cancel->execute();
            if ($cancel->affected_rows > 0) {
                set_flash('success', 'Application cancelled.');
            } else {
                set_flash('warning', 'Only your own pending applications can be cancelled.');
            }
        }
    } catch (mysqli_sql_exception $ex) {
        error_log($ex->getMessage());
        set_flash('danger', 'Something went wrong. Please try again.');
    }
    redirect('student_dashboard.php');
}

try {
    $profile = fetch_user_profile($conn, $uid);

    $applicationStmt = $conn->prepare("SELECT a.application_id, a.semester, a.status, a.amount_due, a.amount_paid,
            a.payment_method, a.payment_reference, a.payment_status, a.rejection_reason,
            a.applied_at, a.decided_at, r.room_number, r.capacity, r.amenities, h.name AS hostel, h.location
        FROM applications a
        JOIN rooms r ON r.room_id = a.room_id
        JOIN hostels h ON h.hostel_id = r.hostel_id
        WHERE a.user_id = ?
        ORDER BY a.applied_at DESC, a.application_id DESC");
    $applicationStmt->bind_param('i', $uid);
    $applicationStmt->execute();
    $applications = $applicationStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $ticketStmt = $conn->prepare('SELECT ticket_id, category, description, status, updated_at FROM maintenance_tickets WHERE user_id = ? ORDER BY created_at DESC');
    $ticketStmt->bind_param('i', $uid);
    $ticketStmt->execute();
    $tickets = $ticketStmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $ex) {
    error_log($ex->getMessage());
    exit('Could not load your dashboard. Please try again later.');
}

$totalDue = 0.0;
$totalPaid = 0.0;
$allocation = null;
foreach ($applications as $application) {
    if ($application['status'] === 'Approved') {
        $totalDue += (float)$application['amount_due'];
        $totalPaid += (float)$application['amount_paid'];
        $allocation ??= $application;
    }
}

// The newest application decides whether the rejection notice is shown.
$latest = $applications[0] ?? null;
$rejected = ($latest !== null && $latest['status'] === 'Rejected') ? $latest : null;
$firstName = explode(' ', (string)($profile['full_name'] ?? 'Student'))[0];

require __DIR__ . '/header.php';
?>
<?php render_banner('banner-stairwell.jpg', 'Spiral staircase inside a campus building', 'Welcome back, ' . $firstName, 'Track your allocation, fee statement and maintenance tickets from one place.'); ?>

<?php if ($rejected): ?>
  <div class="card border-danger rejection-card mb-4" role="alert">
    <div class="card-body p-4">
      <div class="d-flex gap-3 align-items-start">
        <i class="bi bi-x-octagon-fill text-danger fs-2 flex-shrink-0" aria-hidden="true"></i>
        <div class="flex-grow-1">
          <h2 class="h5 text-danger mb-2">Action needed</h2>
          <p class="mb-3"><strong>Application Rejected:</strong> <?= e($rejected['rejection_reason'] ?? 'No reason was recorded') ?>. Please update your payment details or contact the warden office.</p>
          <dl class="fee-list mb-3">
            <div><dt>Hostel block</dt><dd><?= e($rejected['hostel']) ?></dd></div>
            <div><dt>Room</dt><dd><?= e($rejected['room_number']) ?></dd></div>
            <div><dt>Semester</dt><dd><?= e($rejected['semester']) ?></dd></div>
            <div><dt>Payment method</dt><dd><?= e($rejected['payment_method'] ?? 'Not recorded') ?></dd></div>
            <div><dt>Transaction code</dt><dd class="evidence-code"><?= e($rejected['payment_reference'] ?? 'Not recorded') ?></dd></div>
            <div><dt>Payment status</dt><dd><span class="badge text-bg-<?= e(status_class((string)$rejected['payment_status'])) ?>"><?= e($rejected['payment_status']) ?></span></dd></div>
            <div><dt>Decision date</dt><dd><?= e(substr((string)($rejected['decided_at'] ?? ''), 0, 10)) ?></dd></div>
          </dl>
          <a class="btn btn-primary" href="apply_room.php">Apply again with corrected details</a>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="row g-4 mb-4">
  <div class="col-lg-4">
    <div class="card h-100 p-4">
      <h2 class="h6 text-uppercase text-muted mb-3">My profile</h2>
      <dl class="fee-list mb-0">
        <div><dt>Reg. number</dt><dd><?= e($profile['reg_no'] ?? '') ?></dd></div>
        <div><dt>Email</dt><dd class="text-break"><?= e($profile['email'] ?? '') ?></dd></div>
        <div><dt>Phone</dt><dd><?= e($profile['phone'] ?? '') ?></dd></div>
        <div><dt>Gender</dt><dd><?= e($profile['gender'] ?? '') ?></dd></div>
      </dl>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card stat-card h-100 p-4">
      <h2 class="h6 text-uppercase text-muted mb-3">Fee statement</h2>
      <dl class="fee-list mb-0">
        <div><dt>Total billed</dt><dd><?= e(money($totalDue)) ?></dd></div>
        <div><dt>Paid</dt><dd class="text-success"><?= e(money($totalPaid)) ?></dd></div>
      </dl>
      <div class="fee-total d-flex justify-content-between align-items-center mt-3">
        <span class="fw-semibold">Balance</span>
        <span class="fw-bold text-danger"><?= e(money($totalDue - $totalPaid)) ?></span>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <?php if ($allocation): ?>
      <div class="card h-100 overflow-hidden">
        <?php render_image(room_photo_for((int)$allocation['capacity']), 'Study room interior', 'allocation-img'); ?>
        <div class="card-body">
          <h2 class="h6 text-uppercase text-muted mb-1">Your allocated room</h2>
          <p class="fw-bold fs-5 mb-0">Room <?= e($allocation['room_number']) ?></p>
          <p class="small text-muted mb-2"><?= e($allocation['hostel']) ?> &middot; <?= e($allocation['location']) ?></p>
          <div class="amenity-list"><?php render_amenity_badges((string)$allocation['amenities']); ?></div>
        </div>
      </div>
    <?php else: ?>
      <div class="card h-100 p-4 text-center d-flex flex-column justify-content-center align-items-center">
        <i class="bi bi-house-door fs-1 text-success mb-2" aria-hidden="true"></i>
        <h2 class="h6">No room allocated yet</h2>
        <p class="small text-muted">Once the warden verifies your payment and approves the application, your room appears here.</p>
        <a class="btn btn-primary btn-sm" href="apply_room.php">Find a room</a>
      </div>
    <?php endif; ?>
  </div>
</div>

<ul class="nav nav-pills mb-3" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" id="applications-tab" data-bs-toggle="pill" data-bs-target="#applicationsPane" type="button" role="tab" aria-controls="applicationsPane" aria-selected="true">Applications &amp; fees</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tickets-tab" data-bs-toggle="pill" data-bs-target="#ticketsPane" type="button" role="tab" aria-controls="ticketsPane" aria-selected="false">Maintenance tickets</button>
  </li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade show active" id="applicationsPane" role="tabpanel" aria-labelledby="applications-tab">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h2 class="h5 mb-0">My room applications</h2>
      <a class="btn btn-primary btn-sm" href="apply_room.php">New application</a>
    </div>
    <div class="table-responsive">
      <table class="table bg-white align-middle">
        <thead class="table-dark">
          <tr><th>Hostel</th><th>Room</th><th>Semester</th><th>Payment evidence</th><th>Status</th><th>Billed</th><th>Paid</th><th>Balance</th><th>Applied</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($applications as $application): ?>
            <tr>
              <td><?= e($application['hostel']) ?></td>
              <td><?= e($application['room_number']) ?></td>
              <td><?= e($application['semester']) ?></td>
              <td>
                <?php if ($application['payment_method']): ?>
                  <div class="small"><?= e($application['payment_method']) ?></div>
                  <div class="evidence-code"><?= e($application['payment_reference']) ?></div>
                <?php else: ?>
                  <span class="small text-muted">Not recorded</span>
                <?php endif; ?>
                <span class="badge text-bg-<?= e(status_class((string)$application['payment_status'])) ?> mt-1"><?= e($application['payment_status']) ?></span>
              </td>
              <td><span class="badge text-bg-<?= e(status_class((string)$application['status'])) ?>"><?= e($application['status']) ?></span></td>
              <td><?= e(money($application['amount_due'])) ?></td>
              <td><?= e(money($application['amount_paid'])) ?></td>
              <td><?= e(money((float)$application['amount_due'] - (float)$application['amount_paid'])) ?></td>
              <td><?= e(substr((string)$application['applied_at'], 0, 10)) ?></td>
              <td>
                <?php if ($application['status'] === 'Pending'): ?>
                  <form method="post" action="student_dashboard.php" data-confirm="Cancel this application?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel_application">
                    <input type="hidden" name="application_id" value="<?= (int)$application['application_id'] ?>">
                    <button class="btn btn-sm btn-outline-danger">Cancel</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$applications): ?>
            <tr><td colspan="10" class="text-center text-muted">No applications yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="tab-pane fade" id="ticketsPane" role="tabpanel" aria-labelledby="tickets-tab">
    <div class="row g-4">
      <div class="col-lg-5">
        <div class="card p-4">
          <h2 class="h5 mb-3">Report an issue</h2>
          <form method="post" action="student_dashboard.php" data-validate novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="submit_ticket">
            <div class="mb-3">
              <label class="form-label fw-semibold" for="category">Category</label>
              <select id="category" name="category" class="form-select" data-rule="select">
                <option value="">Choose a category</option>
                <?php foreach (TICKET_CATEGORIES as $category): ?>
                  <option><?= e($category) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="invalid-feedback">Select a category.</div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold" for="description">Describe the problem</label>
              <textarea id="description" name="description" rows="4" maxlength="500" class="form-control" data-rule="text"></textarea>
              <div class="invalid-feedback">Describe the problem in 10 to 500 characters.</div>
            </div>
            <button class="btn btn-primary w-100">Submit ticket</button>
          </form>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="table-responsive">
          <table class="table bg-white align-middle">
            <thead class="table-dark"><tr><th>#</th><th>Category</th><th>Description</th><th>Status</th><th>Updated</th></tr></thead>
            <tbody>
              <?php foreach ($tickets as $ticket): ?>
                <tr>
                  <td><?= (int)$ticket['ticket_id'] ?></td>
                  <td><?= e($ticket['category']) ?></td>
                  <td><?= e($ticket['description']) ?></td>
                  <td><span class="badge text-bg-<?= e(status_class((string)$ticket['status'])) ?>"><?= e($ticket['status']) ?></span></td>
                  <td><?= e(substr((string)$ticket['updated_at'], 0, 10)) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$tickets): ?>
                <tr><td colspan="5" class="text-center text-muted">No tickets filed.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
