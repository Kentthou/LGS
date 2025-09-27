<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../functions.php';
require_role('admin');
$db = db_connect();
$err = $msg = '';

// --- Fetch agents ---
$agents = [];
$res = $db->query("SELECT id, username, full_name FROM users WHERE role='agent' ORDER BY username");
if ($res) $agents = $res->fetch_all(MYSQLI_ASSOC);

/**
 * Validate uploaded CSV file
 */
function validate_csv_upload(array $file): ?string {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) return 'File upload error.';
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') return 'Only CSV files allowed.';
    if ($file['size'] > 5 * 1024 * 1024) return 'Max 5MB.';
    return null;
}

/**
 * Read CSV from temp path, deduplicate rows by number (first column) and company (second column),
 * taking into account existing leads across all users in the DB. Return unique rows and count.
 */
function process_csv_for_dedup(string $tmpPath, mysqli $db, string $today): array {
    $uniqueRows = []; // array of original data rows
    $seen = [];

    // Preload existing leads (number + company) into seen set to avoid duplicates across users
    $res = $db->query("SELECT number, company_name FROM leads");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $n = strtolower(trim($r['number'] ?? ''));
            $c = strtolower(trim($r['company_name'] ?? ''));
            if ($n === '' || $c === '') continue;
            $seen[$n . '|' . $c] = true;
        }
        $res->free();
    }

    if (($handle = fopen($tmpPath, 'r')) === false) return [[], 0];
    $rowNum = 0;
    while (($data = fgetcsv($handle, 0, ",")) !== false) {
        $rowNum++;
        if ($rowNum === 1) { // skip header if detected
            $lower = strtolower(implode(',', $data));
            if (strpos($lower, 'company') !== false || strpos($lower, 'name') !== false) continue;
        }
        if (!array_filter($data)) continue; // skip empty row
        $number = trim($data[0] ?? '');
        $company = trim($data[1] ?? '');
        if ($number === '' || $company === '') continue;
        // Normalize when checking for duplicates (case-insensitive)
        $key = strtolower($number) . '|' . strtolower($company);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $uniqueRows[] = $data;
    }
    fclose($handle);
    return [$uniqueRows, count($uniqueRows)];
}

// --- Controller Logic ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf'] ?? null)) {
        $err = 'Invalid CSRF token.';
    } elseif (empty($_POST['agent_id'])) {
        $err = 'Select an agent.';
    } elseif ($error = validate_csv_upload($_FILES['csv'])) {
        $err = $error;
    } else {
        $today = date('Y-m-d');
        list($uniqueRows, $uniqueCount) = process_csv_for_dedup($_FILES['csv']['tmp_name'], $db, $today);
        if ($uniqueCount === 0) {
            $err = 'CSV processed but no valid leads found.';
        } else {
            $uploadDir = __DIR__ . '/../uploads';
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    $err = 'Failed to create uploads directory.';
                }
            }
            if (!$err) {
                if (!is_writable($uploadDir)) {
                    $err = 'Uploads directory not writable. Check permissions.';
                }
            }
            if (!$err) {
                $filename = 'upload_' . time() . '_' . uniqid() . '.csv';
                $dest = $uploadDir . '/' . $filename;
                $dedupHandle = fopen($dest, 'w');
                if (!$dedupHandle) {
                    $err = 'Failed to create deduplicated file.';
                } else {
                    foreach ($uniqueRows as $data) {
                        fputcsv($dedupHandle, $data);
                    }
                    fclose($dedupHandle);
                    $admin_id = $_SESSION['user_id'];
                    $ins_csv = $db->prepare("INSERT INTO uploaded_csvs (agent_id, file_path, uploaded_by) VALUES (?, ?, ?)");
                    if (!$ins_csv) {
                        $err = 'Database error: Failed to prepare CSV insert.';
                    } else {
                        $agentIdInt = (int) $_POST['agent_id'];
                        if (!$ins_csv->bind_param('isi', $agentIdInt, $dest, $admin_id)) {
                            $err = 'Database error: Failed to bind CSV parameters.';
                        } elseif (!$ins_csv->execute()) {
                            $err = 'Database error: Failed to insert CSV record.';
                        } else {
                            $csv_id = $ins_csv->insert_id;
                            $ins_csv->close();
                            $stmt = $db->prepare("INSERT INTO leads 
                                (number, agent_id, company_name, description, status, notes, lead_date, csv_id) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                            if (!$stmt) {
                                $err = 'Database error: Failed to prepare lead insert.';
                            } else {
                                $inserted = 0;
                                foreach ($uniqueRows as $data) {
                                    $number = trim($data[0] ?? '');
                                    $company = trim($data[1] ?? '');
                                    $desc = trim($data[2] ?? '');
                                    $status = ucfirst(strtolower(trim($data[4] ?? 'N/A')));
                                    $notes = trim($data[5] ?? '');
                                    $lead_date = trim($data[6] ?? $today);
                                    if (!in_array($status, ['Good','Bad','N/A'])) $status = 'N/A';
                                    $stmt->bind_param('sisssssi', $number, $agentIdInt, $company, $desc, $status, $notes, $lead_date, $csv_id);
                                    if ($stmt->execute()) $inserted++;
                                }
                                $stmt->close();
                                $msg = $inserted > 0 ? "CSV processed: $inserted leads imported (deduplicated to $uniqueCount unique rows)." : "CSV processed but no leads inserted.";
                            }
                        }
                    }
                }
            }
        }
    }
}
$db->close();

$csrf = generate_csrf_token();
require_once __DIR__ . '/../includes/admin_header.php';
?>
<body>
<?php require_once __DIR__ . '/../includes/admin_sidebar.php'; ?>
<div class="overlay active"></div>

<main class="main-wrapper">
    <?php require_once __DIR__ . '/../includes/admin_navbar.php'; ?>

    <section class="table-components">
      <div class="container-fluid">
        <div class="row mt-5">
          <div class="col-lg-12">
            <div class="card-style mb-30">
              <div class="card mb-3">
                <div class="card-body">
                  <h4 class="mb-3">Upload Leads (CSV)</h4>

                  <?php if ($err): ?><div class="alert alert-danger"><?= esc($err) ?></div><?php endif; ?>
                  <?php if ($msg): ?><div class="alert alert-success"><?= esc($msg) ?></div><?php endif; ?>

                  <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                    <div class="mb-3">
                      <label class="form-label">Agent</label>
                      <select name="agent_id" class="form-select" required>
                        <option value="">-- select agent --</option>
                        <?php if ($agents): ?>
                          <?php foreach ($agents as $a): ?>
                            <option value="<?= esc((string)$a['id']) ?>">
                              <?= esc($a['username'] . ' — ' . $a['full_name']) ?>
                            </option>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <option value="">⚠ No agents found. Please add an agent first.</option>
                        <?php endif; ?>
                      </select>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">CSV file</label>
                      <input type="file" name="csv" accept=".csv" class="form-control" required>
                      <div class="form-text">
                        CSV columns (header optional): id, company_name, description, agent_id, status, notes, lead_date. Max 5MB.
                      </div>
                    </div>
                    <button class="btn btn-primary">Upload and Assign</button>
                  </form>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
</main>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
</body>
</html>