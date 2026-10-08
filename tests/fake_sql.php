<?php
// Minimal stand-in for HerikaServer's sql class (lib/postgresql.class.php), for local tests only.
class sql
{
    public $conn;
    public function __construct(string $dsn) { $this->conn = pg_connect($dsn); }
    public function fetchAll($q, $log = false)
    {
        $r = pg_query($this->conn, $q);
        if (!$r) throw new Exception(pg_last_error($this->conn) . " in $q");
        return pg_fetch_all($r) ?: [];
    }
    public function fetchOne($q, array $params = [])
    {
        $r = $params ? pg_query_params($this->conn, $q, $params) : pg_query($this->conn, $q);
        if (!$r) { fwrite(STDERR, pg_last_error($this->conn) . " in $q\n"); return []; }
        return pg_fetch_assoc($r) ?: [];
    }
    public function escapeLiteral($s) { return pg_escape_literal($this->conn, (string)$s); }
    public function escape($s) { return pg_escape_string($this->conn, (string)$s); }
    public function insert($table, $data)
    {
        $cols = implode(',', array_keys($data));
        $vals = implode(',', array_map(fn($v) => is_int($v) ? $v : $this->escapeLiteral($v), array_values($data)));
        return $this->fetchAll("INSERT INTO $table ($cols) VALUES ($vals)") !== null;
    }
}
