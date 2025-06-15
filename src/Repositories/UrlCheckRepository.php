<?php

namespace App\Repositories;

use PDO;

class UrlCheckRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getLatestChecks(): array
    {
        $stmt = $this->db->query("
            SELECT DISTINCT ON (url_id) *
            FROM url_checks
            ORDER BY url_id, created_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByUrlId(int $urlId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM url_checks WHERE url_id = :url_id ORDER BY id DESC");
        $stmt->bindParam(':url_id', $urlId);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function insert(array $data): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at)
            VALUES (:url_id, :status_code, :h1, :title, :description, :created_at)
        ");
        $stmt->execute($data);
    }
}
