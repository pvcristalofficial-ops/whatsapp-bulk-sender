<?php
// api/campaign-actions.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Check Admin Authentication
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Setting.php';
require_once __DIR__ . '/../models/Campaign.php';
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Contact.php';
require_once __DIR__ . '/../models/Template.php';

function normalizeTemplateVariableValue(mixed $value, string $fallback = 'Student'): string {
    $normalized = trim((string)($value ?? ''));
    return $normalized !== '' ? $normalized : $fallback;
}

function resolveTemplateParameterName(string $placeholder): string {
    $placeholder = strtolower(trim($placeholder));
    if ($placeholder === '') {
        return 'name';
    }

    if (is_numeric($placeholder)) {
        $index = (int)$placeholder;
        if ($index === 1) return 'name';
        if ($index === 2) return 'city';
        if ($index === 3) return 'course';
        return 'var_' . $index;
    }

    $map = ['name' => 'name', 'city' => 'city', 'course' => 'course'];
    return $map[$placeholder] ?? $placeholder;
}

function resolveTemplateVariableValue(string $parameterName, array $contact): string {
    $parameterName = strtolower(trim($parameterName));
    if ($parameterName === 'name') return normalizeTemplateVariableValue($contact['name'] ?? '', 'Student');
    if ($parameterName === 'city') return normalizeTemplateVariableValue($contact['city'] ?? '', 'City');
    if ($parameterName === 'course') return normalizeTemplateVariableValue($contact['course'] ?? '', 'Course');
    return 'Student';
}

function buildTemplateParameters(array $template, array $contact): array {
    $parameters = [];
    $variables = trim($template['body_variables'] ?? '');
    $matches = [];
    preg_match_all('/\{\{([^}]+)\}\}/', $variables, $matches);
    $placeholders = $matches[1] ?? [];

    if (empty($placeholders)) {
        return $parameters;
    }

    $seen = [];
    foreach ($placeholders as $placeholder) {
        $parameterName = resolveTemplateParameterName($placeholder);
        if (isset($seen[$parameterName])) {
            continue;
        }
        $seen[$parameterName] = true;

        $parameters[] = [
            'type' => 'text',
            'parameter_name' => $parameterName,
            'text' => resolveTemplateVariableValue($parameterName, $contact)
        ];
    }

    return $parameters;
}

function ensureTemplateHeaderComponent(array &$payload, Template $templateModel, array $template): void {
    if (!isset($payload['template']['components']) || !is_array($payload['template']['components'])) {
        $payload['template']['components'] = [];
    }

    foreach ($payload['template']['components'] as $component) {
        if (($component['type'] ?? '') === 'header') {
            return;
        }
    }

    if (!$templateModel->shouldIncludeHeaderMediaComponent($template)) {
        return;
    }

    $payload['template']['components'][] = [
        'type' => 'header',
        'parameters' => [[
            'type' => 'image',
            'image' => ['link' => $templateModel->resolveHeaderImageLink($template)]
        ]]
    ];
}

function isHeaderTextOnly(array $template): bool {
    $components = json_decode($template['components_json'] ?? '[]', true);
    if (!is_array($components)) {
        return true;
    }

    foreach ($components as $component) {
        if (strtolower($component['type'] ?? '') === 'header') {
            $format = strtoupper(trim($component['format'] ?? $component['header_format'] ?? 'TEXT'));
            return $format === '' || $format === 'TEXT';
        }
    }
    return true;
}

$settingModel = new Setting();
$campaignModel = new Campaign();
$logModel = new Log();
$templateModel = new Template();

$action = $_GET['action'] ?? '';

// -------------------------------------------------------------
// 1. Process Batch Queue Send
// -------------------------------------------------------------
if ($action === 'send_batch') {
    $campaignId = (int)($_GET['campaign_id'] ?? 0);
    if ($campaignId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid Campaign ID']);
        exit();
    }

    $campaign = $campaignModel->getById($campaignId);
    if (!$campaign) {
        echo json_encode(['success' => false, 'error' => 'Campaign not found']);
        exit();
    }

    $db = Database::getConnection();

    // Force campaign status to Sending if it was Pending or Paused.
    // If the campaign was marked Completed but still has pending contacts, reopen it.
    $stmtPendingCount = $db->prepare("SELECT COUNT(*) FROM campaign_contacts WHERE campaign_id = :campaign_id AND status = 'Pending'");
    $stmtPendingCount->execute(['campaign_id' => $campaignId]);
    $pendingCount = (int)$stmtPendingCount->fetchColumn();

    if ($campaign['status'] === 'Completed') {
        if ($pendingCount > 0) {
            if (!$campaignModel->updateStatus($campaignId, 'Sending')) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to reopen campaign for sending. Current status: ' . $campaign['status']
                ]);
                exit();
            }
            $campaign['status'] = 'Sending';
        } else {
            $stats = $campaignModel->updateStats($campaignId);
            echo json_encode([
                'success' => true,
                'completed' => true,
                'campaign_name' => $campaign['name'],
                'stats' => $stats,
                'message' => 'Campaign is already complete.'
            ]);
            exit();
        }
    } elseif (!in_array($campaign['status'], ['Pending', 'Paused', 'Sending'])) {
        echo json_encode([
            'success' => false,
            'error' => 'Campaign is not in active sending state. Current status: ' . $campaign['status']
        ]);
        exit();
    }

    if ($campaign['status'] === 'Sending' && $pendingCount === 0) {
        $stats = $campaignModel->updateStats($campaignId);
        echo json_encode([
            'success' => true,
            'completed' => true,
            'campaign_name' => $campaign['name'],
            'stats' => $stats,
            'message' => 'Campaign queue is already complete.'
        ]);
        exit();
    }

    if (in_array($campaign['status'], ['Pending', 'Paused'])) {
        if (!$campaignModel->updateStatus($campaignId, 'Sending')) {
            echo json_encode([
                'success' => false,
                'error' => 'Failed to activate campaign for sending. Current status: ' . $campaign['status']
            ]);
            exit();
        }
        $campaign['status'] = 'Sending';
    }

    $db = Database::getConnection();

    // Fetch batch of 5 contacts (safe for AJAX timeouts)
    $stmtPending = $db->prepare("
        SELECT cc.*, c.name, c.phone, c.city, c.course
        FROM campaign_contacts cc
        JOIN contacts c ON cc.contact_id = c.id
        WHERE cc.campaign_id = :campaign_id AND cc.status = 'Pending'
        ORDER BY cc.id ASC
        LIMIT 5
    ");
    $stmtPending->execute(['campaign_id' => $campaignId]);
    $pendingContacts = $stmtPending->fetchAll();

    if (empty($pendingContacts)) {
        // Queue is finished
        $campaignModel->updateStatus($campaignId, 'Completed');
        $stats = $campaignModel->updateStats($campaignId);
        echo json_encode([
            'success' => true,
            'completed' => true,
            'campaign_name' => $campaign['name'],
            'stats' => $stats
        ]);
        exit();
    }

    // Load API Keys
    $accessToken = $settingModel->get('access_token');
    $phoneId = $settingModel->get('phone_number_id');
    $version = $settingModel->get('api_version', 'v23.0');

    if (empty($accessToken) || empty($phoneId)) {
        // Put campaign back to Paused since credentials are blank
        $campaignModel->updateStatus($campaignId, 'Paused');
        echo json_encode(['success' => false, 'error' => 'Meta API settings are empty. Save your settings first!']);
        exit();
    }

    // Load template variables config to match body
    $stmtTpl = $db->prepare("SELECT * FROM templates WHERE id = :id LIMIT 1");
    $stmtTpl->execute(['id' => $campaign['template_id']]);
    $template = $stmtTpl->fetch();

    if (!$template) {
        $campaignModel->updateStatus($campaignId, 'Paused');
        echo json_encode(['success' => false, 'error' => 'Campaign template is missing']);
        exit();
    }

    // Send loop for this batch
    foreach ($pendingContacts as $pc) {
        $contactId = $pc['contact_id'];
        $campaignContactId = $pc['id'];
        $phone = $pc['phone'];

        // Build parameters dynamically
        $parameters = [];
        preg_match_all('/\{\{(\d+)\}\}/', $template['body_variables'], $matches);
        $varIndexes = $matches[1] ?? [];
        if (!empty($varIndexes)) {
            $uniqueVars = array_unique($varIndexes);
            sort($uniqueVars);
            foreach ($uniqueVars as $vNum) {
                $val = '';
                if ($vNum == 1) $val = $pc['name'];
                elseif ($vNum == 2) $val = $pc['city'] ?? '';
                elseif ($vNum == 3) $val = $pc['course'] ?? '';

                $parameters[] = [
                    'type' => 'text',
                    'text' => $val
                ];
            }
        }

        // Build Payload
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $phone,
            'type' => 'template',
            'template' => [
                'name' => $template['name'],
                'language' => [
                    'code' => $template['language']
                ]
            ]
        ];

        $templateComponents = $templateModel->buildTemplateComponents($template, $pc);
        if (!empty($templateComponents)) {
            $payload['template']['components'] = $templateComponents;
        }
        ensureTemplateHeaderComponent($payload, $templateModel, $template);

        // Dispatch via cURL
        $url = "https://graph.facebook.com/{$version}/{$phoneId}/messages";
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        error_log('WhatsApp payload: ' . $jsonPayload);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$accessToken}",
            "Content-Type: application/json"
        ]);
        // Set standard timeouts
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $statusStr = 'Failed';
        $errorMsg = null;
        $messageId = null;

        if ($response === false) {
            $errorMsg = "cURL Connection Error: " . $curlError;
        } else {
            $resData = json_decode($response, true);
            if ($httpCode >= 200 && $httpCode < 300 && isset($resData['messages'][0]['id'])) {
                $statusStr = 'Sent';
                $messageId = $resData['messages'][0]['id'];
            } else {
                $errorMsg = $resData['error']['message'] ?? 'Meta API Error (HTTP ' . $httpCode . ')';
            }
        }

        // Update database queue status
        if ($statusStr === 'Sent') {
            $stmtUpdateCC = $db->prepare("
                UPDATE campaign_contacts 
                SET status = 'Sent', message_id = :message_id, sent_at = NOW(), error_message = NULL 
                WHERE id = :id
            ");
            $stmtUpdateCC->execute([
                'message_id' => $messageId,
                'id' => $campaignContactId
            ]);
        } else {
            $stmtUpdateCC = $db->prepare("
                UPDATE campaign_contacts 
                SET status = 'Failed', error_message = :err 
                WHERE id = :id
            ");
            $stmtUpdateCC->execute([
                'err' => $errorMsg,
                'id' => $campaignContactId
            ]);

            // Add retry record
            $stmtRetry = $db->prepare("
                INSERT INTO failed_messages (campaign_contact_id, error_message, retry_count) 
                VALUES (:cc_id, :err, 0)
                ON DUPLICATE KEY UPDATE retry_count = retry_count, error_message = :err_update
            ");
            $stmtRetry->execute([
                'cc_id' => $campaignContactId,
                'err' => $errorMsg,
                'err_update' => $errorMsg
            ]);
        }

        // Add transactional Log record
        $logModel->add($campaignId, $contactId, $jsonPayload, $response ?: $curlError, $statusStr === 'Sent' ? 'Success' : 'Failed', $errorMsg);
    }

    // Fetch and return updated stats
    $stats = $campaignModel->updateStats($campaignId);
    echo json_encode([
        'success' => true,
        'completed' => false,
        'stats' => $stats
    ]);
    exit();
}

// -------------------------------------------------------------
// 2. Pause Campaign
// -------------------------------------------------------------
elseif ($action === 'pause') {
    $campaignId = (int)($_GET['campaign_id'] ?? 0);
    if ($campaignModel->updateStatus($campaignId, 'Paused')) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to pause campaign']);
    }
    exit();
}

// -------------------------------------------------------------
// 3. Reset and Retry Failed Messages
// -------------------------------------------------------------
elseif ($action === 'retry_failed') {
    $campaignId = (int)($_GET['campaign_id'] ?? 0);
    if ($campaignModel->retryFailed($campaignId)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to reset failed queue']);
    }
    exit();
}

// -------------------------------------------------------------
// 4. Test Meta Settings Connection
// -------------------------------------------------------------
elseif ($action === 'test_connection') {
    // Read from post variables directly (allows test connection without saving)
    $accessToken = trim($_POST['access_token'] ?? '');
    $phoneId = trim($_POST['phone_number_id'] ?? '');
    $version = trim($_POST['api_version'] ?? 'v23.0');
    $testPhone = trim($_POST['test_phone'] ?? '');

    if (empty($accessToken) || empty($phoneId) || empty($testPhone)) {
        echo json_encode(['success' => false, 'error' => 'All connection parameters are required']);
        exit();
    }

    // Call Meta Graph API using the standard pre-configured "hello_world" sandbox template
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $testPhone,
        'type' => 'template',
        'template' => [
            'name' => 'hello_world',
            'language' => [
                'code' => 'en_US'
            ]
        ]
    ];

    $url = "https://graph.facebook.com/{$version}/{$phoneId}/messages";
    $jsonPayload = json_encode($payload);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$accessToken}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $statusStr = 'Failed';
    $errorMsg = null;

    if ($response === false) {
        $errorMsg = "Connection Error: " . $curlError;
    } else {
        $resData = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            $statusStr = 'Success';
        } else {
            $errorMsg = $resData['error']['message'] ?? 'Meta API Error (HTTP ' . $httpCode . ')';
        }
    }

    // Write connection test to logs
    $logModel->add(null, null, $jsonPayload, $response ?: $curlError, $statusStr === 'Success' ? 'Success' : 'Failed', $errorMsg);

    if ($statusStr === 'Success') {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $errorMsg]);
    }
    exit();
}

// -------------------------------------------------------------
// 5. Verify WhatsApp Business Account ID
// -------------------------------------------------------------
elseif ($action === 'verify_waba') {
    $accessToken = trim($_POST['access_token'] ?? '');
    $wabaId = trim($_POST['business_account_id'] ?? '');
    $phoneId = trim($_POST['phone_number_id'] ?? '');
    $version = trim($_POST['api_version'] ?? 'v23.0');

    if (empty($accessToken) || empty($wabaId)) {
        echo json_encode(['success' => false, 'error' => 'Access token and Business Account ID are required for validation.']);
        exit();
    }

    if (!empty($phoneId) && $wabaId === $phoneId) {
        echo json_encode(['success' => false, 'error' => 'Your Business Account ID appears to match the Phone Number ID. Use your WhatsApp Business Account ID (WABA ID), not the Phone Number ID.']);
        exit();
    }

    $url = "https://graph.facebook.com/{$version}/{$wabaId}?fields=id,name";
    $headers = ["Authorization: Bearer {$accessToken}"];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        echo json_encode(['success' => false, 'error' => "Connection error: {$curlError}"]);
        exit();
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'error' => 'Invalid response from Meta when validating WABA ID.']);
        exit();
    }

    if ($httpCode >= 200 && $httpCode < 300 && !empty($data['id'])) {
        echo json_encode(['success' => true, 'account' => $data]);
    } else {
        $errorMsg = $data['error']['message'] ?? "Meta API returned HTTP {$httpCode}.";
        if (stripos($errorMsg, 'unsupported get request') !== false || stripos($errorMsg, 'does not exist') !== false) {
            $errorMsg .= ' Please verify that this ID is the WhatsApp Business Account ID (WABA ID), not a phone number ID or app/page ID.';
        }
        echo json_encode(['success' => false, 'error' => $errorMsg]);
    }
    exit();
}

// -------------------------------------------------------------
// 6. Sync WhatsApp Message Templates from Meta
// -------------------------------------------------------------
elseif ($action === 'sync_templates') {
    $accessToken = trim($settingModel->get('access_token'));
    $wabaId = trim($settingModel->get('business_account_id'));
    $phoneId = trim($settingModel->get('phone_number_id'));
    $version = trim($settingModel->get('api_version', 'v23.0'));

    if (empty($accessToken) || empty($wabaId)) {
        echo json_encode(['success' => false, 'error' => 'Meta access token or WABA ID is not configured.']);
        exit();
    }

    if (!empty($phoneId) && $wabaId === $phoneId) {
        echo json_encode(['success' => false, 'error' => 'Configured Business Account ID appears to match the Phone Number ID. Use your WhatsApp Business Account ID (WABA ID), not the Phone Number ID.']);
        exit();
    }

    $baseUrl = "https://graph.facebook.com/{$version}/{$wabaId}/message_templates";
    $queryString = http_build_query([
        'fields' => 'name,category,language,status,updated_time,components',
        'limit' => 100
    ]);
    $url = "{$baseUrl}?{$queryString}";

    $headers = [
        "Authorization: Bearer {$accessToken}",
        'Content-Type: application/json'
    ];

    $created = 0;
    $updated = 0;
    $fetched = 0;
    $errors = [];

    while ($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $errors[] = "Connection error: {$curlError}";
            break;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = 'Invalid API response format.';
            break;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMsg = $data['error']['message'] ?? "Meta API returned HTTP {$httpCode}.";
            if (stripos($errorMsg, 'unsupported get request') !== false || stripos($errorMsg, 'does not exist') !== false) {
                $errorMsg .= ' Please verify that your stored Business Account ID is the WhatsApp Business Account ID (WABA ID), not a phone number ID or app/page ID, and that your access token has permission for this account.';
            }
            $errors[] = $errorMsg;
            break;
        }

        if (empty($data['data'])) {
            $errors[] = 'Meta returned no templates.';
            break;
        }

        foreach ($data['data'] as $template) {
            $fetched++;
            $templateData = [
                'meta_template_id' => $template['id'] ?? '',
                'name' => $template['name'] ?? '',
                'category' => $template['category'] ?? '',
                'language' => $template['language'] ?? '',
                'status' => $template['status'] ?? '',
                'updated_at' => isset($template['updated_time']) ? date('Y-m-d H:i:s', strtotime($template['updated_time'])) : date('Y-m-d H:i:s'),
                'components' => $template['components'] ?? [],
                'header_variables' => null,
                'body_variables' => null,
                'footer_text' => null,
                'buttons_json' => null
            ];

            if (!empty($template['components']) && is_array($template['components'])) {
                $headerParts = [];
                $bodyParts = [];
                $footerParts = [];
                $buttons = [];

                foreach ($template['components'] as $component) {
                    $type = strtolower($component['type'] ?? '');
                    if ($type === 'header') {
                        if (!empty($component['text'])) {
                            $headerParts[] = $component['text'];
                        }
                    } elseif ($type === 'body') {
                        if (!empty($component['text'])) {
                            $bodyParts[] = $component['text'];
                        }
                    } elseif ($type === 'footer') {
                        if (!empty($component['text'])) {
                            $footerParts[] = $component['text'];
                        }
                    } elseif ($type === 'button' || $type === 'action' || $type === 'buttons') {
                        if (!empty($component['buttons']) && is_array($component['buttons'])) {
                            foreach ($component['buttons'] as $button) {
                                $buttons[] = [
                                    'type' => strtoupper($button['type'] ?? $button['sub_type'] ?? ''),
                                    'text' => $button['text'] ?? $button['title'] ?? '',
                                    'payload' => $button['payload'] ?? $button['url'] ?? $button['phone_number'] ?? ''
                                ];
                            }
                        } elseif (!empty($component['sub_type']) || !empty($component['text'])) {
                            $buttons[] = [
                                'type' => strtoupper($component['sub_type'] ?? $component['type'] ?? 'BUTTON'),
                                'text' => $component['text'] ?? $component['title'] ?? '',
                                'payload' => $component['payload'] ?? $component['url'] ?? $component['phone_number'] ?? ''
                            ];
                        }
                    }
                }

                $templateData['header_variables'] = $headerParts ? implode(' ', $headerParts) : null;
                $templateData['body_variables'] = $bodyParts ? implode("\n", $bodyParts) : null;
                $templateData['footer_text'] = $footerParts ? implode(' ', $footerParts) : null;
                $templateData['buttons_json'] = !empty($buttons) ? $buttons : null;
            }

            $existingTemplate = $templateModel->getByMetaTemplateId($templateData['meta_template_id']);
            $result = $templateModel->upsertMetaTemplate($templateData);
            if ($result) {
                if ($existingTemplate) {
                    $updated++;
                } else {
                    $created++;
                }
            } else {
                $errors[] = "Failed to save template {$templateData['meta_template_id']}";
            }
        }

        $url = $data['paging']['next'] ?? null;
    }

    $logModel->add(null, null, json_encode(['url' => $baseUrl, 'response' => $response]), json_encode(['fetched' => $fetched, 'errors' => $errors]), empty($errors) ? 'Success' : 'Failed', implode(' | ', $errors));

    if (!empty($errors) && $fetched === 0) {
        echo json_encode(['success' => false, 'error' => implode(' | ', $errors)]);
    } else {
        echo json_encode([
            'success' => empty($errors),
            'fetched' => $fetched,
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors
        ]);
    }
    exit();
}

// -------------------------------------------------------------
// Fallback
// -------------------------------------------------------------
else {
    echo json_encode(['success' => false, 'error' => 'Invalid API action']);
    exit();
}
