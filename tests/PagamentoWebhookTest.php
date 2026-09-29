<?php

declare(strict_types=1);

use EstudaFitHub\Cobranca\WebhookInbox;
use EstudaFitHub\Controllers\PagamentoWebhookController;
use EstudaFitHub\Http\Request;
use EstudaFitHub\Models\Notificacao;
use EstudaFitHub\Models\Pagamento;
use EstudaFitHub\Support\DB;
use EstudaFitHub\Support\HttpException;

/**
 * Ajuste de testes: o endpoint agora só grava na inbox e responde 202; o efeito acontece
 * quando o worker processa a inbox. O helper `webhook` faz os dois passos, então os cenários
 * de negócio do legado (pagar, reativar aluno, recusar) continuam testados como antes.
 */
final class PagamentoWebhookTest extends TestCase
{
    private function receber(array $payload): int
    {
        $request = new Request('POST', '/webhooks/pagamento', [], $payload);

        return (new PagamentoWebhookController())->receber($request)->status();
    }

    private function processar(): array
    {
        return (new WebhookInbox($this->cobranca))->processar();
    }

    private function webhook(array $payload): int
    {
        $status = $this->receber($payload);
        $this->processar();

        return $status;
    }

    public function testPagamentoAprovadoMarcaFaturaPagaERegistraPagamento(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $fatura = $this->criarFatura($matricula, ['status' => 'vencida', 'vencimento' => $this->diasAtras(3)]);

        $status = $this->webhook([
            'reference' => $fatura->gateway_ref,
            'status' => 'approved',
            'amount' => 99.90,
            'method' => 'pix',
            'event_id' => 'evt_1',
        ]);

        $this->assertSame(202, $status);
        $this->assertSame('paga', $fatura->refresh()->status);
        $this->assertNotNull($fatura->pago_em);
        $this->assertSame(1, Pagamento::where('fatura_id', $fatura->id)->count());
        $this->assertSame(1, Notificacao::where('aluno_id', $aluno->id)->where('template', 'pagamento_confirmado')->count());
    }

    public function testPagamentoAprovadoReativaAlunoBloqueado(): void
    {
        $aluno = $this->criarAluno(['situacao' => 'bloqueado']);
        $matricula = $this->criarMatricula($aluno);
        $fatura = $this->criarFatura($matricula, ['status' => 'vencida', 'vencimento' => $this->diasAtras(20)]);

        $this->webhook(['reference' => $fatura->gateway_ref, 'status' => 'approved', 'event_id' => 'evt_2']);

        $this->assertSame('ativo', $aluno->refresh()->situacao);
    }

    public function testPagamentoRecusadoNotificaPorEmailESemMudarFatura(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $fatura = $this->criarFatura($matricula);

        $this->webhook(['reference' => $fatura->gateway_ref, 'status' => 'refused', 'event_id' => 'evt_3']);

        $this->assertSame('aberta', $fatura->refresh()->status);
        $this->assertSame(1, Notificacao::where('aluno_id', $aluno->id)->where('template', 'pagamento_recusado')->count());
    }

    /**
     * Substitui testWebhookDuplicadoRegistraPagamentoDuasVezesComportamentoAtual.
     * Aquele teste documentava um defeito (pagamento em dobro) que o desafio pede para eliminar.
     * O mesmo payload agora é descartado na entrada e, mesmo com outro event_id, não gera
     * segundo pagamento para fatura já paga.
     */
    public function testWebhookDuplicadoNaoRegistraPagamentoEmDobro(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $fatura = $this->criarFatura($matricula);
        $payload = ['reference' => $fatura->gateway_ref, 'status' => 'approved', 'event_id' => 'evt_4'];

        $this->webhook($payload);
        $this->webhook($payload);
        $this->webhook(array_merge($payload, ['event_id' => 'evt_4_reenvio']));

        $this->assertSame(1, Pagamento::where('fatura_id', $fatura->id)->count());
        $this->assertSame(1, Notificacao::where('aluno_id', $aluno->id)->where('template', 'pagamento_confirmado')->count());
        $this->assertSame(2, (int) DB::selectOne('SELECT COUNT(*) AS c FROM webhook_inbox')['c']);
    }

    public function testRecusaForaDeOrdemDepoisDoPagamentoNaoNotifica(): void
    {
        $aluno = $this->criarAluno();
        $fatura = $this->criarFatura($this->criarMatricula($aluno));

        $this->webhook(['reference' => $fatura->gateway_ref, 'status' => 'approved', 'event_id' => 'evt_a']);
        $this->webhook(['reference' => $fatura->gateway_ref, 'status' => 'refused', 'event_id' => 'evt_b']);

        $this->assertSame('paga', $fatura->refresh()->status);
        $this->assertSame(0, Notificacao::where('aluno_id', $aluno->id)->where('template', 'pagamento_recusado')->count());
    }

    /** Antes: 404. Agora a referência pode ser de fatura criada pelo serviço: vai para ele. */
    public function testReferenciaDesconhecidaNoLegadoEhEncaminhadaAoServico(): void
    {
        $status = $this->webhook(['reference' => 'gwc_nao_existe_no_legado', 'status' => 'approved', 'event_id' => 'evt_5']);

        $this->assertSame(202, $status);
        $this->assertCount(1, $this->cobranca->webhooks);
        $this->assertSame('gwc_nao_existe_no_legado', $this->cobranca->webhooks[0]['reference']);
    }

    public function testFaturaDeUnidadeMigradaEhEncaminhadaEPagamentoLegadoNaoMuda(): void
    {
        $aluno = $this->criarAluno();
        $fatura = $this->criarFatura($this->criarMatricula($aluno));
        $this->migrarUnidade((int) $aluno->unidade_id);

        $this->webhook(['reference' => $fatura->gateway_ref, 'status' => 'approved', 'event_id' => 'evt_6']);

        $this->assertCount(1, $this->cobranca->webhooks);
        $this->assertSame('aberta', $fatura->refresh()->status, 'Legado congelado para unidade migrada');
        $this->assertSame(0, Pagamento::where('fatura_id', $fatura->id)->count());
    }

    public function testServicoForaDoArNaoPerdeWebhookEReprocessaDepois(): void
    {
        $this->cobranca->foraDoAr = true;
        $this->receber(['reference' => 'gwc_novo', 'status' => 'approved', 'event_id' => 'evt_7']);

        $r = $this->processar();
        $this->assertSame(1, $r['pendentes']);
        $this->assertCount(0, $this->cobranca->webhooks);

        $this->cobranca->foraDoAr = false;
        $this->processar();
        $this->assertCount(1, $this->cobranca->webhooks);
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS c FROM webhook_inbox WHERE processado_em IS NULL')['c']);
    }

    public function testPayloadSemReferenciaEhRejeitado(): void
    {
        $this->assertLanca(HttpException::class, fn () => $this->receber(['status' => 'approved']), 422);
    }
}
