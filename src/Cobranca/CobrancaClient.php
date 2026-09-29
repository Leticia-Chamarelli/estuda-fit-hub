<?php

declare(strict_types=1);

namespace EstudaFitHub\Cobranca;

/**
 * Porta do monólito para o serviço de Cobrança. Nunca é usada no caminho da catraca.
 *
 * Erros: CobrancaIndisponivel para falha transitória (rede, timeout, 5xx), que o chamador
 * deve tentar de novo; ComandoRejeitado para 4xx, que não adianta repetir.
 */
interface CobrancaClient
{
    /** Entrega um comando da outbox. Idempotente do lado do serviço. */
    public function enviarComando(string $tipo, array $payload): void;

    /** Encaminha um webhook do gateway recebido pela inbox do monólito. */
    public function encaminharWebhook(array $payload): void;

    /** @return array{eventos: array<int, array>, proximo_cursor: int} */
    public function eventos(int $apos, int $limite): array;

    /** Carga do histórico de uma unidade (backfill). @return array{assinaturas: int, faturas: int} */
    public function importarLote(array $lote): array;

    /** @return array<int, array{competencia: string, status: string, quantidade: int, total: string}> */
    public function reconciliacao(int $unidadeId): array;

    /** Faturas de uma unidade, para rollback. @return array<int, array> */
    public function faturasDaUnidade(int $unidadeId): array;

    /** Serviço para de gerar faturas para a unidade (rollback). */
    public function devolverUnidade(int $unidadeId): void;
}
