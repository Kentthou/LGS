<section class="table-components">
  <div class="container-fluid">
    <div class="row mt-5">
      <div class="col-lg-12">
        <div class="card-style mb-30">
          <div class="card mb-3">
            <div class="card-body">
              <h4 class="mb-3">Leads Monitor</h4>

              <!-- Filter Form -->
              <form class="row g-3 align-items-end mb-4" method="get">
                <input type="hidden" name="mode" value="<?= esc($mode) ?>">
                <div class="col-md-4">
                  <label class="form-label fw-bold">Agent</label>
                  <select name="agent_id" class="form-select" onchange="this.form.submit()">
                    <option value="">-- Select Agent --</option>
                    <?php foreach ($agents as $a): ?>
                      <option value="<?= esc((string)$a['id']) ?>" <?= $selected_agent === (int)$a['id'] ? 'selected' : '' ?>>
                        <?= esc($a['username'] . ' — ' . $a['full_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-5">
                  <label class="form-label fw-bold">Date</label>
                  <div class="input-group">
                    <a class="btn btn-outline-secondary" href="?agent_id=<?= $selected_agent ?>&date=<?= esc(date('Y-m-d', strtotime($selected_date . ' -1 day'))) ?>&mode=<?= esc($mode) ?>&status_filter=<?= esc($status_filter) ?>">← Prev</a>
                    <input type="date" name="date" value="<?= esc($selected_date) ?>" class="form-control" onchange="this.form.submit()">
                    <a class="btn btn-outline-secondary" href="?agent_id=<?= $selected_agent ?>&date=<?= esc(date('Y-m-d')) ?>&mode=<?= esc($mode) ?>&status_filter=<?= esc($status_filter) ?>">Today</a>
                    <a class="btn btn-outline-secondary" href="?agent_id=<?= $selected_agent ?>&date=<?= esc(date('Y-m-d', strtotime($selected_date . ' +1 day'))) ?>&mode=<?= esc($mode) ?>&status_filter=<?= esc($status_filter) ?>">Next →</a>
                  </div>
                </div>
              </form>

              <?php if ($selected_agent === 0): ?>
                <div class="alert alert-info">Please select an agent to view leads.</div>
              <?php else: ?>
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <div class="d-flex align-items-center gap-2">
                    <h5 class="mb-0">Leads for <span class="text-primary"><?= esc($selected_date) ?></span></h5>

                    <!-- Dropdown Filter -->
                    <div class="dropdown">
                      <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" id="statusFilterDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        Filter: <?= esc(ucfirst($status_filter)) ?>
                      </button>
                      <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="statusFilterDropdown">
                        <li><a class="dropdown-item" href="?agent_id=<?= $selected_agent ?>&date=<?= esc($selected_date) ?>&mode=<?= esc($mode) ?>&status_filter=all">All</a></li>
                        <li><a class="dropdown-item" href="?agent_id=<?= $selected_agent ?>&date=<?= esc($selected_date) ?>&mode=<?= esc($mode) ?>&status_filter=Reviewed">Reviewed</a></li>
                        <li><a class="dropdown-item" href="?agent_id=<?= $selected_agent ?>&date=<?= esc($selected_date) ?>&mode=<?= esc($mode) ?>&status_filter=Reviewed - Redesign">Reviewed - Redesign</a></li>
                        <li><a class="dropdown-item" href="?agent_id=<?= $selected_agent ?>&date=<?= esc($selected_date) ?>&mode=<?= esc($mode) ?>&status_filter=Contacted - In Progress">Contacted - In Progress</a></li>
                        <li><a class="dropdown-item" href="?agent_id=<?= $selected_agent ?>&date=<?= esc($selected_date) ?>&mode=<?= esc($mode) ?>&status_filter=Pending - In Progress">Pending - In Progress</a></li>
                        <li><a class="dropdown-item" href="?agent_id=<?= $selected_agent ?>&date=<?= esc($selected_date) ?>&mode=<?= esc($mode) ?>&status_filter=Completed - Paid">Completed - Paid</a></li>
                        <li><a class="dropdown-item" href="?agent_id=<?= $selected_agent ?>&date=<?= esc($selected_date) ?>&mode=<?= esc($mode) ?>&status_filter=Bad">Bad</a></li>
                        <li><a class="dropdown-item" href="?agent_id=<?= $selected_agent ?>&date=<?= esc($selected_date) ?>&mode=<?= esc($mode) ?>&status_filter=N/A">N/A</a></li>
                      </ul>
                    </div>
                  </div>
                  <a href="?agent_id=<?= $selected_agent ?>&date=<?= esc($selected_date) ?>&mode=<?= $mode === 'all' ? 'paged' : 'all' ?>&status_filter=<?= esc($status_filter) ?>" class="btn btn-sm btn-outline-dark">
                    <?= $mode === 'all' ? 'Switch to Paginated' : 'Show All Leads' ?>
                  </a>
                </div>

                <?php if (empty($leads)): ?>
                  <div class="alert alert-info">No leads for this date.</div>
                <?php else: ?>
                  <?php $modals = ''; ?>
                  <?php
                    $status_classes = [
                        'Reviewed' => 'status-reviewed',
                        'Reviewed - Redesign' => 'status-redesign',
                        'Contacted - In Progress' => 'status-contacted',
                        'Pending - In Progress' => 'status-pending',
                        'Completed - Paid' => 'status-completed',
                        'Bad' => 'status-bad',
                    ];
                  ?>
                  <div class="table-responsive">
                    <table class="table table-hover align-middle">
                      <thead class="table-light">
                        <tr>
                          <th>#</th>
                          <th>Company</th>
                          <th>Description</th>
                          <th>Status</th>
                          <th>Notes</th>
                          <th>Updated</th>
                          <th>Action</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($leads as $l): ?>
                          <?php
                            $row_class = $status_classes[$l['status']] ?? '';
                            $modal_id = "editLeadModal" . (int)$l['id'];
                            $modals .= '
                            <div class="modal fade" id="' . esc($modal_id) . '" tabindex="-1" aria-hidden="true">
                              <div class="modal-dialog modal-lg modal-dialog-centered">
                                <div class="modal-content">
                                  <form method="post">
                                    <input type="hidden" name="csrf" value="' . esc($csrf) . '">
                                    <div class="modal-header">
                                      <h5 class="modal-title">Edit Lead #' . esc((string)$l['number']) . '</h5>
                                      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                      <input type="hidden" name="lead_id" value="' . esc((string)$l['id']) . '">
                                      <div class="mb-3"><label class="form-label">Company Name</label><input type="text" name="company_name" class="form-control" value="' . esc($l['company_name']) . '"></div>
                                      <div class="mb-3"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3">' . esc($l['description']) . '</textarea></div>
                                      <div class="mb-3"><label class="form-label">Status</label><select name="status" class="form-select">';
                            foreach ($status_options as $statusOption) {
                                $selectedAttr = ($l['status'] === $statusOption) ? ' selected' : '';
                                $modals .= '<option value="' . $statusOption . '"' . $selectedAttr . '>' . $statusOption . '</option>';
                            }
                            $modals .= '</select></div>
                                      <div class="mb-3"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="3">' . esc($l['notes']) . '</textarea></div>
                                    </div>
                                    <div class="modal-footer">
                                      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                      <button type="submit" name="update_lead" class="btn btn-primary">Save Changes</button>
                                    </div>
                                  </form>
                                </div>
                              </div>
                            </div>';
                          ?>
                          <tr class="<?= $row_class ?>">
                            <td>
                              <a href="https://wa.me/<?= esc((string)$l['number']) ?>" target="_blank" class="text-decoration-none">
                                <?= esc((string)$l['number']) ?>
                              </a>
                            </td>
                            <td>
                              <a href="https://www.google.com/search?q=<?= urlencode($l['company_name']) ?>" target="_blank" class="text-decoration-none truncate" data-bs-toggle="tooltip" data-bs-placement="top" title="<?= esc($l['company_name']) ?>">
                                <?= esc($l['company_name']) ?>
                              </a>
                            </td>
                            <td class="truncate" data-bs-toggle="tooltip" data-bs-placement="top" title="<?= esc($l['description']) ?>"><?= esc(mb_strimwidth($l['description'],0,50,'...')) ?></td>
                            <td>
                              <form method="post" class="d-inline">
                                <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                                <input type="hidden" name="lead_id" value="<?= esc((string)$l['id']) ?>">
                                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                  <?php foreach ($status_options as $s): ?>
                                    <option value="<?= $s ?>" <?= $l['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                                  <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="update_lead" value="1">
                              </form>
                            </td>
                            <td>
                              <form method="post" class="d-inline">
                                <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                                <input type="hidden" name="lead_id" value="<?= esc((string)$l['id']) ?>">
                                <input type="text" name="notes" class="form-control form-control-sm" value="<?= esc($l['notes']) ?>" onchange="this.form.submit()">
                                <input type="hidden" name="update_lead" value="1">
                              </form>
                            </td>
                            <td><?= esc(date('H:i', strtotime($l['updated_at']))) ?></td>
                            <td><button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#<?= esc($modal_id) ?>">Edit</button></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                  <?= $modals ?>

                  <!-- Pagination -->
                  <?php if ($mode === 'paged' && $total_rows > $per_page): ?>
                    <?php $total_pages = ceil($total_rows / $per_page); ?>
                    <nav aria-label="Lead pagination">
                      <ul class="pagination justify-content-center">
                        <?php
                          $max_visible = 10;
                          $half_visible = floor($max_visible / 2);
                          $start = max(1, $page - $half_visible);
                          $end = min($total_pages, $start + $max_visible - 1);
                          if ($end - $start + 1 < $max_visible) {
                            $start = max(1, $end - $max_visible + 1);
                          }
                        ?>
                        <!-- First -->
                        <?php if ($page > 1): ?>
                          <li class="page-item">
                            <a class="page-link" href="?agent_id=<?= $selected_agent ?>&date=<?= esc($selected_date) ?>&mode=paged&page=1&status_filter=<?= esc($status_filter) ?>">First</a>
                          </li>
                        <?php endif; ?>
                        <!-- Prev -->
                        <?php if ($page > 1): ?>
                          <li class="page-item">
                            <a class="page-link" href="?agent_id=<?= $selected_agent ?>&date=<?= esc($selected_date) ?>&mode=paged&page=<?= $page - 1 ?>&status_filter=<?= esc($status_filter) ?>">Prev</a>
                          </li>
                        <?php else: ?>
                          <li class="page-item disabled">
                            <span class="page-link">Prev</span>
                          </li>
                        <?php endif; ?>
                        <!-- Numbers -->
                        <?php for ($p = $start; $p <= $end; $p++): ?>
                          <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?agent_id=<?= $selected_agent ?>&date=<?= esc($selected_date) ?>&mode=paged&page=<?= $p ?>&status_filter=<?= esc($status_filter) ?>"><?= $p ?></a>
                          </li>
                        <?php endfor; ?>
                        <!-- Next -->
                        <?php if ($page < $total_pages): ?>
                          <li class="page-item">
                            <a class="page-link" href="?agent_id=<?= $selected_agent ?>&date=<?= esc($selected_date) ?>&mode=paged&page=<?= $page + 1 ?>&status_filter=<?= esc($status_filter) ?>">Next</a>
                          </li>
                        <?php else: ?>
                          <li class="page-item disabled">
                            <span class="page-link">Next</span>
                          </li>
                        <?php endif; ?>
                        <!-- Last -->
                        <?php if ($page < $total_pages): ?>
                          <li class="page-item">
                            <a class="page-link" href="?agent_id=<?= $selected_agent ?>&date=<?= esc($selected_date) ?>&mode=paged&page=<?= $total_pages ?>&status_filter=<?= esc($status_filter) ?>">Last</a>
                          </li>
                        <?php endif; ?>
                      </ul>
                    </nav>
                  <?php endif; ?>

                  <!-- Leads Distribution (added above performance overview) -->
                  <?php if ($selected_agent > 0): ?>
                    <div class="card mt-4">
                      <div class="card-body">
                        <h5 class="mb-3">Leads Distribution</h5>
                        <div class="chart-container">
                          <canvas id="leadsPieChart"></canvas>
                        </div>
                        <div class="row g-3 mt-3">
                          <div class="col-md-3 col-sm-6 col-12"><div class="stat-card"><h3><?= $total_leads ?></h3><p>Total</p></div></div>
                          <?php foreach ($status_options as $status): ?>
                          <div class="col-md-3 col-sm-6 col-12"><div class="stat-card"><h3><?= $status_counts[$status] ?></h3><p><?= esc($status) ?></p></div></div>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    </div>

                    <!-- Performance Overview -->
                    <div class="card mt-4">
                      <div class="card-body">
                        <h5 class="mb-3">Performance Overview</h5>
                        <div class="chart-container">
                          <canvas id="performanceChart"></canvas>
                        </div>
                        <div class="row g-3 mt-3">
                          <div class="col-3"><div class="stat-card"><h3><?= $average_per_day ?></h3><p>Avg / Day</p></div></div>
                          <div class="col-3"><div class="stat-card"><h3><?= $conversion_rate ?>%</h3><p>Conversion</p></div></div>
                          <div class="col-3"><div class="stat-card"><h3><?= $recent_leads ?></h3><p>Last 7 Days</p></div></div>
                          <div class="col-3"><div class="stat-card"><h3><?= $peak_count ?></h3><p>Peak Day</p></div></div>
                        </div>
                      </div>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              <?php endif; ?>

              <script src="../assets/js/Chart.min.js"></script>
              <script>
                // Pie Chart for Leads Distribution
                const ctxPie = document.getElementById('leadsPieChart').getContext('2d');
                const leadsPieChart = new Chart(ctxPie, {
                  type: 'pie',
                  data: {
                    labels: <?= json_encode($status_options) ?>,
                    datasets: [{
                      data: <?= json_encode(array_values($status_counts)) ?>,
                      backgroundColor: [
                        '#FF6384', // N/A
                        '#36A2EB', // Reviewed
                        '#FFCE56', // Reviewed - Redesign
                        '#4BC0C0', // Contacted - In Progress
                        '#9966FF', // Pending - In Progress
                        '#FF9F40', // Completed - Paid
                        '#C9CBCF'  // Bad
                      ]
                    }]
                  },
                  options: {
                    responsive: true,
                    plugins: {
                      legend: {
                        position: 'top',
                      }
                    }
                  }
                });

                // Line Chart for Performance Overview
                const ctx = document.getElementById('performanceChart').getContext('2d');
                const performanceChart = new Chart(ctx, {
                  type: 'line',
                  data: {
                    labels: <?= json_encode($performance_labels ?? []) ?>,
                    datasets: [{
                      label: 'Completed Leads',
                      data: <?= json_encode($performance_data ?? []) ?>,
                      borderColor: 'rgb(75, 192, 192)',
                      tension: 0.1
                    }]
                  },
                  options: {
                    responsive: true,
                    maintainAspectRatio: false, // ensures chart uses CSS container height
                    layout: {
                      padding: 0
                    },
                    scales: {
                      y: {
                        beginAtZero: true
                      }
                    }
                  }
                });
              </script>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>