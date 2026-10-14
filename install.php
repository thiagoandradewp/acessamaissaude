<?php
declare(strict_types=1);

// Instalador web do Acessa+ Saúde para hospedagem compartilhada, VPS ou XAMPP.
// Usa um banco já existente (ou cria, se o usuário tiver permissão), importa a
// estrutura, cria a conta do desenvolvedor e grava config.local.php.
// Nunca apaga um banco: se encontrar tabelas do sistema, interrompe.

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
session_start();

const INSTALL_LOCK = __DIR__ . '/install.lock';
const LOCAL_CONFIG = __DIR__ . '/config.local.php';
const DEMO_UBS = ['ubsA', 'ubsC', 'ubsD', 'ubsE', 'ubsF'];
const UFS = ['AC'=>'Acre','AL'=>'Alagoas','AP'=>'Amapá','AM'=>'Amazonas','BA'=>'Bahia','CE'=>'Ceará','DF'=>'Distrito Federal','ES'=>'Espírito Santo','GO'=>'Goiás','MA'=>'Maranhão','MT'=>'Mato Grosso','MS'=>'Mato Grosso do Sul','MG'=>'Minas Gerais','PA'=>'Pará','PB'=>'Paraíba','PR'=>'Paraná','PE'=>'Pernambuco','PI'=>'Piauí','RJ'=>'Rio de Janeiro','RN'=>'Rio Grande do Norte','RS'=>'Rio Grande do Sul','RO'=>'Rondônia','RR'=>'Roraima','SC'=>'Santa Catarina','SP'=>'São Paulo','SE'=>'Sergipe','TO'=>'Tocantins'];

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function installer_requirements(): array
{
    return [
        ['PHP 8.1 ou superior', PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>=')],
        ['Extensão PDO MySQL (pdo_mysql)', extension_loaded('pdo_mysql') ? 'ativa' : 'inativa', extension_loaded('pdo_mysql')],
        ['Extensão JSON', extension_loaded('json') ? 'ativa' : 'inativa', extension_loaded('json')],
        ['Pasta do sistema com permissão de escrita', is_writable(__DIR__) ? 'sim' : 'não — o arquivo de configuração será exibido para você criar manualmente', true],
    ];
}

/** Divide database.sql em comandos, sem os trechos exclusivos do XAMPP (DROP/CREATE DATABASE e USE). */
function installer_statements(string $sql): array
{
    $lines = preg_split('/\R/', $sql) ?: [];
    $lines = array_filter($lines, static fn(string $line): bool => !preg_match('/^\s*--/', $line));
    $statements = [];
    foreach (preg_split('/;\s*(?:\R|$)/', implode("\n", $lines)) ?: [] as $statement) {
        $statement = trim($statement);
        if ($statement === '' || preg_match('/^(DROP\s+DATABASE|CREATE\s+DATABASE|USE)\b/i', $statement)) continue;
        $statements[] = $statement;
    }
    return $statements;
}

function installer_connect(array $db): PDO
{
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
    $base = 'mysql:host=' . $db['host'] . ';port=' . $db['port'] . ';charset=utf8mb4';
    try {
        return new PDO($base . ';dbname=' . $db['name'], $db['user'], $db['pass'], $options);
    } catch (PDOException $e) {
        // 1049 = banco inexistente: tenta criá-lo (funciona em VPS/XAMPP; em hospedagem compartilhada crie pelo painel).
        if ((int)($e->errorInfo[1] ?? 0) !== 1049) throw $e;
        $pdo = new PDO($base, $db['user'], $db['pass'], $options);
        try {
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $db['name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (PDOException $ignored) {
            throw new RuntimeException('O banco "' . $db['name'] . '" não existe e este usuário não tem permissão para criá-lo. Crie o banco no painel da hospedagem (ex.: cPanel → Bancos de dados MySQL), vincule o usuário a ele e tente de novo.');
        }
        return new PDO($base . ';dbname=' . $db['name'], $db['user'], $db['pass'], $options);
    }
}

function installer_friendly_error(Throwable $e): string
{
    $code = $e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : 0;
    return match ($code) {
        1045 => 'Usuário ou senha do banco incorretos. Em hospedagem compartilhada, o usuário costuma ter um prefixo (ex.: conta_usuario).',
        1044 => 'O usuário não tem acesso a esse banco. Confira se o nome do banco está certo (com o prefixo da hospedagem) e se o usuário foi vinculado a ele com todos os privilégios.',
        2002, 2005 => 'Não foi possível encontrar o servidor do banco. Em hospedagem compartilhada, o servidor normalmente é "localhost".',
        default => $e->getMessage(),
    };
}

function installer_local_config(array $db): string
{
    return "<?php\n// Gerado pelo instalador em " . date('d/m/Y H:i') . ". Não publique este arquivo.\nreturn " . var_export([
        'host' => $db['host'],
        'port' => $db['port'],
        'name' => $db['name'],
        'user' => $db['user'],
        'pass' => $db['pass'],
    ], true) . ";\n";
}

$installed = is_file(INSTALL_LOCK) || is_file(LOCAL_CONFIG);
$requirements = installer_requirements();
$requirementsOk = !in_array(false, array_column($requirements, 2), true);
$errors = [];
$done = null;
$form = [
    'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '',
    'admin_user' => 'desenvolvedor', 'demo' => '', 'demo_cidade' => '', 'demo_estado' => '',
];
if (empty($_SESSION['install_token'])) $_SESSION['install_token'] = bin2hex(random_bytes(16));

if (!$installed && $requirementsOk && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach (array_keys($form) as $field) $form[$field] = trim((string)($_POST[$field] ?? ''));
    $db = [
        'host' => $form['db_host'], 'port' => $form['db_port'] ?: '3306', 'name' => $form['db_name'],
        'user' => $form['db_user'], 'pass' => (string)($_POST['db_pass'] ?? ''),
    ];
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    $demo = $form['demo'] === '1';
    $demoCidade = trim((string)preg_replace('/\s+/u', ' ', $form['demo_cidade']));
    $demoEstado = strtoupper($form['demo_estado']);

    if (!hash_equals($_SESSION['install_token'], (string)($_POST['token'] ?? ''))) $errors[] = 'Sessão do instalador expirada. Recarregue a página e tente de novo.';
    if ($db['host'] === '' || $db['name'] === '' || $db['user'] === '') $errors[] = 'Preencha servidor, nome do banco e usuário do banco.';
    if (!preg_match('/^\d{2,5}$/', $db['port'])) $errors[] = 'Porta do banco inválida.';
    if (!preg_match('/^[A-Za-z0-9._@-]{3,80}$/', $form['admin_user'])) $errors[] = 'Usuário do administrador: use de 3 a 80 caracteres (letras, números, ponto, hífen, sublinhado ou @).';
    // Regras da conta nova; só valem quando o banco ainda está vazio.
    $newInstallErrors = [];
    if (strlen($adminPass) < 12) $newInstallErrors[] = 'A senha do administrador precisa ter pelo menos 12 caracteres.';
    if ($adminPass !== (string)($_POST['admin_pass_confirm'] ?? '')) $newInstallErrors[] = 'A confirmação da senha do administrador não confere.';
    if ($demo && in_array(strtolower($form['admin_user']), ['admina','adminc','admind','admine','adminf'], true)) $newInstallErrors[] = 'Esse usuário é usado por uma UBS de demonstração. Escolha outro nome para o administrador.';
    if ($demo && (preg_match('/^.{2,120}$/uD', $demoCidade) !== 1 || !isset(UFS[$demoEstado]))) $newInstallErrors[] = 'Para os dados de demonstração, informe a cidade e a UF das UBS de exemplo.';

    if (!$errors) {
        try {
            $pdo = installer_connect($db);
            $exists = $pdo->query("SHOW TABLES LIKE 'administradores'")->fetchColumn();
            if ($exists) {
                // Banco já instalado (ex.: site movido para outro domínio/pasta): só reconecta, sem alterar dados,
                // e apenas para quem comprovar ser o desenvolvedor dessa instalação.
                $q = $pdo->prepare("SELECT senha_hash FROM administradores WHERE usuario = ? AND tipo = 'desenvolvedor'");
                $q->execute([$form['admin_user']]);
                $hash = $q->fetchColumn();
                if ($hash === false || !password_verify($adminPass, (string)$hash)) {
                    throw new RuntimeException('Este banco já tem o Acessa+ Saúde instalado. Nada foi alterado. Para conectar este site a ele, informe no passo 3 o usuário e a senha do Desenvolvedor que você já usa para entrar no sistema.');
                }
                $configContent = installer_local_config($db);
                $configSaved = @file_put_contents(LOCAL_CONFIG, $configContent, LOCK_EX) !== false;
                if ($configSaved) @chmod(LOCAL_CONFIG, 0640);
                @file_put_contents(INSTALL_LOCK, 'Reconectado em ' . date('c') . PHP_EOL, LOCK_EX);
                unset($_SESSION['install_token']);
                $done = ['configSaved' => $configSaved, 'config' => $configContent, 'admin' => $form['admin_user'], 'demo' => false, 'reconnected' => true];
            } elseif ($newInstallErrors) {
                $errors = array_merge($errors, $newInstallErrors);
            } else {
                $sql = file_get_contents(__DIR__ . '/database.sql');
                if ($sql === false) throw new RuntimeException('Arquivo database.sql não encontrado. Envie todos os arquivos do sistema para a hospedagem.');

                foreach (installer_statements($sql) as $statement) {
                    // "ADD COLUMN IF NOT EXISTS" só existe no MariaDB; no MySQL a coluna já vem no CREATE TABLE.
                    $mysqlSafe = preg_replace('/ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS/i', 'ADD COLUMN', $statement);
                    try {
                        $pdo->exec($mysqlSafe);
                    } catch (PDOException $e) {
                        if ((int)($e->errorInfo[1] ?? 0) === 1060 && $mysqlSafe !== $statement) continue; // coluna duplicada
                        throw $e;
                    }
                }

                // As contas de exemplo têm senhas conhecidas: nunca permanecem.
                $placeholders = implode(',', array_fill(0, count(DEMO_UBS), '?'));
                $pdo->exec("DELETE FROM administradores WHERE tipo = 'desenvolvedor'");
                if ($demo) {
                    $pdo->prepare("UPDATE ubs SET cidade = ?, estado = ? WHERE id IN ($placeholders)")->execute([$demoCidade, $demoEstado, ...DEMO_UBS]);
                    $pdo->prepare("UPDATE administradores SET senha_hash = ? WHERE ubs_id IN ($placeholders)")->execute([password_hash($adminPass, PASSWORD_DEFAULT), ...DEMO_UBS]);
                } else {
                    $pdo->prepare("DELETE FROM ubs WHERE id IN ($placeholders)")->execute(DEMO_UBS);
                }
                $pdo->prepare("INSERT INTO administradores (usuario, senha_hash, tipo, ubs_id) VALUES (?, ?, 'desenvolvedor', NULL)")
                    ->execute([$form['admin_user'], password_hash($adminPass, PASSWORD_DEFAULT)]);

                $configContent = installer_local_config($db);
                $configSaved = @file_put_contents(LOCAL_CONFIG, $configContent, LOCK_EX) !== false;
                if ($configSaved) @chmod(LOCAL_CONFIG, 0640);
                @file_put_contents(INSTALL_LOCK, 'Instalado em ' . date('c') . PHP_EOL, LOCK_EX);
                unset($_SESSION['install_token']);
                $done = ['configSaved' => $configSaved, 'config' => $configContent, 'admin' => $form['admin_user'], 'demo' => $demo];
            }
        } catch (Throwable $e) {
            $errors[] = installer_friendly_error($e);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Instalação - Acessa+ Saúde</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Inter,Segoe UI,Arial,sans-serif;background:linear-gradient(135deg,#e9fbf9,#f7ffff);color:#073f43;padding:24px 16px}
.card{max-width:760px;margin:20px auto;background:#fff;border-radius:22px;padding:30px;box-shadow:0 15px 45px rgba(0,76,80,.12);border:1px solid #d8eeee}
h1{margin:0 0 4px;color:#006c70}h2{font-size:18px;margin:28px 0 6px;color:#0a5458}p{line-height:1.5}
label{display:block;font-weight:700;margin:14px 0 6px;font-size:14px}input,select{width:100%;padding:11px 12px;border:1px solid #cfe5e6;border-radius:10px;font:inherit}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}@media(max-width:560px){.grid{grid-template-columns:1fr}}
.hint{font-size:13px;color:#527174;margin:4px 0 0}.ok{background:#e4f8ef;border:1px solid #bce9d2;padding:14px 16px;border-radius:12px;color:#176d43}
.err{background:#fff0f0;border:1px solid #f0c2c2;padding:14px 16px;border-radius:12px;color:#a52d2d}.err ul{margin:0;padding-left:18px}
.checks{list-style:none;padding:0;margin:0}.checks li{padding:8px 12px;border-radius:8px;margin:6px 0;font-size:14px}.checks .yes{background:#e4f8ef}.checks .no{background:#fff0f0;color:#a52d2d}
.check-row{display:flex;gap:10px;align-items:flex-start;margin-top:16px}.check-row input{width:auto;margin-top:4px}
button,.btn{display:inline-block;margin-top:24px;background:#008f8c;color:#fff;border:0;text-decoration:none;padding:13px 22px;border-radius:12px;font-weight:700;font-size:16px;cursor:pointer}
pre{background:#f1f8f8;padding:12px;border-radius:10px;overflow:auto;font-size:13px}code{background:#edf7f7;padding:2px 6px;border-radius:5px}.hidden{display:none}
</style>
</head>
<body>
<div class="card">
<h1>Acessa+ Saúde</h1>
<p class="hint">Instalação do sistema</p>

<?php if ($done): ?>
    <?php if (!empty($done['reconnected'])): ?>
    <p class="ok"><strong>Site conectado à instalação existente!</strong> Nenhum dado foi alterado e o instalador foi bloqueado.</p>
    <?php else: ?>
    <p class="ok"><strong>Instalação concluída!</strong> O banco foi preparado e o instalador foi bloqueado.</p>
    <?php endif; ?>
    <?php if (!$done['configSaved']): ?>
        <p class="err">Não foi possível gravar o arquivo de configuração (a pasta não permite escrita). Crie, na mesma pasta do <code>index.php</code>, um arquivo chamado <code>config.local.php</code> com o conteúdo abaixo:</p>
        <pre><?= h($done['config']) ?></pre>
    <?php endif; ?>
    <h2>Próximos passos</h2>
    <ol>
        <li>Entre na área administrativa com o usuário <strong><?= h($done['admin']) ?></strong> e a senha que você definiu.</li>
        <li>Na aba <strong>Administrador da Secretaria</strong>, crie a conta da Secretaria de Saúde de cada município (com estado e cidade).</li>
        <li>A Secretaria cadastra as UBS da cidade; elas aparecem no login do paciente.</li>
        <?php if ($done['demo']): ?><li>As UBS de demonstração usam a mesma senha do administrador (usuários <code>adminA</code>, <code>adminC</code>, <code>adminD</code>, <code>adminE</code>, <code>adminF</code>). Desative-as quando cadastrar as unidades reais.</li><?php endif; ?>
        <li>Por segurança, apague o arquivo <code>install.php</code> da hospedagem.</li>
    </ol>
    <a class="btn" href="index.php">Abrir o Acessa+ Saúde</a>

<?php elseif ($installed): ?>
    <p class="ok">O sistema já está instalado. Por segurança, o instalador fica bloqueado.</p>
    <p class="hint">Para reinstalar, faça backup do banco, esvazie-o e apague os arquivos <code>install.lock</code> e <code>config.local.php</code> da hospedagem.</p>
    <a class="btn" href="index.php">Abrir o Acessa+ Saúde</a>

<?php else: ?>
    <h2>1. Verificação do servidor</h2>
    <ul class="checks">
        <?php foreach ($requirements as [$label, $value, $ok]): ?>
            <li class="<?= $ok ? 'yes' : 'no' ?>"><?= $ok ? '✔' : '✖' ?> <?= h($label) ?>: <?= h((string)$value) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php if (!$requirementsOk): ?>
        <p class="err">Ajuste os itens em vermelho no painel da hospedagem (ex.: cPanel → "Selecionar versão do PHP") e recarregue esta página.</p>
    <?php else: ?>
        <?php if ($errors): ?><div class="err"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="token" value="<?= h($_SESSION['install_token']) ?>">

            <h2>2. Banco de dados MySQL</h2>
            <p class="hint">Crie o banco e o usuário no painel da hospedagem (cPanel → Bancos de dados MySQL), vincule o usuário ao banco com todos os privilégios e informe os dados aqui. Mudou o site de endereço? Informe o banco que já usa e, no passo 3, o usuário e a senha do Desenvolvedor: o site é reconectado sem alterar nenhum dado.</p>
            <div class="grid">
                <div><label for="db_host">Servidor</label><input id="db_host" name="db_host" value="<?= h($form['db_host']) ?>" required><p class="hint">Quase sempre <code>localhost</code>.</p></div>
                <div><label for="db_port">Porta</label><input id="db_port" name="db_port" value="<?= h($form['db_port']) ?>" inputmode="numeric" required></div>
                <div><label for="db_name">Nome do banco</label><input id="db_name" name="db_name" value="<?= h($form['db_name']) ?>" placeholder="ex.: conta_acessa" required></div>
                <div><label for="db_user">Usuário do banco</label><input id="db_user" name="db_user" value="<?= h($form['db_user']) ?>" placeholder="ex.: conta_acessa" required></div>
                <div><label for="db_pass">Senha do banco</label><input id="db_pass" name="db_pass" type="password" autocomplete="new-password"></div>
            </div>

            <h2>3. Administrador (Desenvolvedor)</h2>
            <p class="hint">É a conta principal: cria as Secretarias de cada município e administra o sistema.</p>
            <div class="grid">
                <div><label for="admin_user">Usuário</label><input id="admin_user" name="admin_user" value="<?= h($form['admin_user']) ?>" required></div>
                <div></div>
                <div><label for="admin_pass">Senha (mínimo 12 caracteres)</label><input id="admin_pass" name="admin_pass" type="password" minlength="12" autocomplete="new-password" required></div>
                <div><label for="admin_pass_confirm">Confirme a senha</label><input id="admin_pass_confirm" name="admin_pass_confirm" type="password" minlength="12" autocomplete="new-password" required></div>
            </div>

            <h2>4. Dados de demonstração (opcional)</h2>
            <div class="check-row"><input id="demo" name="demo" type="checkbox" value="1" <?= $form['demo'] === '1' ? 'checked' : '' ?> onchange="document.getElementById('demoFields').classList.toggle('hidden',!this.checked)"><label for="demo" style="margin:0;font-weight:400">Criar 5 UBS de exemplo para testar o sistema</label></div>
            <div id="demoFields" class="grid <?= $form['demo'] === '1' ? '' : 'hidden' ?>">
                <div><label for="demo_estado">Estado das UBS de exemplo</label><select id="demo_estado" name="demo_estado"><option value="">Selecione</option><?php foreach (UFS as $uf => $nome): ?><option value="<?= h($uf) ?>" <?= $form['demo_estado'] === $uf ? 'selected' : '' ?>><?= h($nome) ?></option><?php endforeach; ?></select></div>
                <div><label for="demo_cidade">Cidade das UBS de exemplo</label><input id="demo_cidade" name="demo_cidade" value="<?= h($form['demo_cidade']) ?>" maxlength="120"></div>
            </div>

            <button type="submit">Instalar</button>
        </form>
    <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
