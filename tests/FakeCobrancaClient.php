<?php

declare(strict_types=1);

use EstudaFitHub\Cobranca\CobrancaClient;
use EstudaFitHub\Cobranca\CobrancaIndisponivel;

/**
 * Dublê do serviço de Cobrança: grava o que recebeu, serve um feed de eventos montado
 * pelo teste e pode simular o serviço fora do ar.
 */
final class FakeCobrancaClient implements CobrancaClient
{
    public bool $foraDoAr = false;
    /** @var array<int, array{string, array}> */
    public array $comandos = [];
    /** @var array<int, array> */
    public array $webhooks = [];
    /** @var array<int, array> */
    public array $feed = [];
    /** @var array<int, array> */
    public array $lotes = [];
    public array $faturasParaDevolver = [];
    public array $linhasReconciliacao = [];

    public function enviarComando(string $tipo, array $payload): void
    {
        $this->falharSeForaDoAr();
        $this->comandos[] = [$tipo, $payload];
    }

    public function encaminharWebhook(array $payload): void
    {
        $this->falharSeForaDoAr();
        $this->webhooks[] = $payload;
    }

    public function eventos(int $apos, int $limite): array
    {
        $this->falharSeForaDoAr();
        $eventos = array_values(array_filter($this->feed, static fn (array $e): bool => $e['id'] > $apos));
        $eventos = array_slice($eventos, 0, $limite);

        return ['eventos' => $eventos, 'proximo_cursor' => $eventos === [] ? $apos : end($eventos)['id']];
    }

    public function importarLote(array $lote): array
    {
        $this->falharSeForaDoAr();
        $this->lotes[] = $lote;

        return ['assinaturas' => count($lote['assinaturas']), 'faturas' => count($lote['faturas'])];
    }

    public function reconciliacao(int $unidadeId): array
    {
        return $this->linhasReconciliacao;
    }

    public function faturasDaUnidade(int $unidadeId): array
    {
        return $this->faturasParaDevolver;
    }

    public function devolverUnidade(int $unidadeId): void
    {
        $this->comandos[] = ['DevolverUnidade', ['unidade_id' => $unidadeId]];
    }

    /** Monta um evento no formato do feed do serviço. */
    public function publicar(string $tipo, array $fatura): void
    {
        $this->feed[] = ['id' => count($this->feed) + 1, 'tipo' => $tipo, 'criado_em' => date('Y-m-d H:i:s'), 'fatura' => $fatura];
    }

    private function falharSeForaDoAr(): void
    {
        if ($this->foraDoAr) {
            throw new CobrancaIndisponivel('Serviço de Cobrança fora do ar (simulado)');
        }
    }
}
