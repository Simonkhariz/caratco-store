<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;

class CategoryRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get all active categories with product counts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllActive(): array
    {
        $sql = "SELECT c.id, c.name, c.slug, c.description, c.image_url, c.display_order,
                       COUNT(p.id) AS product_count
                FROM categories c
                LEFT JOIN products p ON p.category_id = c.id AND p.status = 'published'
                WHERE c.is_active = 1
                GROUP BY c.id
                ORDER BY c.display_order ASC, c.name ASC";

        $rows = $this->db->fetchAll($sql);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['display_order'] = (int) $row['display_order'];
            $row['product_count'] = (int) $row['product_count'];
        }
        unset($row);
        return $rows;
    }

    /**
     * Every category, active or not, with total and published product counts (admin list).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllForAdmin(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT c.id, c.name, c.slug, c.description, c.image_url, c.display_order, c.is_active,
                    COUNT(p.id) AS product_count,
                    COALESCE(SUM(p.status = 'published'), 0) AS published_count
             FROM categories c
             LEFT JOIN products p ON p.category_id = c.id
             GROUP BY c.id
             ORDER BY c.is_active DESC, c.display_order ASC, c.name ASC"
        );
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['display_order'] = (int) $row['display_order'];
            $row['is_active'] = (bool) $row['is_active'];
            $row['product_count'] = (int) $row['product_count'];
            $row['published_count'] = (int) $row['published_count'];
        }
        unset($row);
        return $rows;
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM categories WHERE id = ? LIMIT 1", "i", [$id]);
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->db->fetchOne("SELECT * FROM categories WHERE slug = ? LIMIT 1", "s", [$slug]);
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO categories (name, slug, description, image_url, display_order, is_active)
                VALUES (?, ?, ?, ?, ?, ?)";
        return (int) $this->db->insert(
            $sql,
            "ssssii",
            [
                $data['name'],
                $data['slug'],
                $data['description'] ?? null,
                $data['image_url'] ?? null,
                $data['display_order'] ?? 0,
                $data['is_active'] ?? 1,
            ]
        );
    }

    public function update(int $id, array $data): bool
    {
        $sql = "UPDATE categories
                SET name = ?, slug = ?, description = ?, image_url = ?, display_order = ?, is_active = ?
                WHERE id = ?";
        $this->db->execute(
            $sql,
            "ssssiii",
            [
                $data['name'],
                $data['slug'],
                $data['description'] ?? null,
                $data['image_url'] ?? null,
                $data['display_order'] ?? 0,
                $data['is_active'] ?? 1,
                $id,
            ]
        );
        return true;
    }

    public function delete(int $id): bool
    {
        $this->db->execute("UPDATE categories SET is_active = 0 WHERE id = ?", "i", [$id]);
        return true;
    }
}
