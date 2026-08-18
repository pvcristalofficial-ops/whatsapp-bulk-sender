<?php
// cron/send-queue.php

// Ensure this script runs only via Command Line Interface (CLI)
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Error: This script is restricted to CLI execution only.\n");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Setting.php';
require_once __DIR__ . '/../models/Campaign.php';
require_once __DIR__ . '/../models/Log.php';
require_once __DIR__ . '/../models/Contact.php';
require_once __DIR__ . '/../models/Template.php';

$db = Database::getConnection();
$settingModel = new Setting();
$campaignModel = new Campaign();
$logModel = new Log();
$templateModel = new Template();

echo "=====================================================\n";
echo "WhatsApp Bulk Sender Pro - CLI Queue Processor Started\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
echo "=====================================================\n\n";

// 1. Fetch API settings
$accessToken = $settingModel->get('access_token');
$phoneId = $settingModel->get('phone_number_id');
$version = $settingModel->get('api_version', 'v23.0');

if (empty($accessToken) || empty($phoneId)) {
    die("Critical Error: Meta API credentials are not configured in settings.\n");
}

// 2. Fetch campaigns that are 'Sending' or are 'Pending' and due/scheduled
$stmtCamps = $db->query("
    SELECT c.*, t.name as template_name, t.language as template_language, t.body_variables, t.header_variables, t.components_json
    FROM campaigns c
    JOIN templates t ON c.template_id = t.id
    WHERE c.status = 'Sending' 
       OR (c.status = 'Pending' AND (c.scheduled_at IS NULL OR c.scheduled_at <= NOW()))
    ORDER BY c.id ASC
");
$campaigns = $stmtCamps->fetchAll();

if (empty($campaigns)) {
    echo "No active or due campaigns found in queue. Exiting.\n";
    exit();
}

foreach ($campaigns as $camp) {
    $campaignId = $camp['id'];
    $campaignName = $camp['name'];
    echo "Processing Campaign: '{$campaignName}' (ID: {$campaignId})\n";

    // Set campaign status to Sending if it was Pending
    if ($camp['status'] === 'Pending') {
        $campaignModel->updateStatus($campaignId, 'Sending');
        echo "Campaign status changed from Pending to Sending.\n";
    }

    // Process in batches of 50 (Batch size parameter requirement)
    $batchSize = 50;
    
    // Process campaign queue loop
    while (true) {
        // Fetch next batch of pending contacts
        $stmtCC = $db->prepare("
            SELECT cc.*, c.name, c.phone, c.city, c.course
            FROM campaign_contacts cc
            JOIN contacts c ON cc.contact_id = c.id
            WHERE cc.campaign_id = :campaign_id AND cc.status = 'Pending'
            ORDER BY cc.id ASC
            LIMIT :limit
        ");
        $stmtCC->bindValue(':campaign_id', $campaignId, PDO::PARAM_INT);
        $stmtCC->bindValue(':limit', $batchSize, PDO::PARAM_INT);
        $stmtCC->execute();
        $batch = $stmtCC->fetchAll();

        if (empty($batch)) {
            // Check if there are failed messages that qualify for Auto-Retry (up to 3 times)
            $stmtFailedRetry = $db->prepare("
                SELECT cc.id, cc.contact_id, fm.retry_count 
                FROM campaign_contacts cc
                JOIN failed_messages fm ON cc.id = fm.campaign_contact_id
                WHERE cc.campaign_id = :campaign_id 
                  AND cc.status = 'Failed' 
                  AND fm.retry_count < 3
            ");
            $stmtFailedRetry->execute(['campaign_id' => $campaignId]);
            $failedRetries = $stmtFailedRetry->fetchAll();

            if (!empty($failedRetries)) {
                echo "Found " . count($failedRetries) . " failed contacts eligible for auto-retry. Resetting to Pending...\n";
                
                $db->beginTransaction();
                try {
                    $stmtResetCC = $db->prepare("UPDATE campaign_contacts SET status = 'Pending', error_message = NULL WHERE id = :id");
                    $stmtIncRetry = $db->prepare("UPDATE failed_messages SET retry_count = retry_count + 1 WHERE campaign_contact_id = :cc_id");
                    
                    foreach ($failedRetries as $fr) {
                        $stmtResetCC->execute(['id' => $fr['id']]);
                        $stmtIncRetry->execute(['cc_id' => $fr['id']]);
                    }
                    $db->commit();
                    echo "Auto-retry queue updated. Continuing processing...\n";
                    continue; // Re-run the batch fetch loop to process reset contacts
                } catch (Exception $e) {
                    $db->rollBack();
                    echo "Database transaction failed during retry reset: " . $e->getMessage() . "\n";
                }
            }

            // No pending contacts and no retries left, campaign is finished!
            $campaignModel->updateStatus($campaignId, 'Completed');
            $campaignModel->updateStats($campaignId);
            echo "Campaign '{$campaignName}' processing completed successfully!\n\n";
            break; // Break the batch loop for this campaign
        }

        echo "Found " . count($batch) . " contacts. Dispatching messages...\n";

        foreach ($batch as $pc) {
            $contactId = $pc['contact_id'];
            $ccId = $pc['id'];
            $phone = $pc['phone'];
            $name = $pc['name'];

            // Parse body variables placeholders
            $parameters = [];
            preg_match_all('/\{\{(\d+)\}\}/', $camp['body_variables'], $matches);
            $varIndexes = $matches[1] ?? [];
            if (!empty($varIndexes)) {
                $uniqueVars = array_unique($varIndexes);
                sort($uniqueVars);
                foreach ($uniqueVars as $vNum) {
                    $val = '';
                    if ($vNum == 1) $val = $name;
                    elseif ($vNum == 2) $val = $pc['city'] ?? '';
                    elseif ($vNum == 3) $val = $pc['course'] ?? '';

                    $parameters[] = [
                        'type' => 'text',
                        'text' => $val
                    ];
                }
            }

            // Meta payload structure
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $phone,
                'type' => 'template',
                'template' => [
                    'name' => $camp['template_name'],
                    'language' => [
                        'code' => $camp['template_language']
                    ]
                ]
            ];

            $templateComponents = $templateModel->buildTemplateComponents($camp, $pc);
            if (!empty($templateComponents)) {
                $payload['template']['components'] = $templateComponents;
            }

            if (!isset($payload['template']['components']) || !is_array($payload['template']['components'])) {
                $payload['template']['components'] = [];
            }
            foreach ($payload['template']['components'] as $component) {
                if (($component['type'] ?? '') === 'header') {
                    break;
                }
            }
            if ($templateModel->shouldIncludeHeaderMediaComponent($camp) && !array_filter($payload['template']['components'], fn($component) => strtolower($component['type'] ?? '') === 'header')) {
                $payload['template']['components'][] = [
                    'type' => 'header',
                    'parameters' => [[
                        'type' => 'image',
                        'image' => ['link' => $templateModel->resolveHeaderImageLink($camp)]
                    ]]
                ];
            }

            $url = "https://graph.facebook.com/{$version}/{$phoneId}/messages";
            $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            error_log('WhatsApp payload: ' . $jsonPayload);
            echo "Payload: " . $jsonPayload . "\n";

            // Execute POST cURL
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

            // Update Database Queue status
            if ($statusStr === 'Sent') {
                $stmtUpdate = $db->prepare("UPDATE campaign_contacts SET status = 'Sent', message_id = :message_id, sent_at = NOW() WHERE id = :id");
                $stmtUpdate->execute(['message_id' => $messageId, 'id' => $ccId]);
                echo "Sent successfully to +{$phone} ({$name})\n";
            } else {
                $stmtUpdate = $db->prepare("UPDATE campaign_contacts SET status = 'Failed', error_message = :err WHERE id = :id");
                $stmtUpdate->execute(['err' => $errorMsg, 'id' => $ccId]);
                echo "Failed sending to +{$phone} ({$name}) | Error: {$errorMsg}\n";

                // Add to failed messages table for retry count tracking
                $stmtRetry = $db->prepare("
                    INSERT INTO failed_messages (campaign_contact_id, error_message, retry_count) 
                    VALUES (:cc_id, :err, 0)
                    ON DUPLICATE KEY UPDATE error_message = :err_update
                ");
                $stmtRetry->execute([
                    'cc_id' => $ccId,
                    'err' => $errorMsg,
                    'err_update' => $errorMsg
                ]);
            }

            // Record Log entry
            $logModel->add($campaignId, $contactId, $jsonPayload, $response ?: $curlError, $statusStr === 'Sent' ? 'Success' : 'Failed', $errorMsg);

            // Delay of 2 seconds between dispatches (Delay parameter requirement)
            sleep(2);
        }

        // Update stats summary counts for campaign progress bar updates
        $campaignModel->updateStats($campaignId);
    }
}

echo "=====================================================\n";
echo "CLI Queue Processor finished executions.\n";
echo "=====================================================\n";
