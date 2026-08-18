<?php
// views/reports.php
Admin::checkAuth();

require_once __DIR__ . '/../models/Campaign.php';
$campaignModel = new Campaign();

$db = Database::getConnection();

// -------------------------------------------------------------
// Report Export Action (Excel/CSV Download)
// -------------------------------------------------------------
$action = $_GET['action'] ?? '';
if ($action === 'export_report') {
    $campaignId = (int)($_GET['campaign_id'] ?? 0);
    $campaign = $campaignModel->getById($campaignId);
    
    if ($campaign) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=campaign_report_' . sanitizeFileName($campaign['name']) . '_' . date('Ymd') . '.csv');
        $output = fopen('php://output', 'w');
        
        // Write header row
        fputcsv($output, ['Campaign Name', htmlspecialchars_decode($campaign['name'])]);
        fputcsv($output, ['Template Name', $campaign['template_name']]);
        fputcsv($output, ['Language', $campaign['template_language']]);
        fputcsv($output, []); // Empty spacing row
        
        fputcsv($output, ['Recipient Name', 'Phone Number', 'City', 'Course', 'Status', 'Sent At', 'Delivered At', 'Read At', 'Error Message']);
        
        $contacts = $campaignModel->getCampaignContacts($campaignId);
        foreach ($contacts as $c) {
            fputcsv($output, [
                $c['contact_name'],
                $c['contact_phone'],
                $c['city'] ?? '-',
                $c['course'] ?? '-',
                $c['status'],
                $c['sent_at'] ?? '',
                $c['delivered_at'] ?? '',
                $c['read_at'] ?? '',
                $c['error_message'] ?? ''
            ]);
        }
        fclose($output);
        exit();
    }
}

function sanitizeFileName(string $name): string {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
}

// -------------------------------------------------------------
// Main Report Viewer Logic
// -------------------------------------------------------------
$campaignId = (int)($_GET['campaign_id'] ?? 0);
$campaign = null;
$campaignContacts = [];
$allCampaigns = [];

if ($campaignId > 0) {
    $campaign = $campaignModel->getById($campaignId);
    if ($campaign) {
        // Retrieve and force refresh stats
        $stats = $campaignModel->updateStats($campaignId);
        $campaignContacts = $campaignModel->getCampaignContacts($campaignId);
    }
} else {
    // Load all campaigns list
    $allCampaigns = $campaignModel->getAll();
}
?>

<?php if ($campaign): ?>
    <!-- Campaign Details Report -->
    <div class="mb-4">
        <a href="index.php?page=reports" class="btn btn-sm btn-outline-secondary mb-3">
            <i class="fas fa-arrow-left me-1"></i> Back to Campaign List
        </a>
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
            <div>
                <h4 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($campaign['name']); ?></h4>
                <p class="text-secondary small mb-0">Template: <code><?php echo htmlspecialchars($campaign['template_name']); ?></code> | Lang: <code><?php echo $campaign['template_language']; ?></code></p>
            </div>
            <a href="index.php?page=reports&action=export_report&campaign_id=<?php echo $campaign['id']; ?>" class="btn btn-success">
                <i class="fas fa-file-excel me-1"></i> Export Excel (CSV)
            </a>
        </div>
    </div>

    <!-- Summary Statistics Grid -->
    <div class="row g-3 mb-4">
        <!-- Total Queued -->
        <div class="col-sm-6 col-md-3">
            <div class="card p-3 border-start border-primary border-4 text-center">
                <p class="text-secondary small fw-semibold mb-1">Total Recipients</p>
                <h3 class="fw-bold m-0"><?php echo number_format($campaign['total_contacts']); ?></h3>
            </div>
        </div>
        <!-- Successfully Sent -->
        <div class="col-sm-6 col-md-3">
            <div class="card p-3 border-start border-success border-4 text-center">
                <p class="text-secondary small fw-semibold mb-1">Messages Sent</p>
                <h3 class="fw-bold m-0"><?php echo number_format($campaign['sent_count'] + $campaign['delivered_count'] + $campaign['read_count']); ?></h3>
            </div>
        </div>
        <!-- Messages Failed -->
        <div class="col-sm-6 col-md-3">
            <div class="card p-3 border-start border-danger border-4 text-center">
                <p class="text-secondary small fw-semibold mb-1">Failed Messages</p>
                <h3 class="fw-bold m-0"><?php echo number_format($campaign['failed_count']); ?></h3>
            </div>
        </div>
        <!-- Open Rate (Read / Delivered) -->
        <div class="col-sm-6 col-md-3">
            <div class="card p-3 border-start border-info border-4 text-center">
                <p class="text-secondary small fw-semibold mb-1">Open / Read Rate</p>
                <h3 class="fw-bold m-0">
                    <?php
                    $deliveredAndRead = $campaign['delivered_count'] + $campaign['read_count'];
                    $openRate = $deliveredAndRead > 0 ? round(($campaign['read_count'] / $deliveredAndRead) * 100, 1) : 0;
                    echo $openRate . '%';
                    ?>
                </h3>
            </div>
        </div>
    </div>

    <!-- Recipients Detailed Status Table -->
    <div class="card p-4">
        <h5 class="fw-bold mb-3">Recipient Dispatch Log</h5>
        <div class="table-responsive">
            <table class="table table-hover align-middle small mb-0 w-100" id="tblReportDetails">
                <thead>
                    <tr>
                        <th>Recipient</th>
                        <th>Phone Number</th>
                        <th>City / Course</th>
                        <th>Delivery Status</th>
                        <th>Timestamps</th>
                        <th>Errors</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($campaignContacts as $cc): ?>
                        <tr>
                            <td class="fw-bold"><?php echo htmlspecialchars($cc['contact_name']); ?></td>
                            <td>
                                <a href="https://wa.me/<?php echo $cc['contact_phone']; ?>" target="_blank" class="text-success text-decoration-none">
                                    <i class="fab fa-whatsapp"></i> +<?php echo $cc['contact_phone']; ?>
                                </a>
                            </td>
                            <td>
                                <span class="text-secondary small">
                                    City: <strong><?php echo htmlspecialchars($cc['city'] ?? '-'); ?></strong><br>
                                    Course: <strong><?php echo htmlspecialchars($cc['course'] ?? '-'); ?></strong>
                                </span>
                            </td>
                            <td>
                                <?php
                                $badge = 'bg-secondary';
                                if ($cc['status'] === 'Read') $badge = 'bg-info text-dark';
                                elseif ($cc['status'] === 'Delivered') $badge = 'bg-teal text-white';
                                elseif ($cc['status'] === 'Sent') $badge = 'bg-success';
                                elseif ($cc['status'] === 'Failed') $badge = 'bg-danger';
                                ?>
                                <span class="badge <?php echo $badge; ?>" style="<?php echo $cc['status'] === 'Delivered' ? 'background-color:#20c997!important;' : ''; ?>">
                                    <?php echo $cc['status']; ?>
                                </span>
                            </td>
                            <td class="text-muted" style="font-size:0.75rem;">
                                <?php if (!empty($cc['sent_at'])): ?>
                                    Sent: <strong><?php echo date('M d, H:i:s', strtotime($cc['sent_at'])); ?></strong><br>
                                <?php endif; ?>
                                <?php if (!empty($cc['delivered_at'])): ?>
                                    Delivered: <strong><?php echo date('M d, H:i:s', strtotime($cc['delivered_at'])); ?></strong><br>
                                <?php endif; ?>
                                <?php if (!empty($cc['read_at'])): ?>
                                    Read: <strong><?php echo date('M d, H:i:s', strtotime($cc['read_at'])); ?></strong>
                                <?php endif; ?>
                                <?php if (empty($cc['sent_at']) && empty($cc['delivered_at'])): ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td class="text-danger small" style="max-width: 150px; overflow-wrap: break-word;">
                                <?php echo htmlspecialchars($cc['error_message'] ?? '-'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        $('#tblReportDetails').DataTable({
            "order": [[4, "desc"]]
        });
    });
    </script>

<?php else: ?>
    <!-- General Campaigns Reports Dashboard -->
    <div class="card p-4">
        <h5 class="fw-bold mb-3"><i class="fas fa-list-ul text-primary me-1"></i> Campaign Reports</h5>
        <p class="text-secondary small mb-3">Select any campaign to view recipient logs, status metrics, and delivery open rates.</p>
        
        <?php if (empty($allCampaigns)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-file-excel fa-3x mb-3"></i>
                <p class="mb-0">No campaigns found. Create campaigns to check analytics.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle small mb-0" id="tblReportsIndex">
                    <thead>
                        <tr>
                            <th>Campaign Name</th>
                            <th>Template</th>
                            <th>Status</th>
                            <th>Total Recipient</th>
                            <th>Open Rate</th>
                            <th style="width: 200px;" class="text-end">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allCampaigns as $c): ?>
                            <?php
                            $total = (int)$c['total_contacts'];
                            $read = (int)$c['read_count'];
                            $deliveredAndRead = (int)$c['delivered_count'] + $read;
                            
                            $openRate = $deliveredAndRead > 0 ? round(($read / $deliveredAndRead) * 100, 1) : 0;
                            
                            $badgeClass = 'bg-secondary';
                            if ($c['status'] === 'Completed') $badgeClass = 'bg-success';
                            elseif ($c['status'] === 'Sending') $badgeClass = 'bg-warning text-dark';
                            elseif ($c['status'] === 'Paused') $badgeClass = 'bg-info text-dark';
                            ?>
                            <tr>
                                <td class="fw-bold"><?php echo htmlspecialchars($c['name']); ?></td>
                                <td class="font-monospace text-secondary"><?php echo htmlspecialchars($c['template_name']); ?></td>
                                <td>
                                    <span class="badge <?php echo $badgeClass; ?>"><?php echo $c['status']; ?></span>
                                </td>
                                <td><?php echo number_format($total); ?></td>
                                <td>
                                    <strong><?php echo $openRate; ?>%</strong>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex gap-1 justify-content-end">
                                        <a href="index.php?page=reports&campaign_id=<?php echo $c['id']; ?>" class="btn btn-sm btn-primary">
                                            <i class="fas fa-chart-bar me-1"></i> View Report
                                        </a>
                                        <a href="index.php?page=reports&action=export_report&campaign_id=<?php echo $c['id']; ?>" class="btn btn-sm btn-outline-success">
                                            <i class="fas fa-file-excel"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        $('#tblReportsIndex').DataTable({
            "order": [[0, "desc"]]
        });
    });
    </script>
<?php endif; ?>
