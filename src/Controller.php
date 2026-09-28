<?php

declare(strict_types=1);

namespace Maxim\Cats;

use PDOException;

class Controller
{
    private const MAX_AGE = 30;
    private const MAX_NAME = 60;
    private const MAX_BREED = 80;

    private DB $db;

    private array $routes = [
        'GET    #^/api/cats$#'                      => 'listCats',
        'POST   #^/api/cats$#'                      => 'addCat',
        'GET    #^/api/cats/(\d+)$#'                => 'showCat',
        'PUT    #^/api/cats/(\d+)$#'                => 'updateCat',
        'DELETE #^/api/cats/(\d+)$#'                => 'deleteCat',
        'GET    #^/api/litters$#'                   => 'listLitters',
        'POST   #^/api/litters$#'                   => 'addLitter',
        'GET    #^/api/litters/(\d+)$#'             => 'showLitter',
        'DELETE #^/api/litters/(\d+)$#'             => 'deleteLitter',
        'POST   #^/api/litters/(\d+)/sires$#'       => 'addSire',
        'DELETE #^/api/litters/(\d+)/sires/(\d+)$#' => 'deleteSire',
    ];

    public function __construct(DB $db)
    {
        $this->db = $db;
    }

    public function handle(string $method, string $uri): void
    {
        $path = '/' . trim((string) parse_url($uri, PHP_URL_PATH), '/');
        $path = rawurldecode($path);

        $route = null;
        $args  = [];

        foreach ($this->routes as $key => $handler) {
            [$routeMethod, $pattern] = explode(' ', $key, 2);

            if ($routeMethod !== $method) {
                continue;
            }

            if (preg_match($pattern, $path, $found)) {
                $route = $handler;
                $args  = array_map('intval', array_slice($found, 1));
                break;
            }
        }

        if ($route === null) {
            foreach ($this->routes as $key => $handler) {
                [, $pattern] = explode(' ', $key, 2);

                if (preg_match($pattern, $path)) {
                    $this->fail(405, 'метод ' . $method . ' тут не поддерживается');

                    return;
                }
            }

            $this->fail(404, 'нет такого эндпоинта: ' . $path);

            return;
        }

        try {
            $this->$route(...$args);
        } catch (PDOException $e) {
            $this->fail(500, 'ошибка базы: ' . $e->getMessage());
        }
    }

    private function listCats(): void
    {
        $cats = $this->db->listCats(
            $this->query('sex'),
            $this->queryInt('min_age'),
            $this->queryInt('max_age'),
            $this->queryInt('litter_id')
        );

        $this->ok(['cats' => $cats, 'total' => count($cats)]);
    }

    private function showCat(int $id): void
    {
        $cat = $this->db->getCat($id);

        if ($cat === null) {
            $this->fail(404, 'кошки с id ' . $id . ' нет');

            return;
        }

        $parents = $this->db->getParents($id);

        $this->ok([
            'cat'     => $cat,
            'mother'  => $parents['mother'],
            'sires'   => $parents['sires'],
            'kittens' => $this->db->listKittens($id),
        ]);
    }

    private function addCat(): void
    {
        $data = $this->input();

        $name = $this->string($data, 'name', self::MAX_NAME);
        $sex  = $this->string($data, 'sex', 1);
        $age  = $this->age($data);

        if (!in_array($sex, ['M', 'F'], true)) {
            $this->fail(400, 'sex должен быть M или F');

            return;
        }

        $id = $this->db->addCat(
            $name,
            $sex,
            $age,
            $this->textOrNull($data, 'breed', self::MAX_BREED),
            $this->intOrNull($data, 'litter_id')
        );

        $this->ok(['id' => $id], 201);
    }

    private function updateCat(int $id): void
    {
        if ($this->db->getCat($id) === null) {
            $this->fail(404, 'кошки с id ' . $id . ' нет');

            return;
        }

        $data = $this->input();

        $name = $this->string($data, 'name', self::MAX_NAME);
        $sex  = $this->string($data, 'sex', 1);
        $age  = $this->age($data);

        if (!in_array($sex, ['M', 'F'], true)) {
            $this->fail(400, 'sex должен быть M или F');

            return;
        }

        $litterId = $this->intOrNull($data, 'litter_id');

        if ($litterId !== null && $this->db->getLitter($litterId) === null) {
            $this->fail(404, 'помёта с id ' . $litterId . ' нет');

            return;
        }

        $this->db->updateCat(
            $id,
            $name,
            $sex,
            $age,
            $this->textOrNull($data, 'breed', self::MAX_BREED),
            $litterId
        );

        $this->ok(['cat' => $this->db->getCat($id)]);
    }

    private function deleteCat(int $id): void
    {
        $this->needCat($id);

        $this->db->deleteCat($id);

        $this->ok(['deleted' => $id]);
    }

    private function listLitters(): void
    {
        $litters = $this->db->listLitters($this->queryInt('mother_id'));

        $this->ok(['litters' => $litters, 'total' => count($litters)]);
    }

    private function showLitter(int $id): void
    {
        $litter = $this->db->getLitter($id);

        if ($litter === null) {
            $this->fail(404, 'помёта с id ' . $id . ' нет');

            return;
        }

        $this->ok([
            'litter'  => $litter,
            'mother'  => $this->db->getCat((int) $litter['mother_id']),
            'sires'   => $this->db->listSires($id),
            'kittens' => $this->db->listCats(null, null, null, $id),
        ]);
    }

    private function addLitter(): void
    {
        $data     = $this->input();
        $motherId = $this->int($data, 'mother_id');

        $this->needCat($motherId);

        $id = $this->db->addLitter($motherId, $this->textOrNull($data, 'name', self::MAX_NAME));

        $this->ok(['id' => $id], 201);
    }

    private function deleteLitter(int $id): void
    {
        if ($this->db->getLitter($id) === null) {
            $this->fail(404, 'помёта с id ' . $id . ' нет');

            return;
        }

        $this->db->deleteLitter($id);

        $this->ok(['deleted' => $id]);
    }

    private function addSire(int $litterId): void
    {
        $this->needLitter($litterId);

        $data   = $this->input();
        $sireId = $this->int($data, 'sire_id');

        $this->needCat($sireId);

        $this->db->addSire($litterId, $sireId);

        $this->ok(['litter' => $litterId, 'sires' => $this->db->listSires($litterId)]);
    }

    private function deleteSire(int $litterId, int $sireId): void
    {
        $this->needLitter($litterId);

        $this->db->removeSire($litterId, $sireId);

        $this->ok(['deleted' => $sireId, 'sires' => $this->db->listSires($litterId)]);
    }

    private function ok(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function fail(int $code, string $message): void
    {
        $this->ok(['error' => $message], $code);
    }

    private function input(): array
    {
        $raw = file_get_contents('php://input');

        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            $this->fail(400, 'тело запроса должно быть json');

            exit;
        }

        return $data;
    }

    private function needCat(int $id): void
    {
        if ($this->db->getCat($id) === null) {
            $this->fail(404, 'кошки с id ' . $id . ' нет');

            exit;
        }
    }

    private function needLitter(int $id): void
    {
        if ($this->db->getLitter($id) === null) {
            $this->fail(404, 'помёта с id ' . $id . ' нет');

            exit;
        }
    }

    private function string(array $data, string $key, int $max): string
    {
        $value = trim((string) ($this->textOrNull($data, $key, $max) ?? ''));

        if ($value === '') {
            $this->fail(400, 'поле ' . $key . ' обязательное');

            exit;
        }

        return $value;
    }

    private function textOrNull(array $data, string $key, int $max): ?string
    {
        if (!isset($data[$key]) || $data[$key] === '') {
            return null;
        }

        $value = trim((string) $data[$key]);

        if (mb_strlen($value) > $max) {
            $this->fail(400, 'поле ' . $key . ' не длиннее ' . $max . ' символов');

            exit;
        }

        return $value;
    }

    private function int(array $data, string $key): int
    {
        $value = $this->intOrNull($data, $key);

        if ($value === null) {
            $this->fail(400, 'поле ' . $key . ' обязательное и должно быть числом');

            exit;
        }

        return $value;
    }

    private function age(array $data): int
    {
        $age = $this->int($data, 'age');

        if ($age < 0 || $age > self::MAX_AGE) {
            $this->fail(400, 'возраст должен быть от 0 до ' . self::MAX_AGE . ' лет');

            exit;
        }

        return $age;
    }

    private function intOrNull(array $data, string $key): ?int
    {
        if (!isset($data[$key]) || $data[$key] === '' || !is_numeric($data[$key])) {
            return null;
        }

        return (int) $data[$key];
    }

    private function query(string $key): ?string
    {
        $value = $_GET[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    private function queryInt(string $key): ?int
    {
        $value = $this->query($key);

        return $value === null || !is_numeric($value) ? null : (int) $value;
    }
}
