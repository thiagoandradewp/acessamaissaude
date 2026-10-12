<?php
declare(strict_types=1);

require_once __DIR__ . '/../common.php';

function expect_same($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': esperado ' . var_export($expected, true) . ', obtido ' . var_export($actual, true));
    }
}

$serra = admin_region_from_row(['cidade' => '  Serra ', 'estado' => 'es']);
expect_same(['cidade' => 'Serra', 'estado' => 'ES'], $serra, 'Região normalizada da Secretaria');
expect_same(null, admin_region_from_row(['cidade' => '', 'estado' => 'ES']), 'Conta antiga sem cidade mantém escopo global');
expect_same(null, admin_region_from_row(null), 'Sem conta não há região');

expect_same(true, ubs_pertence_regiao(['cidade' => 'serra', 'estado' => 'ES'], $serra), 'Cidade sem diferenciar maiúsculas');
expect_same(true, ubs_pertence_regiao(['cidade' => 'Serra  ', 'estado' => 'es '], $serra), 'Espaços e UF minúscula');
expect_same(false, ubs_pertence_regiao(['cidade' => 'Vitória', 'estado' => 'ES'], $serra), 'Outra cidade da mesma UF');
expect_same(false, ubs_pertence_regiao(['cidade' => 'Serra', 'estado' => 'PB'], $serra), 'Mesma cidade em outra UF');
expect_same(true, ubs_pertence_regiao(['cidade' => 'Qualquer', 'estado' => 'SP'], null), 'Sem região, tudo é permitido');

$saoJose = admin_region_from_row(['cidade' => 'São José', 'estado' => 'SC']);
if (function_exists('mb_strtolower')) {
    expect_same(true, ubs_pertence_regiao(['cidade' => 'SÃO JOSÉ', 'estado' => 'SC'], $saoJose), 'Acentos em maiúsculas');
}

echo "Escopo regional da Secretaria: OK\n";
