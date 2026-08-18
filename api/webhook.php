<?php
// api/webhook.php

// -------------------------------------------------------------
// 1. Handle Webhook Verification (GET Request)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../models/Setting.php';
    
    $settingModel = new Setting();
    $verifyToken = $settingModel->get('webhook_verify_token', 'whatsapp_verify_token_123');

    $mode = $_GET['hub_mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    if ($mode === 'subscribe' && $token === $verifyToken) {
        // Verification succeeded
        http_response_code(200);
        echo $challenge;
        exit();
    } else {
        // Verification failed
        http_response_code(403);
        echo "Forbidden - Verify Token Mismatch";
        exit();
    }
}

// -------------------------------------------------------------
// 2. Handle Event Notifications (POST Request)
// -------------------------------------------------------------
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get raw input stream
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (!$data) {
        http_response_code(400);
        exit();
    }

    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../models/Log.php';
    $logModel = new Log();
    $db = Database::getConnection();

    // Log the incoming webhook event for diagnostic purposes
    $logModel->add(null, null, "Meta Webhook POST", $rawInput, "Success", "Webhook Event Notification Received");

    // Drill down to the status updates in the payload
    // Structure: entry[] -> changes[] -> value -> statuses[]
    if (isset($data['entry'][0]['changes'][0]['value']['statuses'][0])) {
        $statusInfo = $data['entry'][0]['changes'][0]['value']['statuses'][0];
        $messageId = $statusInfo['id'] ?? '';
        $status = strtolower($statusInfo['status'] ?? '');
        $timestamp = $statusInfo['timestamp'] ?? time();
        $formattedTime = date('Y-m-d H:i:s', $timestamp);

        if (!empty($messageId) && !empty($status)) {
            // Map incoming status to DB ENUM ('Sent', 'Delivered', 'Read', 'Failed')
            $mappedStatus = '';
            $timeColumn = '';

            switch ($status) {
                case 'sent':
                    $mappedStatus = 'Sent';
                    $timeColumn = 'sent_at';
                    break;
                case 'delivered':
                    $mappedStatus = 'Delivered';
                    $timeColumn = 'delivered_at';
                    break;
                case 'read':
                    $mappedStatus = 'Read';
                    $timeColumn = 'read_at';
                    break;
                case 'failed':
                    $mappedStatus = 'Failed';
                    break;
            }

            if (!empty($mappedStatus)) {
                // Find campaign contact corresponding to this message ID
                $stmtCheck = $db->prepare("SELECT id, campaign_id, status FROM campaign_contacts WHERE message_id = :message_id LIMIT 1");
                $stmtCheck->execute(['message_id' => $messageId]);
                $cc = $stmtCheck->fetch();

                if ($cc) {
                    $ccId = $cc['id'];
                    $campaignId = $cc['campaign_id'];

                    // Update status and appropriate timestamp
                    if (!empty($timeColumn)) {
                        $sql = "UPDATE campaign_contacts SET status = :status, {$timeColumn} = :time";
                        
                        // If status is Read, it implies it was also Delivered. Update delivered_at if null
                        if ($mappedStatus === 'Read') {
                            $sql .= ", delivered_at = COALESCE(delivered_at, :time)";
                        }
                        
                        $sql .= " WHERE id = :id";
                        $stmtUpdate = $db->prepare($sql);
                        $stmtUpdate->execute([
                            'status' => $mappedStatus,
                            'time' => $formattedTime,
                            'id' => $ccId
                        ]);
                    } else {
                        // Failed status handles errors
                        $errorData = $statusInfo['errors'][0] ?? null;
                        $errorMsg = $errorData ? ($errorData['message'] . " (Code: " . $errorData['code'] . ")") : "Webhook reports delivery failure";

                        $stmtUpdate = $db->prepare("
                            UPDATE campaign_contacts 
                            SET status = 'Failed', error_message = :err 
                            WHERE id = :id
                        ");
                        $stmtUpdate->execute([
                            'err' => $errorMsg,
                            'id' => $ccId
                        ]);

                        // Add to failed messages table
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

                    // Recalculate campaign statistics totals
                    require_once __DIR__ . '/../models/Campaign.php';
                    $campaignModel = new Campaign();
                    $campaignModel->updateStats($campaignId);
                }
            }
        }
    }

    // Always respond 200 OK to Meta to acknowledge event receipt
    http_response_code(200);
    echo "EVENT_RECEIVED";
    exit();
} else {
    http_response_code(405);
    echo "Method Not Allowed";
    exit();
}
