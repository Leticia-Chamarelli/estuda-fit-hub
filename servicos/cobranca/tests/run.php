<?php

declare(strict_types=1);

/**
 * Runner de testes do serviço de Cobrança, no mesmo estilo do monólito.
 * Uso: php tests/run.php [FiltroDeNome]
 * Roda contra COBRANCA_DB_TEST_NAME (padrão cobranca_test), recriando o schema antes.
 */

putenv('COBRANCA_DB_NAME=' . (getenv('COBRANCA_DB_TEST_NAME') ?: 'cobranca_test'));
putenv('GATEWAY_LATENCIA_MS=0');
putenv('EVENTOS_ATRASO_MS=0');

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/TestCase.php';

use Cobranca\Infra\Db;

$sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(__DIR__ . '/../database/schema.sql'));
foreach (array_filter(array_map('trim', explode(';', $sql))) as $comando) {
    Db::pdo()->exec($comando);
}

$filtro = $argv[1] ?? '';
$arquivos = glob(__DIR__ . '/*Test.php') ?: [];
sort($arquivos);
$total = 0;
$falhas = 0;
$inicio = microtime(true);

foreach ($arquivos as $arquivo) {
    require_once $arquivo;
    $classe = basename($arquivo, '.php');
    $instancia = new $classe();
    $impresso = false;
    foreach (array_filter(get_class_methods($instancia), static fn (string $m): bool => str_starts_with($m, 'test')) as $metodo) {
        if ($filtro !== '' && stripos("{$classe}::{$metodo}", $filtro) === false) {
            continue;
        }
        if (!$impresso) {
            echo "\n{$classe}\n";
            $impresso = true;
        }
        $total++;
        try {
            $instancia->limparBanco();
            $instancia->$metodo();
            echo "  ok    {$metodo}\n";
        } catch (Throwable $e) {
            $falhas++;
            echo "  FALHA {$metodo}\n        " . $e::class . ': ' . $e->getMessage() . "\n        em {$e->getFile()}:{$e->getLine()}\n";
        }
    }
}

printf("\n%d testes, %d falhas, %.1fs\n", $total, $falhas, microtime(true) - $inicio);
exit($falhas > 0 ? 1 : 0);
