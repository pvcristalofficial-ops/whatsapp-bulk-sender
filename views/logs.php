<?php
// views/logs.php
Admin::checkAuth();

require_once __DIR__ . '/../models/Log.php';
$logModel = new Log();

$message = '';
$messageType = 'success';

$action = $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'clear') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = 'CSRF validation failed.';
        $messageType = 'danger';
    } else {
        if ($logModel->clear()) {
            $message = 'API logs database cleared successfully.';
        } else {
            $message = 'Failed to clear logs.';
            $messageType = 'danger';
        }
    }
}

// Load logs (load last 1000 for client-side search/paging)
$logs = $logModel->getAll(1000, 0);
?>

<?php if (!empty($message)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            Swal.fire({
                title: 'Notice',
                text: '<?php echo addslashes($message); ?>',
                icon: '<?php echo $messageType === 'success' ? 'success' : 'error'; ?>',
                confirmButtonColor: '#0d6efd'
            });
        });
    </script>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="fw-bold m-0"><i class="fas fa-terminal text-primary me-1"></i> Integration Diagnostics</h5>
        <p class="text-secondary small m-0">Detailed communication logs with Meta WhatsApp Cloud servers.</p>
    </div>
    
    <form action="index.php?page=logs&action=clear" method="POST" id="clearLogsForm">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <button type="submit" class="btn btn-outline-danger" <?php echo empty($logs) ? 'disabled' : ''; ?>>
            <i class="fas fa-trash-alt me-1"></i> Clear Logs
        </button>
    </form>
</div>

<div class="card p-4">
    <?php if (empty($logs)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-receipt fa-3x mb-3"></i>
            <p class="mb-0">No API log records found.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle small mb-0 w-100" id="tblLogs">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Campaign</th>
                        <th>Recipient</th>
                        <th>Status</th>
                        <th>API Error (If Any)</th>
                        <th style="width: 120px;" class="text-end no-sort">Payloads</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td class="font-monospace text-secondary" style="font-size:0.8rem;">
                                <?php echo date('Y-m-d H:i:s', strtotime($l['created_at'])); ?>
                            </td>
                            <td class="fw-bold">
                                <?php echo htmlspecialchars($l['campaign_name'] ?? 'System Test / Connection Test'); ?>
                            </td>
                            <td>
                                <?php if (!empty($l['contact_name'])): ?>
                                    <div class="fw-semibold text-dark"><?php echo htmlspecialchars($l['contact_name']); ?></div>
                                    <span class="text-muted font-monospace small" style="font-size: 0.75rem;">+<?php echo $l['contact_phone']; ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $l['status'] === 'Success' ? 'bg-success' : 'bg-danger'; ?>">
                                    <?php echo $l['status']; ?>
                                </span>
                            </td>
                            <td class="text-danger small" style="max-width: 200px; overflow-wrap: break-word;">
                                <?php echo htmlspecialchars($l['error_message'] ?? '-'); ?>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary btn-view-payload" 
                                        data-request="<?php echo htmlspecialchars($l['request_payload'] ?? ''); ?>"
                                        data-response="<?php echo htmlspecialchars($l['response_payload'] ?? ''); ?>"
                                        data-bs-toggle="modal" data-bs-target="#modalPayloadDetails">
                                    <i class="fas fa-code me-1"></i> View
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- =============================================================
     MODAL: PAYLOAD DETAILS
     ============================================================= -->
<div class="modal fade" id="modalPayloadDetails" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">API Transaction Payload Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <!-- Nav Tabs -->
                <ul class="nav nav-tabs px-3 pt-2 bg-light" id="payloadTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active small fw-bold" id="request-tab" data-bs-toggle="tab" data-bs-target="#tabRequest" type="button" role="tab" aria-controls="tabRequest" aria-selected="true">
                            HTTP Request JSON
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link small fw-bold" id="response-tab" data-bs-toggle="tab" data-bs-target="#tabResponse" type="button" role="tab" aria-controls="tabResponse" aria-selected="false">
                            HTTP Response JSON
                        </button>
                    </li>
                </ul>
                
                <!-- Tab Panes -->
                <div class="tab-content p-3" id="payloadTabsContent">
                    <!-- Request -->
                    <div class="tab-pane fade show active" id="tabRequest" role="tabpanel" aria-labelledby="request-tab">
                        <pre class="bg-dark text-light p-3 rounded font-monospace small mb-0" style="max-height: 400px; overflow: auto;"><code id="codeRequest"></code></pre>
                    </div>
                    <!-- Response -->
                    <div class="tab-pane fade" id="tabResponse" role="tabpanel" aria-labelledby="response-tab">
                        <pre class="bg-dark text-light p-3 rounded font-monospace small mb-0" style="max-height: 400px; overflow: auto;"><code id="codeResponse"></code></pre>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    $('#tblLogs').DataTable({
        "order": [[0, "desc"]],
        "columnDefs": [
            { "orderable": false, "targets": 'no-sort' }
        ]
    });

    // Confirm Clear Logs
    const clearForm = document.getElementById('clearLogsForm');
    if (clearForm) {
        clearForm.addEventListener('submit', function (e) {
            e.preventDefault();
            Swal.fire({
                title: 'Confirm System Wipe',
                text: "Are you sure you want to completely erase all API transaction logs? This is irreversible.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, clear all!'
            }).then((result) => {
                if (result.isConfirmed) {
                    clearForm.submit();
                }
            });
        });
    }

    // Modal Payload Bindings
    const payloadModal = document.getElementById('modalPayloadDetails');
    if (payloadModal) {
        // CRITICAL: Move the modal to document.body to prevent backdrop overlay z-index blocking
        document.body.appendChild(payloadModal);

        const tabButtons = payloadModal.querySelectorAll('#payloadTabs button');

        // Manually bind tab switching events for 100% reliability, bypassing Bootstrap JS helper conflicts
        tabButtons.forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                
                // 1. Reset all tab buttons states
                tabButtons.forEach(b => {
                    b.classList.remove('active');
                    b.setAttribute('aria-selected', 'false');
                });
                
                // 2. Set clicked tab button active
                this.classList.add('active');
                this.setAttribute('aria-selected', 'true');
                
                // 3. Hide all tab content panes
                const targetSelector = this.getAttribute('data-bs-target');
                const tabPanes = payloadModal.querySelectorAll('.tab-content .tab-pane');
                tabPanes.forEach(pane => {
                    pane.classList.remove('show', 'active');
                });
                
                // 4. Show target tab content pane
                const targetPane = payloadModal.querySelector(targetSelector);
                if (targetPane) {
                    targetPane.classList.add('show', 'active');
                }
            });
        });

        payloadModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const reqStr = button.getAttribute('data-request');
            const resStr = button.getAttribute('data-response');

            // Helper to format JSON nicely
            function prettyJSON(str) {
                try {
                    const parsed = JSON.parse(str);
                    return JSON.stringify(parsed, null, 4);
                } catch(e) {
                    return str; // If plain string / raw
                }
            }

            document.getElementById('codeRequest').textContent = prettyJSON(reqStr);
            document.getElementById('codeResponse').textContent = prettyJSON(resStr);
            
            // Manually reset back to first tab on open
            const firstTabBtn = payloadModal.querySelector('#request-tab');
            if (firstTabBtn) {
                tabButtons.forEach(b => {
                    b.classList.remove('active');
                    b.setAttribute('aria-selected', 'false');
                });
                firstTabBtn.classList.add('active');
                firstTabBtn.setAttribute('aria-selected', 'true');
                
                const tabPanes = payloadModal.querySelectorAll('.tab-content .tab-pane');
                tabPanes.forEach(pane => {
                    pane.classList.remove('show', 'active');
                });
                
                const firstPane = payloadModal.querySelector('#tabRequest');
                if (firstPane) {
                    firstPane.classList.add('show', 'active');
                }
            }
        });
    }
});
</script>
