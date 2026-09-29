<?php

declare(strict_types=1);

namespace Cobranca\Aplicacao;

use Cobranca\Infra\Db;

/**
 * Outbox do serviço. `publicar` roda na mesma transação que mudou a fatura, então evento e
 * estado nunca divergem. Cada evento carrega o retrato completo da fatura e a sua versão:
 * o consumidor não precisa de ordem global nem de chamar o serviço de volta.
 *
 * Contrato (v1):
 *   { "id": 42, "tipo": "FaturaPaga", "criado_em": "...",
 *     "fatura": { "codigo", "matricula_id", "aluno_id", "unidade_id", "competencia", "valor",
 *                 "vencimento", "status", "pago_em", "valor_pago", "qtd_pagamentos", "versao" } }
 */
final class Eventos
{
    public const FATURA_GERADA = 'FaturaGerada';
    public const FATURA_PAGA = 'FaturaPaga';
    public const FATURA_VENCIDA = 'FaturaVencida';
    public const FATURA_CANCELADA = 'FaturaCancelada';
    public const PAGAMENTO_RECUSADO = 'PagamentoRecusado';

    private const CAMPOS = ['codigo', 'matricula_id', 'aluno_id', 'unidade_id', 'competencia', 'valor', 'vencimento', 'status', 'pago_em', 'valor_pago', 'qtd_pagamentos', 'versao'];

    public static function publicar(string $tipo, array $fatura): void
    {
        $retrato = array_intersect_key($fatura, array_flip(self::CAMPOS));
        Db::inserir('eventos', [
            'tipo' => $tipo,
            'fatura_codigo' => $fatura['codigo'],
            'aluno_id' => $fatura['aluno_id'],
            'payload' => json_encode($retrato, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Feed paginado por cursor. Eventos mais novos que EVENTOS_ATRASO_MS ficam de fora para
     * que uma transação com id menor, ainda não commitada, não seja pulada pelo cursor.
     * @return array{eventos: array, proximo_cursor: int}
     */
    public static function feed(int $apos, int $limite): array
    {
        $atrasoMs = (int) (getenv('EVENTOS_ATRASO_MS') === false ? 2000 : getenv('EVENTOS_ATRASO_MS'));
        $linhas = Db::todos(
            'SELECT id, tipo, payload, criado_em FROM eventos
              WHERE id > ? AND criado_em <= TIMESTAMPADD(MICROSECOND, ?, NOW(3))
              ORDER BY id LIMIT ' . max(1, min($limite, 1000)),
            [$apos, -1000 * $atrasoMs],
        );

        $eventos = array_map(static fn (array $l): array => [
            'id' => (int) $l['id'],
            'tipo' => $l['tipo'],
            'criado_em' => $l['criado_em'],
            'fatura' => json_decode($l['payload'], true),
        ], $linhas);

        return ['eventos' => $eventos, 'proximo_cursor' => $eventos === [] ? $apos : end($eventos)['id']];
    }
}
