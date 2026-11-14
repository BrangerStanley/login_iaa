<?php
declare(strict_types=1);

/**
 * ============================================================================
 * PROYECTO IAA — CAPA DE COMPATIBILIDAD PDO CON API DE mysqli
 * Archivo: config/Database.php
 * ============================================================================
 *
 * PROPÓSITO
 * ---------
 * El sistema tiene 2.577 puntos de llamada con la API de mysqli repartidos en
 * 290 archivos. Reescribirlos todos de golpe sería un trabajo de semanas y con
 * alto riesgo de regresión en un sistema clínico en producción.
 *
 * Esta capa expone la MISMA API que mysqli (bind_param, get_result,
 * fetch_assoc, insert_id, num_rows, …) pero por debajo habla PostgreSQL con
 * PDO nativo. Los archivos existentes siguen funcionando sin cambios y la
 * migración de cada archivo a PDO puro puede hacerse después, de forma
 * incremental y verificable, sin bloquear la puesta en marcha.
 *
 * DIFERENCIAS DELIBERADAS CON mysqli (documentadas, no accidentales)
 * -----------------------------------------------------------------
 *  1. ERRSEMÁNTICA. mysqli devuelve false y deja el mensaje en ->error. Se
 *     reproduce ese contrato: prepare()/query()/execute() devuelven false en
 *     lugar de lanzar excepción, porque el código existente comprueba
 *     `if (!$stmt)`. Las excepciones de PDO se capturan y se convierten.
 *
 *  2. BOOLEANOS. PostgreSQL devuelve 't'/'f'. El código fue escrito contra
 *     MariaDB, donde un tinyint(1) llega como '0'/'1', y hace comparaciones del
 *     tipo `$fila['leida'] == 1`. En PHP 8, 'f' == 0 es FALSE, así que devolver
 *     't'/'f' rompería la lógica. Se traducen de vuelta a 1/0.
 *
 *  3. RESULTADOS EN MEMORIA. get_result() no existe en PDO; se emula
 *     almacenando las filas al ejecutar. mysqli también bufferiza por omisión,
 *     así que el comportamiento observable es equivalente.
 *
 *  4. CONJUNTO DE CARACTERES. PostgreSQL es UTF-8 de forma nativa;
 *     set_charset() es una operación nula que devuelve true para no romper las
 *     llamadas existentes.
 *
 *  5. VALIDACIÓN DE prepare(). pdo_pgsql no analiza la sentencia hasta
 *     execute(), mientras que mysqli la validaba en prepare(). Por tanto, ante
 *     una consulta inválida, prepare() devuelve un objeto y es execute() quien
 *     devuelve false (con el motivo en ->error). No afecta al funcionamiento
 *     normal —el SQL válido se ejecuta igual— pero conviene saberlo al depurar.
 * ============================================================================
 */

// Constantes de mysqli, por si la extensión no estuviera cargada.
if (!defined('MYSQLI_ASSOC')) {
    define('MYSQLI_ASSOC', 1);
}
if (!defined('MYSQLI_NUM')) {
    define('MYSQLI_NUM', 2);
}
if (!defined('MYSQLI_BOTH')) {
    define('MYSQLI_BOTH', 3);
}

/**
 * Conjunto de resultados bufferizado, con la API de mysqli_result.
 */
final class IaaResult
{
    /** @var array<int, array<string, mixed>> */
    private array $rows;
    private int $pos = 0;

    /** Propiedad pública: el código lee $res->num_rows. */
    public int $num_rows = 0;
    public int $field_count = 0;

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(array $rows, int $fieldCount = 0)
    {
        $this->rows        = $rows;
        $this->num_rows    = count($rows);
        $this->field_count = $fieldCount ?: (isset($rows[0]) ? count($rows[0]) : 0);
    }

    public function fetch_assoc(): ?array
    {
        if (!isset($this->rows[$this->pos])) {
            return null;
        }
        return $this->rows[$this->pos++];
    }

    public function fetch_row(): ?array
    {
        if (!isset($this->rows[$this->pos])) {
            return null;
        }
        return array_values($this->rows[$this->pos++]);
    }

    public function fetch_array(int $mode = MYSQLI_BOTH): ?array
    {
        if (!isset($this->rows[$this->pos])) {
            return null;
        }
        $fila = $this->rows[$this->pos++];
        if ($mode === MYSQLI_ASSOC) {
            return $fila;
        }
        if ($mode === MYSQLI_NUM) {
            return array_values($fila);
        }
        // MYSQLI_BOTH: claves asociativas y numéricas a la vez.
        $mixto = [];
        $i = 0;
        foreach ($fila as $k => $v) {
            $mixto[$k] = $v;
            $mixto[$i] = $v;
            $i++;
        }
        return $mixto;
    }

    /**
     * @return array<int, mixed>
     */
    public function fetch_all(int $mode = MYSQLI_ASSOC): array
    {
        $resto = array_slice($this->rows, $this->pos);
        $this->pos = count($this->rows);

        if ($mode === MYSQLI_ASSOC) {
            return $resto;
        }
        if ($mode === MYSQLI_NUM) {
            return array_map('array_values', $resto);
        }
        $salida = [];
        foreach ($resto as $fila) {
            $mixto = [];
            $i = 0;
            foreach ($fila as $k => $v) {
                $mixto[$k] = $v;
                $mixto[$i] = $v;
                $i++;
            }
            $salida[] = $mixto;
        }
        return $salida;
    }

    public function fetch_object(string $clase = 'stdClass'): ?object
    {
        $fila = $this->fetch_assoc();
        if ($fila === null) {
            return null;
        }
        return (object)$fila;
    }

    public function data_seek(int $n): bool
    {
        $this->pos = max(0, min($n, $this->num_rows));
        return true;
    }

    public function free(): void
    {
        // El resultado ya está en memoria; no hay recurso que liberar.
    }

    public function close(): void
    {
        $this->free();
    }
}

/**
 * Sentencia preparada con la API de mysqli_stmt.
 */
final class IaaStatement
{
    private IaaConnection $conn;
    private PDOStatement $st;
    private string $sql = '';
    private string $types = '';

    /** @var array<int, mixed> Referencias a las variables enlazadas. */
    private array $params = [];

    /** @var array<int, mixed> Referencias para bind_result()/fetch(). */
    private array $resultVars = [];

    /** @var array<int, array<string,mixed>> Filas ya bufferizadas. */
    private array $rows = [];
    private int $pos = 0;
    private bool $ejecutada = false;

    public string $error = '';
    public int $errno = 0;
    public int $affected_rows = 0;
    public int $num_rows = 0;
    public int $insert_id = 0;

    public function __construct(IaaConnection $conn, PDOStatement $st, string $sql = '')
    {
        $this->conn = $conn;
        $this->st   = $st;
        $this->sql  = $sql;
    }

    /**
     * Enlaza variables por referencia, igual que mysqli_stmt::bind_param().
     *
     * Los valores se leen en el momento de execute(), de modo que si el código
     * modifica la variable entre bind_param() y execute() —patrón que usa el
     * sistema— se envía el valor vigente, no el del enlace.
     */
    public function bind_param(string $types, mixed &...$vars): bool
    {
        if (strlen($types) !== count($vars)) {
            $this->error = 'bind_param: el número de tipos no coincide con el de variables';
            $this->errno = 2031;
            return false;
        }
        $this->types  = $types;
        $this->params = $vars;
        return true;
    }

    public function execute(?array $params = null): bool
    {
        try {
            $valores = $params ?? $this->params;

            if ($valores) {
                foreach ($valores as $i => $v) {
                    $tipo = ($params === null && isset($this->types[$i])) ? $this->types[$i] : 's';

                    if ($v === null) {
                        $this->st->bindValue($i + 1, null, PDO::PARAM_NULL);
                        continue;
                    }
                    switch ($tipo) {
                        case 'i':
                            $this->st->bindValue($i + 1, (int)$v, PDO::PARAM_INT);
                            break;
                        case 'b':
                            $this->st->bindValue($i + 1, $v, PDO::PARAM_LOB);
                            break;
                        case 'd':
                            // PDO no tiene PARAM_FLOAT; se envía como cadena para
                            // no perder precisión y que PostgreSQL haga el cast.
                            $this->st->bindValue($i + 1, (string)$v, PDO::PARAM_STR);
                            break;
                        default:
                            $this->st->bindValue($i + 1, (string)$v, PDO::PARAM_STR);
                    }
                }
            }

            $ok = $this->st->execute();
            $this->ejecutada    = true;
            $this->affected_rows = $this->st->rowCount();

            // mysqli expone affected_rows en la CONEXIÓN, no solo en la
            // sentencia. El código existente lee $conexion->affected_rows
            // después de execute(), así que hay que propagarlo.
            $this->conn->affected_rows = $this->affected_rows;

            // insert_id SOLO se consulta tras un INSERT real. pdo_pgsql
            // implementa lastInsertId() con SELECT lastval(); si la sesión aún
            // no usó una secuencia, lastval() falla y PostgreSQL ABORTA la
            // transacción en curso, aunque la excepción se capture. Por eso no
            // se consulta en SELECT/UPDATE/DELETE.
            $esInsert = (bool)preg_match('/^\s*(INSERT|REPLACE)\b/i', $this->sql);
            if ($esInsert && $ok && $this->affected_rows > 0) {
                $this->conn->ultimaFueInsert = true;
                try {
                    $this->insert_id = (int)$this->conn->pdo()->lastInsertId();
                } catch (Throwable) {
                    $this->insert_id = 0;
                }
            } else {
                $this->insert_id = 0;
            }

            // Bufferizado inmediato: PDO no ofrece get_result(), así que las
            // filas se materializan aquí para get_result()/num_rows/fetch().
            $this->rows = $this->st->fetchAll(PDO::FETCH_ASSOC);
            $this->rows = IaaConnection::normalizarFilas($this->rows);
            $this->num_rows = count($this->rows);
            $this->pos = 0;

            return $ok;
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            $this->errno = (int)($e->errorInfo[1] ?? 0);
            $this->conn->registrarError($e);
            return false;
        }
    }

    public function get_result(): IaaResult|false
    {
        if (!$this->ejecutada) {
            $this->error = 'get_result: la sentencia no se ha ejecutado';
            $this->errno = 2014;
            return false;
        }
        return new IaaResult($this->rows, $this->st->columnCount());
    }

    public function store_result(): bool
    {
        return $this->ejecutada;
    }

    /**
     * Enlaza variables de salida por referencia (estilo mysqli clásico).
     */
    public function bind_result(mixed &...$vars): bool
    {
        $this->resultVars = $vars;
        $this->pos = 0;
        return true;
    }

    /**
     * Avanza a la siguiente fila y la asigna a las variables de bind_result().
     */
    public function fetch(): ?bool
    {
        if (!isset($this->rows[$this->pos])) {
            return null;
        }
        $fila = $this->rows[$this->pos++];
        $valores = array_values($fila);
        foreach ($this->resultVars as $i => &$var) {
            $var = $valores[$i] ?? null;
        }
        unset($var);
        return true;
    }

    public function close(): bool
    {
        $this->rows = [];
        $this->params = [];
        return true;
    }

    public function reset(): bool
    {
        $this->pos = 0;
        return true;
    }

    public function free_result(): void
    {
        $this->rows = [];
        $this->num_rows = 0;
    }
}

/**
 * Conexión con la API pública de mysqli.
 */
final class IaaConnection
{
    private PDO $pdo;

    /** Propiedades públicas que el código consulta directamente. */
    public string $error = '';
    public int $errno = 0;
    public int $affected_rows = 0;
    public ?string $connect_error = null;
    public string $server_info = '';
    public string $client_info = '';
    public int $thread_id = 0;

    /** ¿La última operación que usó una secuencia fue un INSERT? */
    public bool $ultimaFueInsert = false;

    /**
     * @param array{host:string,port:int,db:string,user:string,pass:string,sslmode?:string} $cfg
     */
    public function __construct(array $cfg)
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['db']
        );
        // sslmode: en Windows/XAMPP local el servidor no tiene TLS; en Linux se
        // exige verify-full. Nunca se deja en 'disable' por omisión.
        $sslmode = $cfg['sslmode'] ?? 'prefer';
        $dsn .= ';sslmode=' . $sslmode;

        $this->pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);

        // Zona horaria de la sesión: garantiza que TIMESTAMPTZ se lea y escriba
        // en hora de Venezuela, que es la que el sistema siempre mostró.
        $this->pdo->exec("SET TIME ZONE 'America/Caracas'");
        $this->pdo->exec('SET search_path = public');

        $this->server_info = (string)$this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $this->client_info = (string)$this->pdo->getAttribute(PDO::ATTR_CLIENT_VERSION);
        $this->thread_id   = (int)$this->pdo->query('SELECT pg_backend_pid()')->fetchColumn();
    }

    /**
     * Acceso a insert_id como propiedad ($conexion->insert_id), igual que mysqli.
     */
    public function __get(string $nombre): mixed
    {
        if ($nombre === 'insert_id') {
            // No invocar lastval() si la sesión no ha insertado: abortaría la
            // transacción en curso (ver IaaStatement::execute()).
            if (!$this->ultimaFueInsert) {
                return 0;
            }
            try {
                return (int)$this->pdo->lastInsertId();
            } catch (Throwable) {
                return 0;
            }
        }
        return null;
    }

    public function __isset(string $nombre): bool
    {
        return $nombre === 'insert_id';
    }

    /**
     * Convierte 't'/'f' de PostgreSQL al 1/0 que el sistema espera de MariaDB.
     *
     * @param array<int, array<string,mixed>> $filas
     * @return array<int, array<string,mixed>>
     */
    public static function normalizarFilas(array $filas): array
    {
        foreach ($filas as &$fila) {
            foreach ($fila as $col => $v) {
                if ($v === true) {
                    $fila[$col] = 1;
                } elseif ($v === false) {
                    $fila[$col] = 0;
                }
            }
        }
        unset($fila);
        return $filas;
    }

    public function registrarError(PDOException $e): void
    {
        $this->error = $e->getMessage();
        $this->errno = (int)($e->errorInfo[1] ?? 0);
    }

    public function query(string $sql): IaaResult|false
    {
        try {
            $st = $this->pdo->query($sql);
            if ($st === false) {
                return false;
            }
            $this->affected_rows = $st->rowCount();
            if ($this->affected_rows > 0 && preg_match('/^\s*(INSERT|REPLACE)\b/i', $sql)) {
                $this->ultimaFueInsert = true;
            }
            $filas = self::normalizarFilas($st->fetchAll(PDO::FETCH_ASSOC));
            return new IaaResult($filas, $st->columnCount());
        } catch (PDOException $e) {
            $this->registrarError($e);
            return false;
        }
    }

    public function prepare(string $sql): IaaStatement|false
    {
        try {
            $st = $this->pdo->prepare($sql);
            if ($st === false) {
                return false;
            }
            return new IaaStatement($this, $st, $sql);
        } catch (PDOException $e) {
            $this->registrarError($e);
            return false;
        }
    }

    public function real_escape_string(string $valor): string
    {
        $citado = $this->pdo->quote($valor);
        return substr($citado, 1, -1);
    }

    public function escape_string(string $valor): string
    {
        return $this->real_escape_string($valor);
    }

    /** PostgreSQL es UTF-8 nativo: no hay conjunto que cambiar. */
    public function set_charset(string $charset): bool
    {
        return true;
    }

    public function character_set_name(): string
    {
        return 'utf8';
    }

    public function begin_transaction(): bool
    {
        try {
            if ($this->pdo->inTransaction()) {
                return true;
            }
            return $this->pdo->beginTransaction();
        } catch (PDOException $e) {
            $this->registrarError($e);
            return false;
        }
    }

    public function commit(): bool
    {
        try {
            if (!$this->pdo->inTransaction()) {
                return true;
            }
            return $this->pdo->commit();
        } catch (PDOException $e) {
            $this->registrarError($e);
            return false;
        }
    }

    public function rollback(): bool
    {
        try {
            if (!$this->pdo->inTransaction()) {
                // mysqli tampoco hace nada útil aquí; se evita la excepción de
                // PDO ("There is no active transaction") que rompería los
                // bloques catch existentes.
                return true;
            }
            return $this->pdo->rollBack();
        } catch (PDOException $e) {
            $this->registrarError($e);
            return false;
        }
    }

    public function autocommit(bool $activar): bool
    {
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function close(): bool
    {
        return true;
    }

    public function ping(): bool
    {
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Acceso al PDO subyacente para código nuevo escrito ya en PDO nativo.
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
