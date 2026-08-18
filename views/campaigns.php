<?php
// views/campaigns.php
Admin::checkAuth();

require_once __DIR__ . '/../models/Campaign.php';
require_once __DIR__ . '/../models/Template.php';
require_once __DIR__ . '/../models/Contact.php';

$campaignModel = new Campaign();
$templateModel = new Template();
$contactModel = new Contact();

$message = '';
$messageType = 'success';

if (isset($_SESSION['campaign_flash'])) {
    $message = $_SESSION['campaign_flash']['message'] ?? '';
    $messageType = $_SESSION['campaign_flash']['type'] ?? 'success';
    unset($_SESSION['campaign_flash']);
}

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['campaign_flash'] = ['message' => 'CSRF validation failed.', 'type' => 'danger'];
        header('Location: index.php?page=campaigns');
        exit();
    } else {
        // Create Campaign
        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $template_id = (int)($_POST['template_id'] ?? 0);
            $send_type = $_POST['send_type'] ?? 'immediate';
            $scheduled_at = $_POST['scheduled_at'] ?? '';
            
            // Build filters
            $filters = [];
            $target = $_POST['target_contacts'] ?? 'all';
            if ($target === 'filtered') {
                $filters['city'] = $_POST['filter_city'] ?? '';
                $filters['course'] = $_POST['filter_course'] ?? '';
            }

            if (empty($name) || $template_id <= 0) {
                $_SESSION['campaign_flash'] = ['message' => 'Campaign Name and Template selection are required.', 'type' => 'danger'];
                header('Location: index.php?page=campaigns');
                exit();
            }

            $schedTime = ($send_type === 'scheduled') ? date('Y-m-d H:i:s', strtotime($scheduled_at)) : null;
            $campaignId = $campaignModel->create($name, $template_id, $filters, $schedTime);
            
            if ($campaignId > 0) {
                $_SESSION['campaign_flash'] = ['message' => 'Campaign created successfully with recipients in the queue.', 'type' => 'success'];
            } else {
                $_SESSION['campaign_flash'] = ['message' => 'Failed to create campaign. Verify if there are active contacts matching your filters.', 'type' => 'danger'];
            }

            header('Location: index.php?page=campaigns');
            exit();
        }

        // Delete Campaign
        elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($campaignModel->delete($id)) {
                $_SESSION['campaign_flash'] = ['message' => 'Campaign deleted successfully.', 'type' => 'success'];
            } else {
                $_SESSION['campaign_flash'] = ['message' => 'Failed to delete campaign.', 'type' => 'danger'];
            }

            header('Location: index.php?page=campaigns');
            exit();
        }
    }
}

// Fetch lists
$campaigns = $campaignModel->getAll();
$templates = $templateModel->getAll();
$cities = $contactModel->getUniqueCities();
$courses = $contactModel->getUniqueCourses();
?>

<?php if (!empty($message)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            Swal.fire({
                title: '<?php echo $messageType === 'success' ? 'Success!' : 'Notice'; ?>',
                text: '<?php echo addslashes($message); ?>',
                icon: '<?php echo $messageType === 'success' ? 'success' : 'error'; ?>',
                confirmButtonColor: '#0d6efd'
            });
        });
    </script>
<?php endif; ?>

<div class="row g-4">
    <!-- Campaign Generator -->
    <div class="col-lg-5">
        <div class="card p-4">
            <h5 class="fw-bold mb-3"><i class="fas fa-bullhorn text-primary me-1"></i> Setup New Campaign</h5>
            
            <form action="index.php?page=campaigns&action=create" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Campaign Name *</label>
                    <input type="text" class="form-control" name="name" placeholder="e.g. July Admissions Batch" required>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Select Template *</label>
                    <select class="form-select font-monospace" name="template_id" required>
                        <option value="">Choose template...</option>
                        <?php foreach ($templates as $t): ?>
                            <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?> (<?php echo $t['language']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Recipient Base *</label>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="target_contacts" id="targetAll" value="all" checked>
                        <label class="form-check-label small" for="targetAll">
                            All Active Contacts
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="target_contacts" id="targetFiltered" value="filtered">
                        <label class="form-check-label small" for="targetFiltered">
                            Filter by City / Course
                        </label>
                    </div>
                </div>

                <!-- Conditional Filters Panel -->
                <div id="targetFiltersPanel" class="p-3 bg-light rounded border mb-3 d-none">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-secondary">Target City</label>
                        <select class="form-select form-select-sm" name="filter_city">
                            <option value="">All Cities</option>
                            <?php foreach ($cities as $city): ?>
                                <option value="<?php echo htmlspecialchars($city); ?>"><?php echo htmlspecialchars($city); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label small fw-semibold text-secondary">Target Course</label>
                        <select class="form-select form-select-sm" name="filter_course">
                            <option value="">All Courses</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?php echo htmlspecialchars($course); ?>"><?php echo htmlspecialchars($course); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Dispatch Protocol</label>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="send_type" id="sendImmediate" value="immediate" checked>
                        <label class="form-check-label small" for="sendImmediate">
                            Send Immediately (Interactive loop / CLI)
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="send_type" id="sendScheduled" value="scheduled">
                        <label class="form-check-label small" for="sendScheduled">
                            Schedule Dispatch Time
                        </label>
                    </div>
                </div>

                <!-- Schedule Date Input -->
                <div id="scheduleTimePanel" class="mb-4 d-none">
                    <label class="form-label small fw-semibold text-secondary">Schedule Date and Time</label>
                    <input type="datetime-local" class="form-control" name="scheduled_at" id="scheduledAtInput">
                </div>

                <button type="submit" class="btn btn-primary w-100 rounded-pill py-2">
                    <i class="fas fa-rocket me-1"></i> Launch Campaign
                </button>
            </form>
        </div>
    </div>

    <!-- Campaigns List -->
    <div class="col-lg-7">
        <div class="card p-4 h-100">
            <h5 class="fw-bold mb-3"><i class="fas fa-history text-primary me-1"></i> Campaign Registries</h5>
            
            <?php if (empty($campaigns)): ?>
                <div class="text-center py-5 text-muted flex-grow-1 d-flex flex-column justify-content-center">
                    <i class="fas fa-bullhorn fa-3x mb-3"></i>
                    <p class="mb-0">No campaigns created yet.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle small mb-0 w-100" id="tblCampaigns">
                        <thead>
                            <tr>
                                <th>Campaign / Template</th>
                                <th>Status</th>
                                <th style="width: 140px;">Progress</th>
                                <th style="width: 150px;" class="text-end">Controls</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($campaigns as $c): ?>
                                <?php
                                $total = (int)$c['total_contacts'];
                                $sent = (int)$c['sent_count'];
                                $delivered = (int)$c['delivered_count'];
                                $read = (int)$c['read_count'];
                                $failed = (int)$c['failed_count'];
                                $processed = $sent + $delivered + $read + $failed;
                                
                                $pct = $total > 0 ? min(100, round(($processed / $total) * 100)) : 0;
                                
                                $badgeClass = 'bg-secondary';
                                if ($c['status'] === 'Completed') $badgeClass = 'bg-success';
                                elseif ($c['status'] === 'Sending') $badgeClass = 'bg-warning text-dark';
                                elseif ($c['status'] === 'Paused') $badgeClass = 'bg-info text-dark';
                                ?>
                                <tr id="campaign-row-<?php echo $c['id']; ?>" data-total="<?php echo $total; ?>">
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($c['name']); ?></div>
                                        <div class="text-muted small">
                                            Template: <code class="small"><?php echo htmlspecialchars($c['template_name']); ?></code><br>
                                            Queued: <strong><?php echo $total; ?></strong>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $badgeClass; ?> campaign-status-badge"><?php echo $c['status']; ?></span>
                                    </td>
                                    <td>
                                        <div class="progress mb-1" style="height: 12px;">
                                            <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" role="progressbar" style="width: <?php echo $pct; ?>%;" aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100">
                                                <?php echo $pct; ?>%
                                            </div>
                                        </div>
                                        <div class="small text-muted" style="font-size: 0.7rem;">
                                            Sent: <span class="stat-sent"><?php echo $sent; ?></span> | 
                                            Delivered: <span class="stat-delivered"><?php echo ($delivered + $read); ?></span> | 
                                            Failed: <span class="stat-failed"><?php echo $failed; ?></span>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex gap-1 justify-content-end">
                                            <!-- Interactive loop control triggers in client app.js -->
                                            <?php if ($c['status'] !== 'Completed'): ?>
                                                <button type="button" class="btn btn-sm btn-success btn-start <?php echo $c['status'] === 'Sending' ? 'd-none' : ''; ?>" onclick="startCampaignSending(<?php echo $c['id']; ?>)" title="Start/Resume Sending">
                                                    <i class="fas fa-play"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-warning btn-pause <?php echo $c['status'] !== 'Sending' ? 'd-none' : ''; ?>" onclick="pauseCampaignSending(<?php echo $c['id']; ?>)" title="Pause Campaign">
                                                    <i class="fas fa-pause"></i>
                                                </button>
                                            <?php endif; ?>

                                            <!-- Retry failed trigger -->
                                            <button type="button" class="btn btn-sm btn-outline-warning btn-retry-failed <?php echo ($failed > 0 && $c['status'] !== 'Sending') ? '' : 'd-none'; ?>" onclick="retryFailedCampaign(<?php echo $c['id']; ?>)" title="Reset & Retry Failed">
                                                <i class="fas fa-sync-alt"></i>
                                            </button>

                                            <!-- View detailed report -->
                                            <a href="index.php?page=reports&campaign_id=<?php echo $c['id']; ?>" class="btn btn-sm btn-outline-info" title="View Report">
                                                <i class="fas fa-chart-line"></i>
                                            </a>

                                            <!-- Delete campaign -->
                                            <form action="index.php?page=campaigns&action=delete" method="POST" class="delete-campaign-form d-inline-block">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Recipient type selection
    const targetAll = document.getElementById('targetAll');
    const targetFiltered = document.getElementById('targetFiltered');
    const filtersPanel = document.getElementById('targetFiltersPanel');
    
    if (targetAll && targetFiltered && filtersPanel) {
        targetAll.addEventListener('change', () => filtersPanel.classList.add('d-none'));
        targetFiltered.addEventListener('change', () => filtersPanel.classList.remove('d-none'));
    }

    // Dispatch type selection
    const sendImmediate = document.getElementById('sendImmediate');
    const sendScheduled = document.getElementById('sendScheduled');
    const schedulePanel = document.getElementById('scheduleTimePanel');
    const scheduledInput = document.getElementById('scheduledAtInput');

    if (sendImmediate && sendScheduled && schedulePanel) {
        sendImmediate.addEventListener('change', () => {
            schedulePanel.classList.add('d-none');
            scheduledInput.required = false;
        });
        sendScheduled.addEventListener('change', () => {
            schedulePanel.classList.remove('d-none');
            scheduledInput.required = true;
        });
    }

    // Confirm Campaign Deletion
    const deleteForms = document.querySelectorAll('.delete-campaign-form');
    deleteForms.forEach(form => {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            Swal.fire({
                title: 'Are you sure?',
                text: "This will delete the campaign, raw dispatch queue, and all reports. Recipient contacts will not be deleted.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
});

// Reset failed records and set back to Pending
function retryFailedCampaign(campaignId) {
    Swal.fire({
        title: 'Retry Failed Messages?',
        text: "This will reset all failed messages in this campaign back to 'Pending' so they will be retried.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d6efd',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, reset queue'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.showLoading();
            fetch(`api/campaign-actions.php?action=retry_failed&campaign_id=${campaignId}`, { method: 'POST' })
                .then(res => res.json())
                .then(data => {
                    Swal.close();
                    if (data.success) {
                        Swal.fire({
                            title: 'Reset Success!',
                            text: 'Failed messages are ready to be sent again. You can start/resume the campaign now.',
                            icon: 'success'
                        }).then(() => {
                            window.location.reload();
                        });
                    } else {
                        Swal.fire('Failed', data.error || 'Failed to reset queue', 'error');
                    }
                });
        }
    });
}
</script>
