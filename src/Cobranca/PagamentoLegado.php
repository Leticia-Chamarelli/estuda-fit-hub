<?php

declare(strict_types=1);

namespace EstudaFitHub\Cobranca;

use EstudaFitHub\Models\Aluno;
use EstudaFitHub\Models\Fatura;
use EstudaFitHub\Models\Pagamento;
use EstudaFitHub\Services\NotificacaoService;

/**
 * Regra antiga do webhook, aplicada às unidades que ainda estão no legado.
 * É o mesmo comportamento do controller original, com duas correções necessárias para
 * sobreviver a duplicatas e fora de ordem:
 *  - `approved` para fatura já paga não gera segundo pagamento;
 *  - `refused` ou `pending` para fatura já paga não fazem nada.
 * Deve rodar dentro de uma transação; devolve a notificação para depois do commit.
 */
final class PagamentoLegado
{
    public function __construct(private readonly NotificacaoService $notificacoes = new NotificacaoService())
    {
    }

    public function aplicar(Fatura $fatura, array $payload): callable
    {
        $status = (string) ($payload['status'] ?? '');
        $aluno = $fatura->aluno();
        $semNotificacao = static fn () => null;

        if ($fatura->status === 'paga' || $aluno === null) {
            return $semNotificacao;
        }

        if ($status === 'approved') {
            $fatura->update(['status' => 'paga', 'pago_em' => date('Y-m-d H:i:s')]);
            Pagamento::create([
                'fatura_id' => $fatura->id,
                'valor' => $payload['amount'] ?? $fatura->valor,
                'metodo' => (string) ($payload['method'] ?? 'desconhecido'),
                'gateway_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
            if ($aluno->situacao === 'bloqueado') {
                $aluno->update(['situacao' => 'ativo']);
            }

            return fn () => $this->notificacoes->enviarSms($aluno, 'pagamento_confirmado', ['fatura_id' => $fatura->id]);
        }

        if ($status === 'refused') {
            return fn () => $this->notificacoes->enviarEmail($aluno, 'pagamento_recusado', ['fatura_id' => $fatura->id]);
        }

        return $semNotificacao;
    }
}
