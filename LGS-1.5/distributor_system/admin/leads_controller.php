<?php
// admin/leads_controller.php
require_once __DIR__ . '/../functions.php';
require_role('admin');

$db = db_connect();

// Fetch agents
$agents = [];
$res = $db->query("SELECT id, username, full_name FROM users WHERE role='agent' ORDER BY username");
while ($r = $res->fetch_assoc()) $agents[] = $r;

$selected_agent = (int)($_GET['agent_id'] ?? 0);
$selected_date = $_GET['date'] ?? date('Y-m-d');
$mode = $_GET['mode'] ?? 'paged';
$page = max(1, (int)($_GET['page'] ?? 1));
$status_filter = $_GET['status_filter'] ?? 'all';
$per_page = 10;

$status_options = [
    'N/A',
    'Reviewed',
    'Reviewed - Redesign',
    'Contacted - In Progress',
    'Pending - In Progress',
    'Completed - Paid',
    'Bad'
];

// Handle updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_lead'])) {
    verify_csrf_token($_POST['csrf'] ?? '');
    $lead_id = (int)$_POST['lead_id'];
    $updates = [];
    $params = [];
    $types = '';

    if (isset($_POST['company_name'])) {
        $updates[] = 'company_name = ?';
        $params[] = trim($_POST['company_name']);
        $types .= 's';
    }
    if (isset($_POST['description'])) {
        $updates[] = 'description = ?';
        $params[] = trim($_POST['description']);
        $types .= 's';
    }
    if (isset($_POST['status'])) {
        $updates[] = 'status = ?';
        $params[] = $_POST['status'];
        $types .= 's';
    }
    if (isset($_POST['notes'])) {
        $updates[] = 'notes = ?';
        $params[] = trim($_POST['notes']);
        $types .= 's';
    }

    if (!empty($updates)) {
        $sql = "UPDATE leads SET " . implode(', ', $updates) . " WHERE id = ? AND agent_id = ?";
        $stmt = $db->prepare($sql);
        $types .= 'ii';
        $params[] = $lead_id;
        $params[] = $selected_agent;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
        $_SESSION['flash_msg'] = 'Lead updated successfully.';
    }

    header("Location: leads.php?agent_id={$selected_agent}&date={$selected_date}&mode={$mode}&page={$page}&status_filter={$status_filter}");
    exit;
}

// Fetch leads
$leads = [];
$total_rows = 0;

if ($selected_agent > 0) {
    $filter_sql = "agent_id = ? AND lead_date = ?";
    $filter_params = ['is', $selected_agent, $selected_date];

    if ($status_filter !== 'all') {
        $filter_sql .= " AND status = ?";
        $filter_params[0] .= 's';
        $filter_params[] = $status_filter;
    }

    if ($mode === 'paged') {
        $count_sql = "SELECT COUNT(*) FROM leads WHERE $filter_sql";
        $stmt = $db->prepare($count_sql);
        $stmt->bind_param(...$filter_params);
        $stmt->execute();
        $stmt->bind_result($total_rows);
        $stmt->fetch();
        $stmt->close();

        $offset = ($page - 1) * $per_page;
        $sql = "SELECT id, number, company_name, description, status, notes, lead_date, created_at, updated_at 
                FROM leads 
                WHERE $filter_sql
                ORDER BY id ASC
                LIMIT ? OFFSET ?";
        $filter_params[0] .= 'ii';
        $filter_params[] = $per_page;
        $filter_params[] = $offset;
        $stmt = $db->prepare($sql);
        $stmt->bind_param(...$filter_params);
    } else {
        $sql = "SELECT id, number, company_name, description, status, notes, lead_date, created_at, updated_at 
                FROM leads 
                WHERE $filter_sql
                ORDER BY id ASC";
        $stmt = $db->prepare($sql);
        $stmt->bind_param(...$filter_params);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $leads[] = $row;
    $stmt->close();

    // Performance Overview Stats (adapted from agent side, using lead_date for consistency)
    $status_counts = [];
    foreach ($status_options as $status) {
        $safe_status = $db->real_escape_string($status);
        $status_counts[$status] = $db->query("SELECT COUNT(*) AS cnt FROM leads WHERE agent_id = $selected_agent AND status='$safe_status' AND DATE(lead_date) = '$selected_date'")->fetch_assoc()['cnt'] ?? 0;
    }
    $total_leads = array_sum($status_counts);

    $days_active = $db->query("SELECT COUNT(DISTINCT DATE(lead_date)) AS days FROM leads WHERE agent_id = $selected_agent")->fetch_assoc()['days'] ?? 1;
    $average_per_day = $days_active > 0 ? round($total_leads / $days_active, 2) : 0;
    $completed_leads = $status_counts['Completed - Paid'];
    $conversion_rate = $total_leads > 0 ? round(($completed_leads / $total_leads) * 100, 2) : 0;
    $recent_leads = $db->query("SELECT COUNT(*) AS cnt FROM leads WHERE agent_id = $selected_agent AND lead_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['cnt'] ?? 0;

    $peak_result = $db->query("
        SELECT DATE(lead_date) AS peak_date, COUNT(*) AS cnt 
        FROM leads 
        WHERE agent_id = $selected_agent 
        GROUP BY DATE(lead_date) 
        ORDER BY cnt DESC LIMIT 1
    ")->fetch_assoc();
    $peak_day = $peak_result['peak_date'] ?? 'N/A';
    $peak_count = $peak_result['cnt'] ?? 0;

    /* Performance Chart Data */
    $performance_labels = [];
    $performance_data = [];

    $safe_completed = $db->real_escape_string('Completed - Paid');

    for ($i = 0; $i < 24; $i++) {
        $performance_labels[] = str_pad($i, 2, '0', STR_PAD_LEFT) . ':00';
        $performance_data[$i] = 0;
    }

    $hourly_query = $db->query("
        SELECT HOUR(created_at) AS hour, COUNT(*) AS cnt
        FROM leads
        WHERE agent_id = $selected_agent AND status = '$safe_completed' AND DATE(lead_date) = '$selected_date'
        GROUP BY HOUR(created_at)
        ORDER BY hour ASC
    ");

    while ($row = $hourly_query->fetch_assoc()) {
        $performance_data[(int)$row['hour']] = (int)$row['cnt'];
    }
    $performance_data = array_values($performance_data);
}
$db->close();
?>