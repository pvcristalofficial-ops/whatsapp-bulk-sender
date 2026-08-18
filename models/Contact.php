<?php
// models/Contact.php

require_once __DIR__ . '/../config/database.php';

class Contact {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM contacts WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $contact = $stmt->fetch();
        return $contact ? $contact : null;
    }

    public function getAll(
        int $limit = 10,
        int $offset = 0,
        string $search = '',
        string $city = '',
        string $course = '',
        string $status = ''
    ): array {
        $sql = "SELECT * FROM contacts WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (name LIKE :search OR phone LIKE :search_phone OR city LIKE :search_city OR course LIKE :search_course)";
            $params['search'] = "%$search%";
            $params['search_phone'] = "%$search%";
            $params['search_city'] = "%$search%";
            $params['search_course'] = "%$search%";
        }

        if (!empty($city)) {
            $sql .= " AND city = :city";
            $params['city'] = $city;
        }

        if (!empty($course)) {
            $sql .= " AND course = :course";
            $params['course'] = $course;
        }

        if (!empty($status)) {
            $sql .= " AND status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY id DESC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->db->prepare($sql);
        // Bind variables for LIMIT and OFFSET as integers since emulated prepares might be off
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($params as $key => $val) {
            $stmt->bindValue(":$key", $val);
        }
        
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countAll(
        string $search = '',
        string $city = '',
        string $course = '',
        string $status = ''
    ): int {
        $sql = "SELECT COUNT(*) FROM contacts WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (name LIKE :search OR phone LIKE :search_phone OR city LIKE :search_city OR course LIKE :search_course)";
            $params['search'] = "%$search%";
            $params['search_phone'] = "%$search%";
            $params['search_city'] = "%$search%";
            $params['search_course'] = "%$search%";
        }

        if (!empty($city)) {
            $sql .= " AND city = :city";
            $params['city'] = $city;
        }

        if (!empty($course)) {
            $sql .= " AND course = :course";
            $params['course'] = $course;
        }

        if (!empty($status)) {
            $sql .= " AND status = :status";
            $params['status'] = $status;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function getUniqueCities(): array {
        $stmt = $this->db->query("SELECT DISTINCT city FROM contacts WHERE city IS NOT NULL AND city != '' ORDER BY city ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getUniqueCourses(): array {
        $stmt = $this->db->query("SELECT DISTINCT course FROM contacts WHERE course IS NOT NULL AND course != '' ORDER BY course ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function create(array $data): bool {
        // Validate phone number format
        $cleanPhone = self::sanitizePhone($data['phone']);
        if (!$cleanPhone) {
            return false;
        }

        $stmt = $this->db->prepare("
            INSERT INTO contacts (name, phone, city, course, status) 
            VALUES (:name, :phone, :city, :course, :status)
        ");
        return $stmt->execute([
            'name' => htmlspecialchars($data['name']),
            'phone' => $cleanPhone,
            'city' => !empty($data['city']) ? htmlspecialchars($data['city']) : null,
            'course' => !empty($data['course']) ? htmlspecialchars($data['course']) : null,
            'status' => $data['status'] ?? 'Active'
        ]);
    }

    public function update(int $id, array $data): bool {
        $cleanPhone = self::sanitizePhone($data['phone']);
        if (!$cleanPhone) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE contacts 
            SET name = :name, phone = :phone, city = :city, course = :course, status = :status 
            WHERE id = :id
        ");
        return $stmt->execute([
            'id' => $id,
            'name' => htmlspecialchars($data['name']),
            'phone' => $cleanPhone,
            'city' => !empty($data['city']) ? htmlspecialchars($data['city']) : null,
            'course' => !empty($data['course']) ? htmlspecialchars($data['course']) : null,
            'status' => $data['status'] ?? 'Active'
        ]);
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM contacts WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }

    public function bulkDelete(array $ids): bool {
        if (empty($ids)) {
            return false;
        }
        $inQuery = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("DELETE FROM contacts WHERE id IN ($inQuery)");
        return $stmt->execute(array_map('intval', $ids));
    }

    public static function sanitizePhone(string $phone): ?string {
        // Strip everything except digits
        $clean = preg_replace('/[^0-9]/', '', $phone);
        // Remove leading double zeros or single zeros for international format
        $clean = ltrim($clean, '0');
        // Minimum length validation: most country codes + phone numbers are at least 10 digits
        if (strlen($clean) < 10 || strlen($clean) > 15) {
            return null;
        }
        return $clean;
    }

    public function phoneExists(string $phone, int $excludeId = 0): bool {
        $cleanPhone = self::sanitizePhone($phone);
        if (!$cleanPhone) {
            return false;
        }

        $sql = "SELECT COUNT(*) FROM contacts WHERE phone = :phone";
        $params = ['phone' => $cleanPhone];
        if ($excludeId > 0) {
            $sql .= " AND id != :id";
            $params['id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Parse and import CSV/Excel file.
     * Columns: Name, Phone, City, Course, Status
     */
    public function importFromFile(string $filePath, string $extension, bool $detectDuplicates): array {
        $imported = 0;
        $skipped = 0;
        $invalid = 0;

        $rows = [];

        if ($extension === 'csv') {
            if (($handle = fopen($filePath, "r")) !== FALSE) {
                // Get header row
                $header = fgetcsv($handle, 1000, ",");
                if ($header) {
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        $rows[] = $data;
                    }
                }
                fclose($handle);
            }
        } elseif ($extension === 'xlsx') {
            $rows = $this->parseXLSX($filePath);
            if ($rows === false) {
                return ['success' => false, 'error' => 'Failed to parse Excel file.'];
            }
            // Remove header row
            if (!empty($rows)) {
                array_shift($rows);
            }
        } else {
            return ['success' => false, 'error' => 'Unsupported file extension.'];
        }

        if (empty($rows)) {
            return ['success' => false, 'error' => 'The file is empty.'];
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO contacts (name, phone, city, course, status) 
                VALUES (:name, :phone, :city, :course, :status)
                ON DUPLICATE KEY UPDATE name = :name_update, city = :city_update, course = :course_update, status = :status_update
            ");

            foreach ($rows as $row) {
                // We need at least 2 columns: Name (0), Phone (1)
                if (count($row) < 2) {
                    $invalid++;
                    continue;
                }

                $name = trim($row[0] ?? '');
                $phone = trim($row[1] ?? '');
                $city = trim($row[2] ?? '');
                $course = trim($row[3] ?? '');
                $status = trim($row[4] ?? 'Active');

                if (empty($name) || empty($phone)) {
                    $invalid++;
                    continue;
                }

                $cleanPhone = self::sanitizePhone($phone);
                if (!$cleanPhone) {
                    $invalid++;
                    continue;
                }

                // Check duplicates if detection is enabled
                if ($detectDuplicates) {
                    $stmtCheck = $this->db->prepare("SELECT id FROM contacts WHERE phone = :phone LIMIT 1");
                    $stmtCheck->execute(['phone' => $cleanPhone]);
                    if ($stmtCheck->fetch()) {
                        $skipped++;
                        continue;
                    }
                }

                // Parse status
                $status = in_array(strtolower($status), ['active', 'inactive']) ? ucfirst(strtolower($status)) : 'Active';

                $stmt->execute([
                    'name' => htmlspecialchars($name),
                    'phone' => $cleanPhone,
                    'city' => !empty($city) ? htmlspecialchars($city) : null,
                    'course' => !empty($course) ? htmlspecialchars($course) : null,
                    'status' => $status,
                    'name_update' => htmlspecialchars($name),
                    'city_update' => !empty($city) ? htmlspecialchars($city) : null,
                    'course_update' => !empty($course) ? htmlspecialchars($course) : null,
                    'status_update' => $status
                ]);
                $imported++;
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => 'Database error during import: ' . $e->getMessage()];
        }

        return [
            'success' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'invalid' => $invalid
        ];
    }

    /**
     * Native, dependency-free XLSX file reader. Unzips and reads sheet1.xml and sharedStrings.xml
     */
    private function parseXLSX(string $filePath) {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== TRUE) {
            return false;
        }

        $sharedStrings = [];
        $sharedStringsEntry = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedStringsEntry) {
            $xml = simplexml_load_string($sharedStringsEntry);
            if ($xml && $xml->si) {
                foreach ($xml->si as $val) {
                    // Strings could be stored in <t> or nested in rich text <r> <t> tags
                    if (isset($val->t)) {
                        $sharedStrings[] = (string)$val->t;
                    } else {
                        $text = "";
                        if (isset($val->r)) {
                            foreach ($val->r as $r) {
                                $text .= (string)$r->t;
                            }
                        }
                        $sharedStrings[] = $text;
                    }
                }
            }
        }

        $sheetEntry = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheetEntry) {
            $zip->close();
            return false;
        }

        $xml = simplexml_load_string($sheetEntry);
        if (!$xml || !isset($xml->sheetData)) {
            $zip->close();
            return false;
        }

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $rowData = [];
            foreach ($row->c as $cell) {
                $attr = $cell->attributes();
                $cellRef = (string)$attr['r']; // e.g. A1, B1
                $type = (string)$attr['t']; // e.g. s = shared string
                $value = (string)$cell->v;

                if ($type === 's') {
                    $value = $sharedStrings[intval($value)] ?? '';
                }

                // Decode cell coordinates to index
                preg_match('/([A-Z]+)(\d+)/', $cellRef, $matches);
                if (isset($matches[1])) {
                    $colLetter = $matches[1];
                    $colIndex = 0;
                    $len = strlen($colLetter);
                    for ($i = 0; $i < $len; $i++) {
                        $colIndex = $colIndex * 26 + (ord($colLetter[$i]) - 64);
                    }
                    $rowData[$colIndex - 1] = $value;
                }
            }

            if (!empty($rowData)) {
                $maxKey = max(array_keys($rowData));
                for ($i = 0; $i <= $maxKey; $i++) {
                    if (!isset($rowData[$i])) {
                        $rowData[$i] = '';
                    }
                }
                ksort($rowData);
                $rows[] = $rowData;
            }
        }

        $zip->close();
        return $rows;
    }
}
