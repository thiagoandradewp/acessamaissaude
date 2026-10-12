<?php
declare(strict_types=1);

const SESSION_IDLE_TIMEOUT = 1800;
const SESSION_ABSOLUTE_TIMEOUT = 28800;

function configure_secure_session(int $cookieLifetime = 0): void
{
    $cookieLifetime = max(0, $cookieLifetime);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_lifetime', (string)$cookieLifetime);
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
        || getenv('VERCEL') === '1';
    session_set_cookie_params([
        'lifetime' => $cookieLifetime,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    date_default_timezone_set('America/Sao_Paulo');
}

/** Renova o cookie persistente somente enquanto houver uma sessão profissional válida. */
function refresh_professional_session_cookie(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['professional']) || headers_sent()) {
        return false;
    }

    $params = session_get_cookie_params();
    $lifetime = (int)($params['lifetime'] ?? 0);
    if ($lifetime <= 0) {
        return false;
    }

    $options = [
        'expires' => time() + $lifetime,
        'path' => ($params['path'] ?? '') !== '' ? $params['path'] : '/',
        'secure' => (bool)($params['secure'] ?? false),
        'httponly' => true,
        'samesite' => ($params['samesite'] ?? '') !== '' ? $params['samesite'] : 'Lax',
    ];
    if (!empty($params['domain'])) {
        $options['domain'] = $params['domain'];
    }

    return setcookie(session_name(), session_id(), $options);
}

/** Persiste sessões PHP em Supabase para que funcionem entre invocações serverless. */
final class PostgresSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private PDO $pdo;
    private int $lifetime;
    private string $table;
    private bool $mysql;
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->lifetime = max(SESSION_ABSOLUTE_TIMEOUT, (int)ini_get('session.gc_maxlifetime'));
        try {
            $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable) {
            $driver = '';
        }
        $this->mysql = strtolower($driver) === 'mysql';
        $this->table = $this->mysql ? 'php_sessions' : 'public.php_sessions';
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function validateId(string $id): bool
    {
        if ($id === '') return false;
        $stmt = $this->pdo->prepare('SELECT 1 FROM '.$this->table.' WHERE id_hash = ? AND expires_at > ?');
        $stmt->execute([$this->idHash($id), time()]);
        return $stmt->fetchColumn() !== false;
    }

    public function read(string $id): string|false
    {
        $stmt = $this->pdo->prepare('SELECT payload FROM '.$this->table.' WHERE id_hash = ? AND expires_at > ?');
        $stmt->execute([$this->idHash($id), time()]);
        $payload = $stmt->fetchColumn();
        if (!is_string($payload)) return '';
        $decoded = base64_decode($payload, true);
        return $decoded === false ? '' : $decoded;
    }

    public function write(string $id, string $data): bool
    {
        $sql = $this->mysql
            ? 'INSERT INTO php_sessions (id_hash, payload, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires_at = VALUES(expires_at)'
            : 'INSERT INTO public.php_sessions (id_hash, payload, expires_at) VALUES (?, ?, ?) ON CONFLICT (id_hash) DO UPDATE SET payload = EXCLUDED.payload, expires_at = EXCLUDED.expires_at';
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$this->idHash($id), base64_encode($data), time() + $this->lifetime]);
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }

    public function destroy(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM '.$this->table.' WHERE id_hash = ?');
        return $stmt->execute([$this->idHash($id)]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = $this->pdo->prepare('DELETE FROM '.$this->table.' WHERE expires_at <= ?');
        if (!$stmt->execute([time()])) return false;
        return $stmt->rowCount();
    }

    private function idHash(string $id): string
    {
        return hash('sha256', $id);
    }
}

function configure_database_session_handler(PDO $pdo): void
{
    $serverless = trim((string)(getenv('DATABASE_URL') ?: '')) !== '' || getenv('VERCEL') === '1';
    if (!$serverless) return;
    if (session_status() !== PHP_SESSION_NONE) {
        throw new LogicException('O handler de sessão precisa ser configurado antes de session_start().');
    }
    if (!session_set_save_handler(new PostgresSessionHandler($pdo), true)) {
        throw new RuntimeException('Não foi possível configurar o armazenamento persistente da sessão.');
    }
}

function enforce_session_lifetime(): void
{
    $hasAuth = !empty($_SESSION['professional']) || !empty($_SESSION['admin']);
    if (!$hasAuth) return;
    // Mantém a sessão profissional durante o trabalho; o botão Sair continua encerrando o acesso.
    if (!empty($_SESSION['professional'])) {
        $_SESSION['_last_activity'] = time();
        return;
    }
    $now = time();
    $last = (int)($_SESSION['_last_activity'] ?? $now);
    $created = (int)($_SESSION['_auth_created_at'] ?? $now);
    if (($now - $last) > SESSION_IDLE_TIMEOUT || ($now - $created) > SESSION_ABSOLUTE_TIMEOUT) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $params['path'] ?: '/', 'domain' => $params['domain'] ?? '', 'secure' => (bool)$params['secure'], 'httponly' => true, 'samesite' => $params['samesite'] ?? 'Lax']);
            }
            session_regenerate_id(true);
        }
        return;
    }
    $_SESSION['_last_activity'] = $now;
}

function rotate_csrf_token(): string
{
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf_token'];
}

function require_csrf(): void
{
    $expected = (string)($_SESSION['_csrf_token'] ?? '');
    $received = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
        $currentToken = $expected !== '' ? $expected : rotate_csrf_token();
        json_response(['success' => false, 'message' => 'A sessão de segurança expirou. Atualizando a proteção; tente novamente.', 'code' => 'CSRF_INVALID', 'csrf_token' => $currentToken], 419);
    }
}

function json_response(array $data, int $status = 200): never
{
    if (function_exists('database_normalize_booleans')) {
        $data = database_normalize_booleans($data);
    }

    // Remove qualquer warning/saída acidental antes do JSON, evitando
    // que o navegador receba uma resposta inválida após um agendamento.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($json === false) {
        http_response_code(500);
        $json = '{"success":false,"message":"Resposta do servidor indisponivel."}';
    }
    echo $json;
    exit;
}

function body_json(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function required_string(array $data, string $key): string
{
    $value = trim((string)($data[$key] ?? ''));

    if ($value === '') {
        json_response([
            'success' => false,
            'message' => "O campo '{$key}' é obrigatório."
        ], 422);
    }

    return $value;
}

function clean_list(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $result = [];

    foreach ($value as $item) {
        $item = trim((string)$item);

        if ($item !== '' && !in_array($item, $result, true)) {
            $result[] = $item;
        }
    }

    return $result;
}

function valid_date(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function is_holiday(string $date): bool
{
    $md = date('m-d', strtotime($date));

    return in_array($md, [
        '01-01',
        '04-21',
        '05-01',
        '09-07',
        '10-12',
        '11-02',
        '11-15',
        '12-25'
    ], true);
}

function is_business_day(string $date): bool
{
    $weekday = (int)date('N', strtotime($date));

    return $weekday <= 5 && !is_holiday($date);
}

function admin_session(): ?array
{
    return $_SESSION['admin'] ?? null;
}

function require_admin(): array
{
    $session = admin_session();

    if (!$session) {
        json_response([
            'success' => false,
            'message' => 'Sessão administrativa expirada. Faça login novamente.'
        ], 401);
    }

    return $session;
}

function professional_session(): ?array
{
    return $_SESSION['professional'] ?? null;
}

function professional_session_matches_account(?array $session, ?array $account): bool
{
    return $session !== null
        && $account !== null
        && ($account['status'] ?? null) === 'ativo'
        && (int)($session['auth_version'] ?? 0) === (int)($account['auth_version'] ?? -1);
}

function require_professional(): array
{
    $session = professional_session();
    if (!$session) {
        json_response(['success' => false, 'message' => 'Sessão profissional expirada. Faça login novamente.'], 401);
    }
    return $session;
}

function authorize_ubs(string $ubsId): array
{
    $session = require_admin();

    if (
        !in_array($session['tipo'], ['desenvolvedor', 'secretaria'], true) &&
        $session['ubs_id'] !== $ubsId
    ) {
        json_response([
            'success' => false,
            'message' => 'Você não tem permissão para acessar esta UBS.'
        ], 403);
    }

    if ($session['tipo'] === 'secretaria') {
        assert_ubs_in_admin_region(db(), $session, $ubsId);
    }

    return $session;
}

function normalizar_nome_cidade(string $cidade): string
{
    return trim((string)preg_replace('/\s+/u', ' ', $cidade));
}

function chave_cidade(string $cidade): string
{
    $cidade = normalizar_nome_cidade($cidade);
    return function_exists('mb_strtolower') ? mb_strtolower($cidade, 'UTF-8') : strtolower($cidade);
}

/**
 * Região (cidade/UF) a que uma conta da Secretaria está restrita.
 * Retorna null quando a conta não tem localização definida (contas antigas mantêm o escopo global).
 */
function admin_region_from_row(?array $row): ?array
{
    if (!$row) return null;
    $cidade = normalizar_nome_cidade((string)($row['cidade'] ?? ''));
    $estado = strtoupper(trim((string)($row['estado'] ?? '')));
    return ($cidade !== '' && $estado !== '') ? ['cidade' => $cidade, 'estado' => $estado] : null;
}

function ubs_pertence_regiao(array $ubs, ?array $region): bool
{
    if ($region === null) return true;
    return strtoupper(trim((string)($ubs['estado'] ?? ''))) === $region['estado']
        && chave_cidade((string)($ubs['cidade'] ?? '')) === chave_cidade($region['cidade']);
}

function admin_region(PDO $pdo, array $session): ?array
{
    if (($session['tipo'] ?? '') !== 'secretaria') return null;
    static $cache = [];
    $id = (int)($session['id'] ?? 0);
    if (!array_key_exists($id, $cache)) {
        $q = $pdo->prepare("SELECT cidade, estado FROM administradores WHERE id=? AND tipo='secretaria'");
        $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_response(['success' => false, 'message' => 'Conta da Secretaria não encontrada.'], 403);
        $cache[$id] = admin_region_from_row($row);
    }
    return $cache[$id];
}

function assert_ubs_in_admin_region(PDO $pdo, array $session, string $ubsId): void
{
    $region = admin_region($pdo, $session);
    if ($region === null) return;
    $q = $pdo->prepare('SELECT cidade, estado FROM ubs WHERE id = ?');
    $q->execute([$ubsId]);
    $ubs = $q->fetch(PDO::FETCH_ASSOC);
    if (!$ubs) json_response(['success' => false, 'message' => 'UBS não encontrada.'], 404);
    if (!ubs_pertence_regiao($ubs, $region)) {
        json_response([
            'success' => false,
            'message' => 'Esta UBS não pertence ao município da sua Secretaria de Saúde.'
        ], 403);
    }
}
