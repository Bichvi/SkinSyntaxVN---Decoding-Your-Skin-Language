<?php
// Mongo giả trong bộ nhớ, CHỈ dùng cho test (không DB, không mạng).
// $db->ten_collection trả về FakeCollection (tạo lười).

class FakeCollection
{
    /** @var array<int,array> */
    public array $docs = [];
    public bool $throwOnRead = false;
    /** Bật: mọi insertOne ném lỗi trùng khóa cho tới khi tắt lại. */
    public bool $forceDuplicateOnInsert = false;
    /** Bật: mọi insertOne ném lỗi tạm thời (không phải trùng khóa) cho tới khi tắt lại. */
    public bool $throwOnInsert = false;
    /** Mã lỗi của ngoại lệ trùng khóa (driver thật có thể trả mã khác 11000). */
    public int $duplicateExceptionCode = 11000;
    /** @var array<int,array{keys:array,unique:bool}> */
    public array $indexes = [];

    public function createIndex(array $keys, array $opts = [])
    {
        $this->indexes[] = ['keys' => $keys, 'unique' => !empty($opts['unique'])];
        return 'idx_' . count($this->indexes);
    }

    public function insertOne(array $doc)
    {
        if ($this->throwOnInsert) {
            throw new RuntimeException('FakeCollection: throwOnInsert', 91);
        }
        if ($this->forceDuplicateOnInsert) {
            throw new RuntimeException('E11000 duplicate key error collection: x index: source_key_1', $this->duplicateExceptionCode);
        }
        foreach ($this->indexes as $ix) {
            if (!$ix['unique']) {
                continue;
            }
            foreach ($this->docs as $d) {
                $same = true;
                foreach (array_keys($ix['keys']) as $f) {
                    if (self::str(self::path($d, $f)) !== self::str(self::path($doc, $f))) {
                        $same = false;
                        break;
                    }
                }
                if ($same) {
                    throw new RuntimeException('E11000 duplicate key', 11000);
                }
            }
        }
        $this->docs[] = $doc;
        return (object) ['insertedCount' => 1];
    }

    public function deleteOne(array $filter)
    {
        foreach ($this->docs as $i => $d) {
            if ($this->matches($d, $filter)) {
                array_splice($this->docs, $i, 1);
                return (object) ['deletedCount' => 1];
            }
        }
        return (object) ['deletedCount' => 0];
    }

    public function findOne(array $filter, array $opts = [])
    {
        $this->guardRead();
        $rows = $this->select($filter, $opts);
        return $rows[0] ?? null;
    }

    public function find(array $filter, array $opts = [])
    {
        $this->guardRead();
        return new ArrayIterator($this->select($filter, $opts));
    }

    public function countDocuments(array $filter = [])
    {
        $this->guardRead();
        return count($this->select($filter, []));
    }

    public function updateOne(array $filter, array $update, array $opts = [])
    {
        foreach ($this->docs as $i => $d) {
            if ($this->matches($d, $filter)) {
                $this->docs[$i] = $this->apply($d, $update, false);
                return (object) ['matchedCount' => 1, 'modifiedCount' => 1, 'upsertedCount' => 0];
            }
        }
        if (!empty($opts['upsert'])) {
            $base = [];
            foreach ($filter as $k => $v) {
                if ($k[0] !== '$' && !(is_array($v) && self::isOpArray($v))) {
                    $base[$k] = $v;
                }
            }
            $this->docs[] = $this->apply($base, $update, true);
            return (object) ['matchedCount' => 0, 'modifiedCount' => 0, 'upsertedCount' => 1];
        }
        return (object) ['matchedCount' => 0, 'modifiedCount' => 0, 'upsertedCount' => 0];
    }

    private function guardRead(): void
    {
        if ($this->throwOnRead) {
            throw new RuntimeException('FakeCollection: throwOnRead');
        }
    }

    private function apply(array $doc, array $update, bool $inserting): array
    {
        foreach ($update['$set'] ?? [] as $k => $v) {
            $doc[$k] = $v;
        }
        foreach ($update['$inc'] ?? [] as $k => $v) {
            $doc[$k] = ($doc[$k] ?? 0) + $v;
        }
        if ($inserting) {
            foreach ($update['$setOnInsert'] ?? [] as $k => $v) {
                $doc[$k] = $v;
            }
        }
        return $doc;
    }

    private function select(array $filter, array $opts): array
    {
        $rows = [];
        foreach ($this->docs as $d) {
            if ($this->matches($d, $filter)) {
                $rows[] = $d;
            }
        }
        if (!empty($opts['sort'])) {
            $field = array_key_first($opts['sort']);
            $dir = (int) $opts['sort'][$field];
            if ($dir < 0) {
                $rows = array_reverse($rows); // trùng khóa: mới chèn trước (usort ổn định)
            }
            usort($rows, function ($a, $b) use ($field, $dir) {
                $c = self::cmp(self::path($a, $field), self::path($b, $field));
                return $dir < 0 ? -$c : $c;
            });
        }
        if (isset($opts['limit']) && (int) $opts['limit'] > 0) {
            $rows = array_slice($rows, 0, (int) $opts['limit']);
        }
        return $rows;
    }

    private function matches(array $doc, array $filter): bool
    {
        foreach ($filter as $field => $cond) {
            if ($field === '$or') {
                $any = false;
                foreach ($cond as $sub) {
                    if ($this->matches($doc, $sub)) {
                        $any = true;
                        break;
                    }
                }
                if (!$any) {
                    return false;
                }
                continue;
            }
            $val = self::path($doc, (string) $field);
            if (is_array($cond) && self::isOpArray($cond)) {
                foreach ($cond as $op => $arg) {
                    switch ($op) {
                        case '$in':
                            $hit = false;
                            foreach ($arg as $a) {
                                if (self::str($a) === self::str($val)) {
                                    $hit = true;
                                    break;
                                }
                            }
                            if (!$hit) {
                                return false;
                            }
                            break;
                        case '$gte':
                            if ($val === null || self::cmp($val, $arg) < 0) {
                                return false;
                            }
                            break;
                        case '$lt':
                            if ($val === null || self::cmp($val, $arg) >= 0) {
                                return false;
                            }
                            break;
                        default:
                            throw new RuntimeException('FakeCollection: unsupported operator ' . $op);
                    }
                }
            } elseif (self::str($val) !== self::str($cond)) {
                return false;
            }
        }
        return true;
    }

    private static function isOpArray(array $a): bool
    {
        $k = array_key_first($a);
        return is_string($k) && $k !== '' && $k[0] === '$';
    }

    private static function path(array $doc, string $field)
    {
        $cur = $doc;
        foreach (explode('.', $field) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return null;
            }
            $cur = $cur[$part];
        }
        return $cur;
    }

    private static function str($v): string
    {
        if ($v === null) {
            return "\0null";
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        return (string) $v;
    }

    private static function cmp($a, $b): int
    {
        $sa = self::str($a);
        $sb = self::str($b);
        if (is_numeric($sa) && is_numeric($sb)) {
            return (float) $sa <=> (float) $sb;
        }
        return strcmp($sa, $sb);
    }
}

class FakeDb
{
    /** @var array<string,FakeCollection> */
    private array $collections = [];

    public function __get(string $name): FakeCollection
    {
        return $this->collections[$name] ??= new FakeCollection();
    }

    public function selectCollection(string $name): FakeCollection
    {
        return $this->__get($name);
    }
}
