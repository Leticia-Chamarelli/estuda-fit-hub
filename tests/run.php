<?php

declare(strict_types=1);

/**
 * Runner de testes sem dependências externas.
 * Uso: php tests/run.php [FiltroDeNome]
 *
 * Roda contra o banco DB_TEST_NAME (padrão estuda_fit_hub_test), recriando o schema antes.
 */

putenv('DB_NAME=' . (getenv('DB_TEST_NAME') ?: 'estuda_fit_hub_test'));
putenv('NOTIFICACAO_LATENCIA_MS=0');
putenv('GATEWAY_LATENCIA_MS=0');

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/FakeCobrancaClient.php';
require __DIR__ . '/TestCase.php';

use EstudaFitHub\Console\DbMigrate;

$filtro = $argv[1] ?? '';

ob_start();
(new DbMigrate())->executar([]);
ob_end_clean();

$arquivos = glob(__DIR__ . '/*Test.php') ?: [];
sort($arquivos);

$total = 0;
$falhas = 0;
$inicio = microtime(true);

foreach ($arquivos as $arquivo) {
    require_once $arquivo;
    $classe = basename($arquivo, '.php');
    if (!class_exists($classe)) {
        continue;
    }
    $instancia = new $classe();
    $metodos = array_filter(get_class_methods($instancia), static fn (string $m): bool => str_starts_with($m, 'test'));
    $imprimiuClasse = false;

    foreach ($metodos as $metodo) {
        $nomeCompleto = "{$classe}::{$metodo}";
        if ($filtro !== '' && stripos($nomeCompleto, $filtro) === false) {
            continue;
        }
        if (!$imprimiuClasse) {
            echo "\n{$classe}\n";
            $imprimiuClasse = true;
        }
        $total++;
        try {
            $instancia->limparBanco();
            $instancia->setUp();
            $instancia->$metodo();
            echo "  ok    {$metodo}\n";
        } catch (Throwable $e) {
            $falhas++;
            echo "  FALHA {$metodo}\n";
            echo '        ' . $e::class . ': ' . $e->getMessage() . "\n";
            echo "        em {$e->getFile()}:{$e->getLine()}\n";
        }
    }
}

printf("\n%d testes, %d falhas, %.1fs\n", $total, $falhas, microtime(true) - $inicio);
exit($falhas > 0 ? 1 : 0);
