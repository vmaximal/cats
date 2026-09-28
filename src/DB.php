<?php

declare(strict_types=1);

namespace Maxim\Cats;

use PDO;

class DB
{
    private PDO $pdo;

    public function __construct(string $file = __DIR__ . '/../database.db')
    {
        $this->pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    public function addCat(string $name, string $sex, int $age, ?string $breed = null, ?int $litterId = null): int
    {
        $query = $this->pdo->prepare(
            'INSERT INTO cats (name, sex, age, breed, litter_id)
             VALUES (:name, :sex, :age, :breed, :litter_id)'
        );

        $query->execute([
            'name'      => $name,
            'sex'       => $sex,
            'age'       => $age,
            'breed'     => $breed,
            'litter_id' => $litterId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateCat(int $id, string $name, string $sex, int $age, ?string $breed = null, ?int $litterId = null): void
    {
        $query = $this->pdo->prepare(
            'UPDATE cats
             SET name = :name, sex = :sex, age = :age, breed = :breed, litter_id = :litter_id
             WHERE id = :id'
        );

        $query->execute([
            'id'        => $id,
            'name'      => $name,
            'sex'       => $sex,
            'age'       => $age,
            'breed'     => $breed,
            'litter_id' => $litterId,
        ]);
    }

    public function deleteCat(int $id): void
    {
        $this->pdo->beginTransaction();

        try {
            $query = $this->pdo->prepare(
                'UPDATE cats SET litter_id = NULL
                 WHERE litter_id IN (SELECT id FROM litters WHERE mother_id = :id)'
            );
            $query->execute(['id' => $id]);

            $query = $this->pdo->prepare('DELETE FROM litter_sires WHERE sire_id = :id');
            $query->execute(['id' => $id]);

            $query = $this->pdo->prepare(
                'DELETE FROM litter_sires
                 WHERE litter_id IN (SELECT id FROM litters WHERE mother_id = :id)'
            );
            $query->execute(['id' => $id]);

            $query = $this->pdo->prepare('DELETE FROM litters WHERE mother_id = :id');
            $query->execute(['id' => $id]);

            $query = $this->pdo->prepare('DELETE FROM cats WHERE id = :id');
            $query->execute(['id' => $id]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    public function getCat(int $id): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM cats WHERE id = :id');
        $query->execute(['id' => $id]);

        return $query->fetch() ?: null;
    }

    public function listCats(?string $sex = null, ?int $minAge = null, ?int $maxAge = null, ?int $litterId = null): array
    {
        $where = [];
        $params = [];

        if ($sex !== null) {
            $where[] = 'sex = :sex';
            $params['sex'] = $sex;
        }

        if ($minAge !== null) {
            $where[] = 'age >= :min_age';
            $params['min_age'] = $minAge;
        }

        if ($maxAge !== null) {
            $where[] = 'age <= :max_age';
            $params['max_age'] = $maxAge;
        }

        if ($litterId !== null) {
            $where[] = 'litter_id = :litter_id';
            $params['litter_id'] = $litterId;
        }

        $sql = 'SELECT * FROM cats';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY name';

        $query = $this->pdo->prepare($sql);
        $query->execute($params);

        return $query->fetchAll();
    }

    public function addLitter(int $motherId, ?string $name = null): int
    {
        $query = $this->pdo->prepare('INSERT INTO litters (name, mother_id) VALUES (:name, :mother_id)');
        $query->execute(['name' => $name, 'mother_id' => $motherId]);

        return (int) $this->pdo->lastInsertId();
    }

    public function getLitter(int $id): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM litters WHERE id = :id');
        $query->execute(['id' => $id]);

        return $query->fetch() ?: null;
    }

    public function listLitters(?int $motherId = null): array
    {
        if ($motherId === null) {
            return $this->pdo->query('SELECT * FROM litters ORDER BY id')->fetchAll();
        }

        $query = $this->pdo->prepare('SELECT * FROM litters WHERE mother_id = :mother_id ORDER BY id');
        $query->execute(['mother_id' => $motherId]);

        return $query->fetchAll();
    }

    public function deleteLitter(int $id): void
    {
        $this->pdo->beginTransaction();

        try {
            $query = $this->pdo->prepare('UPDATE cats SET litter_id = NULL WHERE litter_id = :id');
            $query->execute(['id' => $id]);

            $query = $this->pdo->prepare('DELETE FROM litter_sires WHERE litter_id = :id');
            $query->execute(['id' => $id]);

            $query = $this->pdo->prepare('DELETE FROM litters WHERE id = :id');
            $query->execute(['id' => $id]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    public function addSire(int $litterId, int $sireId): void
    {
        $query = $this->pdo->prepare(
            'INSERT OR IGNORE INTO litter_sires (litter_id, sire_id) VALUES (:litter_id, :sire_id)'
        );
        $query->execute(['litter_id' => $litterId, 'sire_id' => $sireId]);
    }

    public function removeSire(int $litterId, int $sireId): void
    {
        $query = $this->pdo->prepare('DELETE FROM litter_sires WHERE litter_id = :litter_id AND sire_id = :sire_id');
        $query->execute(['litter_id' => $litterId, 'sire_id' => $sireId]);
    }

    public function listSires(int $litterId): array
    {
        $query = $this->pdo->prepare(
            'SELECT cats.* FROM cats
             JOIN litter_sires ON litter_sires.sire_id = cats.id
             WHERE litter_sires.litter_id = :litter_id
             ORDER BY cats.name'
        );
        $query->execute(['litter_id' => $litterId]);

        return $query->fetchAll();
    }

    public function getParents(int $catId): array
    {
        $cat = $this->getCat($catId);
        $litterId = $cat['litter_id'] ?? null;

        if (!$litterId) {
            return ['mother' => null, 'sires' => []];
        }

        $litter = $this->getLitter((int) $litterId);
        if ($litter === null) {
            return ['mother' => null, 'sires' => []];
        }

        return [
            'mother' => $this->getCat((int) $litter['mother_id']),
            'sires'  => $this->listSires((int) $litter['id']),
        ];
    }

    public function listKittens(int $motherId): array
    {
        $query = $this->pdo->prepare(
            'SELECT cats.* FROM cats
             JOIN litters ON litters.id = cats.litter_id
             WHERE litters.mother_id = :mother_id
             ORDER BY cats.name'
        );
        $query->execute(['mother_id' => $motherId]);

        return $query->fetchAll();
    }

    public function listKittensBySire(int $sireId): array
    {
        $query = $this->pdo->prepare(
            'SELECT cats.* FROM cats
             JOIN litter_sires ON litter_sires.litter_id = cats.litter_id
             WHERE litter_sires.sire_id = :sire_id
             ORDER BY cats.name'
        );
        $query->execute(['sire_id' => $sireId]);

        return $query->fetchAll();
    }
}
