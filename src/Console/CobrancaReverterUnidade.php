<?php

declare(strict_types=1);

namespace EstudaFitHub\Console;

use EstudaFitHub\Cobranca\MigracaoUnidade;
use EstudaFitHub\Cobranca\Servico;

/**
 * Rollback de uma unidade: a escrita volta ao legado e o que o serviço criou ou mudou
 * é trazido de volta para `faturas`. Pode ser repetido.
 * Uso: php bin/console cobranca:reverter-unidade <unidade_id>
 */
final class CobrancaReverterUnidade implements Comando
{
    public function executar(array $args): int
    {
        $unidadeId = (int) ($args[0] ?? 0);
        if ($unidadeId <= 0) {
            fwrite(STDERR, "Uso: cobranca:reverter-unidade <unidade_id>\n");

            return 1;
        }

        $r = (new MigracaoUnidade(Servico::cliente()))->reverter($unidadeId);
        echo "Unidade {$unidadeId} de volta ao legado: {$r['atualizadas']} faturas atualizadas, {$r['inseridas']} trazidas do serviço\n";

        return 0;
    }
}
