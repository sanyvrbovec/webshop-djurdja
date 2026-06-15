<?php
/**
 * ĐurđaShop — PDO wrapper.
 * API namjerno identičan starom shopu (fetch/fetchAll/insert/update/query/pdo)
 * da bi se Fiscalizer i srodne klase mogle prenijeti bez izmjena.
 */

class Database
{
    private PDO $pdo;
    private static ?Database $instance = null;

    public function __construct(string $host, string $name, string $user, string $pass)
    {
        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Uskladi DB vrijeme s hrvatskim (Europe/Zagreb) BEZ obzira gdje je server
        // hostan — inače NOW()/CURRENT_TIMESTAMP mogu biti sat-dva drugačiji od PHP
        // vremena, što je porezno opasno (krivo vrijeme na računu). Offset se računa
        // po trenutnom DST-u pa je točan i ljeti (+02:00) i zimi (+01:00).
        try {
            $offset = (new DateTime('now', new DateTimeZone('Europe/Zagreb')))->format('P');
            $this->pdo->exec("SET time_zone = '{$offset}'");
        } catch (Throwable $e) {
            error_log('[Database] time_zone: ' . $e->getMessage());
        }
    }

    public static function instance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new Database(DB_HOST, DB_NAME, DB_USER, DB_PASS);
        }
        return self::$instance;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchColumn(string $sql, array $params = [])
    {
        $v = $this->query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** INSERT iz asocijativnog niza; vraća lastInsertId. */
    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES ("
             . implode(',', array_fill(0, count($cols), '?')) . ")";
        $this->query($sql, array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    /** UPDATE iz asocijativnog niza. $where koristi imenovane parametre (:id). */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = [];
        $params = [];
        $i = 0;
        foreach ($data as $col => $val) {
            $ph = "set_$i";
            $set[] = "`$col` = :$ph";
            $params[$ph] = $val;
            $i++;
        }
        foreach ($whereParams as $k => $v) {
            $params[ltrim($k, ':')] = $v;
        }
        $sql = "UPDATE `$table` SET " . implode(', ', $set) . " WHERE $where";
        return $this->query($sql, $params)->rowCount();
    }

    public function delete(string $table, string $where, array $whereParams = []): int
    {
        $params = [];
        foreach ($whereParams as $k => $v) $params[ltrim($k, ':')] = $v;
        return $this->query("DELETE FROM `$table` WHERE $where", $params)->rowCount();
    }
}
