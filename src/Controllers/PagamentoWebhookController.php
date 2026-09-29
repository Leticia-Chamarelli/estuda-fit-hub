<?php

declare(strict_types=1);

namespace EstudaFitHub\Controllers;

use EstudaFitHub\Cobranca\WebhookInbox;
use EstudaFitHub\Http\Request;
use EstudaFitHub\Http\Response;

/**
 * Recebe as notificações do gateway de pagamento.
 * Payload esperado: { "reference": "gw_...", "status": "approved|refused|pending", "amount": 89.9, "method": "pix", "event_id": "evt_..." }
 *
 * Durante a transição só grava na inbox e responde 202 (duplicata também recebe 202, para o
 * gateway parar de reenviar). O processamento é do worker: legado para unidades não migradas,
 * serviço de Cobrança para as demais. Ao final da migração o gateway aponta direto para o serviço.
 */
final class PagamentoWebhookController
{
    public function receber(Request $request): Response
    {
        $request->exigir(['reference', 'status']);

        $novo = WebhookInbox::receber($request->all());

        return Response::json(['recebido' => true, 'duplicado' => !$novo], 202);
    }
}
