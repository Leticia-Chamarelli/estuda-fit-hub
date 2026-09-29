<?php

declare(strict_types=1);

namespace EstudaFitHub\Console;

use EstudaFitHub\Cobranca\MigracaoUnidade;
use EstudaFitHub\Cobranca\Servico;

/**
 * Compara legado e serviço (quantidade e soma por competência e status) para uma unidade.
 * Sai com código 1 se houver divergência: é o critério de avanço do plano de migração.
 * Uso: php bin/console cobranca:reconciliar <unidade_id>
 */
final class Reconciliacao implements Comando
{
    public function executar(array $args): int
    {
        $unidadeId = (int) ($args[0] ?? 0);
        if ($unidadeId <= 0) {
            fwrite(STDERR, "Uso: cobranca:reconciliar <unidade_id>\n");

            return 1;
        }

        return self::imprimir((new MigracaoUnidade(Servico::cliente()))->reconciliar($unidadeId));
    }

    public static function imprimir(array $divergencias): int
    {
        if ($divergencias === []) {
            echo "Reconciliação OK: legado e serviço batem por competência e status\n";

            return 0;
        }

        echo "DIVERGÊNCIAS (quantidade/total):\n";
        foreach ($divergencias as $d) {
            printf("  %s %-10s legado %-16s serviço %s\n", $d['competencia'], $d['status'], $d['legado'], $d['servico']);
        }

        return 1;
    }
}
