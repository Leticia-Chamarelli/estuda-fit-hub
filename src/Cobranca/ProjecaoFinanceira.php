<?php

declare(strict_types=1);

namespace EstudaFitHub\Cobranca;

use EstudaFitHub\Support\DB;

/**
 * Aplica em fin_faturas o retrato de uma fatura publicado pelo serviço.
 *
 * Cada evento carrega a fatura inteira e a sua `versao` (contador por fatura no serviço).
 * O upsert só troca a linha se a versão recebida for maior: evento repetido ou fora de ordem
 * não regride o estado. A atribuição de `versao` fica por último porque o MySQL avalia o
 * ON DUPLICATE KEY UPDATE da esquerda para a direita.
 */
final class ProjecaoFinanceira
{
    /**
     * @param bool $unidadeMigrada false quando a unidade voltou ao legado (rollback): nesse caso o
     *        evento não retoma uma linha que os triggers do legado já reassumiram. A checagem fica
     *        dentro do upsert para ser atômica com a escrita do rollback.
     * @return bool true se a linha mudou
     */
    public static function aplicar(array $f, bool $unidadeMigrada = true): bool
    {
        $podeAssumir = $unidadeMigrada ? '1' : "fin_faturas.origem = 'servico'";
        $novo = "n.versao > fin_faturas.versao AND ({$podeAssumir})";
        $colunas = ['origem', 'status', 'valor', 'vencimento', 'pago_em', 'valor_pago', 'qtd_pagamentos', 'matricula_id', 'aluno_id', 'competencia'];
        $sets = implode(', ', array_map(
            static fn (string $c): string => "{$c} = IF({$novo}, n.{$c}, fin_faturas.{$c})",
            $colunas,
        ));

        $afetadas = DB::execute(
            "INSERT INTO fin_faturas (codigo, origem, matricula_id, aluno_id, competencia, valor, vencimento, status, pago_em, valor_pago, qtd_pagamentos, versao)
             VALUES (?, 'servico', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) AS n
             ON DUPLICATE KEY UPDATE {$sets}, versao = IF({$novo}, n.versao, fin_faturas.versao)",
            [
                (string) $f['codigo'],
                (int) $f['matricula_id'],
                (int) $f['aluno_id'],
                (string) $f['competencia'],
                (string) $f['valor'],
                (string) $f['vencimento'],
                (string) $f['status'],
                $f['pago_em'] ?? null,
                (string) ($f['valor_pago'] ?? '0'),
                (int) ($f['qtd_pagamentos'] ?? 0),
                (int) $f['versao'],
            ],
        );

        return $afetadas > 0;
    }
}
