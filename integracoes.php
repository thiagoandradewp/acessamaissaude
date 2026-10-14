<?php
declare(strict_types=1);

// Integrações de envio (e-mail e WhatsApp) configuradas pelo desenvolvedor no painel.
// As credenciais ficam na tabela `configuracoes`; variáveis de ambiente antigas
// (RESEND_API_KEY, MAIL_FROM, APP_URL) continuam funcionando como reserva.

const INTEGRACAO_CAMPOS = [
    'email_provedor', 'smtp_host', 'smtp_porta', 'smtp_seguranca', 'smtp_usuario', 'smtp_senha',
    'email_remetente', 'email_nome', 'resend_api_key', 'app_url',
    'whatsapp_provedor', 'meta_token', 'meta_phone_number_id', 'meta_template', 'meta_idioma',
];
const INTEGRACAO_SEGREDOS = ['smtp_senha', 'resend_api_key', 'meta_token'];

function integracoes(PDO $pdo, bool $recarregar = false): array
{
    static $cache = null;
    if ($cache !== null && !$recarregar) return $cache;
    $salvas = [];
    try {
        foreach ($pdo->query('SELECT chave, valor FROM configuracoes')->fetchAll() as $row) {
            $salvas[(string)$row['chave']] = (string)$row['valor'];
        }
    } catch (Throwable $e) {
        // Banco ainda sem a tabela (ex.: Supabase sem a migração): usa só as variáveis de ambiente.
    }
    $cfg = array_fill_keys(INTEGRACAO_CAMPOS, '');
    foreach ($salvas as $chave => $valor) if (array_key_exists($chave, $cfg)) $cfg[$chave] = $valor;

    if ($cfg['email_provedor'] === '' && getenv('RESEND_API_KEY')) {
        $cfg['email_provedor'] = 'resend';
        $cfg['resend_api_key'] = (string)getenv('RESEND_API_KEY');
        $from = trim((string)getenv('MAIL_FROM'));
        if (preg_match('/^\s*"?([^"<]*)"?\s*<([^>]+)>\s*$/', $from, $m)) {
            $cfg['email_nome'] = trim($m[1]);
            $cfg['email_remetente'] = trim($m[2]);
        } else {
            $cfg['email_remetente'] = $from;
        }
    }
    if ($cfg['app_url'] === '' && getenv('APP_URL')) $cfg['app_url'] = (string)getenv('APP_URL');
    if ($cfg['smtp_porta'] === '') $cfg['smtp_porta'] = '465';
    if ($cfg['smtp_seguranca'] === '') $cfg['smtp_seguranca'] = 'ssl';
    if ($cfg['whatsapp_provedor'] === '') $cfg['whatsapp_provedor'] = 'manual';
    if ($cfg['meta_idioma'] === '') $cfg['meta_idioma'] = 'pt_BR';
    if ($cfg['email_nome'] === '') $cfg['email_nome'] = 'Acessa+ Saúde';
    return $cache = $cfg;
}

function email_configurado(array $cfg): bool
{
    if (!filter_var($cfg['email_remetente'], FILTER_VALIDATE_EMAIL)) return false;
    return match ($cfg['email_provedor']) {
        'smtp' => $cfg['smtp_host'] !== '' && $cfg['smtp_usuario'] !== '' && $cfg['smtp_senha'] !== '',
        'resend' => $cfg['resend_api_key'] !== '',
        default => false,
    };
}

function whatsapp_api_configurado(array $cfg): bool
{
    return $cfg['whatsapp_provedor'] === 'meta' && $cfg['meta_token'] !== '' && $cfg['meta_phone_number_id'] !== '' && $cfg['meta_template'] !== '';
}

/** Resumo seguro para o painel: nunca devolve senhas ou tokens. */
function integracoes_publicas(array $cfg): array
{
    $publico = $cfg;
    foreach (INTEGRACAO_SEGREDOS as $segredo) {
        $publico[$segredo] = '';
        $publico[$segredo . '_configurado'] = $cfg[$segredo] !== '';
    }
    $publico['email_ativo'] = email_configurado($cfg);
    $publico['whatsapp_ativo'] = whatsapp_api_configurado($cfg);
    return $publico;
}

function salvar_integracoes(PDO $pdo, array $data): array
{
    $atual = integracoes($pdo, true);
    $novo = [];
    foreach (INTEGRACAO_CAMPOS as $campo) {
        $valor = trim((string)($data[$campo] ?? ''));
        // Campo secreto em branco mantém o valor já salvo; "limpar_<campo>" apaga.
        if (in_array($campo, INTEGRACAO_SEGREDOS, true) && $valor === '' && empty($data['limpar_' . $campo])) $valor = $atual[$campo];
        if (strlen($valor) > 2000) throw new InvalidArgumentException('Valor muito longo em ' . $campo . '.');
        $novo[$campo] = $valor;
    }
    if (!in_array($novo['email_provedor'], ['', 'smtp', 'resend'], true)) throw new InvalidArgumentException('Provedor de e-mail inválido.');
    if (!in_array($novo['smtp_seguranca'], ['ssl', 'tls', 'nenhuma'], true)) throw new InvalidArgumentException('Segurança SMTP inválida.');
    if (!in_array($novo['whatsapp_provedor'], ['manual', 'meta'], true)) throw new InvalidArgumentException('Provedor de WhatsApp inválido.');
    if ($novo['smtp_porta'] !== '' && !preg_match('/^\d{2,5}$/', $novo['smtp_porta'])) throw new InvalidArgumentException('Porta SMTP inválida.');
    if ($novo['email_remetente'] !== '' && !filter_var($novo['email_remetente'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('E-mail do remetente inválido.');
    if ($novo['app_url'] !== '' && !preg_match('~^https?://~i', $novo['app_url'])) throw new InvalidArgumentException('O endereço do site deve começar com https://');
    if ($novo['email_provedor'] !== '' && !filter_var($novo['email_remetente'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Informe o e-mail do remetente.');
    if (preg_match('/[\r\n]/', $novo['email_nome'] . $novo['email_remetente'])) throw new InvalidArgumentException('Remetente inválido.');

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM configuracoes WHERE chave IN (' . implode(',', array_fill(0, count($novo), '?')) . ')')->execute(array_keys($novo));
        $insert = $pdo->prepare('INSERT INTO configuracoes (chave, valor) VALUES (?, ?)');
        foreach ($novo as $chave => $valor) if ($valor !== '') $insert->execute([$chave, $valor]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return integracoes($pdo, true);
}

/** URL pública do site: a configurada no painel ou, na falta dela, a do próprio acesso. */
function url_publica_site(array $cfg): string
{
    $url = rtrim(trim($cfg['app_url']), '/');
    if ($url === '') {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || !preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) throw new RuntimeException('Configure o endereço do site em Integrações.');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $base = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        if (str_ends_with($base, '/api')) $base = substr($base, 0, -4);
        $url = ($https ? 'https://' : 'http://') . $host . $base;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) throw new RuntimeException('Endereço do site inválido em Integrações.');
    return $url . '/';
}

function cabecalho_mime(string $texto): string
{
    return preg_match('/[^\x20-\x7E]/', $texto) ? '=?UTF-8?B?' . base64_encode($texto) . '?=' : $texto;
}

function montar_email_mime(array $cfg, string $para, string $assunto, string $html, string $texto): string
{
    $limite = 'acessa-' . bin2hex(random_bytes(8));
    $dominio = substr((string)strrchr($cfg['email_remetente'], '@'), 1) ?: 'localhost';
    $cabecalhos = [
        'Date: ' . date('r'),
        'From: ' . cabecalho_mime($cfg['email_nome']) . ' <' . $cfg['email_remetente'] . '>',
        'To: <' . $para . '>',
        'Subject: ' . cabecalho_mime($assunto),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $dominio . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $limite . '"',
    ];
    $partes = '';
    foreach (['text/plain' => $texto, 'text/html' => $html] as $tipo => $conteudo) {
        $partes .= "--{$limite}\r\nContent-Type: {$tipo}; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . rtrim(chunk_split(base64_encode($conteudo), 76, "\r\n")) . "\r\n";
    }
    return implode("\r\n", $cabecalhos) . "\r\n\r\n" . $partes . "--{$limite}--\r\n";
}

function smtp_ler($socket): array
{
    $resposta = '';
    while (($linha = fgets($socket, 1024)) !== false) {
        $resposta .= $linha;
        if (strlen($linha) < 4 || $linha[3] === ' ') break;
    }
    if ($resposta === '') throw new RuntimeException('O servidor SMTP encerrou a conexão.');
    return [(int)substr($resposta, 0, 3), trim($resposta)];
}

function smtp_comando($socket, string $comando, array $esperado, string $etapa): string
{
    if ($comando !== '') fwrite($socket, $comando . "\r\n");
    [$codigo, $resposta] = smtp_ler($socket);
    if (!in_array($codigo, $esperado, true)) {
        throw new RuntimeException('SMTP recusou ' . $etapa . ': ' . substr($resposta, 0, 200));
    }
    return $resposta;
}

function enviar_email_smtp(array $cfg, string $para, string $assunto, string $html, string $texto): void
{
    $seguranca = $cfg['smtp_seguranca'];
    $porta = (int)($cfg['smtp_porta'] ?: 465);
    $endereco = ($seguranca === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['smtp_host'] . ':' . $porta;
    $contexto = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $cfg['smtp_host']]]);
    $socket = @stream_socket_client($endereco, $erroNumero, $erroTexto, 15, STREAM_CLIENT_CONNECT, $contexto);
    if (!$socket) throw new RuntimeException('Não foi possível conectar ao servidor SMTP ' . $cfg['smtp_host'] . ':' . $porta . ' (' . $erroTexto . ').');
    stream_set_timeout($socket, 20);
    try {
        $nomeLocal = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)($_SERVER['SERVER_NAME'] ?? '')) ?: 'localhost';
        smtp_comando($socket, '', [220], 'a conexão');
        smtp_comando($socket, 'EHLO ' . $nomeLocal, [250], 'o EHLO');
        if ($seguranca === 'tls') {
            smtp_comando($socket, 'STARTTLS', [220], 'o STARTTLS');
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('Falha ao ativar TLS com o servidor SMTP.');
            }
            smtp_comando($socket, 'EHLO ' . $nomeLocal, [250], 'o EHLO');
        }
        smtp_comando($socket, 'AUTH LOGIN', [334], 'a autenticação');
        smtp_comando($socket, base64_encode($cfg['smtp_usuario']), [334], 'o usuário');
        smtp_comando($socket, base64_encode($cfg['smtp_senha']), [235], 'a senha (confira usuário e senha do e-mail)');
        smtp_comando($socket, 'MAIL FROM:<' . $cfg['email_remetente'] . '>', [250], 'o remetente');
        smtp_comando($socket, 'RCPT TO:<' . $para . '>', [250, 251], 'o destinatário');
        smtp_comando($socket, 'DATA', [354], 'o envio');
        $mensagem = montar_email_mime($cfg, $para, $assunto, $html, $texto);
        $mensagem = preg_replace('/^\./m', '..', $mensagem);
        fwrite($socket, $mensagem . "\r\n.\r\n");
        smtp_comando($socket, '', [250], 'a mensagem');
        fwrite($socket, "QUIT\r\n");
    } finally {
        fclose($socket);
    }
}

function enviar_email_resend(array $cfg, string $para, string $assunto, string $html, string $texto): void
{
    if (!function_exists('curl_init')) throw new RuntimeException('Ative a extensão cURL do PHP para usar o Resend.');
    $payload = json_encode(['from' => $cfg['email_nome'] . ' <' . $cfg['email_remetente'] . '>', 'to' => [$para], 'subject' => $assunto, 'html' => $html, 'text' => $texto], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['resend_api_key'], 'Content-Type: application/json'], CURLOPT_POSTFIELDS => $payload, CURLOPT_TIMEOUT => 12]);
    $resposta = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    curl_close($ch);
    if ($resposta === false || $erro !== '' || $status < 200 || $status >= 300) {
        $detalhe = is_string($resposta) ? (string)(json_decode($resposta, true)['message'] ?? '') : $erro;
        throw new RuntimeException('O Resend recusou o envio' . ($detalhe !== '' ? ': ' . $detalhe : '.'));
    }
}

function enviar_email(PDO $pdo, string $para, string $assunto, string $html, string $texto): void
{
    $cfg = integracoes($pdo);
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('E-mail do destinatário inválido.');
    if (!email_configurado($cfg)) throw new RuntimeException('Envio de e-mail não configurado.');
    if ($cfg['email_provedor'] === 'smtp') enviar_email_smtp($cfg, $para, $assunto, $html, $texto);
    else enviar_email_resend($cfg, $para, $assunto, $html, $texto);
}

function telefone_whatsapp(?string $telefone): ?string
{
    $digitos = preg_replace('/\D+/', '', (string)$telefone);
    if ($digitos === '' || strlen($digitos) < 10) return null;
    return str_starts_with($digitos, '55') && strlen($digitos) >= 12 ? $digitos : '55' . $digitos;
}

/**
 * Envia pela API oficial do WhatsApp (Meta Cloud API) usando um modelo aprovado
 * com um único parâmetro no corpo ({{1}}), que recebe o texto da mensagem.
 */
function enviar_whatsapp_api(PDO $pdo, string $telefone, string $mensagem): void
{
    $cfg = integracoes($pdo);
    if (!whatsapp_api_configurado($cfg)) throw new RuntimeException('WhatsApp automático não configurado.');
    $numero = telefone_whatsapp($telefone);
    if ($numero === null) throw new RuntimeException('Telefone inválido para WhatsApp.');
    if (!function_exists('curl_init')) throw new RuntimeException('Ative a extensão cURL do PHP para usar o WhatsApp automático.');
    $texto = trim((string)preg_replace('/\s+/u', ' ', $mensagem));
    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to' => $numero,
        'type' => 'template',
        'template' => [
            'name' => $cfg['meta_template'],
            'language' => ['code' => $cfg['meta_idioma']],
            'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => function_exists('mb_substr') ? mb_substr($texto, 0, 1000) : substr($texto, 0, 1000)]]]],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ch = curl_init('https://graph.facebook.com/v21.0/' . rawurlencode($cfg['meta_phone_number_id']) . '/messages');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['meta_token'], 'Content-Type: application/json'], CURLOPT_POSTFIELDS => $payload, CURLOPT_TIMEOUT => 12]);
    $resposta = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    curl_close($ch);
    if ($resposta === false || $erro !== '' || $status < 200 || $status >= 300) {
        $detalhe = is_string($resposta) ? (string)(json_decode($resposta, true)['error']['message'] ?? '') : $erro;
        throw new RuntimeException('O WhatsApp recusou o envio' . ($detalhe !== '' ? ': ' . $detalhe : '.'));
    }
}

function html_email_paciente(string $titulo, string $mensagem): string
{
    $seguro = nl2br(htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8'));
    return '<div style="font-family:Arial,sans-serif;color:#16474b;max-width:560px"><h2 style="color:#0b9f9f">' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</h2><p style="font-size:15px;line-height:1.5">' . $seguro . '</p><p style="font-size:12px;color:#6f898c">Mensagem automática do Acessa+ Saúde. Não responda este e-mail.</p></div>';
}

/**
 * Avisa o paciente pelos canais automáticos configurados (e-mail e/ou WhatsApp).
 * Nunca interrompe o fluxo principal: falhas são registradas e devolvidas como "não enviado".
 * Retorna os canais pelos quais a mensagem saiu, ex.: ['email', 'whatsapp'].
 */
function avisar_paciente(PDO $pdo, int $pacienteId, ?string $consultaId, string $tipo, string $titulo, string $mensagem): array
{
    $cfg = integracoes($pdo);
    $q = $pdo->prepare('SELECT email, telefone FROM pacientes WHERE id = ?');
    $q->execute([$pacienteId]);
    $paciente = $q->fetch() ?: [];
    $enviados = [];
    $tentativas = [];
    if (email_configurado($cfg) && filter_var((string)($paciente['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        $tentativas['email'] = static fn() => enviar_email($pdo, (string)$paciente['email'], $titulo . ' — Acessa+ Saúde', html_email_paciente($titulo, $mensagem), $mensagem);
    }
    if (whatsapp_api_configurado($cfg) && telefone_whatsapp($paciente['telefone'] ?? null) !== null) {
        $tentativas['whatsapp'] = static fn() => enviar_whatsapp_api($pdo, (string)$paciente['telefone'], $mensagem);
    }
    foreach ($tentativas as $canal => $enviar) {
        $status = 'enviada';
        try {
            $enviar();
            $enviados[] = $canal;
        } catch (Throwable $e) {
            $status = 'erro';
            error_log('Falha ao avisar paciente por ' . $canal . ': ' . $e->getMessage());
        }
        try {
            $pdo->prepare("INSERT INTO notificacoes (paciente_id,consulta_id,canal,tipo,mensagem,agendada_para,enviada_em,status) VALUES (?,?,?,?,?,NOW(),?,?)")
                ->execute([$pacienteId, $consultaId, $canal, $tipo, $mensagem, $status === 'enviada' ? date('Y-m-d H:i:s') : null, $status]);
        } catch (Throwable $e) {
            error_log('Falha ao registrar notificação: ' . $e->getMessage());
        }
    }
    return $enviados;
}
