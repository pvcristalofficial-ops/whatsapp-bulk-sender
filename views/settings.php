<?php
// views/settings.php
Admin::checkAuth();

require_once __DIR__ . '/../models/Setting.php';
$settingModel = new Setting();

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = 'CSRF validation failed.';
        $messageType = 'danger';
    } else {
        $data = [
            'access_token' => trim($_POST['access_token'] ?? ''),
            'phone_number_id' => trim($_POST['phone_number_id'] ?? ''),
            'business_account_id' => trim($_POST['business_account_id'] ?? ''),
            'api_version' => trim($_POST['api_version'] ?? 'v23.0'),
            'webhook_verify_token' => trim($_POST['webhook_verify_token'] ?? '')
        ];

        if ($settingModel->saveMultiple($data)) {
            $message = 'Settings saved successfully.';
        } else {
            $message = 'Failed to save settings.';
            $messageType = 'danger';
        }
    }
}

// Load current settings
$settings = $settingModel->getAll();

// Determine actual webhook URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$domain = $_SERVER['HTTP_HOST'];
$webhookUrl = $protocol . $domain . str_replace('index.php', 'api/webhook.php', $_SERVER['SCRIPT_NAME']);
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
    <!-- Meta API settings -->
    <div class="col-lg-7">
        <div class="card p-4">
            <h5 class="fw-bold mb-3"><i class="fas fa-sliders-h me-1 text-primary"></i> Meta API Configuration</h5>
            
            <form action="index.php?page=settings" method="POST" id="settingsForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary" for="accessToken">Permanent Access Token *</label>
                    <textarea class="form-control font-monospace" id="accessToken" name="access_token" rows="4" placeholder="EAAG..." required><?php echo htmlspecialchars($settings['access_token'] ?? ''); ?></textarea>
                    <div class="form-text text-muted" style="font-size:0.75rem;">System User access token generated inside your Meta Business Manager. Avoid using temporary 24-hour developer tokens.</div>
                </div>

                <div class="row g-2">
                    <div class="col-sm-6 mb-3">
                        <label class="form-label small fw-semibold text-secondary" for="phoneId">Phone Number ID *</label>
                        <input type="text" class="form-control font-monospace" id="phoneId" name="phone_number_id" value="<?php echo htmlspecialchars($settings['phone_number_id'] ?? ''); ?>" placeholder="e.g. 10928374829384" required>
                        <div class="form-text text-muted" style="font-size:0.75rem;">Found in WhatsApp Getting Started settings.</div>
                    </div>
                    <div class="col-sm-6 mb-3">
                        <label class="form-label small fw-semibold text-secondary" for="businessId">Business Account ID (WABA ID) *</label>
                        <input type="text" class="form-control font-monospace" id="businessId" name="business_account_id" value="<?php echo htmlspecialchars($settings['business_account_id'] ?? ''); ?>" placeholder="e.g. 1293847283472" required>
                        <div class="form-text text-muted" style="font-size:0.75rem;">Use the WhatsApp Business Account ID (WABA ID), not the phone number ID or app/page ID.</div>
                    </div>
                </div>

                <div class="row g-2">
                    <div class="col-sm-6 mb-3">
                        <label class="form-label small fw-semibold text-secondary" for="apiVersion">API Version *</label>
                        <input type="text" class="form-control font-monospace" id="apiVersion" name="api_version" value="<?php echo htmlspecialchars($settings['api_version'] ?? 'v23.0'); ?>" placeholder="e.g. v23.0" required>
                    </div>
                    <div class="col-sm-6 mb-3">
                        <label class="form-label small fw-semibold text-secondary" for="webhookToken">Webhook Verify Token *</label>
                        <input type="text" class="form-control font-monospace" id="webhookToken" name="webhook_verify_token" value="<?php echo htmlspecialchars($settings['webhook_verify_token'] ?? 'whatsapp_verify_token_123'); ?>" required>
                        <div class="form-text text-muted" style="font-size:0.75rem;">Used to verify webhooks inside Meta Dashboard.</div>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-2">
                    <button type="submit" class="btn btn-primary flex-grow-1 rounded-pill">
                        <i class="fas fa-save me-1"></i> Save API Credentials
                    </button>
                    <button type="button" class="btn btn-outline-success rounded-pill" id="btnTestConnection">
                        <i class="fas fa-paper-plane me-1"></i> Test Connection
                    </button>
                    <button type="button" class="btn btn-outline-primary rounded-pill" id="btnValidateWaba">
                        <i class="fas fa-check-circle me-1"></i> Validate WABA ID
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Webhook details -->
    <div class="col-lg-5">
        <div class="card p-4 h-100">
            <h5 class="fw-bold mb-3"><i class="fas fa-project-diagram me-1 text-success"></i> Webhook Callback Settings</h5>
            <p class="text-secondary small mb-3">Configure webhooks inside your Meta App settings to receive real-time delivery confirmations (Sent, Delivered, Read, Failed) and error details.</p>
            
            <div class="mb-3">
                <label class="form-label small fw-semibold text-secondary">Webhook URL</label>
                <div class="input-group">
                    <input type="text" class="form-control font-monospace bg-light small" style="font-size: 0.85rem;" value="<?php echo htmlspecialchars($webhookUrl); ?>" readonly id="txtWebhookUrl">
                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('txtWebhookUrl').value); Swal.fire({text:'Copied to clipboard!', toast:true, position:'bottom', showConfirmButton:false, timer:1500});">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
                <div class="form-text text-muted" style="font-size:0.75rem;">Meta will send webhook requests here. Ensure this URL is publicly accessible (e.g. via ngrok or production domain).</div>
            </div>

            <div class="mb-4">
                <label class="form-label small fw-semibold text-secondary">Verification Token</label>
                <div class="input-group">
                    <input type="text" class="form-control font-monospace bg-light small" style="font-size: 0.85rem;" value="<?php echo htmlspecialchars($settings['webhook_verify_token'] ?? 'whatsapp_verify_token_123'); ?>" readonly id="txtVerifyToken">
                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('txtVerifyToken').value); Swal.fire({text:'Copied to clipboard!', toast:true, position:'bottom', showConfirmButton:false, timer:1500});">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
            </div>

            <div class="bg-light p-3 rounded small border text-secondary">
                <h6 class="fw-bold text-dark small mb-2"><i class="fas fa-info-circle me-1"></i> Setup Instructions:</h6>
                <ol class="mb-0 ps-3">
                    <li>Go to your <a href="https://developers.facebook.com/" target="_blank" class="fw-bold text-decoration-none">Meta Developer Portal</a>.</li>
                    <li>Add the <strong>WhatsApp</strong> product to your app.</li>
                    <li>Under WhatsApp, click <strong>Configuration</strong>.</li>
                    <li>Paste the Webhook URL and Verify Token from above.</li>
                    <li>Subscribe to webhook fields: <strong>messages</strong>.</li>
                </ol>
            </div>
        </div>
    </div>
</div>
