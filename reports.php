<?php
$pageTitle = 'Reports';
require_once __DIR__ . '/auth_check.php';
require_login('admin');

// Filters: the booked-hostels table (block and booking status) and Report 3 (maintenance status).
$blockFilter = (int)($_GET['block'] ?? 0);
$bookingStatus = (string)($_GET['booking_status'] ?? 'All');
if ($bookingStatus !== 'All' && !in_array($bookingStatus, APPLICATION_STATUSES, true)) {
    $bookingStatus = 'All';
}
$ticketStatus = (string)($_GET['ticket_status'] ?? 'All');
if ($ticketStatus !== 'All' && !in_array($ticketStatus, TICKET_STATUSES, true)) {
    $ticketStatus = 'All';
}

try {
    $hostels = $conn->query('SELECT hostel_id, name FROM hostels ORDER BY name')->fetch_all(MYSQLI_ASSOC);

    // Booked hostels: one row per application. Zero and "All" mean no filter.
    $bookingStmt = $conn->prepare("SELECT a.application_id, a.status, a.applied_at, a.amount_due, a.amount_paid,
            a.payment_method, a.payment_reference, u.full_name, u.reg_no, u.email, h.name AS hostel, r.room_number
        FROM applications a
        JOIN users u ON u.user_id = a.user_id
        JOIN rooms r ON r.room_id = a.room_id
        JOIN hostels h ON h.hostel_id = r.hostel_id
        WHERE (? = 0 OR h.hostel_id = ?) AND (? = 'All' OR a.status = ?)
        ORDER BY h.name, r.room_number, a.applied_at DESC");
    $bookingStmt->bind_param('iiss', $blockFilter, $blockFilter, $bookingStatus, $bookingStatus);
    $bookingStmt->execute();
    $bookedRows = $bookingStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Summary cards follow the same filters as the table.
    $summary = ['rooms_booked' => 0, 'confirmed' => 0, 'pending' => 0, 'revenue' => 0.0];
    foreach ($bookedRows as $row) {
        if ($row['status'] === 'Approved') {
            $summary['rooms_booked']++;
            $summary['revenue'] += (float)$row['amount_paid'];
            if ((float)$row['amount_paid'] >= (float)$row['amount_due']) {
                $summary['confirmed']++;
            }
        }
        if ($row['status'] === 'Pending') {
            $summary['pending']++;
        }
    }

    // Report 1: occupancy and capacity per hostel block (whole system).
    $occupancy = $conn->query("SELECT h.name,
            (SELECT COUNT(*) FROM rooms r WHERE r.hostel_id = h.hostel_id) AS total_rooms,
            (SELECT COALESCE(SUM(r.capacity), 0) FROM rooms r WHERE r.hostel_id = h.hostel_id) AS total_beds,
            (SELECT COUNT(*) FROM applications a JOIN rooms r ON r.room_id = a.room_id
                WHERE r.hostel_id = h.hostel_id AND a.status = 'Approved') AS occupied
        FROM hostels h
        ORDER BY h.name")->fetch_all(MYSQLI_ASSOC);

    // Report 2: fees billed, collected and pending for approved applications.
    $finance = $conn->query("SELECT h.name,
            COALESCE(SUM(a.amount_due), 0) AS billed,
            COALESCE(SUM(a.amount_paid), 0) AS collected,
            COALESCE(SUM(a.amount_due - a.amount_paid), 0) AS pending
        FROM hostels h
        LEFT JOIN rooms r ON r.hostel_id = h.hostel_id
        LEFT JOIN applications a ON a.room_id = r.room_id AND a.status = 'Approved'
        GROUP BY h.hostel_id, h.name
        ORDER BY h.name")->fetch_all(MYSQLI_ASSOC);

    // Report 3: maintenance tickets by category, optionally filtered by status.
    $ticketSql = "SELECT category,
                   SUM(status = 'Pending') AS pending,
                   SUM(status = 'In Progress') AS in_progress,
                   SUM(status = 'Resolved') AS resolved,
                   COUNT(*) AS total
            FROM maintenance_tickets"
        . ($ticketStatus !== 'All' ? ' WHERE status = ?' : '')
        . ' GROUP BY category';
    $ticketStmt = $conn->prepare($ticketSql);
    if ($ticketStatus !== 'All') {
        $ticketStmt->bind_param('s', $ticketStatus);
    }
    $ticketStmt->execute();
    $byCategory = [];
    foreach ($ticketStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $byCategory[$row['category']] = $row;
    }
} catch (mysqli_sql_exception $ex) {
    error_log($ex->getMessage());
    exit('Could not generate the reports. Please try again later.');
}

$occTotals = ['rooms' => 0, 'beds' => 0, 'occupied' => 0];
foreach ($occupancy as $row) {
    $occTotals['rooms'] += (int)$row['total_rooms'];
    $occTotals['beds'] += (int)$row['total_beds'];
    $occTotals['occupied'] += (int)$row['occupied'];
}

$finTotals = ['billed' => 0.0, 'collected' => 0.0, 'pending' => 0.0];
foreach ($finance as $row) {
    $finTotals['billed'] += (float)$row['billed'];
    $finTotals['collected'] += (float)$row['collected'];
    $finTotals['pending'] += (float)$row['pending'];
}

$maintenance = [];
foreach (TICKET_CATEGORIES as $category) {
    $maintenance[$category] = $byCategory[$category] ?? ['pending' => 0, 'in_progress' => 0, 'resolved' => 0, 'total' => 0];
}

// Chart data for report-charts.js.
$chartData = [
    'occupancy' => [
        'labels'   => array_column($occupancy, 'name'),
        'occupied' => array_map(static fn(array $r): int => (int)$r['occupied'], $occupancy),
        'free'     => array_map(static fn(array $r): int => max(0, (int)$r['total_beds'] - (int)$r['occupied']), $occupancy),
    ],
    'finance' => [round($finTotals['collected'], 2), round($finTotals['pending'], 2)],
    'maintenance' => [
        'labels'      => array_keys($maintenance),
        'pending'     => array_map(static fn(array $r): int => (int)$r['pending'], array_values($maintenance)),
        'in_progress' => array_map(static fn(array $r): int => (int)$r['in_progress'], array_values($maintenance)),
        'resolved'    => array_map(static fn(array $r): int => (int)$r['resolved'], array_values($maintenance)),
    ],
];
$chartJson = json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$extraScripts = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.js"></script>'
    . '<script>window.kyuReports = ' . $chartJson . ';</script>'
    . '<script src="report-charts.js"></script>';

require __DIR__ . '/header.php';
?>
<?php render_banner('banner-portico.jpg', 'Portico entrance of a campus administration building', 'Administrative Occupancy, Revenue & Facility Reports', 'Booked hostels, occupancy, fee collection and maintenance, ready to print.'); ?>

<section class="mb-5" aria-labelledby="bookedHeading">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
    <div>
      <h2 id="bookedHeading" class="h4 section-title mb-2">Booked Hostels &amp; Occupancy Breakdown</h2>
      <p class="small text-muted mb-0">Generated <?= e(date('d M Y, H:i')) ?>. The summary cards and the table follow the filters below.</p>
    </div>
  </div>

  <form method="get" action="reports.php" class="row g-2 align-items-end mb-4 no-print">
    <div class="col-md-4">
      <label class="form-label small fw-semibold" for="blockFilter">Hostel block</label>
      <select id="blockFilter" name="block" class="form-select">
        <option value="0">All blocks</option>
        <?php foreach ($hostels as $hostel): ?>
          <option value="<?= (int)$hostel['hostel_id'] ?>" <?= $blockFilter === (int)$hostel['hostel_id'] ? 'selected' : '' ?>><?= e($hostel['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label small fw-semibold" for="bookingStatusFilter">Booking status</label>
      <select id="bookingStatusFilter" name="booking_status" class="form-select">
        <option value="All" <?= $bookingStatus === 'All' ? 'selected' : '' ?>>All statuses</option>
        <?php foreach (APPLICATION_STATUSES as $option): ?>
          <option <?= $bookingStatus === $option ? 'selected' : '' ?>><?= e($option) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4 d-flex gap-2">
      <button class="btn btn-primary">Apply filters</button>
      <button type="button" class="btn btn-outline-dark" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>
    </div>
  </form>

  <div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3">
      <div class="card kpi-card accent-blue p-3 h-100">
        <div class="kpi-icon"><i class="bi bi-house-door-fill" aria-hidden="true"></i></div>
        <div>
          <div class="small text-uppercase text-muted">Total rooms booked</div>
          <div class="kpi-value"><?= (int)$summary['rooms_booked'] ?></div>
          <div class="small text-muted">Approved bookings, one per bed</div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="card kpi-card accent-green p-3 h-100">
        <div class="kpi-icon"><i class="bi bi-patch-check-fill" aria-hidden="true"></i></div>
        <div>
          <div class="small text-uppercase text-muted">Confirmed paid bookings</div>
          <div class="kpi-value"><?= (int)$summary['confirmed'] ?></div>
          <div class="small text-muted">Approved and fully paid</div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="card kpi-card accent-amber p-3 h-100">
        <div class="kpi-icon"><i class="bi bi-hourglass-split" aria-hidden="true"></i></div>
        <div>
          <div class="small text-uppercase text-muted">Pending approvals</div>
          <div class="kpi-value"><?= (int)$summary['pending'] ?></div>
          <div class="small text-muted">Awaiting verification or a decision</div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="card kpi-card accent-slate p-3 h-100">
        <div class="kpi-icon"><i class="bi bi-cash-stack" aria-hidden="true"></i></div>
        <div>
          <div class="small text-uppercase text-muted">Total Revenue Collected (KSh)</div>
          <div class="kpi-value fs-3">KSh <?= e(number_format((float)$summary['revenue'], 2)) ?></div>
          <div class="small text-muted">Amount paid on approved bookings</div>
        </div>
      </div>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table table-bordered table-sm align-middle bg-white">
      <thead class="table-dark">
        <tr>
          <th>Student name</th><th>Admission no.</th><th>KyU email</th><th>Hostel block</th><th>Room</th>
          <th>Payment method</th><th>Transaction code</th><th>Booking date</th><th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($bookedRows as $row): ?>
          <tr>
            <td><?= e($row['full_name']) ?></td>
            <td><?= e($row['reg_no']) ?></td>
            <td class="text-break"><?= e($row['email']) ?></td>
            <td><?= e($row['hostel']) ?></td>
            <td><?= e($row['room_number']) ?></td>
            <td><?= e($row['payment_method'] ?? 'Not recorded') ?></td>
            <td class="evidence-code"><?= e($row['payment_reference'] ?? '-') ?></td>
            <td><?= e(date('d M Y', strtotime((string)$row['applied_at']))) ?></td>
            <td><span class="badge text-bg-<?= e(status_class((string)$row['status'])) ?>"><?= e($row['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$bookedRows): ?>
          <tr><td colspan="9" class="text-center text-muted">No bookings match these filters.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card mb-4">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold mb-1">Report 1: Occupancy &amp; Capacity Summary</h2>
    <p class="small text-muted mb-3">Rooms, beds and occupied beds for each hostel block.</p>
    <div class="row g-4 align-items-center">
      <div class="col-lg-7 table-responsive">
        <table class="table table-bordered align-middle mb-0">
          <thead class="table-dark">
            <tr><th>Hostel block</th><th class="text-end">Rooms</th><th class="text-end">Total beds</th><th class="text-end">Occupied</th><th class="text-end">Unoccupied</th><th class="text-end">Occupancy</th></tr>
          </thead>
          <tbody>
            <?php foreach ($occupancy as $row): ?>
              <?php
                $beds = (int)$row['total_beds'];
                $occupied = (int)$row['occupied'];
                $percent = $beds > 0 ? (int)round($occupied / $beds * 100) : 0;
              ?>
              <tr>
                <td><?= e($row['name']) ?></td>
                <td class="text-end"><?= (int)$row['total_rooms'] ?></td>
                <td class="text-end"><?= $beds ?></td>
                <td class="text-end"><?= $occupied ?></td>
                <td class="text-end"><?= max(0, $beds - $occupied) ?></td>
                <td class="text-end"><?= $percent ?>%</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light fw-bold">
            <tr>
              <td>Total</td>
              <td class="text-end"><?= $occTotals['rooms'] ?></td>
              <td class="text-end"><?= $occTotals['beds'] ?></td>
              <td class="text-end"><?= $occTotals['occupied'] ?></td>
              <td class="text-end"><?= max(0, $occTotals['beds'] - $occTotals['occupied']) ?></td>
              <td class="text-end"><?= $occTotals['beds'] > 0 ? (int)round($occTotals['occupied'] / $occTotals['beds'] * 100) : 0 ?>%</td>
            </tr>
          </tfoot>
        </table>
      </div>
      <div class="col-lg-5">
        <div class="chart-box"><canvas id="chartOccupancy" role="img" aria-label="Bar chart of occupied and unoccupied beds per hostel block"></canvas></div>
      </div>
    </div>
  </div>
</section>

<section class="card mb-4">
  <div class="card-body p-4">
    <h2 class="h5 fw-bold mb-1">Report 2: Revenue &amp; Pending Fees Breakdown</h2>
    <p class="small text-muted mb-3">Fees billed to approved students, the amount collected so far and the balance still pending.</p>
    <div class="row g-4 align-items-center">
      <div class="col-lg-7 table-responsive">
        <table class="table table-bordered align-middle mb-0">
          <thead class="table-dark">
            <tr><th>Hostel block</th><th class="text-end">Billed</th><th class="text-end">Collected</th><th class="text-end">Pending</th></tr>
          </thead>
          <tbody>
            <?php foreach ($finance as $row): ?>
              <tr>
                <td><?= e($row['name']) ?></td>
                <td class="text-end"><?= e(money($row['billed'])) ?></td>
                <td class="text-end"><?= e(money($row['collected'])) ?></td>
                <td class="text-end"><?= e(money($row['pending'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light fw-bold">
            <tr>
              <td>Total</td>
              <td class="text-end"><?= e(money($finTotals['billed'])) ?></td>
              <td class="text-end"><?= e(money($finTotals['collected'])) ?></td>
              <td class="text-end"><?= e(money($finTotals['pending'])) ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <div class="col-lg-5">
        <div class="chart-box"><canvas id="chartFinance" role="img" aria-label="Doughnut chart of fees collected against pending balances"></canvas></div>
      </div>
    </div>
  </div>
</section>

<section class="card mb-4">
  <div class="card-body p-4">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
      <h2 class="h5 fw-bold mb-0">Report 3: Facility Maintenance Analytics</h2>
      <form method="get" action="reports.php" class="no-print">
        <label class="visually-hidden" for="ticketStatusFilter">Filter maintenance by status</label>
        <select id="ticketStatusFilter" name="ticket_status" class="form-select form-select-sm" data-autosubmit>
          <?php foreach (array_merge(['All'], TICKET_STATUSES) as $option): ?>
            <option <?= $ticketStatus === $option ? 'selected' : '' ?>><?= e($option) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <p class="small text-muted mb-3">Reported issues by category. Showing: <strong><?= e($ticketStatus) ?></strong>.</p>
    <div class="row g-4 align-items-center">
      <div class="col-lg-7 table-responsive">
        <table class="table table-bordered align-middle mb-0">
          <thead class="table-dark">
            <tr><th>Category</th><th class="text-end">Pending</th><th class="text-end">In progress</th><th class="text-end">Resolved</th><th class="text-end">Total</th></tr>
          </thead>
          <tbody>
            <?php foreach ($maintenance as $category => $row): ?>
              <tr>
                <td><?= e($category) ?></td>
                <td class="text-end"><?= (int)$row['pending'] ?></td>
                <td class="text-end"><?= (int)$row['in_progress'] ?></td>
                <td class="text-end"><?= (int)$row['resolved'] ?></td>
                <td class="text-end"><?= (int)($row['total'] ?? 0) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="col-lg-5">
        <div class="chart-box"><canvas id="chartMaintenance" role="img" aria-label="Bar chart of maintenance tickets by category and status"></canvas></div>
      </div>
    </div>
  </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
