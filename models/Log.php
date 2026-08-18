<?php
// models/Log.php

require_once __DIR__ . '/../config/database.php';

class Log {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function add(
        ?int $campaignId,
        ?int $contactId,
        string $requestPayload,
        string $responsePayload,
        string $status,
        ?string $errorMessage = null
    ): bool {
        $stmt = $this->db->prepare("
            INSERT INTO logs (campaign_id, contact_id, request_payload, response_payload, status, error_message) 
            VALUES (:campaign_id, :contact_id, :request_payload, :response_payload, :status, :error_message)
        ");
        return $stmt->execute([
            'campaign_id' => $campaignId,
            'contact_id' => $contactId,
            'request_payload' => $requestPayload,
            'response_payload' => $responsePayload,
            'status' => $status,
            'error_message' => $errorMessage
        ]);
    }

    public function getAll(int $limit = 50, int $offset = 0): array {
        $stmt = $this->db->prepare("
            SELECT l.*, c.name as campaign_name, co.name as contact_name, co.phone as contact_phone
            FROM logs l
            LEFT JOIN campaigns c ON l.campaign_id = c.id
            LEFT JOIN contacts co ON l.contact_id = co.id
            ORDER BY l.id DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countAll(): int {
        $stmt = $this->db->query("SELECT COUNT(*) FROM logs");
        return (int)$stmt->fetchColumn();
    }

    public function clear(): bool {
        return $this->db->query("TRUNCATE TABLE logs") !== false;
    }
}
