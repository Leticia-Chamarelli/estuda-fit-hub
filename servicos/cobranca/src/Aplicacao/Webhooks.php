<?php

declare(strict_types=1);

namespace Cobranca\Aplicacao;

use Cobranca\Http\Erro;
use Cobranca\Infra\Db;

/**
 * Caso de uso "receber o webhook de pagamento".
 *
 * Duplicado: a chave (event_id, ou hash do payload quando não vem event_id) é UNIQUE em
 * webhooks_recebidos; a segunda entrega só devolve o resultado da primeira.
 * Fora de ordem: `paga` é estado final. `refused` ou `pending` depois de `approved` não mudam
 * nada; `approved` repetido com outro event_id também não cria segundo pagamento
 * (UNIQUE em pagamentos.fatura_id). A fatura é travada com FOR UPDATE para que dois webhooks
 * simultâneos da mesma fatura sejam serializados.
 */
final class Webhooks
{
    /** @return array{resultado: string, fatura: ?string} */
    public function receber(array $p): array
    {
        if (empty($p['reference']) || empty($p['status'])) {
            throw new Erro(422, 'reference e status são obrigatórios');
        }
        $chave = !empty($p['event_id']) ? 'evt:' . $p['event_id'] : 'hash:' . sha1(self::canonico($p));

        return Db::transacao(function () use ($p, $chave): array {
            $webhookId = Db::inserir('webhooks_recebidos', [
                'chave' => substr($chave, 0, 80),
                'referencia' => (string) $p['reference'],
                'status_gateway' => (string) $p['status'],
                'payload' => json_encode($p, JSON_UNESCAPED_UNICODE),
                'resultado' => 'processando',
            ], ignorarDuplicado: true);
            if ($webhookId === 0) {
                $anterior = Db::um('SELECT resultado FROM webhooks_recebidos WHERE chave = ?', [substr($chave, 0, 80)]);

                return ['resultado' => 'duplicado:' . ($anterior['resultado'] ?? '?'), 'fatura' => null];
            }

            $fatura = Db::um('SELECT * FROM faturas WHERE gateway_ref = ? FOR UPDATE', [(string) $p['reference']]);
            $resultado = $fatura === null ? 'referencia_desconhecida' : $this->aplicar($fatura, $p, $webhookId);
            Db::executar('UPDATE webhooks_recebidos SET resultado = ? WHERE id = ?', [$resultado, $webhookId]);

            return ['resultado' => $resultado, 'fatura' => $fatura['codigo'] ?? null];
        });
    }

    private function aplicar(array $fatura, array $p, int $webhookId): string
    {
        $status = (string) $p['status'];
        if ($fatura['status'] === 'paga') {
            return 'ignorado_fatura_ja_paga';
        }

        if ($status === 'approved') {
            $valor = isset($p['amount']) ? number_format((float) $p['amount'], 2, '.', '') : (string) $fatura['valor'];
            $metodo = (string) ($p['method'] ?? 'desconhecido');
            Db::inserir('pagamentos', ['fatura_id' => $fatura['id'], 'valor' => $valor, 'metodo' => $metodo, 'webhook_id' => $webhookId], ignorarDuplicado: true);
            Faturas::transicionar($fatura, [
                'status' => 'paga',
                'pago_em' => date('Y-m-d H:i:s'),
                'valor_pago' => $valor,
                'qtd_pagamentos' => 1,
                'metodo' => $metodo,
            ], Eventos::FATURA_PAGA);

            return $fatura['status'] === 'cancelada' ? 'paga_apos_cancelamento' : 'paga';
        }

        if ($status === 'refused') {
            Faturas::transicionar($fatura, [], Eventos::PAGAMENTO_RECUSADO);

            return 'recusado';
        }

        return 'ignorado_status_' . preg_replace('/\W/', '', $status);
    }

    private static function canonico(array $p): string
    {
        ksort($p);

        return (string) json_encode($p);
    }
}
