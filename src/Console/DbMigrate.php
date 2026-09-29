<?php

declare(strict_types=1);

namespace EstudaFitHub\Console;

use EstudaFitHub\Support\DB;
use EstudaFitHub\Support\ScriptSql;

/**
 * Recria o schema do zero a partir de database/schema.sql e aplica, em ordem,
 * os arquivos de database/migrations (transição da extração de Cobrança).
 * Com --se-vazio não faz nada se o schema já existir (usado na subida do compose).
 */
final class DbMigrate implements Comando
{
    public function executar(array $args): int
    {
        if (in_array('--se-vazio', $args, true) && DB::selectOne("SHOW TABLES LIKE 'fin_faturas'") !== null) {
            echo "Schema já existe, nada a fazer\n";

            return 0;
        }

        $base = dirname(__DIR__, 2) . '/database';
        $migracoes = glob($base . '/migrations/*.sql') ?: [];
        sort($migracoes);
        $arquivos = array_merge([$base . '/schema.sql'], $migracoes);

        $total = 0;
        foreach ($arquivos as $arquivo) {
            $sql = file_get_contents($arquivo);
            if ($sql === false) {
                fwrite(STDERR, "Não foi possível ler {$arquivo}\n");

                return 1;
            }
            foreach (ScriptSql::comandos($sql) as $comando) {
                DB::raw($comando);
                $total++;
            }
        }

        echo 'Schema aplicado: ' . count($arquivos) . " arquivos, {$total} comandos executados\n";

        return 0;
    }
}
