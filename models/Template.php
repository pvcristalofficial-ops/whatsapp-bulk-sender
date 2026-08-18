<?php
// models/Template.php

require_once __DIR__ . '/../config/database.php';

class Template {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM templates WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $template = $stmt->fetch();
        return $template ? $template : null;
    }

    public function getAll(): array {
        $this->ensureMetaSyncColumns();
        $stmt = $this->db->query("SELECT * FROM templates ORDER BY updated_at DESC, name ASC");
        return $stmt->fetchAll();
    }

    public function getCount(): int {
        $this->ensureMetaSyncColumns();
        $stmt = $this->db->query("SELECT COUNT(*) FROM templates");
        return (int)$stmt->fetchColumn();
    }

    public function getMetaTemplates(string $search = '', string $status = '', string $language = '', int $limit = 0, int $offset = 0): array {
        $this->ensureMetaSyncColumns();

        $sql = "SELECT * FROM templates WHERE meta_template_id IS NOT NULL";
        $params = [];

        if ($search !== '') {
            $sql .= " AND (name LIKE :search OR meta_template_id LIKE :search OR category LIKE :search OR language LIKE :search)";
            $params['search'] = "%{$search}%";
        }
        if ($status !== '') {
            $sql .= " AND status = :status";
            $params['status'] = $status;
        }
        if ($language !== '') {
            $sql .= " AND language = :language";
            $params['language'] = $language;
        }

        $sql .= " ORDER BY updated_at DESC, name ASC";
        if ($limit > 0) {
            $sql .= " LIMIT :limit OFFSET :offset";
        }

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value, PDO::PARAM_STR);
        }
        if ($limit > 0) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countMetaTemplates(string $search = '', string $status = '', string $language = ''): int {
        $this->ensureMetaSyncColumns();

        $sql = "SELECT COUNT(*) FROM templates WHERE meta_template_id IS NOT NULL";
        $params = [];

        if ($search !== '') {
            $sql .= " AND (name LIKE :search OR meta_template_id LIKE :search OR category LIKE :search OR language LIKE :search)";
            $params['search'] = "%{$search}%";
        }
        if ($status !== '') {
            $sql .= " AND status = :status";
            $params['status'] = $status;
        }
        if ($language !== '') {
            $sql .= " AND language = :language";
            $params['language'] = $language;
        }

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value, PDO::PARAM_STR);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function getByMetaTemplateId(string $metaTemplateId): ?array {
        $this->ensureMetaSyncColumns();
        $stmt = $this->db->prepare("SELECT * FROM templates WHERE meta_template_id = :meta_template_id LIMIT 1");
        $stmt->execute(['meta_template_id' => $metaTemplateId]);
        $template = $stmt->fetch();
        return $template ? $template : null;
    }

    private function ensureMetaSyncColumns(): void {
        $columns = [
            'meta_template_id' => 'VARCHAR(255) DEFAULT NULL',
            'category' => 'VARCHAR(100) DEFAULT NULL',
            'status' => 'VARCHAR(50) DEFAULT NULL',
            'components_json' => 'TEXT DEFAULT NULL',
            'updated_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        ];

        foreach ($columns as $column => $definition) {
            $columnName = $this->db->quote($column);
            $stmt = $this->db->query("SHOW COLUMNS FROM templates LIKE {$columnName}");
            if (!$stmt || !$stmt->fetch()) {
                $this->db->exec("ALTER TABLE templates ADD COLUMN {$column} {$definition}");
            }
        }

        $indexStmt = $this->db->prepare("SHOW INDEX FROM templates WHERE Key_name = 'idx_meta_template_id'");
        $indexStmt->execute();
        if (!$indexStmt->fetch()) {
            $this->db->exec("ALTER TABLE templates ADD UNIQUE KEY idx_meta_template_id (meta_template_id)");
        }
    }

    public function upsertMetaTemplate(array $data): bool {
        $this->ensureMetaSyncColumns();

        $existing = $this->getByMetaTemplateId($data['meta_template_id']);
        if ($existing) {
            $stmt = $this->db->prepare("UPDATE templates SET
                    name = :name,
                    language = :language,
                    category = :category,
                    status = :status,
                    header_variables = :header_variables,
                    body_variables = :body_variables,
                    footer_text = :footer_text,
                    buttons_json = :buttons_json,
                    components_json = :components_json,
                    updated_at = :updated_at
                WHERE id = :id");

            return $stmt->execute([
                'id' => $existing['id'],
                'name' => htmlspecialchars($data['name']),
                'language' => htmlspecialchars($data['language'] ?? 'en_US'),
                'category' => htmlspecialchars($data['category'] ?? ''),
                'status' => htmlspecialchars($data['status'] ?? ''),
                'header_variables' => $data['header_variables'] ?? null,
                'body_variables' => $data['body_variables'] ?? null,
                'footer_text' => $data['footer_text'] ?? null,
                'buttons_json' => !empty($data['buttons_json']) ? json_encode($data['buttons_json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'components_json' => json_encode($data['components'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => $data['updated_at'] ?? date('Y-m-d H:i:s')
            ]);
        }

        $stmt = $this->db->prepare("INSERT INTO templates (
                name,
                language,
                category,
                status,
                meta_template_id,
                header_variables,
                body_variables,
                footer_text,
                buttons_json,
                components_json,
                updated_at
            ) VALUES (
                :name,
                :language,
                :category,
                :status,
                :meta_template_id,
                :header_variables,
                :body_variables,
                :footer_text,
                :buttons_json,
                :components_json,
                :updated_at
            )");

        return $stmt->execute([
            'name' => htmlspecialchars($data['name']),
            'language' => htmlspecialchars($data['language'] ?? 'en_US'),
            'category' => htmlspecialchars($data['category'] ?? ''),
            'status' => htmlspecialchars($data['status'] ?? ''),
            'meta_template_id' => htmlspecialchars($data['meta_template_id']),
            'header_variables' => $data['header_variables'] ?? null,
            'body_variables' => $data['body_variables'] ?? null,
            'footer_text' => $data['footer_text'] ?? null,
            'buttons_json' => !empty($data['buttons_json']) ? json_encode($data['buttons_json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'components_json' => json_encode($data['components'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at' => $data['updated_at'] ?? date('Y-m-d H:i:s')
        ]);
    }

    private function normalizeTemplateVariableValue(mixed $value, string $fallback = 'Student'): string {
        $normalized = trim((string)($value ?? ''));
        return $normalized !== '' ? $normalized : $fallback;
    }

    private function resolveTemplateParameterName(string $placeholder): string {
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

    private function resolveTemplateVariableValue(string $parameterName, array $contact): string {
        $parameterName = strtolower(trim($parameterName));
        if ($parameterName === 'name') return $this->normalizeTemplateVariableValue($contact['name'] ?? '', 'Student');
        if ($parameterName === 'city') return $this->normalizeTemplateVariableValue($contact['city'] ?? '', 'City');
        if ($parameterName === 'course') return $this->normalizeTemplateVariableValue($contact['course'] ?? '', 'Course');
        return 'Student';
    }

    public function resolveHeaderImageLink(array $template): string {
        $link = trim((string)($template['header_image_url'] ?? ''));

        if ($link === '' && class_exists('Setting')) {
            $setting = new Setting();
            $link = trim((string)$setting->get('template_header_image_url', ''));
        }

        if ($link === '') {
            $link = 'https://example.com/academy_welcome_info.jpg';
        }

        return $link;
    }

    public function shouldIncludeHeaderMediaComponent(array $template): bool {
        $components = $this->getTemplateComponents($template);
        if (!empty($components)) {
            foreach ($components as $component) {
                $type = strtolower($component['type'] ?? '');
                if ($type === 'header') {
                    $format = strtoupper(trim($component['format'] ?? $component['header_format'] ?? 'TEXT'));
                    return $format !== '' && $format !== 'TEXT';
                }
            }
        }

        $headerVariables = trim((string)($template['header_variables'] ?? ''));
        return $headerVariables !== '' && preg_match('/\{\{.*\}\}/', $headerVariables) === 1;
    }

    private function parseTemplateVariables(string $variables, array $contact): array {
        $parameters = [];
        $matches = [];
        preg_match_all('/\{\{([^}]+)\}\}/', $variables, $matches);
        $placeholders = $matches[1] ?? [];

        if (empty($placeholders)) {
            return $parameters;
        }

        $seen = [];
        foreach ($placeholders as $placeholder) {
            $parameterName = $this->resolveTemplateParameterName($placeholder);
            if (isset($seen[$parameterName])) {
                continue;
            }
            $seen[$parameterName] = true;

            $parameters[] = [
                'type' => 'text',
                'parameter_name' => $parameterName,
                'text' => $this->resolveTemplateVariableValue($parameterName, $contact)
            ];
        }

        return $parameters;
    }

    private function buildHeaderMediaParameters(array $component): array {
        $format = strtoupper(trim($component['format'] ?? $component['header_format'] ?? 'TEXT'));
        $example = $component['example'] ?? [];
        $link = null;

        if (!empty($example['header_handle'])) {
            if (is_array($example['header_handle'])) {
                $link = $example['header_handle'][0] ?? null;
            } else {
                $link = $example['header_handle'];
            }
        }

        if (empty($link) && !empty($component['header_handle'])) {
            $link = is_array($component['header_handle']) ? ($component['header_handle'][0] ?? null) : $component['header_handle'];
        }

        if (empty($link)) {
            $link = $this->resolveHeaderImageLink($component);
        }

        switch ($format) {
            case 'IMAGE':
                return [[
                    'type' => 'image',
                    'image' => ['link' => $link]
                ]];
            case 'VIDEO':
                return [[
                    'type' => 'video',
                    'video' => ['link' => $link]
                ]];
            case 'DOCUMENT':
                return [[
                    'type' => 'document',
                    'document' => ['link' => $link]
                ]];
            default:
                return [];
        }
    }

    public function getTemplateComponents(array $template): array {
        if (empty($template['components_json'])) {
            return [];
        }

        $components = json_decode($template['components_json'], true);
        return is_array($components) ? $components : [];
    }

    public function hasUnsupportedTemplateComponents(array $template): bool {
        foreach ($this->getTemplateComponents($template) as $component) {
            $type = strtolower($component['type'] ?? '');
            if ($type === 'header') {
                $format = strtoupper(trim($component['format'] ?? $component['header_format'] ?? 'TEXT'));
                if ($format !== '' && $format !== 'TEXT') {
                    return true;
                }
            }
            if ($type === 'button') {
                return true;
            }
        }
        return false;
    }

    public function buildTemplateComponentParameters(array $template, array $contact, string $componentType = 'body', array $rawComponent = []): array {
        if ($componentType === 'header') {
            $variables = trim($template['header_variables'] ?? '');
            if ($variables !== '') {
                return $this->parseTemplateVariables($variables, $contact);
            }

            if (!empty($rawComponent)) {
                $format = strtoupper(trim($rawComponent['format'] ?? $rawComponent['header_format'] ?? 'TEXT'));
                if ($format !== '' && $format !== 'TEXT') {
                    return $this->buildHeaderMediaParameters($rawComponent);
                }
            }

            return [];
        }

        if ($componentType === 'body') {
            $variables = trim($template['body_variables'] ?? '');
            if ($variables !== '') {
                return $this->parseTemplateVariables($variables, $contact);
            }

            if (!empty($rawComponent['text'])) {
                return $this->parseTemplateVariables($rawComponent['text'], $contact);
            }

            return [];
        }

        if ($componentType === 'footer') {
            $variables = trim($template['footer_text'] ?? '');
            if ($variables === '') {
                return [];
            }
            return $this->parseTemplateVariables($variables, $contact);
        }

        return [];
    }

    public function buildTemplateComponents(array $template, array $contact): array {
        $components = [];
        $templateComponents = $this->getTemplateComponents($template);

        if (empty($templateComponents)) {
            // Legacy / manual templates without saved Meta component JSON.
            $headerParameters = $this->buildTemplateComponentParameters($template, $contact, 'header');
            if (!empty($headerParameters)) {
                $components[] = [
                    'type' => 'header',
                    'parameters' => $headerParameters
                ];
            }

            $bodyParameters = $this->buildTemplateComponentParameters($template, $contact, 'body');
            if (!empty($bodyParameters)) {
                $components[] = [
                    'type' => 'body',
                    'parameters' => $bodyParameters
                ];
            }

            $footerParameters = $this->buildTemplateComponentParameters($template, $contact, 'footer');
            if (!empty($footerParameters)) {
                $components[] = [
                    'type' => 'footer',
                    'parameters' => $footerParameters
                ];
            }

            return $components;
        }

        foreach ($templateComponents as $component) {
            $type = strtolower($component['type'] ?? '');
            if ($type === 'header') {
                $headerParameters = $this->buildTemplateComponentParameters($template, $contact, 'header', $component);
                if (!empty($headerParameters)) {
                    $components[] = [
                        'type' => 'header',
                        'parameters' => $headerParameters
                    ];
                }
            } elseif ($type === 'body') {
                $bodyParameters = $this->buildTemplateComponentParameters($template, $contact, 'body', $component);
                if (!empty($bodyParameters)) {
                    $components[] = [
                        'type' => 'body',
                        'parameters' => $bodyParameters
                    ];
                }
            } elseif ($type === 'footer') {
                $footerParameters = $this->buildTemplateComponentParameters($template, $contact, 'footer', $component);
                if (!empty($footerParameters)) {
                    $components[] = [
                        'type' => 'footer',
                        'parameters' => $footerParameters
                    ];
                }
            }
            // Buttons are not sent unless the Meta template requires dynamic button values.
            // The approved Meta template itself already contains button definitions.
        }

        if ($this->shouldIncludeHeaderMediaComponent($template) && !array_filter($components, fn($component) => strtolower($component['type'] ?? '') === 'header')) {
            $components[] = [
                'type' => 'header',
                'parameters' => [[
                    'type' => 'image',
                    'image' => ['link' => $this->resolveHeaderImageLink($template)]
                ]]
            ];
        }

        return $components;
    }

    public function create(array $data): bool {
        $stmt = $this->db->prepare("
            INSERT INTO templates (name, language, header_variables, body_variables, footer_text, buttons_json) 
            VALUES (:name, :language, :header_variables, :body_variables, :footer_text, :buttons_json)
        ");
        return $stmt->execute([
            'name' => htmlspecialchars($data['name']),
            'language' => htmlspecialchars($data['language'] ?? 'en_US'),
            'header_variables' => $data['header_variables'] ?? null,
            'body_variables' => $data['body_variables'] ?? null,
            'footer_text' => !empty($data['footer_text']) ? htmlspecialchars($data['footer_text']) : null,
            'buttons_json' => !empty($data['buttons_json']) ? json_encode($data['buttons_json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null
        ]);
    }

    public function update(int $id, array $data): bool {
        $stmt = $this->db->prepare("
            UPDATE templates 
            SET name = :name, 
                language = :language, 
                header_variables = :header_variables, 
                body_variables = :body_variables, 
                footer_text = :footer_text, 
                buttons_json = :buttons_json 
            WHERE id = :id
        ");
        return $stmt->execute([
            'id' => $id,
            'name' => htmlspecialchars($data['name']),
            'language' => htmlspecialchars($data['language'] ?? 'en_US'),
            'header_variables' => $data['header_variables'] ?? null,
            'body_variables' => $data['body_variables'] ?? null,
            'footer_text' => !empty($data['footer_text']) ? htmlspecialchars($data['footer_text']) : null,
            'buttons_json' => !empty($data['buttons_json']) ? $data['buttons_json'] : null
        ]);
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM templates WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }

    public function nameExists(string $name, int $excludeId = 0): bool {
        $sql = "SELECT COUNT(*) FROM templates WHERE name = :name";
        $params = ['name' => $name];
        if ($excludeId > 0) {
            $sql .= " AND id != :id";
            $params['id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() > 0;
    }
}
