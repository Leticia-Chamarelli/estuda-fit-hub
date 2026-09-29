<?php

declare(strict_types=1);

namespace EstudaFitHub\Cobranca;

use EstudaFitHub\Support\DB;

/**
 * Leitura da situação financeira de um aluno a partir do modelo de leitura local (fin_faturas).
 * Não consulta a tabela `faturas` nem o serviço de Cobrança: continua respondendo com o
 * serviço fora do ar e não muda de comportamento quando a unidade migra.
 */
final class SituacaoFinanceira
{
    /** Tolerância da catraca, em dias, depois do vencimento. Regra de acesso: é do monólito. */
    public const TOLERANCIA_CATRACA_DIAS = 5;

    /** Vencimento mais antigo entre as faturas vencidas do aluno, ou null se está em dia. */
    public static function vencidaDesde(int $alunoId): ?string
    {
        $linha = DB::selectOne(
            "SELECT vencimento FROM fin_faturas WHERE aluno_id = ? AND status = 'vencida' ORDER BY vencimento ASC LIMIT 1",
            [$alunoId],
        );

        return $linha['vencimento'] ?? null;
    }

    public static function bloqueiaCatraca(int $alunoId, ?string $hoje = null): bool
    {
        $desde = self::vencidaDesde($alunoId);
        if ($desde === null) {
            return false;
        }
        $limite = date('Y-m-d', strtotime(($hoje ?? date('Y-m-d')) . ' -' . self::TOLERANCIA_CATRACA_DIAS . ' days'));

        return $desde < $limite;
    }

    /** @return array{faturas_abertas: int, faturas_vencidas: int} */
    public static function resumo(int $alunoId): array
    {
        $linha = DB::selectOne(
            "SELECT SUM(status = 'aberta') AS abertas, SUM(status = 'vencida') AS vencidas
               FROM fin_faturas WHERE aluno_id = ? AND status IN ('aberta', 'vencida')",
            [$alunoId],
        );

        return [
            'faturas_abertas' => (int) ($linha['abertas'] ?? 0),
            'faturas_vencidas' => (int) ($linha['vencidas'] ?? 0),
        ];
    }
}
