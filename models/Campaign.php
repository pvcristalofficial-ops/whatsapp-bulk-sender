<?php
// models/Campaign.php

require_once __DIR__ . '/../config/database.php';

class Campaign {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT c.*, t.name as template_name, t.language as template_language 
            FROM campaigns c 
            JOIN templates t ON c.template_id = t.id 
            WHERE c.id = :id LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $campaign = $stmt->fetch();
        return $campaign ? $campaign : null;
    }

    public function getAll(): array {
        $stmt = $this->db->query("
            SELECT c.*, t.name as template_name 
            FROM campaigns c 
            JOIN templates t ON c.template_id = t.id 
            ORDER BY c.id DESC
        ");
        return $stmt->fetchAll();
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM campaigns WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }

    /**
     * Create a campaign and add matching contacts to the campaign_contacts queue.
     */
    public function create(string $name, int $templateId, array $filters, ?string $scheduledAt): int {
        $this->db->beginTransaction();
        try {
            // 1. Insert Campaign
            $stmt = $this->db->prepare("
                INSERT INTO campaigns (name, template_id, status, scheduled_at) 
                VALUES (:name, :template_id, 'Pending', :scheduled_at)
            ");
            $stmt->execute([
                'name' => htmlspecialchars($name),
                'template_id' => $templateId,
                'scheduled_at' => !empty($scheduledAt) ? $scheduledAt : null
            ]);
            $campaignId = (int)$this->db->lastInsertId();

            // 2. Fetch Matching Contacts
            $sql = "SELECT id FROM contacts WHERE status = 'Active'";
            $params = [];

            if (!empty($filters['city'])) {
                $sql .= " AND city = :city";
                $params['city'] = $filters['city'];
            }

            if (!empty($filters['course'])) {
                $sql .= " AND course = :course";
                $params['course'] = $filters['course'];
            }

            if (isset($filters['ids']) && is_array($filters['ids']) && !empty($filters['ids'])) {
                $inQuery = implode(',', array_fill(0, count($filters['ids']), '?'));
                $sql .= " AND id IN ($inQuery)";
                $stmtContacts = $this->db->prepare($sql);
                $stmtContacts->execute(array_map('intval', $filters['ids']));
            } else {
                $stmtContacts = $this->db->prepare($sql);
                $stmtContacts->execute($params);
            }

            $contactIds = $stmtContacts->fetchAll(PDO::FETCH_COLUMN);

            if (empty($contactIds)) {
                // Rollback if no contacts match
                $this->db->rollBack();
                return 0;
            }

            // 3. Populate queue (campaign_contacts)
            $stmtQueue = $this->db->prepare("
                INSERT INTO campaign_contacts (campaign_id, contact_id, status) 
                VALUES (:campaign_id, :contact_id, 'Pending')
            ");

            foreach ($contactIds as $contactId) {
                $stmtQueue->execute([
                    'campaign_id' => $campaignId,
                    'contact_id' => $contactId
                ]);
            }

            // 4. Update total contacts count in campaigns table
            $total = count($contactIds);
            $stmtUpdateTotal = $this->db->prepare("
                UPDATE campaigns SET total_contacts = :total WHERE id = :campaign_id
            ");
            $stmtUpdateTotal->execute([
                'total' => $total,
                'campaign_id' => $campaignId
            ]);

            $this->db->commit();
            return $campaignId;

        } catch (Exception $e) {
            $this->db->rollBack();
            return 0;
        }
    }

    public function updateStatus(int $campaignId, string $status): bool {
        $validStatuses = ['Pending', 'Sending', 'Paused', 'Completed'];
        if (!in_array($status, $validStatuses)) {
            return false;
        }
        $stmt = $this->db->prepare("UPDATE campaigns SET status = :status WHERE id = :id");
        return $stmt->execute([
            'status' => $status,
            'id' => $campaignId
        ]);
    }

    public function updateStats(int $campaignId): array {
        // Count statuses
        $stmt = $this->db->prepare("
            SELECT status, COUNT(*) as cnt 
            FROM campaign_contacts 
            WHERE campaign_id = :campaign_id 
            GROUP BY status
        ");
        $stmt->execute(['campaign_id' => $campaignId]);
        $results = $stmt->fetchAll();

        $stats = [
            'Pending' => 0,
            'Sent' => 0,
            'Delivered' => 0,
            'Read' => 0,
            'Failed' => 0
        ];

        foreach ($results as $row) {
            $stats[$row['status']] = (int)$row['cnt'];
        }

        // Total processed is Sent + Delivered + Read + Failed
        $sent = $stats['Sent'];
        $delivered = $stats['Delivered'];
        $read = $stats['Read'];
        $failed = $stats['Failed'];
        $pending = $stats['Pending'];

        // If nothing is pending, status becomes Completed (unless it was already paused or pending)
        $campaign = $this->getById($campaignId);
        $newStatus = $campaign['status'];
        if ($pending === 0 && $campaign['status'] === 'Sending') {
            $newStatus = 'Completed';
        }

        $stmtUpdate = $this->db->prepare("
            UPDATE campaigns 
            SET sent_count = :sent, 
                delivered_count = :delivered, 
                read_count = :read, 
                failed_count = :failed,
                status = :status
            WHERE id = :id
        ");
        $stmtUpdate->execute([
            'sent' => $sent,
            'delivered' => $delivered,
            'read' => $read,
            'failed' => $failed,
            'status' => $newStatus,
            'id' => $campaignId
        ]);

        $stats['status'] = $newStatus;
        return $stats;
    }

    public function retryFailed(int $campaignId): bool {
        $this->db->beginTransaction();
        try {
            // Set all failed contacts to Pending
            $stmt = $this->db->prepare("
                UPDATE campaign_contacts 
                SET status = 'Pending', error_message = NULL, sent_at = NULL, message_id = NULL 
                WHERE campaign_id = :campaign_id AND status = 'Failed'
            ");
            $stmt->execute(['campaign_id' => $campaignId]);

            // Set campaign status back to Pending or Sending
            $stmtCamp = $this->db->prepare("
                UPDATE campaigns 
                SET status = 'Pending', failed_count = 0 
                WHERE id = :campaign_id
            ");
            $stmtCamp->execute(['campaign_id' => $campaignId]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    public function getCampaignContacts(int $campaignId): array {
        $stmt = $this->db->prepare("
            SELECT cc.*, c.name as contact_name, c.phone as contact_phone, c.city, c.course
            FROM campaign_contacts cc
            JOIN contacts c ON cc.contact_id = c.id
            WHERE cc.campaign_id = :campaign_id
            ORDER BY cc.id ASC
        ");
        $stmt->execute(['campaign_id' => $campaignId]);
        return $stmt->fetchAll();
    }
}
