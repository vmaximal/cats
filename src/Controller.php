<?php

declare(strict_types=1);

namespace Cat;

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

        $litterId = $this->intOrNull($data, 'litter_id');

        if ($litterId !== null) {
            $this->needLitter($litterId);
        }

        $id = $this->db->addCat(
            $name,
            $sex,
            $age,
            $this->textOrNull($data, 'breed', self::MAX_BREED),
            $litterId
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

        if ($litterId !== null) {
            $this->canBeInLitter($id, $litterId);
        }

        if ($sex !== $this->db->getCat($id)['sex'] && $this->hasPups($id)) {
            $this->fail(400, 'у кошки есть помёт или отцы, пол менять нельзя');

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
        $this->needSex($motherId, 'F', 'матерью может быть только самка');

        $id = $this->db->addLitter($motherId, $this->textOrNull($data, 'name', self::MAX_NAME, 'название помёта'));

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
        $this->canBeSire($litterId, $sireId);

        if ($this->isSire($litterId, $sireId)) {
            $this->fail(409, 'этот кот уже отец в этом помёте');

            return;
        }

        $this->db->addSire($litterId, $sireId);

        $this->ok(['litter' => $litterId, 'sires' => $this->db->listSires($litterId)]);
    }

    private function deleteSire(int $litterId, int $sireId): void
    {
        $this->needLitter($litterId);

        if (!$this->isSire($litterId, $sireId)) {
            $this->fail(404, 'кошки с id ' . $sireId . ' в отцах этого помёта нет');

            return;
        }

        $this->db->removeSire($litterId, $sireId);

        $this->ok(['deleted' => $sireId, 'sires' => $this->db->listSires($litterId)]);
    }

    private function canBeInLitter(int $catId, int $litterId): void
    {
        $this->needLitter($litterId);

        $litter = $this->db->getLitter($litterId);
        $motherId = (int) $litter['mother_id'];

        if ($motherId === $catId) {
            $this->fail(400, 'мать не может быть котёнком своего помёта');

            exit;
        }

        if ($this->isSire($litterId, $catId)) {
            $this->fail(400, 'котёнок не может быть отцом своего помёта');

            exit;
        }

        if ($this->db->isAncestor($catId, $motherId)) {
            $this->fail(400, 'кошка окажется потомком самой себя');

            exit;
        }
    }

    private function canBeSire(int $litterId, int $sireId): void
    {
        $litter = $this->db->getLitter($litterId);
        $cat    = $this->db->getCat($sireId);

        if ($cat['sex'] !== 'M') {
            $this->fail(400, 'отцом может быть только самец');

            exit;
        }

        if ((int) $litter['mother_id'] === $sireId) {
            $this->fail(400, 'мать не может быть отцом своего помёта');

            exit;
        }

        if ((int) ($cat['litter_id'] ?? 0) === $litterId) {
            $this->fail(400, 'котёнок не может быть отцом своего помёта');

            exit;
        }
    }

    private function needSex(int $catId, string $sex, string $message): void
    {
        if ($this->db->getCat($catId)['sex'] !== $sex) {
            $this->fail(400, $message);

            exit;
        }
    }

    private function isSire(int $litterId, int $sireId): bool
    {
        foreach ($this->db->listSires($litterId) as $sire) {
            if ((int) $sire['id'] === $sireId) {
                return true;
            }
        }

        return false;
    }

    private function hasPups(int $catId): bool
    {
        return $this->db->listLitters($catId) !== [] || $this->db->isSireAnywhere($catId);
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

    private function string(array $data, string $key, int $max, ?string $label = null): string
    {
        $value = trim((string) ($this->textOrNull($data, $key, $max, $label) ?? ''));

        if ($value === '') {
            $this->fail(400, 'поле ' . ($label ?? $this->label($key)) . ' обязательное');

            exit;
        }

        return $value;
    }

    private function textOrNull(array $data, string $key, int $max, ?string $label = null): ?string
    {
        if (!isset($data[$key]) || $data[$key] === '') {
            return null;
        }

        $value = trim((string) $data[$key]);

        if (mb_strlen($value) > $max) {
            $this->fail(400, 'поле ' . ($label ?? $this->label($key)) . ' не длиннее ' . $max . ' символов');

            exit;
        }

        return $value;
    }

    private function int(array $data, string $key): int
    {
        $value = $this->intOrNull($data, $key);

        if ($value === null) {
            $this->fail(400, 'поле ' . $this->label($key) . ' обязательное и должно быть числом');

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
        if (!isset($data[$key]) || $data[$key] === '') {
            return null;
        }

        if (!is_numeric($data[$key]) || (int) $data[$key] != $data[$key]) {
            $this->fail(400, 'поле ' . $this->label($key) . ' должно быть целым числом');

            exit;
        }

        return (int) $data[$key];
    }

    private function label(string $key): string
    {
        $labels = [
            'name' => 'кличка',
            'sex' => 'пол',
            'age' => 'возраст',
            'breed' => 'порода',
            'mother_id' => 'мать',
            'litter_id' => 'помёт',
            'sire_id' => 'отец',
        ];

        return $labels[$key] ?? $key;
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
