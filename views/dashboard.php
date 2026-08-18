<?php
// views/dashboard.php
Admin::checkAuth();

require_once __DIR__ . '/../models/Template.php';
require_once __DIR__ . '/../models/Setting.php';

$db = Database::getConnection();

// 1. Fetch metrics
$totalContacts = (int)$db->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
$totalCampaigns = (int)$db->query("SELECT COUNT(*) FROM campaigns")->fetchColumn();

// Aggregate stats across campaigns
$campaignStats = $db->query("
    SELECT 
        SUM(total_contacts) as total_queued,
        SUM(sent_count) as total_sent,
        SUM(delivered_count) as total_delivered,
        SUM(read_count) as total_read,
        SUM(failed_count) as total_failed
    FROM campaigns
")->fetch();
// 1a. Template metadata for dashboard
$templateModel = new Template();
$templateCount = (int)$templateModel->getCount();
$templates = $templateModel->getAll();

// 1b. Meta API status data
$settingModel = new Setting();
$accessToken = $settingModel->get('access_token');
$phoneId = $settingModel->get('phone_number_id');
$apiVersion = $settingModel->get('api_version', 'v23.0');
$apiConfigured = !empty($accessToken) && !empty($phoneId);
$sentCount = (int)($campaignStats['total_sent'] ?? 0);
$deliveredCount = (int)($campaignStats['total_delivered'] ?? 0);
$readCount = (int)($campaignStats['total_read'] ?? 0);
$failedCount = (int)($campaignStats['total_failed'] ?? 0);

// Total Pending in the campaign contacts queue
$pendingCount = (int)$db->query("SELECT COUNT(*) FROM campaign_contacts WHERE status = 'Pending'")->fetchColumn();

// Today's messages (sent/processed today)
$todayCount = (int)$db->query("
    SELECT COUNT(*) 
    FROM campaign_contacts 
    WHERE DATE(sent_at) = CURDATE() OR DATE(delivered_at) = CURDATE() OR DATE(read_at) = CURDATE()
")->fetchColumn();

// 2. Fetch Recent Campaigns
$recentCampaigns = $db->query("
    SELECT c.*, t.name as template_name 
    FROM campaigns c
    JOIN templates t ON c.template_id = t.id
    ORDER BY c.id DESC 
    LIMIT 5
")->fetchAll();

// 3. Prepare Chart Data (Latest 5 campaigns stats)
$chartCampaigns = array_reverse($recentCampaigns);
$chartLabels = [];
$chartSent = [];
$chartDelivered = [];
$chartFailed = [];

foreach ($chartCampaigns as $c) {
    $chartLabels[] = htmlspecialchars($c['name']);
    $chartSent[] = (int)$c['sent_count'];
    $chartDelivered[] = (int)($c['delivered_count'] + $c['read_count']);
    $chartFailed[] = (int)$c['failed_count'];
}

// Format numbers for clean presentation
function formatNum(int $num): string {
    return number_format($num);
}
?>

<!-- Statistics Cards Row -->
<div class="row g-3 mb-4">
    <!-- Total Contacts -->
    <div class="col-xl-3 col-sm-6">
        <div class="card stat-card p-3 border-start border-primary border-4">
            <div class="card-body p-0">
                <p class="text-secondary small fw-semibold mb-1">Total Contacts</p>
                <h3 class="fw-bold text-dark mb-0"><?php echo formatNum($totalContacts); ?></h3>
                <div class="card-icon text-primary"><i class="fas fa-users"></i></div>
            </div>
        </div>
    </div>
    
    <!-- Total Campaigns -->
    <div class="col-xl-3 col-sm-6">
        <div class="card stat-card p-3 border-start border-info border-4">
            <div class="card-body p-0">
                <p class="text-secondary small fw-semibold mb-1">Total Campaigns</p>
                <h3 class="fw-bold text-dark mb-0"><?php echo formatNum($totalCampaigns); ?></h3>
                <div class="card-icon text-info"><i class="fas fa-bullhorn"></i></div>
            </div>
        </div>
    </div>

    <!-- Messages Sent -->
    <div class="col-xl-3 col-sm-6">
        <div class="card stat-card p-3 border-start border-success border-4">
            <div class="card-body p-0">
                <p class="text-secondary small fw-semibold mb-1">Messages Sent</p>
                <h3 class="fw-bold text-dark mb-0"><?php echo formatNum($sentCount); ?></h3>
                <div class="card-icon text-success"><i class="fas fa-paper-plane"></i></div>
            </div>
        </div>
    </div>

    <!-- Messages Delivered -->
    <div class="col-xl-3 col-sm-6">
        <div class="card stat-card p-3 border-start border-teal border-4" style="border-left-color: #20c997 !important;">
            <div class="card-body p-0">
                <p class="text-secondary small fw-semibold mb-1">Delivered (Read)</p>
                <h3 class="fw-bold text-dark mb-0"><?php echo formatNum($deliveredCount); ?> <span class="fs-6 text-muted fw-normal">(<?php echo formatNum($readCount); ?> read)</span></h3>
                <div class="card-icon text-teal" style="color: #20c997;"><i class="fas fa-check-double"></i></div>
            </div>
        </div>
    </div>

    <!-- Messages Failed -->
    <div class="col-xl-3 col-sm-6">
        <div class="card stat-card p-3 border-start border-danger border-4">
            <div class="card-body p-0">
                <p class="text-secondary small fw-semibold mb-1">Failed Messages</p>
                <h3 class="fw-bold text-dark mb-0"><?php echo formatNum($failedCount); ?></h3>
                <div class="card-icon text-danger"><i class="fas fa-times-circle"></i></div>
            </div>
        </div>
    </div>

    <!-- Messages Pending -->
    <div class="col-xl-3 col-sm-6">
        <div class="card stat-card p-3 border-start border-warning border-4">
            <div class="card-body p-0">
                <p class="text-secondary small fw-semibold mb-1">Pending Queue</p>
                <h3 class="fw-bold text-dark mb-0"><?php echo formatNum($pendingCount); ?></h3>
                <div class="card-icon text-warning"><i class="fas fa-clock"></i></div>
            </div>
        </div>
    </div>

    <!-- Templates Registered -->
    <div class="col-xl-3 col-sm-6">
        <div class="card stat-card p-3 border-start border-secondary border-4">
            <div class="card-body p-0">
                <p class="text-secondary small fw-semibold mb-1">Templates Registered</p>
                <h3 class="fw-bold text-dark mb-0"><?php echo formatNum($templateCount); ?></h3>
                <div class="card-icon text-secondary"><i class="fas fa-file-invoice"></i></div>
            </div>
        </div>
    </div>

    <!-- Meta API Status -->
    <div class="col-xl-3 col-sm-6">
        <div class="card stat-card p-3 border-start border-dark border-4">
            <div class="card-body p-0">
                <p class="text-secondary small fw-semibold mb-1">Meta API Status</p>
                <h3 class="fw-bold text-dark mb-0"><?php echo $apiConfigured ? '<span class="text-success">Configured</span>' : '<span class="text-danger">Not Configured</span>'; ?></h3>
                <div class="card-icon text-dark"><i class="fas fa-plug"></i></div>
            </div>
        </div>
    </div>

    <!-- Today's Messages -->
    <div class="col-xl-3 col-sm-6">
        <div class="card stat-card p-3 border-start border-indigo border-4" style="border-left-color: #6610f2 !important;">
            <div class="card-body p-0">
                <p class="text-secondary small fw-semibold mb-1">Today's Traffic</p>
                <h3 class="fw-bold text-dark mb-0"><?php echo formatNum($todayCount); ?></h3>
                <div class="card-icon text-indigo" style="color: #6610f2;"><i class="fas fa-calendar-day"></i></div>
            </div>
        </div>
    </div>

    <!-- Active Delivery Success Rate -->
    <div class="col-xl-3 col-sm-6">
        <div class="card stat-card p-3 border-start border-purple border-4" style="border-left-color: #6f42c1 !important;">
            <div class="card-body p-0">
                <p class="text-secondary small fw-semibold mb-1">Delivery Success Rate</p>
                <h3 class="fw-bold text-dark mb-0">
                    <?php 
                    $totalProcessed = $deliveredCount + $failedCount;
                    echo $totalProcessed > 0 ? round(($deliveredCount / $totalProcessed) * 100, 1) . '%' : '0%';
                    ?>
                </h3>
                <div class="card-icon text-purple" style="color: #6f42c1;"><i class="fas fa-percentage"></i></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card p-3 h-100">
            <div class="card-header bg-transparent border-0 ps-0 d-flex align-items-center justify-content-between">
                <div>
                    <h5 class="fw-bold text-dark mb-1">Template Registry</h5>
                    <p class="text-secondary small mb-0">Meta templates configured in the system</p>
                </div>
                <a href="index.php?page=templates" class="btn btn-sm btn-outline-primary">Manage Templates</a>
            </div>
            <div class="card-body p-0 pt-2">
                <div class="row g-2 mb-3">
                    <div class="col-sm-6">
                        <div class="bg-light p-3 rounded border">
                            <p class="text-secondary small mb-1">Registered Templates</p>
                            <h4 class="mb-0"><?php echo formatNum($templateCount); ?></h4>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="bg-light p-3 rounded border">
                            <p class="text-secondary small mb-1">Meta API Status</p>
                            <h4 class="mb-0 <?php echo $apiConfigured ? 'text-success' : 'text-danger'; ?>"><?php echo $apiConfigured ? 'Configured' : 'Not Configured'; ?></h4>
                        </div>
                    </div>
                </div>
                <?php if (empty($templates)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-file-invoice fa-2x mb-3"></i>
                        <p class="small mb-0">No template metadata is available yet. Add a template to start sending Meta messages.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-borderless align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Language</th>
                                    <th>Header</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($templates, 0, 5) as $tpl): ?>
                                    <tr>
                                        <td class="font-monospace"><?php echo htmlspecialchars($tpl['name']); ?></td>
                                        <td class="text-secondary"><?php echo htmlspecialchars($tpl['language']); ?></td>
                                        <td class="text-truncate" style="max-width: 260px;" title="<?php echo htmlspecialchars($tpl['header_variables'] ?? '-'); ?>"><?php echo htmlspecialchars($tpl['header_variables'] ?: '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card p-3 h-100">
            <div class="card-header bg-transparent border-0 ps-0">
                <h5 class="fw-bold text-dark mb-1">Meta Template Health</h5>
                <p class="text-secondary small mb-0">Realtime system and API metadata checks</p>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <p class="small text-secondary mb-1">Access Token</p>
                    <p class="fw-semibold mb-0 text-truncate" title="<?php echo htmlspecialchars($accessToken); ?>"><?php echo !empty($accessToken) ? 'Saved' : 'Missing'; ?></p>
                </div>
                <div class="mb-3">
                    <p class="small text-secondary mb-1">Phone Number ID</p>
                    <p class="fw-semibold mb-0"><?php echo !empty($phoneId) ? 'Saved' : 'Missing'; ?></p>
                </div>
                <div class="mb-3">
                    <p class="small text-secondary mb-1">API Version</p>
                    <p class="fw-semibold mb-0"><?php echo htmlspecialchars($apiVersion); ?></p>
                </div>
                <div class="mb-3">
                    <p class="small text-secondary mb-1">Realtime Template Access</p>
                    <p class="fw-semibold mb-0"><?php echo !empty($templates) ? 'Available' : 'No templates registered'; ?></p>
                </div>
                <div class="alert <?php echo $apiConfigured ? 'alert-success' : 'alert-warning'; ?> py-3 mb-0">
                    <?php if ($apiConfigured): ?>
                        <strong>Meta API is configured.</strong> Use the Templates tab to add or manage your Meta template metadata.
                    <?php else: ?>
                        <strong>Meta API is not fully configured.</strong> Save your API credentials in the Settings tab to enable real-time Meta template dispatch.
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Chart Column -->
    <div class="col-lg-7">
        <div class="card p-3 h-100">
            <div class="card-header bg-transparent border-0 ps-0">
                <h5 class="fw-bold text-dark mb-1">Campaign Analytics</h5>
                <p class="text-secondary small mb-0">Performance of the latest 5 campaigns</p>
            </div>
            <div class="card-body">
                <canvas id="campaignChart" style="max-height: 320px;"></canvas>
            </div>
        </div>
    </div>

    <!-- Recent Campaigns Column -->
    <div class="col-lg-5">
        <div class="card p-3 h-100">
            <div class="card-header bg-transparent border-0 ps-0 d-flex align-items-center justify-content-between">
                <div>
                    <h5 class="fw-bold text-dark mb-1">Recent Campaigns</h5>
                    <p class="text-secondary small mb-0">Last 5 generated campaigns</p>
                </div>
                <a href="index.php?page=campaigns" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0 pt-2">
                <?php if (empty($recentCampaigns)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-bullhorn fa-2x mb-3"></i>
                        <p class="small mb-0">No campaigns found. Create one to get started.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle small mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Status</th>
                                    <th>Success Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentCampaigns as $c): ?>
                                    <?php
                                    $badgeClass = 'bg-secondary';
                                    if ($c['status'] === 'Completed') $badgeClass = 'bg-success';
                                    elseif ($c['status'] === 'Sending') $badgeClass = 'bg-warning text-dark';
                                    elseif ($c['status'] === 'Paused') $badgeClass = 'bg-info text-dark';
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($c['name']); ?></div>
                                            <span class="text-muted small"><?php echo htmlspecialchars($c['template_name']); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $badgeClass; ?>"><?php echo $c['status']; ?></span>
                                        </td>
                                        <td>
                                            <?php
                                            $total = (int)$c['total_contacts'];
                                            $success = (int)$c['delivered_count'] + (int)$c['read_count'];
                                            $rate = $total > 0 ? round(($success / $total) * 100, 1) : 0;
                                            ?>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="progress flex-grow-1" style="height: 6px; width: 60px;">
                                                    <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $rate; ?>%" aria-valuenow="<?php echo $rate; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                </div>
                                                <span><?php echo $rate; ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Chart script -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const ctx = document.getElementById('campaignChart').getContext('2d');
    const labels = <?php echo json_encode($chartLabels); ?>;
    const sentData = <?php echo json_encode($chartSent); ?>;
    const deliveredData = <?php echo json_encode($chartDelivered); ?>;
    const failedData = <?php echo json_encode($chartFailed); ?>;

    if (labels.length === 0) {
        ctx.font = '14px Inter';
        ctx.fillStyle = '#888';
        ctx.textAlign = 'center';
        ctx.fillText('No campaign data to display', ctx.canvas.width / 2, ctx.canvas.height / 2);
        return;
    }

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Sent',
                    data: sentData,
                    backgroundColor: 'rgba(13, 110, 253, 0.75)',
                    borderColor: 'rgb(13, 110, 253)',
                    borderWidth: 1
                },
                {
                    label: 'Delivered',
                    data: deliveredData,
                    backgroundColor: 'rgba(32, 201, 151, 0.75)',
                    borderColor: 'rgb(32, 201, 151)',
                    borderWidth: 1
                },
                {
                    label: 'Failed',
                    data: failedData,
                    backgroundColor: 'rgba(220, 53, 69, 0.75)',
                    borderColor: 'rgb(220, 53, 69)',
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        font: {
                            family: 'Inter'
                        }
                    }
                }
            }
        }
    });
});
</script>
