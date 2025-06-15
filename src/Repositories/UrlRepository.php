<?php

namespace App\Repositories;

use PDO;

class UrlRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->db->prepare("SELECT id FROM urls WHERE name = :name");
        $stmt->bindParam(':name', $name);
        $stmt->execute();
        return $stmt->fetch() ?: null;
    }

    public function insert(string $name): int
    {
        $stmt = $this->db->prepare("INSERT INTO urls (name) VALUES (:name)");
        $stmt->bindParam(':name', $name);
        $stmt->execute();
        return (int)$this->db->lastInsertId();
    }

    public function getAll(): array
    {
        $stmt = $this->db->query("SELECT * FROM urls ORDER BY id DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM urls WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch() ?: null;
    }
}
