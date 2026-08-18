<?php
// views/templates.php
Admin::checkAuth();

require_once __DIR__ . '/../models/Template.php';
require_once __DIR__ . '/../models/Setting.php';
$templateModel = new Template();
$settingModel = new Setting();

$settings = $settingModel->getAll();
$apiConfigured = !empty($settings['access_token']) && !empty($settings['business_account_id']);

$message = '';
$messageType = 'success';

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = 'CSRF validation failed.';
        $messageType = 'danger';
    } else {
        // Create template
        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $language = trim($_POST['language'] ?? 'en_US');
            $header_variables = trim($_POST['header_variables'] ?? '');
            $body_variables = trim($_POST['body_variables'] ?? '');
            $footer_text = trim($_POST['footer_text'] ?? '');
            
            // Compile buttons into JSON
            $buttons_json = null;
            if (isset($_POST['buttons']) && is_array($_POST['buttons'])) {
                $buttons_arr = [];
                foreach ($_POST['buttons'] as $btn) {
                    if (!empty($btn['text'])) {
                        $btn_data = [
                            'type' => $btn['type'],
                            'text' => htmlspecialchars($btn['text'])
                        ];
                        if (in_array($btn['type'], ['PHONE', 'URL'])) {
                            $btn_data['value'] = htmlspecialchars($btn['value'] ?? '');
                        }
                        $buttons_arr[] = $btn_data;
                    }
                }
                if (!empty($buttons_arr)) {
                    $buttons_json = json_encode($buttons_arr);
                }
            }

            if (empty($name) || empty($body_variables)) {
                $message = 'Template Name and Body content are required.';
                $messageType = 'danger';
            } elseif ($templateModel->nameExists($name)) {
                $message = 'A template with this name already exists.';
                $messageType = 'danger';
            } else {
                $res = $templateModel->create([
                    'name' => $name,
                    'language' => $language,
                    'header_variables' => $header_variables,
                    'body_variables' => $body_variables,
                    'footer_text' => $footer_text,
                    'buttons_json' => $buttons_json
                ]);
                if ($res) {
                    $message = 'Template created successfully.';
                } else {
                    $message = 'Failed to create template.';
                    $messageType = 'danger';
                }
            }
        }
        
        // Delete template
        elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($templateModel->delete($id)) {
                $message = 'Template deleted successfully.';
            } else {
                $message = 'Failed to delete template.';
                $messageType = 'danger';
            }
        }
    }
}

// Fetch all templates and meta template metadata
$templates = $templateModel->getAll();
$metaTemplates = $templateModel->getMetaTemplates('', '', '', 0);
$metaTemplateCount = $templateModel->countMetaTemplates();
$metaLanguages = array_values(array_filter(array_unique(array_column($metaTemplates, 'language'))));
sort($metaLanguages);
$metaStatuses = array_values(array_filter(array_unique(array_column($metaTemplates, 'status'))));
sort($metaStatuses);
$lastSynced = '';
if (!empty($metaTemplates)) {
    $lastSynced = max(array_column($metaTemplates, 'updated_at'));
}
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
    <!-- Template Creator Form -->
    <div class="col-lg-6">
        <div class="card p-4">
            <h5 class="fw-bold mb-3"><i class="fas fa-edit me-1 text-primary"></i> Create Meta Template</h5>
            
            <form action="index.php?page=templates&action=create" method="POST" id="templateForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary" for="templateName">Template Name *</label>
                    <input type="text" class="form-control font-monospace" id="templateName" name="name" placeholder="e.g. spoken_english_offer" required>
                    <div class="form-text text-muted" style="font-size:0.75rem;">Must match the template name in your Meta WhatsApp Manager exactly (lowercase, letters, numbers, and underscores only).</div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary" for="templateLanguage">Template Language *</label>
                    <select class="form-select font-monospace" id="templateLanguage" name="language">
                        <option value="en_US">en_US (English - US)</option>
                        <option value="en_GB">en_GB (English - UK)</option>
                        <option value="hi_IN">hi_IN (Hindi - India)</option>
                        <option value="es_ES">es_ES (Spanish - Spain)</option>
                        <option value="pt_BR">pt_BR (Portuguese - Brazil)</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary" for="headerText">Header Text (Optional)</label>
                    <input type="text" class="form-control" id="headerText" name="header_variables" placeholder="e.g. Special Discount Alert!">
                    <div class="form-text text-muted" style="font-size:0.75rem;">Plain text header. Variables are not supported in plain headers here.</div>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-semibold text-secondary m-0" for="bodyText">Body Text Content *</label>
                        <span class="badge bg-light text-dark small font-monospace">Supports: {{1}}, {{2}}, {{3}}</span>
                    </div>
                    <textarea class="form-control" id="bodyText" name="body_variables" rows="5" placeholder="Hello {{1}},\nWe are glad to offer you our {{3}} course in {{2}} at a 50% discount!" required></textarea>
                    
                    <div class="bg-light p-2 rounded small border mt-2 text-secondary" style="font-size:0.75rem;">
                        <h6 class="fw-bold text-dark small mb-1"><i class="fas fa-code me-1"></i> Parameter Variable Mapping:</h6>
                        <ul class="mb-0 ps-3">
                            <li><code>{{1}}</code> will be replaced with the contact's **Name**</li>
                            <li><code>{{2}}</code> will be replaced with the contact's **City**</li>
                            <li><code>{{3}}</code> will be replaced with the contact's **Course**</li>
                            <li>Wrap words in <code>*</code> for bold (e.g. <code>*Register*</code>) or <code>_</code> for italics.</li>
                        </ul>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary" for="footerText">Footer Text (Optional)</label>
                    <input type="text" class="form-control" id="footerText" name="footer_text" placeholder="e.g. Reply STOP to unsubscribe">
                </div>

                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label small fw-semibold text-secondary m-0">Template Buttons (Optional)</label>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addBtnRow">
                            <i class="fas fa-plus me-1"></i> Add Button
                        </button>
                    </div>
                    <div id="buttonsWrapper">
                        <!-- Dynamic buttons will be appended here by app.js -->
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 rounded-pill py-2">
                    <i class="fas fa-save me-1"></i> Save and Register Template
                </button>
            </form>
        </div>
    </div>

    <!-- Live Preview Mockup Column -->
    <div class="col-lg-6">
        <div class="card p-4 h-100 d-flex flex-column">
            <h5 class="fw-bold mb-3"><i class="fab fa-whatsapp me-1 text-success"></i> WhatsApp Bubble Preview</h5>
            <p class="text-secondary small mb-3">Live mock representation of the message bubble as it appears on recipient smartphones.</p>
            
            <div class="whatsapp-preview-container flex-grow-1 d-flex flex-column justify-content-center">
                <!-- WhatsApp Chat Bubble Mock -->
                <div class="whatsapp-message sent-bubble">
                    <div class="whatsapp-header" id="previewHeader" style="display:none;"></div>
                    <div class="whatsapp-body" id="previewBody">
                        <em>Message body preview will appear here...</em>
                    </div>
                    <div class="whatsapp-footer" id="previewFooter" style="display:none;"></div>
                    <div class="whatsapp-meta-info">
                        <span>10:24 AM</span>
                        <i class="fas fa-check-double"></i>
                    </div>
                    <div class="whatsapp-buttons-container" id="previewButtons">
                        <!-- Buttons render here -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Meta Template Sync and Listing -->
<div class="card p-4 mt-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-3">
        <div>
            <h5 class="fw-bold mb-1"><i class="fas fa-sync-alt me-1 text-primary"></i> Meta Template Sync</h5>
            <p class="text-secondary small mb-0">Fetch approved WhatsApp templates directly from your Meta Business Account.</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-success" id="btnSyncMetaTemplates">
                <i class="fas fa-sync-alt me-1"></i> Sync Templates
            </button>
            <button type="button" class="btn btn-outline-secondary" id="btnRefreshTemplateTable">
                <i class="fas fa-redo me-1"></i> Refresh Table
            </button>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-sm-4">
            <div class="bg-light p-3 rounded border">
                <p class="text-secondary small mb-1">Meta Templates</p>
                <h4 class="mb-0"><?php echo number_format($metaTemplateCount); ?></h4>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="bg-light p-3 rounded border">
                <p class="text-secondary small mb-1">Last Synced</p>
                <h4 class="mb-0"><?php echo $lastSynced ? htmlspecialchars($lastSynced) : 'Never'; ?></h4>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="bg-light p-3 rounded border">
                <p class="text-secondary small mb-1">Meta API Status</p>
                <h4 class="mb-0 <?php echo $apiConfigured ? 'text-success' : 'text-danger'; ?>">
                    <?php echo $apiConfigured ? 'Configured' : 'Not Configured'; ?></h4>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <input type="text" class="form-control" id="templateSearch" placeholder="Search by template name, category, or ID">
        </div>
        <div class="col-md-3">
            <select class="form-select" id="statusFilter">
                <option value="">All Statuses</option>
                <?php foreach ($metaStatuses as $statusOption): ?>
                    <option value="<?php echo htmlspecialchars($statusOption); ?>"><?php echo htmlspecialchars($statusOption); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <select class="form-select" id="languageFilter">
                <option value="">All Languages</option>
                <?php foreach ($metaLanguages as $langOption): ?>
                    <option value="<?php echo htmlspecialchars($langOption); ?>"><?php echo htmlspecialchars($langOption); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <button type="button" class="btn btn-outline-secondary w-100" id="btnClearFilters">Clear Filters</button>
        </div>
    </div>

    <div class="table-responsive">
        <table id="metaTemplateTable" class="table table-hover align-middle small mb-0">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Template ID</th>
                    <th>Category</th>
                    <th>Language</th>
                    <th>Status</th>
                    <th>Header</th>
                    <th>Body</th>
                    <th>Footer</th>
                    <th>Buttons</th>
                    <th>Updated</th>
                    <th style="width: 100px;">Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($metaTemplates as $tmpl): ?>
                    <?php
                    $buttonsDisplay = '-';
                    $buttonData = [];
                    $componentsFallback = json_decode($tmpl['components_json'] ?? '[]', true) ?: [];
                    if (!empty($tmpl['buttons_json'])) {
                        $buttonData = json_decode($tmpl['buttons_json'], true);
                    }
                    if ((empty($buttonData) || !is_array($buttonData)) && is_array($componentsFallback)) {
                        foreach ($componentsFallback as $component) {
                            $ctype = strtolower($component['type'] ?? '');
                            if (in_array($ctype, ['button', 'buttons', 'action'])) {
                                if (!empty($component['buttons']) && is_array($component['buttons'])) {
                                    foreach ($component['buttons'] as $button) {
                                        $buttonData[] = [
                                            'type' => strtoupper($button['type'] ?? $button['sub_type'] ?? ''),
                                            'text' => $button['text'] ?? $button['title'] ?? '',
                                            'payload' => $button['payload'] ?? $button['url'] ?? $button['phone_number'] ?? ''
                                        ];
                                    }
                                } elseif (!empty($component['sub_type']) || !empty($component['text']) || !empty($component['title'])) {
                                    $buttonData[] = [
                                        'type' => strtoupper($component['sub_type'] ?? $component['type'] ?? 'BUTTON'),
                                        'text' => $component['text'] ?? $component['title'] ?? '',
                                        'payload' => $component['payload'] ?? $component['url'] ?? $component['phone_number'] ?? ''
                                    ];
                                }
                            }
                        }
                    }
                    if (is_array($buttonData) && count($buttonData) > 0) {
                        $parts = [];
                        foreach ($buttonData as $btn) {
                            $parts[] = htmlspecialchars($btn['text'] ?? '');
                        }
                        $buttonsDisplay = implode(', ', $parts);
                    }

                    $headerDisplay = $tmpl['header_variables'] ?: null;
                    $bodyDisplay = $tmpl['body_variables'] ?: null;
                    $footerDisplay = $tmpl['footer_text'] ?: null;

                    if (empty($headerDisplay) && is_array($componentsFallback)) {
                        foreach ($componentsFallback as $component) {
                            if (strtolower($component['type'] ?? '') === 'header') {
                                if (!empty($component['text'])) {
                                    $headerDisplay = $component['text'];
                                } elseif (!empty($component['format'])) {
                                    $headerDisplay = strtoupper($component['format']) . ' header';
                                }
                                break;
                            }
                        }
                    }
                    if (empty($bodyDisplay) && is_array($componentsFallback)) {
                        foreach ($componentsFallback as $component) {
                            if (strtolower($component['type'] ?? '') === 'body' && !empty($component['text'])) {
                                $bodyDisplay = $component['text'];
                                break;
                            }
                        }
                    }
                    if (empty($footerDisplay) && is_array($componentsFallback)) {
                        foreach ($componentsFallback as $component) {
                            if (strtolower($component['type'] ?? '') === 'footer' && !empty($component['text'])) {
                                $footerDisplay = $component['text'];
                                break;
                            }
                        }
                    }

                    $detailPayload = htmlspecialchars(base64_encode(json_encode([
                        'name' => $tmpl['name'],
                        'template_id' => $tmpl['meta_template_id'],
                        'category' => $tmpl['category'],
                        'language' => $tmpl['language'],
                        'status' => $tmpl['status'],
                        'header' => $headerDisplay,
                        'body' => $bodyDisplay,
                        'footer' => $footerDisplay,
                        'buttons' => $buttonData,
                        'components' => $componentsFallback,
                        'updated_at' => $tmpl['updated_at']
                    ], JSON_UNESCAPED_UNICODE)), ENT_QUOTES, 'UTF-8');
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($tmpl['name']); ?></td>
                        <td class="font-monospace text-secondary"><?php echo htmlspecialchars($tmpl['meta_template_id']); ?></td>
                        <td><?php echo htmlspecialchars($tmpl['category'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($tmpl['language'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($tmpl['status'] ?? '-'); ?></td>
                        <td title="<?php echo htmlspecialchars($tmpl['header_variables'] ?? '-'); ?>"><?php echo htmlspecialchars($tmpl['header_variables'] ? mb_strimwidth($tmpl['header_variables'], 0, 40, '...') : '-'); ?></td>
                        <td title="<?php echo htmlspecialchars($tmpl['body_variables'] ?? '-'); ?>"><?php echo htmlspecialchars($tmpl['body_variables'] ? mb_strimwidth($tmpl['body_variables'], 0, 40, '...') : '-'); ?></td>
                        <td title="<?php echo htmlspecialchars($tmpl['footer_text'] ?? '-'); ?>"><?php echo htmlspecialchars($tmpl['footer_text'] ? mb_strimwidth($tmpl['footer_text'], 0, 30, '...') : '-'); ?></td>
                        <td title="<?php echo htmlspecialchars($buttonsDisplay); ?>"><?php echo htmlspecialchars($buttonsDisplay); ?></td>
                        <td><?php echo htmlspecialchars($tmpl['updated_at'] ?? '-'); ?></td>
                        <td>
                            <button type="button" class="btn btn-outline-primary btn-sm btn-view-template" data-template="<?php echo $detailPayload; ?>">
                                View
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Template Details Modal -->
<div class="modal fade" id="templateDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Template Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <dl class="row">
                    <dt class="col-sm-3">Name</dt>
                    <dd class="col-sm-9" id="detailName"></dd>
                    <dt class="col-sm-3">Template ID</dt>
                    <dd class="col-sm-9 font-monospace" id="detailTemplateId"></dd>
                    <dt class="col-sm-3">Category</dt>
                    <dd class="col-sm-9" id="detailCategory"></dd>
                    <dt class="col-sm-3">Language</dt>
                    <dd class="col-sm-9" id="detailLanguage"></dd>
                    <dt class="col-sm-3">Status</dt>
                    <dd class="col-sm-9" id="detailStatus"></dd>
                    <dt class="col-sm-3">Last Updated</dt>
                    <dd class="col-sm-9" id="detailUpdatedAt"></dd>
                </dl>
                <hr>
                <div class="row g-3">
                    <div class="col-lg-6">
                        <h6 class="small text-secondary">Header</h6>
                        <pre class="p-3 bg-light rounded" id="detailHeader"></pre>
                    </div>
                    <div class="col-lg-6">
                        <h6 class="small text-secondary">Footer</h6>
                        <pre class="p-3 bg-light rounded" id="detailFooter"></pre>
                    </div>
                </div>
                <div class="mt-3">
                    <h6 class="small text-secondary">Body</h6>
                    <pre class="p-3 bg-light rounded" id="detailBody"></pre>
                </div>
                <div class="mt-3">
                    <h6 class="small text-secondary">Buttons</h6>
                    <div id="detailButtons"></div>
                </div>
                <div class="mt-3">
                    <h6 class="small text-secondary">Components JSON</h6>
                    <pre class="p-3 bg-light rounded" id="detailComponents"></pre>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const deleteForms = document.querySelectorAll('.delete-template-form');
    deleteForms.forEach(form => {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            Swal.fire({
                title: 'Confirm Deletion',
                text: "Deleting this template will also cascade delete any campaigns and analytics reports tied to it. Do you want to proceed?",
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

    const metaTable = $('#metaTemplateTable').DataTable({
        pageLength: 10,
        lengthChange: false,
        order: [[9, 'desc']],
        columnDefs: [
            { targets: [1, 3, 4, 9], className: 'font-monospace text-nowrap' },
            { targets: [5, 6, 7, 8, 10], orderable: false }
        ],
        language: {
            search: '',
            searchPlaceholder: 'Search templates...'
        }
    });

    const templateSearch = document.getElementById('templateSearch');
    const statusFilter = document.getElementById('statusFilter');
    const languageFilter = document.getElementById('languageFilter');
    const btnClearFilters = document.getElementById('btnClearFilters');
    const btnSyncMetaTemplates = document.getElementById('btnSyncMetaTemplates');
    const btnRefreshTemplateTable = document.getElementById('btnRefreshTemplateTable');

    if (templateSearch) {
        templateSearch.addEventListener('input', function () {
            metaTable.search(this.value).draw();
        });
    }

    if (statusFilter) {
        statusFilter.addEventListener('change', function () {
            metaTable.columns(4).search(this.value).draw();
        });
    }

    if (languageFilter) {
        languageFilter.addEventListener('change', function () {
            metaTable.columns(3).search(this.value).draw();
        });
    }

    if (btnClearFilters) {
        btnClearFilters.addEventListener('click', function () {
            if (templateSearch) templateSearch.value = '';
            if (statusFilter) statusFilter.value = '';
            if (languageFilter) languageFilter.value = '';
            metaTable.search('').columns().search('').draw();
        });
    }

    if (btnSyncMetaTemplates) {
        btnSyncMetaTemplates.addEventListener('click', function () {
            <?php if (!$apiConfigured): ?>
            Swal.fire('Not Configured', 'Save your Meta API credentials first in the Settings page to sync templates.', 'warning');
            return;
            <?php endif; ?>

            btnSyncMetaTemplates.disabled = true;
            btnSyncMetaTemplates.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Syncing...';

            fetch('api/campaign-actions.php?action=sync_templates', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            })
            .then(async response => {
                const text = await response.text();
                let result;
                try {
                    result = JSON.parse(text);
                } catch (parseError) {
                    throw new Error(`Invalid server response: ${text}`);
                }

                if (!response.ok) {
                    throw new Error(result.error || `Server returned ${response.status}`);
                }

                return result;
            })
            .then(result => {
                btnSyncMetaTemplates.disabled = false;
                btnSyncMetaTemplates.innerHTML = '<i class="fas fa-sync-alt me-1"></i> Sync Templates';

                if (result.success) {
                    Swal.fire({
                        title: 'Templates Synced',
                        html: `Fetched ${result.fetched} template(s). Created: ${result.created}, Updated: ${result.updated}.`,
                        icon: 'success',
                        confirmButtonColor: '#0d6efd'
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Sync Failed', result.error || 'Unable to sync templates.', 'error');
                }
            })
            .catch(error => {
                btnSyncMetaTemplates.disabled = false;
                btnSyncMetaTemplates.innerHTML = '<i class="fas fa-sync-alt me-1"></i> Sync Templates';
                console.error('Template sync error:', error);
                Swal.fire('Sync Error', error.message || 'Unable to reach the server. Please try again.', 'error');
            });
        });
    }

    if (btnRefreshTemplateTable) {
        btnRefreshTemplateTable.addEventListener('click', function () {
            location.reload();
        });
    }

    const templateDetailsModal = document.getElementById('templateDetailsModal');
    if (templateDetailsModal) {
        document.body.appendChild(templateDetailsModal);
    }

    document.querySelectorAll('.btn-view-template').forEach(button => {
        button.addEventListener('click', function () {
            let templateData = null;
            try {
                const encoded = this.dataset.template || this.getAttribute('data-template');
                templateData = JSON.parse(atob(encoded));
            } catch (err) {
                console.error('Failed to parse template payload:', err, this.dataset.template);
                return;
            }
            document.getElementById('detailName').textContent = templateData.name || '-';
            document.getElementById('detailTemplateId').textContent = templateData.template_id || '-';
            document.getElementById('detailCategory').textContent = templateData.category || '-';
            document.getElementById('detailLanguage').textContent = templateData.language || '-';
            document.getElementById('detailStatus').textContent = templateData.status || '-';
            document.getElementById('detailUpdatedAt').textContent = templateData.updated_at || '-';
            document.getElementById('detailHeader').textContent = templateData.header || '-';
            document.getElementById('detailBody').textContent = templateData.body || '-';
            document.getElementById('detailFooter').textContent = templateData.footer || '-';
            document.getElementById('detailComponents').textContent = JSON.stringify(templateData.components || [], null, 2);

            const buttonsContainer = document.getElementById('detailButtons');
            buttonsContainer.innerHTML = '';
            const buttons = templateData.buttons || [];
            if (buttons.length === 0) {
                buttonsContainer.innerHTML = '<p class="text-muted small mb-0">No buttons defined.</p>';
            } else {
                buttons.forEach(btn => {
                    const badge = document.createElement('span');
                    badge.className = 'badge bg-secondary me-1 mb-1';
                    badge.textContent = `${btn.type || 'Button'}: ${btn.text || ''}`;
                    buttonsContainer.appendChild(badge);
                });
            }

            const modal = new bootstrap.Modal(document.getElementById('templateDetailsModal'));
            modal.show();
        });
    });
});
</script>
