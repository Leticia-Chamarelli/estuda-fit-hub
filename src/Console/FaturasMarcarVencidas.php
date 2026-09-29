<?php

declare(strict_types=1);

namespace EstudaFitHub\Console;

use EstudaFitHub\Support\DB;

/**
 * Cron diário LEGADO: faturas abertas com vencimento no passado viram vencidas,
 * só para unidades não migradas (as migradas são do cron do serviço).
 */
final class FaturasMarcarVencidas implements Comando
{
    public function executar(array $args): int
    {
        $afetadas = DB::execute(
            "UPDATE faturas f
               JOIN alunos a ON a.id = f.aluno_id
               LEFT JOIN cobranca_roteamento r ON r.unidade_id = a.unidade_id
                SET f.status = 'vencida'
              WHERE f.status = 'aberta' AND f.vencimento < CURDATE()
                AND COALESCE(r.modo, 'legado') = 'legado'",
        );

        echo "{$afetadas} faturas marcadas como vencidas\n";

        return 0;
    }
}
