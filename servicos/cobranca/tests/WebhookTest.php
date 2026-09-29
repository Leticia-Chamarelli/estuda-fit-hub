<?php

declare(strict_types=1);

use Cobranca\Aplicacao\Webhooks;
use Cobranca\Http\Erro;
use Cobranca\Infra\Db;

/** Caso de uso 2: receber o webhook de pagamento (duplicado e fora de ordem). */
final class WebhookTest extends TestCase
{
    private function fatura(): array
    {
        return $this->criarAssinatura()['primeira_fatura'];
    }

    private function webhook(array $p): array
    {
        return (new Webhooks())->receber($p);
    }

    public function testAprovadoPagaAFaturaRegistraPagamentoEPublica(): void
    {
        $f = $this->fatura();

        $r = $this->webhook(['reference' => $f['gateway_ref'], 'status' => 'approved', 'amount' => 119.9, 'method' => 'pix', 'event_id' => 'e1']);

        $this->assertSame('paga', $r['resultado']);
        $linha = Db::um('SELECT * FROM faturas WHERE id = ?', [$f['id']]);
        $this->assertSame('paga', $linha['status']);
        $this->assertSame('119.90', $linha['valor_pago']);
        $this->assertSame(1, $this->contar('pagamentos'));
        $this->assertSame(1, $this->contar("eventos WHERE tipo = 'FaturaPaga'"));
    }

    public function testMesmoEventoDuasVezesEhDuplicado(): void
    {
        $f = $this->fatura();
        $p = ['reference' => $f['gateway_ref'], 'status' => 'approved', 'event_id' => 'e2'];

        $this->webhook($p);
        $r = $this->webhook($p);

        $this->assertSame('duplicado:paga', $r['resultado']);
        $this->assertSame(1, $this->contar('pagamentos'));
        $this->assertSame(1, $this->contar("eventos WHERE tipo = 'FaturaPaga'"));
    }

    public function testSemEventIdDeduplicaPeloConteudo(): void
    {
        $f = $this->fatura();
        $p = ['reference' => $f['gateway_ref'], 'status' => 'approved', 'amount' => 119.9];

        $this->webhook($p);
        $r = $this->webhook(array_reverse($p, true));

        $this->assertSame('duplicado:paga', $r['resultado']);
    }

    public function testAprovadoReenviadoComOutroIdNaoPagaDuasVezes(): void
    {
        $f = $this->fatura();
        $this->webhook(['reference' => $f['gateway_ref'], 'status' => 'approved', 'event_id' => 'e3']);

        $r = $this->webhook(['reference' => $f['gateway_ref'], 'status' => 'approved', 'event_id' => 'e3-retry']);

        $this->assertSame('ignorado_fatura_ja_paga', $r['resultado']);
        $this->assertSame(1, $this->contar('pagamentos'));
    }

    public function testRecusadoOuPendenteDepoisDoAprovadoNaoRegride(): void
    {
        $f = $this->fatura();
        $this->webhook(['reference' => $f['gateway_ref'], 'status' => 'approved', 'event_id' => 'e4']);
        $this->webhook(['reference' => $f['gateway_ref'], 'status' => 'refused', 'event_id' => 'e3']);
        $this->webhook(['reference' => $f['gateway_ref'], 'status' => 'pending', 'event_id' => 'e2']);

        $this->assertSame('paga', Db::um('SELECT status FROM faturas WHERE id = ?', [$f['id']])['status']);
        $this->assertSame(0, $this->contar("eventos WHERE tipo = 'PagamentoRecusado'"));
    }

    public function testRecusadoAntesDoAprovadoPublicaRecusaEDepoisPaga(): void
    {
        $f = $this->fatura();
        $this->assertSame('recusado', $this->webhook(['reference' => $f['gateway_ref'], 'status' => 'refused', 'event_id' => 'e5'])['resultado']);
        $this->assertSame('paga', $this->webhook(['reference' => $f['gateway_ref'], 'status' => 'approved', 'event_id' => 'e6'])['resultado']);

        $this->assertSame(1, $this->contar("eventos WHERE tipo = 'PagamentoRecusado'"));
        $versoes = array_column(Db::todos("SELECT JSON_EXTRACT(payload, '$.versao') AS v FROM eventos ORDER BY id"), 'v');
        $this->assertSame($versoes, array_values(array_unique($versoes)), 'Versões estritamente crescentes');
    }

    public function testReferenciaDesconhecidaEhGuardadaParaAuditoria(): void
    {
        $r = $this->webhook(['reference' => 'gw_quem', 'status' => 'approved', 'event_id' => 'e7']);

        $this->assertSame('referencia_desconhecida', $r['resultado']);
        $this->assertSame(1, $this->contar("webhooks_recebidos WHERE resultado = 'referencia_desconhecida'"));
    }

    public function testPayloadInvalido(): void
    {
        $this->assertLanca(Erro::class, fn () => $this->webhook(['status' => 'approved']), 422);
    }
}
