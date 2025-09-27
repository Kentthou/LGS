<?php
require_once __DIR__ . '/../functions.php';
require_role('agent');

// Helper: safely bind params to mysqli stmt (bind_param requires references)
function bind_stmt_params(mysqli_stmt $stmt, string $types, array $params): bool {
    $args = array_merge([$types], $params);
    $refs = [];
    foreach ($args as $key => $val) {
        $refs[$key] = & $args[$key];
    }
    return (bool) call_user_func_array([$stmt, 'bind_param'], $refs);
}

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

// NEW: Lead status filter (supports multi-select)
$status_filter = $_GET['status_filter'] ?? 'all';
if (!is_array($status_filter)) {
    $status_filter = [$status_filter];
} else {
    $status_filter = array_filter($status_filter); // Remove empty
    if (empty($status_filter)) {
        $status_filter = ['all'];
    }
}

// NEW: always deduplicate leads automatically (filter old CSV duplicates)
$dedup = true;

$status_options = [
    'N/A',
    'Reviewed',
    'Reviewed - Redesign',
    'Contacted - In Progress',
    'Pending - In Progress',
    'Completed - Paid',
    'Bad'
];

// Prepare status param for view links
$status_param = '';
if (in_array('all', $status_filter)) {
    $status_param = '&status_filter=all';
} else {
    foreach ($status_filter as $sf) {
        $status_param .= '&status_filter[]=' . urlencode($sf);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_lead'])) {
    verify_csrf_token($_POST['csrf'] ?? '');
    $lead_id = (int)$_POST['lead_id'];
    $updates = [];
    $params = [];
    $types = '';

    if (isset($_POST['company_name'])) { $updates[] = 'company_name = ?'; $params[] = $_POST['company_name']; $types .= 's'; }
    if (isset($_POST['description'])) { $updates[] = 'description = ?'; $params[] = $_POST['description']; $types .= 's'; }
    if (isset($_POST['status'])) { $updates[] = 'status = ?'; $params[] = $_POST['status']; $types .= 's'; }
    if (isset($_POST['notes'])) { $updates[] = 'notes = ?'; $params[] = trim($_POST['notes']); $types .= 's'; }

    if (!empty($updates)) {
        $sql = "UPDATE leads SET " . implode(', ', $updates) . " WHERE id = ? AND agent_id = ?";
        $stmt = $db->prepare($sql);
        $types .= 'ii';
        $params[] = $lead_id;
        $params[] = $agent_id;
        bind_stmt_params($stmt, $types, $params);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: table.php?date={$selected_date}&mode={$mode}&page={$page}{$status_param}&updated=1");
    exit;
}

$leads = [];
$total_rows = 0;

// Build the base WHERE clause for filtering
$filter_statuses = [];
if (!in_array('all', $status_filter)) {
    $filter_statuses = array_filter($status_filter);
}
$num_status = count($filter_statuses);

$where_base = "agent_id = ? AND DATE(lead_date) = ?";
$base_types = 'is';
$base_params = [$agent_id, $selected_date];

$status_part = '';
if (!empty($filter_statuses)) {
    $status_part = " AND status IN (" . implode(',', array_fill(0, $num_status, '?')) . ")";
    $base_types .= str_repeat('s', $num_status);
    $base_params = array_merge($base_params, $filter_statuses);
}

$where_clause = $where_base . $status_part;
$types = $base_types;
$params = $base_params;

// Always deduplicate: Count distinct (number,company) matching filter
$count_sql = "SELECT COUNT(*) FROM (
                SELECT MIN(id) AS id
                FROM leads
                WHERE $where_clause
                GROUP BY LOWER(TRIM(`number`)), LOWER(TRIM(`company_name`))
              ) AS t";
$stmt = $db->prepare($count_sql);
bind_stmt_params($stmt, $types, $params);
$stmt->execute();
$stmt->bind_result($total_rows);
$stmt->fetch();
$stmt->close();

// Fetch data
if ($mode === 'paged') {
    $offset = ($page - 1) * $per_page;
    $sql = "SELECT l.id, l.number, l.company_name, l.description, l.status, l.notes, l.lead_date, l.updated_at
            FROM leads l
            INNER JOIN (
              SELECT MIN(id) AS id
              FROM leads
              WHERE $where_clause
              GROUP BY LOWER(TRIM(`number`)), LOWER(TRIM(`company_name`))
            ) dup ON l.id = dup.id
            ORDER BY l.id ASC
            LIMIT ? OFFSET ?";
    $types_data = $types . 'ii';
    $params_data = array_merge($params, [$per_page, $offset]);
    $stmt = $db->prepare($sql);
    bind_stmt_params($stmt, $types_data, $params_data);
} else {
    // mode == 'all'
    $sql = "SELECT l.id, l.number, l.company_name, l.description, l.status, l.notes, l.lead_date, l.updated_at
            FROM leads l
            INNER JOIN (
              SELECT MIN(id) AS id
              FROM leads
              WHERE $where_clause
              GROUP BY LOWER(TRIM(`number`)), LOWER(TRIM(`company_name`))
            ) dup ON l.id = dup.id
            ORDER BY l.id ASC";
    $types_data = $types;
    $params_data = $params;
    $stmt = $db->prepare($sql);
    bind_stmt_params($stmt, $types_data, $params_data);
}

$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $leads[] = $r;
$stmt->close();

// Performance Overview Stats (for selected date, all statuses)
$status_counts = [];
foreach ($status_options as $status) {
    $safe_status = $db->real_escape_string($status);
    $status_counts[$status] = $db->query("SELECT COUNT(*) AS cnt FROM leads WHERE agent_id = $agent_id AND status='$safe_status' AND DATE(lead_date) = '$selected_date'")->fetch_assoc()['cnt'] ?? 0;
}
$total_leads = array_sum($status_counts);

$total_all_time = $db->query("SELECT COUNT(*) AS cnt FROM leads WHERE agent_id = $agent_id")->fetch_assoc()['cnt'] ?? 0;
$completed_all = $db->query("SELECT COUNT(*) AS cnt FROM leads WHERE agent_id = $agent_id AND status = '" . $db->real_escape_string('Completed - Paid') . "'")->fetch_assoc()['cnt'] ?? 0;
$days_active = $db->query("SELECT COUNT(DISTINCT DATE(lead_date)) AS days FROM leads WHERE agent_id = $agent_id")->fetch_assoc()['days'] ?? 1;
$average_per_day = $days_active > 0 ? round($total_all_time / $days_active, 2) : 0;
$conversion_rate = $total_all_time > 0 ? round(($completed_all / $total_all_time) * 100, 2) : 0;
$recent_leads = $db->query("SELECT COUNT(*) AS cnt FROM leads WHERE agent_id = $agent_id AND lead_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['cnt'] ?? 0;

$peak_result = $db->query("
    SELECT DATE(lead_date) AS peak_date, COUNT(*) AS cnt 
    FROM leads 
    WHERE agent_id = $agent_id 
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
    SELECT HOUR(updated_at) AS hour, COUNT(*) AS cnt
    FROM leads
    WHERE agent_id = $agent_id AND status = '$safe_completed' AND DATE(lead_date) = '$selected_date'
    GROUP BY HOUR(updated_at)
    ORDER BY hour ASC
");

while ($row = $hourly_query->fetch_assoc()) {
    $performance_data[(int)$row['hour']] = (int)$row['cnt'];
}
$performance_data = array_values($performance_data);

$db->close();
$csrf = generate_csrf_token();
?>
<style>
  .action-buttons {
    display: inline-flex;
    gap: 0.5rem;
    align-items: center;
  }
</style>
<?php include __DIR__ . '/../includes/agent_header.php'; ?>
<?php include __DIR__ . '/../includes/agent_sidebar.php'; ?>
<main class="main-wrapper">
  <?php include __DIR__ . '/../includes/agent_navbar.php'; ?>
  <div class="container-fluid">
    <?php include __DIR__ . '/../includes/agent_table.php'; ?>
  </div>
</main>

<script>
  const menuToggle = document.getElementById("menu-toggle");
  const sidebar = document.querySelector("aside.sidebar-nav-wrapper");
  const overlay = document.querySelector(".overlay");

  menuToggle.addEventListener("click", () => {
    sidebar.classList.toggle("active");
    overlay.classList.toggle("active");
  });

  overlay.addEventListener("click", () => {
    sidebar.classList.remove("active");
    overlay.classList.remove("active");
  });
</script>
<?php include __DIR__ . '/../includes/agent_footer.php'; ?>