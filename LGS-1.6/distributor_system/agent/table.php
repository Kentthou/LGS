<?php
require_once __DIR__ . '/../functions.php';
require_role('agent');

$db = db_connect();
$me = current_user();
$agent_id = (int)$me['id'];

// Fetch current profile pic
$profile_pic = '';
$stmt = $db->prepare("SELECT file_path FROM profile_pics WHERE user_id = ?");
$stmt->bind_param('i', $agent_id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $profile_pic = '../' . $row['file_path'];
}
$stmt->close();

$selected_date = $_GET['date'] ?? date('Y-m-d');
$mode = $_GET['mode'] ?? 'paged';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;

// NEW: Lead status filter
$status_filter = $_GET['status_filter'] ?? 'all';

// Fix: Define dummy vars to prevent footer errors (remove if script moved to index.php)
$good_leads = 0;
$bad_leads = 0;
$na_leads = 0;
$performance_labels = [];
$performance_data = [];

$status_options = [
    'N/A',
    'Reviewed',
    'Reviewed - Redesign',
    'Contacted - In Progress',
    'Pending - In Progress',
    'Completed - Paid',
    'Bad'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_lead'])) {
    verify_csrf_token($_POST['csrf'] ?? '');
    $lead_id = (int)($_POST['lead_id']);
    $updates = [];
    $params = [];
    $types = '';

    if (isset($_POST['status'])) { $updates[] = 'status = ?'; $params[] = $_POST['status']; $types .= 's'; }
    if (isset($_POST['notes'])) { $updates[] = 'notes = ?'; $params[] = trim($_POST['notes']); $types .= 's'; }

    if (!empty($updates)) {
        $sql = "UPDATE leads SET " . implode(', ', $updates) . " WHERE id = ? AND agent_id = ?";
        $stmt = $db->prepare($sql);
        $types .= 'ii';
        $params[] = $lead_id;
        $params[] = $agent_id;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: table.php?date={$selected_date}&mode={$mode}&page={$page}&status_filter={$status_filter}&updated=1");
    exit;
}

$leads = [];
$total_rows = 0;

// Build the WHERE clause for filtering
$where_clause = "agent_id = ? AND lead_date = ?";
$params = [$agent_id, $selected_date];
$types = 'is';

if ($status_filter !== 'all') {
    $where_clause .= " AND status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if ($mode === 'paged') {
    // Count total rows
    $sql_count = "SELECT COUNT(*) FROM leads WHERE $where_clause";
    $stmt = $db->prepare($sql_count);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->bind_result($total_rows);
    $stmt->fetch();
    $stmt->close();

    // Fetch paged data
    $offset = ($page - 1) * $per_page;
    $sql_data = "SELECT id, number, company_name, description, status, notes, lead_date, updated_at 
                 FROM leads WHERE $where_clause ORDER BY id ASC LIMIT ? OFFSET ?";
    $types_data = $types . 'ii';
    $params_data = [...$params, $per_page, $offset];
    $stmt = $db->prepare($sql_data);
    $stmt->bind_param($types_data, ...$params_data);
} else {
    // Fetch all
    $sql_data = "SELECT id, number, company_name, description, status, notes, lead_date, updated_at 
                 FROM leads WHERE $where_clause ORDER BY id ASC";
    $stmt = $db->prepare($sql_data);
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $leads[] = $r;
$stmt->close();
$db->close();
$csrf = generate_csrf_token();
?>
<?php include __DIR__ . '/../includes/agent_header.php'; ?>
<?php include __DIR__ . '/../includes/agent_table.php'; ?>
<?php include __DIR__ . '/../includes/agent_footer.php'; ?>