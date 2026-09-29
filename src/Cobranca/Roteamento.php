<?php

declare(strict_types=1);

namespace EstudaFitHub\Cobranca;

use EstudaFitHub\Support\DB;

/**
 * Strangler por unidade: diz quem é dono da ESCRITA de Cobrança de cada unidade.
 * Leitores (check-in, tela do aluno, régua, relatórios) não consultam isto: leem fin_faturas.
 */
final class Roteamento
{
    public const LEGADO = 'legado';
    public const SERVICO = 'servico';

    public static function modo(int $unidadeId): string
    {
        $linha = DB::selectOne('SELECT modo FROM cobranca_roteamento WHERE unidade_id = ?', [$unidadeId]);

        return $linha['modo'] ?? self::LEGADO;
    }

    public static function migrada(int $unidadeId): bool
    {
        return self::modo($unidadeId) === self::SERVICO;
    }

    /** @return int[] */
    public static function unidadesMigradas(): array
    {
        return array_map(
            'intval',
            array_column(DB::select('SELECT unidade_id FROM cobranca_roteamento WHERE modo = ?', [self::SERVICO]), 'unidade_id'),
        );
    }

    public static function definir(int $unidadeId, string $modo): void
    {
        DB::execute(
            'INSERT INTO cobranca_roteamento (unidade_id, modo) VALUES (?, ?) AS n ON DUPLICATE KEY UPDATE modo = n.modo',
            [$unidadeId, $modo],
        );
    }
}
