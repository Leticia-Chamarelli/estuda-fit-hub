<?php

declare(strict_types=1);

namespace EstudaFitHub\Console;

use EstudaFitHub\Cobranca\MigracaoUnidade;
use EstudaFitHub\Cobranca\Servico;

/**
 * Corte de uma unidade: carga do histórico, troca de dono, carga de delta e reconciliação.
 * Uso: php bin/console cobranca:migrar-unidade <unidade_id>
 */
final class CobrancaMigrarUnidade implements Comando
{
    public function executar(array $args): int
    {
        $unidadeId = (int) ($args[0] ?? 0);
        if ($unidadeId <= 0) {
            fwrite(STDERR, "Uso: cobranca:migrar-unidade <unidade_id>\n");

            return 1;
        }

        $r = (new MigracaoUnidade(Servico::cliente()))->migrar($unidadeId, static fn (string $m) => print("- {$m}\n"));

        printf(
            "Carga: %d assinaturas, %d faturas. Delta: %d assinaturas, %d faturas.\n",
            $r['carga']['assinaturas'], $r['carga']['faturas'], $r['delta']['assinaturas'], $r['delta']['faturas'],
        );

        return Reconciliacao::imprimir($r['divergencias']);
    }
}
