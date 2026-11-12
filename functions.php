<?php
// Shared helpers: escaping, flash messages, CSRF, validation, data queries and UI renderers.

const SEMESTERS = ['Semester 1 2026/2027', 'Semester 2 2026/2027', 'Semester 3 2026/2027'];
const TICKET_CATEGORIES = ['Plumbing', 'Electrical', 'Furniture'];
const TICKET_STATUSES = ['Pending', 'In Progress', 'Resolved'];
const TICKET_FLOW = ['Pending' => 'In Progress', 'In Progress' => 'Resolved'];
const APPLICATION_STATUSES = ['Pending', 'Approved', 'Rejected'];
const PAYMENT_METHODS = ['M-Pesa Express / Paybill', 'Bank Transfer', 'KCB Agent Deposit'];
const MPESA_METHOD = 'M-Pesa Express / Paybill';

// Reasons the warden can choose. The flag marks reasons about the payment itself,
// which also set the payment status to Rejected.
const REJECTION_REASONS = [
    'Invalid M-Pesa Transaction Code'       => true,
    'Payment amount does not match the fee' => true,
    'Selected Hostel Block Full'            => false,
    'Fee balance outstanding'               => false,
];

// Institutional email domains accepted for student accounts.
const KYU_EMAIL_DOMAINS = ['student.kyu.ac.ke', 'kyu.ac.ke'];
const KYU_EMAIL_MESSAGE = 'Please enter a valid Kirinyaga University email address ending with @student.kyu.ac.ke';

// Icons for the amenity badges on room cards.
const AMENITY_ICONS = [
    'Wi-Fi'           => 'bi-wifi',
    'Study Desk'      => 'bi-book',
    'Hot Shower'      => 'bi-droplet-half',
    'Wardrobe'        => 'bi-door-closed',
    'Power Sockets'   => 'bi-plug',
    'Shared Bathroom' => 'bi-house-door',
];

const KYU_NAME = 'Kirinyaga University';
const KYU_TAGLINE = 'Innovative Technology for a Dynamic World';
const KYU_LOGO = 'https://kyu.ac.ke/images/kyu_logosmall2.png';

// ---- Escaping, formatting and redirects ----

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function post(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

function money($amount): string
{
    return 'KES ' . number_format((float)$amount, 2);
}

function money_short($amount): string
{
    return 'KES ' . number_format((float)$amount, 0);
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

// ---- Flash messages ----

function set_flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function render_flash(): void
{
    foreach ($_SESSION['flash'] ?? [] as $flash) {
        echo '<div class="alert alert-' . e($flash['type']) . ' alert-dismissible fade show" role="alert">'
            . e($flash['msg'])
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
    unset($_SESSION['flash']);
}

// ---- CSRF protection ----

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}

// ---- Server-side validation (mirrors the rules in script.js) ----

function is_valid_reg_no(string $v): bool
{
    return preg_match('/^[A-Z]{2}\d{3}\/[A-Z]\/\d{4,5}\/\d{2}$/', $v) === 1;
}

function is_valid_phone(string $v): bool
{
    return preg_match('/^\d{10}$/', $v) === 1;
}

function is_strong_password(string $v): bool
{
    return preg_match('/^(?=.*[A-Za-z])(?=.*\d).{8,}$/', $v) === 1;
}

// A well-formed address whose domain is an official Kirinyaga University domain.
function is_kyu_email(string $email): bool
{
    if (strlen($email) > 120 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }
    $domain = strtolower(substr($email, strrpos($email, '@') + 1));
    return in_array($domain, KYU_EMAIL_DOMAINS, true);
}

// M-Pesa codes are exactly 10 letters and digits. Bank and deposit references are 6 to 20
// letters, digits, hyphens or slashes. Pass the code already in upper case.
function valid_payment_reference(string $method, string $code): bool
{
    if ($method === MPESA_METHOD) {
        return preg_match('/^[A-Z0-9]{10}$/', $code) === 1;
    }
    return preg_match('/^[A-Z0-9\-\/]{6,20}$/', $code) === 1;
}

// Cleans a rejection reason. Returns the reason and whether it is a payment problem, or null if unusable.
function clean_rejection_reason(string $raw): ?array
{
    $reason = trim((string)preg_replace('/\s+/', ' ', $raw));
    $length = mb_strlen($reason);
    if ($length < 5 || $length > 255) {
        return null;
    }
    return ['reason' => $reason, 'payment' => (bool)(REJECTION_REASONS[$reason] ?? false)];
}

// Only allow returning to the room application page after login (prevents open redirects).
function safe_next(string $value): string
{
    return preg_match('/^apply_room\.php(\?hostel=\d+)?$/', $value) === 1 ? $value : '';
}

// Cleans a comma-separated amenity list typed by the warden.
function normalize_amenities(string $raw): string
{
    $clean = [];
    foreach (explode(',', $raw) as $item) {
        $item = trim((string)preg_replace('/\s+/', ' ', $item));
        if ($item !== '' && preg_match('/^[A-Za-z][A-Za-z0-9 \-]{0,39}$/', $item) === 1) {
            $clean[] = $item;
        }
    }
    return implode(',', array_unique($clean));
}

// ---- Status, eligibility and availability ----

function status_class(string $status): string
{
    return match ($status) {
        'Approved', 'Resolved', 'Verified' => 'success',
        'Pending', 'Unverified'            => 'warning',
        'In Progress'                      => 'info',
        'Rejected'                         => 'danger',
        default                            => 'secondary',
    };
}

function hostel_gender_label(string $gender): string
{
    return match ($gender) {
        'Male'   => 'Male hostel',
        'Female' => 'Female hostel',
        default  => 'Mixed hostel',
    };
}

function eligible_for(string $hostelGender, string $studentGender): bool
{
    return $hostelGender === 'Mixed' || $hostelGender === $studentGender;
}

// Badge text and colour for a number of free beds.
function availability(int $free): array
{
    if ($free <= 0) {
        return ['label' => 'No beds open', 'class' => 'secondary'];
    }
    if ($free <= 3) {
        return ['label' => $free . ($free === 1 ? ' bed left' : ' beds left'), 'class' => 'warning'];
    }
    return ['label' => $free . ' beds free', 'class' => 'success'];
}

// What the "Apply Now" button on a hostel card should do for the current visitor.
function hostel_action(array $hostel, ?string $role, string $studentGender): array
{
    $id = (int)$hostel['hostel_id'];
    if ($role === 'admin') {
        return ['label' => 'Manage rooms', 'href' => 'admin_dashboard.php#inventory', 'disabled' => false];
    }
    if ((int)$hostel['free_beds'] <= 0) {
        return ['label' => 'No beds open', 'href' => '', 'disabled' => true];
    }
    if ($role === null) {
        return [
            'label'    => 'Apply Now',
            'href'     => 'login.php?next=' . urlencode('apply_room.php?hostel=' . $id),
            'disabled' => false,
        ];
    }
    if (!eligible_for((string)$hostel['gender'], $studentGender)) {
        return ['label' => 'Not open to your gender', 'href' => '', 'disabled' => true];
    }
    return ['label' => 'Apply Now', 'href' => 'apply_room.php?hostel=' . $id, 'disabled' => false];
}

// ---- Data queries ----

// Name, admission number, phone, KyU email and gender of one account.
function fetch_user_profile(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare('SELECT user_id, reg_no, full_name, email, phone, gender FROM users WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?? [];
}

function current_user_gender(mysqli $conn, int $userId): string
{
    $profile = fetch_user_profile($conn, $userId);
    return (string)($profile['gender'] ?? '');
}

function fetch_rooms(mysqli $conn): array
{
    $sql = "SELECT r.room_id, r.room_number, r.capacity, r.status, r.amenities,
                   h.hostel_id, h.name AS hostel_name, h.gender, h.fee_per_semester,
                   (SELECT COUNT(*) FROM applications a WHERE a.room_id = r.room_id AND a.status = 'Approved') AS occupied
            FROM rooms r
            JOIN hostels h ON h.hostel_id = r.hostel_id
            ORDER BY h.name, r.room_number";
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

// Beds a student can still take in one room (zero when the room is under maintenance or full).
function room_free_beds(array $room): int
{
    if (($room['status'] ?? '') !== 'Available') {
        return 0;
    }
    return max(0, (int)$room['capacity'] - (int)$room['occupied']);
}

// One row per hostel block: room count, total beds and free beds.
function fetch_hostel_summaries(mysqli $conn, array $rooms): array
{
    $hostels = $conn->query('SELECT hostel_id, name, gender, location, fee_per_semester, image FROM hostels ORDER BY name')
        ->fetch_all(MYSQLI_ASSOC);

    $summary = [];
    foreach ($hostels as $hostel) {
        $summary[(int)$hostel['hostel_id']] = $hostel + ['room_count' => 0, 'total_beds' => 0, 'free_beds' => 0];
    }
    foreach ($rooms as $room) {
        $id = (int)$room['hostel_id'];
        if (!isset($summary[$id])) {
            continue;
        }
        $summary[$id]['room_count']++;
        $summary[$id]['total_beds'] += (int)$room['capacity'];
        $summary[$id]['free_beds'] += room_free_beds($room);
    }
    return array_values($summary);
}

// ---- Images and banners ----

// Web path of an image in assets/images/, or '' when the file is missing.
function asset_url(string $filename): string
{
    $name = basename($filename);
    return ($name !== '' && is_file(__DIR__ . '/assets/images/' . $name)) ? 'assets/images/' . $name : '';
}

// Outputs an <img> (sized by CSS with object-fit: cover), or a gradient placeholder if the file is missing.
function render_image(string $filename, string $alt, string $class = '', bool $lazy = true): void
{
    $src = asset_url($filename);
    if ($src === '') {
        echo '<span class="' . e(trim($class . ' img-placeholder')) . '" role="img" aria-label="' . e($alt) . '"></span>';
        return;
    }
    echo '<img src="' . e($src) . '" alt="' . e($alt) . '" class="' . e($class) . '"' . ($lazy ? ' loading="lazy"' : '') . '>';
}

// Bedroom photos (room-2bed.jpg, room-4bed.jpg) are used when present; otherwise the study-room photo.
function room_photo_for(int $capacity): string
{
    $preferred = $capacity <= 2 ? 'room-2bed.jpg' : 'room-4bed.jpg';
    return asset_url($preferred) !== '' ? $preferred : 'interior-study-nook.jpg';
}

// Full-width hero banner with the dark gradient overlay and white title text.
function render_banner(string $filename, string $alt, string $title, string $subtitle = ''): void
{
    echo '<section class="kyu-hero kyu-banner">';
    render_image($filename, $alt, 'kyu-hero-img', false);
    echo '<div class="kyu-hero-overlay" aria-hidden="true"></div>';
    echo '<div class="kyu-hero-content">';
    echo '<h1 class="kyu-hero-title">' . e($title) . '</h1>';
    if ($subtitle !== '') {
        echo '<p class="kyu-hero-subtitle mb-0">' . e($subtitle) . '</p>';
    }
    echo '</div></section>';
}

// Amenity badges (Wi-Fi, Study Desk, Hot Shower...) from a comma-separated list.
function amenities_list(string $csv): array
{
    $items = array_map('trim', explode(',', $csv));
    return array_values(array_filter($items, static fn(string $item): bool => $item !== ''));
}

function render_amenity_badges(string $csv): void
{
    foreach (amenities_list($csv) as $amenity) {
        $icon = AMENITY_ICONS[$amenity] ?? 'bi-check2';
        echo '<span class="amenity-badge"><i class="bi ' . e($icon) . '" aria-hidden="true"></i>' . e($amenity) . '</span>';
    }
}

// Homepage room table with live filtering (script.js).
function render_rooms_table(array $rooms, bool $withApply): void
{
    $hostelNames = array_unique(array_column($rooms, 'hostel_name'));
    sort($hostelNames);

    echo '<div data-room-filter>';
    echo '<div class="row g-2 mb-3">';
    echo '<div class="col-md-4"><input type="search" id="fSearch" class="form-control" placeholder="Search room or hostel" aria-label="Search rooms"></div>';
    echo '<div class="col-md-4"><select id="fHostel" class="form-select" aria-label="Filter by hostel"><option value="">All hostels</option>';
    foreach ($hostelNames as $name) {
        echo '<option value="' . e(strtolower($name)) . '">' . e($name) . '</option>';
    }
    echo '</select></div>';
    echo '<div class="col-md-4 d-flex align-items-center"><div class="form-check">'
        . '<input class="form-check-input" type="checkbox" id="fAvail">'
        . '<label class="form-check-label" for="fAvail">Only rooms with free beds</label></div></div>';
    echo '</div>';

    echo '<div class="table-responsive"><table class="table table-hover align-middle bg-white"><thead class="table-dark"><tr>'
        . '<th>Hostel</th><th>Room</th><th>Hostel type</th><th>Beds</th><th>Free</th><th>Fee / semester</th><th>Status</th>'
        . ($withApply ? '<th></th>' : '') . '</tr></thead><tbody>';
    foreach ($rooms as $room) {
        $free = room_free_beds($room);
        $open = $free > 0;
        $label = $room['status'] !== 'Available' ? 'Under maintenance' : ($open ? 'Available' : 'Full');
        $badge = $open ? 'success' : ($room['status'] !== 'Available' ? 'warning' : 'secondary');
        echo '<tr class="room-row" data-hostel="' . e(strtolower($room['hostel_name'])) . '"'
            . ' data-text="' . e(strtolower($room['hostel_name'] . ' ' . $room['room_number'])) . '"'
            . ' data-free="' . ($open ? 1 : 0) . '">';
        echo '<td>' . e($room['hostel_name']) . '</td>';
        echo '<td>' . e($room['room_number']) . '</td>';
        echo '<td>' . e($room['gender']) . '</td>';
        echo '<td>' . (int)$room['capacity'] . '</td>';
        echo '<td>' . $free . '</td>';
        echo '<td>' . e(money($room['fee_per_semester'])) . '</td>';
        echo '<td><span class="badge text-bg-' . $badge . '">' . $label . '</span></td>';
        if ($withApply) {
            echo '<td>' . ($open ? '<a class="btn btn-sm btn-primary" href="apply_room.php?room_id=' . (int)$room['room_id'] . '">Apply</a>' : '') . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '<p id="noRooms" class="text-muted d-none">No rooms match your filters.</p></div>';
}
