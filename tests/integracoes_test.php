<?php
declare(strict_types=1);

require_once __DIR__ . '/../integracoes.php';

function expect_true(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('Falhou: ' . $label);
}

$base = array_fill_keys(INTEGRACAO_CAMPOS, '');
$smtp = array_merge($base, ['email_provedor' => 'smtp', 'smtp_host' => 'smtp.hostinger.com', 'smtp_usuario' => 'naoresponda@exemplo.com', 'smtp_senha' => 'segredo', 'email_remetente' => 'naoresponda@exemplo.com', 'email_nome' => 'Acessa+ Saúde']);
expect_true(email_configurado($smtp), 'SMTP completo fica ativo');
expect_true(!email_configurado(array_merge($smtp, ['smtp_senha' => ''])), 'SMTP sem senha fica inativo');
expect_true(!email_configurado(array_merge($smtp, ['email_remetente' => 'invalido'])), 'Remetente inválido desativa o e-mail');

$publico = integracoes_publicas(array_merge($smtp, ['meta_token' => 'token-secreto']));
expect_true($publico['smtp_senha'] === '' && $publico['meta_token'] === '', 'Segredos nunca voltam para o painel');
expect_true($publico['smtp_senha_configurado'] === true && $publico['resend_api_key_configurado'] === false, 'Painel sabe quais segredos existem');

expect_true(!whatsapp_api_configurado(array_merge($base, ['whatsapp_provedor' => 'manual', 'meta_token' => 'x', 'meta_phone_number_id' => '1', 'meta_template' => 't'])), 'Modo manual não envia pela API');
expect_true(whatsapp_api_configurado(array_merge($base, ['whatsapp_provedor' => 'meta', 'meta_token' => 'x', 'meta_phone_number_id' => '1', 'meta_template' => 't'])), 'API da Meta completa fica ativa');
expect_true(telefone_whatsapp('(27) 99999-0001') === '5527999990001', 'Celular recebe o código do país');
expect_true(telefone_whatsapp('123') === null, 'Telefone curto é rejeitado');

$mime = montar_email_mime($smtp, 'ana@exemplo.com', 'Consulta confirmada', '<p>Olá</p>', "Olá\n.linha com ponto");
expect_true(str_contains($mime, 'Subject: Consulta confirmada') && str_contains($mime, 'From: =?UTF-8?B?'), 'Cabeçalhos com acento são codificados');
expect_true(str_contains($mime, 'multipart/alternative') && !preg_match('/\r\n\.\r\n/', $mime), 'Mensagem multipart sem terminador prematuro');

echo "Integrações de envio: OK\n";
