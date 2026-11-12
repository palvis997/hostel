<?php
$pageTitle = 'Admin Portal';
require_once __DIR__ . '/auth_check.php';
require_login('admin');

// Hidden fields every admin form needs: CSRF token, the action name and the tab to return to.
function action_fields(string $action, string $tab): string
{
    return csrf_field()
        . '<input type="hidden" name="action" value="' . e($action) . '">'
        . '<input type="hidden" name="tab" value="' . e($tab) . '">';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    $tab = post('tab') ?: 'bookings';

    try {
        if (!csrf_ok()) {
            set_flash('danger', 'Your form session has expired. Please try again.');
        } else {
            switch ($action) {
                case 'add_hostel':
                    $name = post('name');
                    $gender = post('gender');
                    $location = post('location');
                    $fee = post('fee');
                    if ($name === '' || mb_strlen($name) > 80 || $location === '' || !in_array($gender, ['Male', 'Female', 'Mixed'], true) || !is_numeric($fee) || (float)$fee < 0) {
                        set_flash('danger', 'Provide a hostel name, type, location and a valid fee.');
                    } else {
                        $feeValue = (float)$fee;
                        $stmt = $conn->prepare('INSERT INTO hostels (name, gender, location, fee_per_semester) VALUES (?, ?, ?, ?)');
                        $stmt->bind_param('sssd', $name, $gender, $location, $feeValue);
                        $stmt->execute();
                        set_flash('success', 'Hostel added.');
                    }
                    $tab = 'inventory';
                    break;

                case 'add_room':
                    $hostelId = (int)post('hostel_id');
                    $number = strtoupper(post('room_number'));
                    $capacity = (int)post('capacity');
                    $amenities = normalize_amenities(post('amenities'));
                    if ($hostelId <= 0 || preg_match('/^[A-Z0-9]{1,10}$/', $number) !== 1 || $capacity < 1 || $capacity > 8) {
                        set_flash('danger', 'Provide a hostel, a room number (letters and digits) and 1 to 8 beds.');
                    } else {
                        $stmt = $conn->prepare('INSERT INTO rooms (hostel_id, room_number, capacity, amenities) VALUES (?, ?, ?, ?)');
                        $stmt->bind_param('isis', $hostelId, $number, $capacity, $amenities);
                        $stmt->execute();
                        set_flash('success', 'Room added.');
                    }
                    $tab = 'inventory';
                    break;

                case 'update_room':
                    $roomId = (int)post('room_id');
                    $capacity = (int)post('capacity');
                    $status = post('status');
                    if ($roomId <= 0 || $capacity < 1 || $capacity > 8 || !in_array($status, ['Available', 'Maintenance'], true)) {
                        set_flash('danger', 'Invalid room update.');
                    } else {
                        $countStmt = $conn->prepare("SELECT COUNT(*) AS n FROM applications WHERE room_id = ? AND status = 'Approved'");
                        $countStmt->bind_param('i', $roomId);
                        $countStmt->execute();
                        $occupants = (int)$countStmt->get_result()->fetch_assoc()['n'];
                        if ($occupants > $capacity) {
                            set_flash('danger', 'Capacity cannot be lower than the number of approved occupants.');
                        } else {
                            $stmt = $conn->prepare('UPDATE rooms SET capacity = ?, status = ? WHERE room_id = ?');
                            $stmt->bind_param('isi', $capacity, $status, $roomId);
                            $stmt->execute();
                            set_flash('success', 'Room updated.');
                        }
                    }
                    $tab = 'inventory';
                    break;

                case 'delete_room':
                    $roomId = (int)post('room_id');
                    $countStmt = $conn->prepare('SELECT COUNT(*) AS n FROM applications WHERE room_id = ?');
                    $countStmt->bind_param('i', $roomId);
                    $countStmt->execute();
                    if ((int)$countStmt->get_result()->fetch_assoc()['n'] > 0) {
                        set_flash('danger', 'This room has applications. Revoke them first.');
                    } else {
                        $stmt = $conn->prepare('DELETE FROM rooms WHERE room_id = ?');
                        $stmt->bind_param('i', $roomId);
                        $stmt->execute();
                        set_flash('success', 'Room deleted.');
                    }
                    $tab = 'inventory';
                    break;

                case 'verify_payment':
                    $applicationId = (int)post('application_id');
                    $stmt = $conn->prepare("UPDATE applications SET payment_status = 'Verified'
                        WHERE application_id = ? AND status = 'Pending' AND payment_status = 'Unverified'");
                    $stmt->bind_param('i', $applicationId);
                    $stmt->execute();
                    if ($stmt->affected_rows > 0) {
                        set_flash('success', 'Payment evidence verified. You can now approve the application.');
                    } else {
                        set_flash('warning', 'This payment is not waiting for verification.');
                    }
                    break;

                case 'approve_application':
                    $applicationId = (int)post('application_id');
                    $conn->begin_transaction();
                    // Locking reads: the application row first, then its room, then the bed count.
                    // Two approvals for the same room therefore run one after the other, and each sees the latest count.
                    $lockStmt = $conn->prepare('SELECT room_id, status, payment_status FROM applications WHERE application_id = ? FOR UPDATE');
                    $lockStmt->bind_param('i', $applicationId);
                    $lockStmt->execute();
                    $application = $lockStmt->get_result()->fetch_assoc();
                    if (!$application || $application['status'] !== 'Pending') {
                        $conn->rollback();
                        set_flash('danger', 'Only pending applications can be approved.');
                        break;
                    }
                    if ($application['payment_status'] !== 'Verified') {
                        $conn->rollback();
                        set_flash('danger', 'Verify the payment evidence before approving this application.');
                        break;
                    }
                    $roomId = (int)$application['room_id'];
                    $roomStmt = $conn->prepare('SELECT capacity, status FROM rooms WHERE room_id = ? FOR UPDATE');
                    $roomStmt->bind_param('i', $roomId);
                    $roomStmt->execute();
                    $room = $roomStmt->get_result()->fetch_assoc();
                    $countStmt = $conn->prepare("SELECT COUNT(*) AS n FROM applications WHERE room_id = ? AND status = 'Approved' FOR UPDATE");
                    $countStmt->bind_param('i', $roomId);
                    $countStmt->execute();
                    $occupied = (int)$countStmt->get_result()->fetch_assoc()['n'];
                    if (!$room || $room['status'] !== 'Available' || $occupied >= (int)$room['capacity']) {
                        $conn->rollback();
                        set_flash('danger', 'The room is full or under maintenance, so it cannot be approved.');
                        break;
                    }
                    $update = $conn->prepare("UPDATE applications SET status = 'Approved', decided_at = NOW() WHERE application_id = ?");
                    $update->bind_param('i', $applicationId);
                    $update->execute();
                    $conn->commit();
                    set_flash('success', 'Application Approved.');
                    break;

                case 'reject_application':
                    $applicationId = (int)post('application_id');
                    $clean = clean_rejection_reason(post('reason'));
                    if ($clean === null) {
                        set_flash('danger', 'Enter a rejection reason of 5 to 255 characters.');
                        break;
                    }
                    $reasonText = $clean['reason'];
                    $paymentProblem = $clean['payment'] ? 1 : 0;
                    $stmt = $conn->prepare("UPDATE applications
                        SET status = 'Rejected', rejection_reason = ?, decided_at = NOW(),
                            payment_status = IF(? = 1, 'Rejected', payment_status)
                        WHERE application_id = ? AND status = 'Pending'");
                    $stmt->bind_param('sii', $reasonText, $paymentProblem, $applicationId);
                    $stmt->execute();
                    if ($stmt->affected_rows > 0) {
                        set_flash('success', 'Application rejected. The reason is now visible to the student.');
                    } else {
                        set_flash('warning', 'Only pending applications can be rejected.');
                    }
                    break;

                case 'record_payment':
                    $applicationId = (int)post('application_id');
                    $amount = post('amount');
                    if (!is_numeric($amount) || (float)$amount <= 0) {
                        set_flash('danger', 'Enter a valid payment amount.');
                        break;
                    }
                    $paid = (float)$amount;
                    $stmt = $conn->prepare("UPDATE applications SET amount_paid = amount_paid + ?
                        WHERE application_id = ? AND status = 'Approved' AND amount_paid + ? <= amount_due");
                    $stmt->bind_param('did', $paid, $applicationId, $paid);
                    $stmt->execute();
                    if ($stmt->affected_rows > 0) {
                        set_flash('success', 'Payment recorded.');
                    } else {
                        set_flash('danger', 'Payment rejected: the application must be approved and the payment cannot exceed the balance.');
                    }
                    break;

                case 'revoke_application':
                    $applicationId = (int)post('application_id');
                    $stmt = $conn->prepare('DELETE FROM applications WHERE application_id = ?');
                    $stmt->bind_param('i', $applicationId);
                    $stmt->execute();
                    if ($stmt->affected_rows > 0) {
                        set_flash('success', 'Application revoked. Any bed it held is free again.');
                    } else {
                        set_flash('warning', 'Application not found.');
                    }
                    break;

                case 'update_ticket':
                    $ticketId = (int)post('ticket_id');
                    $next = post('status');
                    $stmt = $conn->prepare('SELECT status FROM maintenance_tickets WHERE ticket_id = ?');
                    $stmt->bind_param('i', $ticketId);
                    $stmt->execute();
                    $current = $stmt->get_result()->fetch_assoc();
                    if (!$current || (TICKET_FLOW[$current['status']] ?? null) !== $next) {
                        set_flash('danger', 'Invalid status change. Tickets move from Pending to In Progress to Resolved.');
                    } else {
                        $update = $conn->prepare('UPDATE maintenance_tickets SET status = ? WHERE ticket_id = ?');
                        $update->bind_param('si', $next, $ticketId);
                        $update->execute();
                        set_flash('success', "Ticket moved to $next.");
                    }
                    $tab = 'tickets';
                    break;

                default:
                    set_flash('danger', 'Unknown action.');
            }
        }
    } catch (mysqli_sql_exception $ex) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
        error_log($ex->getMessage());
        set_flash('danger', $ex->getCode() === 1062 ? 'That record already exists.' : 'Database error. Please try again.');
    }

    redirect('admin_dashboard.php#' . $tab);
}

try {
    $stats = $conn->query("SELECT
            (SELECT COUNT(*) FROM applications WHERE status = 'Approved') AS occupants,
            (SELECT COUNT(*) FROM applications WHERE status = 'Pending') AS pending_apps,
            (SELECT COUNT(*) FROM maintenance_tickets WHERE status = 'Pending') AS pending_tickets,
            (SELECT COUNT(*) FROM maintenance_tickets WHERE status = 'In Progress') AS progress_tickets")->fetch_assoc();
    $rooms = fetch_rooms($conn);
    $hostels = $conn->query('SELECT hostel_id, name FROM hostels ORDER BY name')->fetch_all(MYSQLI_ASSOC);
    $applications = $conn->query("SELECT a.application_id, a.semester, a.status, a.amount_due, a.amount_paid, a.applied_at,
            a.payment_method, a.payment_reference, a.payment_status, a.rejection_reason,
            u.reg_no, u.full_name, u.phone, r.room_number, h.name AS hostel
        FROM applications a
        JOIN users u ON u.user_id = a.user_id
        JOIN rooms r ON r.room_id = a.room_id
        JOIN hostels h ON h.hostel_id = r.hostel_id
        ORDER BY FIELD(a.status, 'Pending', 'Approved', 'Rejected'), a.applied_at DESC")->fetch_all(MYSQLI_ASSOC);
    $tickets = $conn->query("SELECT t.ticket_id, t.category, t.description, t.status, u.reg_no, u.full_name, r.room_number
        FROM maintenance_tickets t
        JOIN users u ON u.user_id = t.user_id
        LEFT JOIN rooms r ON r.room_id = t.room_id
        ORDER BY FIELD(t.status, 'Pending', 'In Progress', 'Resolved'), t.created_at DESC")->fetch_all(MYSQLI_ASSOC);
    $roster = $conn->query("SELECT u.reg_no, u.full_name, u.email, u.phone, h.name AS hostel, r.room_number
        FROM applications a
        JOIN users u ON u.user_id = a.user_id
        JOIN rooms r ON r.room_id = a.room_id
        JOIN hostels h ON h.hostel_id = r.hostel_id
        WHERE a.status = 'Approved'
        ORDER BY h.name, r.room_number, u.full_name")->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $ex) {
    error_log($ex->getMessage());
    exit('Could not load the admin portal. Please try again later.');
}

$availableBeds = (int)array_sum(array_map('room_free_beds', $rooms));

require __DIR__ . '/header.php';
?>
<?php render_banner('banner-stone-balcony.jpg', 'Stone building with balcony railings and a blue door', 'Warden Management & Hostel Inventory Portal', 'Verify payments, approve or reject applications, manage rooms and track maintenance.'); ?>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card kpi-card accent-green p-3 h-100">
      <div class="kpi-icon"><i class="bi bi-people-fill" aria-hidden="true"></i></div>
      <div>
        <div class="small text-uppercase text-muted">Total occupants</div>
        <div class="kpi-value"><?= (int)$stats['occupants'] ?></div>
        <div class="small text-muted"><?= (int)$stats['pending_apps'] ?> applications awaiting review</div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card kpi-card accent-blue p-3 h-100">
      <div class="kpi-icon"><i class="bi bi-door-open" aria-hidden="true"></i></div>
      <div>
        <div class="small text-uppercase text-muted">Available beds</div>
        <div class="kpi-value"><?= $availableBeds ?></div>
        <div class="small text-muted">Across <?= count($rooms) ?> rooms</div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card kpi-card accent-amber p-3 h-100">
      <div class="kpi-icon"><i class="bi bi-tools" aria-hidden="true"></i></div>
      <div>
        <div class="small text-uppercase text-muted">Pending maintenance tickets</div>
        <div class="kpi-value"><?= (int)$stats['pending_tickets'] ?></div>
        <div class="small text-muted"><?= (int)$stats['progress_tickets'] ?> in progress</div>
      </div>
    </div>
  </div>
</div>

<ul class="nav nav-pills mb-3 flex-wrap" role="tablist">
  <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#bookingsPane" type="button" role="tab">Applications &amp; payments</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#inventoryPane" type="button" role="tab">Hostel inventory</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#ticketsPane" type="button" role="tab">Maintenance</button></li>
  <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#rosterPane" type="button" role="tab">Allocation roster</button></li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade show active" id="bookingsPane" role="tabpanel">
    <div class="table-responsive">
      <table class="table bg-white align-middle">
        <thead class="table-dark">
          <tr><th>Student</th><th>Hostel / room</th><th>Semester</th><th>Payment evidence</th><th>Status</th><th>Due</th><th>Paid</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($applications as $application): ?>
            <?php
              $applicationId = (int)$application['application_id'];
              $isPending = $application['status'] === 'Pending';
              $isApproved = $application['status'] === 'Approved';
              $paymentVerified = $application['payment_status'] === 'Verified';
              $balance = (float)$application['amount_due'] - (float)$application['amount_paid'];
            ?>
            <tr>
              <td>
                <?= e($application['full_name']) ?><br>
                <small class="text-muted"><?= e($application['reg_no']) ?> &middot; <?= e($application['phone']) ?></small>
              </td>
              <td><?= e($application['hostel'] . ' / ' . $application['room_number']) ?></td>
              <td class="small"><?= e($application['semester']) ?></td>
              <td>
                <?php if ($application['payment_method']): ?>
                  <div class="small"><?= e($application['payment_method']) ?></div>
                  <div class="evidence-code"><?= e($application['payment_reference']) ?></div>
                <?php else: ?>
                  <span class="small text-muted">No evidence</span>
                <?php endif; ?>
                <span class="badge text-bg-<?= e(status_class((string)$application['payment_status'])) ?> mt-1"><?= e($application['payment_status']) ?></span>
              </td>
              <td><span class="badge text-bg-<?= e(status_class((string)$application['status'])) ?>"><?= e($application['status']) ?></span></td>
              <td><?= e(money($application['amount_due'])) ?></td>
              <td><?= e(money($application['amount_paid'])) ?></td>
              <td class="d-flex flex-wrap gap-1 align-items-start">
                <?php if ($isPending): ?>
                  <?php if ($application['payment_status'] === 'Unverified'): ?>
                    <form method="post" action="admin_dashboard.php">
                      <?= action_fields('verify_payment', 'bookings') ?>
                      <input type="hidden" name="application_id" value="<?= $applicationId ?>">
                      <button class="btn btn-sm btn-outline-primary">Verify payment</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" action="admin_dashboard.php">
                    <?= action_fields('approve_application', 'bookings') ?>
                    <input type="hidden" name="application_id" value="<?= $applicationId ?>">
                    <button class="btn btn-sm btn-success" <?= $paymentVerified ? '' : 'disabled' ?> title="<?= $paymentVerified ? 'Approve this application' : 'Verify the payment evidence first' ?>">Approve Application</button>
                  </form>
                  <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $applicationId ?>">Reject</button>
                <?php endif; ?>

                <?php if ($isApproved && $balance > 0): ?>
                  <form method="post" action="admin_dashboard.php" class="d-flex gap-1" data-validate novalidate>
                    <?= action_fields('record_payment', 'bookings') ?>
                    <input type="hidden" name="application_id" value="<?= $applicationId ?>">
                    <input name="amount" class="form-control form-control-sm" style="max-width:120px" placeholder="Amount" aria-label="Payment amount" data-rule="money">
                    <button class="btn btn-sm btn-primary">Record</button>
                  </form>
                <?php endif; ?>

                <form method="post" action="admin_dashboard.php" data-confirm="Revoke this application and free any bed it holds?">
                  <?= action_fields('revoke_application', 'bookings') ?>
                  <input type="hidden" name="application_id" value="<?= $applicationId ?>">
                  <button class="btn btn-sm btn-outline-danger">Revoke</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$applications): ?>
            <tr><td colspan="8" class="text-center text-muted">No applications yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <datalist id="rejectionReasons">
      <?php foreach (array_keys(REJECTION_REASONS) as $reasonOption): ?>
        <option value="<?= e($reasonOption) ?>"></option>
      <?php endforeach; ?>
    </datalist>

    <?php foreach ($applications as $application): ?>
      <?php if ($application['status'] === 'Pending'): ?>
        <?php $modalId = (int)$application['application_id']; ?>
        <div class="modal fade" id="rejectModal<?= $modalId ?>" tabindex="-1" aria-labelledby="rejectLabel<?= $modalId ?>" aria-hidden="true">
          <div class="modal-dialog">
            <form method="post" action="admin_dashboard.php" class="modal-content" data-validate novalidate>
              <?= action_fields('reject_application', 'bookings') ?>
              <input type="hidden" name="application_id" value="<?= $modalId ?>">
              <div class="modal-header">
                <h2 class="modal-title h5" id="rejectLabel<?= $modalId ?>">Reject application</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <p class="small text-muted mb-3">
                  <?= e($application['full_name']) ?> (<?= e($application['reg_no']) ?>) &middot; <?= e($application['hostel'] . ' / ' . $application['room_number']) ?>
                </p>
                <label class="form-label fw-semibold" for="reason<?= $modalId ?>">Rejection reason</label>
                <input id="reason<?= $modalId ?>" name="reason" class="form-control" list="rejectionReasons" maxlength="255" placeholder="Choose a reason or type your own" data-rule="reason" autocomplete="off">
                <div class="invalid-feedback">Enter a reason of 5 to 255 characters.</div>
                <div class="form-text">The student sees this reason on their dashboard.</div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger">Reject application</button>
              </div>
            </form>
          </div>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <div class="tab-pane fade" id="inventoryPane" role="tabpanel">
    <div class="row g-3 mb-4">
      <div class="col-lg-6">
        <div class="card p-4 h-100">
          <h2 class="h6 fw-bold mb-3">Add a hostel block</h2>
          <form method="post" action="admin_dashboard.php" class="row g-2" data-validate novalidate>
            <?= action_fields('add_hostel', 'inventory') ?>
            <div class="col-md-6"><input name="name" class="form-control" placeholder="Hostel name" aria-label="Hostel name" data-rule="required"></div>
            <div class="col-md-6">
              <select name="gender" class="form-select" aria-label="Hostel type" data-rule="select">
                <option value="">Hostel type</option><option>Male</option><option>Female</option><option>Mixed</option>
              </select>
            </div>
            <div class="col-md-6"><input name="location" class="form-control" placeholder="Location" aria-label="Location" data-rule="required"></div>
            <div class="col-md-6"><input name="fee" class="form-control" placeholder="Fee per semester (KES)" aria-label="Fee per semester" data-rule="money"></div>
            <div class="col-12"><button class="btn btn-primary btn-sm">Add hostel</button></div>
          </form>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card p-4 h-100">
          <h2 class="h6 fw-bold mb-3">Add a room</h2>
          <form method="post" action="admin_dashboard.php" class="row g-2" data-validate novalidate>
            <?= action_fields('add_room', 'inventory') ?>
            <div class="col-md-6">
              <select name="hostel_id" class="form-select" aria-label="Hostel" data-rule="select">
                <option value="">Hostel</option>
                <?php foreach ($hostels as $hostel): ?>
                  <option value="<?= (int)$hostel['hostel_id'] ?>"><?= e($hostel['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3"><input name="room_number" class="form-control" placeholder="Room no." aria-label="Room number" data-rule="required"></div>
            <div class="col-md-3"><input name="capacity" type="number" min="1" max="8" class="form-control" placeholder="Beds" aria-label="Beds" data-rule="required"></div>
            <div class="col-12"><input name="amenities" class="form-control" placeholder="Amenities, e.g. Wi-Fi, Study Desk, Hot Shower" aria-label="Amenities"></div>
            <div class="col-12"><button class="btn btn-primary btn-sm">Add room</button></div>
          </form>
        </div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table bg-white align-middle">
        <thead class="table-dark"><tr><th>Hostel</th><th>Room</th><th>Occupied / beds</th><th>Amenities</th><th>Beds and status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($rooms as $room): ?>
            <tr>
              <td><?= e($room['hostel_name']) ?></td>
              <td><?= e($room['room_number']) ?></td>
              <td><?= (int)$room['occupied'] ?> / <?= (int)$room['capacity'] ?></td>
              <td class="small"><?= e(str_replace(',', ', ', (string)$room['amenities'])) ?></td>
              <td>
                <form method="post" action="admin_dashboard.php" class="d-flex gap-1">
                  <?= action_fields('update_room', 'inventory') ?>
                  <input type="hidden" name="room_id" value="<?= (int)$room['room_id'] ?>">
                  <input type="number" name="capacity" min="1" max="8" value="<?= (int)$room['capacity'] ?>" class="form-control form-control-sm" style="max-width:80px" aria-label="Beds">
                  <select name="status" class="form-select form-select-sm" style="max-width:150px" aria-label="Status">
                    <?php foreach (['Available', 'Maintenance'] as $option): ?>
                      <option <?= $room['status'] === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-sm btn-outline-primary">Save</button>
                </form>
              </td>
              <td>
                <form method="post" action="admin_dashboard.php" data-confirm="Delete this room?">
                  <?= action_fields('delete_room', 'inventory') ?>
                  <input type="hidden" name="room_id" value="<?= (int)$room['room_id'] ?>">
                  <button class="btn btn-sm btn-outline-danger">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="tab-pane fade" id="ticketsPane" role="tabpanel">
    <div class="table-responsive">
      <table class="table bg-white align-middle">
        <thead class="table-dark"><tr><th>#</th><th>Student</th><th>Room</th><th>Category</th><th>Description</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($tickets as $ticket): ?>
            <?php $next = TICKET_FLOW[$ticket['status']] ?? null; ?>
            <tr>
              <td><?= (int)$ticket['ticket_id'] ?></td>
              <td><?= e($ticket['full_name']) ?><br><small class="text-muted"><?= e($ticket['reg_no']) ?></small></td>
              <td><?= e($ticket['room_number'] ?? '-') ?></td>
              <td><?= e($ticket['category']) ?></td>
              <td><?= e($ticket['description']) ?></td>
              <td><span class="badge text-bg-<?= e(status_class((string)$ticket['status'])) ?>"><?= e($ticket['status']) ?></span></td>
              <td>
                <?php if ($next !== null): ?>
                  <form method="post" action="admin_dashboard.php">
                    <?= action_fields('update_ticket', 'tickets') ?>
                    <input type="hidden" name="ticket_id" value="<?= (int)$ticket['ticket_id'] ?>">
                    <input type="hidden" name="status" value="<?= e($next) ?>">
                    <button class="btn btn-sm btn-primary">Mark <?= e(strtolower($next)) ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$tickets): ?>
            <tr><td colspan="7" class="text-center text-muted">No maintenance tickets.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="tab-pane fade" id="rosterPane" role="tabpanel">
    <div class="table-responsive">
      <table class="table bg-white">
        <thead class="table-dark"><tr><th>Hostel</th><th>Room</th><th>Reg. number</th><th>Name</th><th>Email</th><th>Phone</th></tr></thead>
        <tbody>
          <?php foreach ($roster as $row): ?>
            <tr>
              <td><?= e($row['hostel']) ?></td>
              <td><?= e($row['room_number']) ?></td>
              <td><?= e($row['reg_no']) ?></td>
              <td><?= e($row['full_name']) ?></td>
              <td><?= e($row['email']) ?></td>
              <td><?= e($row['phone']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$roster): ?>
            <tr><td colspan="6" class="text-center text-muted">No approved allocations yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
