<?php

declare(strict_types=1);

use Cobranca\Aplicacao\CronMensal;
use Cobranca\Aplicacao\Eventos;
use Cobranca\Aplicacao\Migracao;
use Cobranca\Aplicacao\Webhooks;
use Cobranca\Infra\Db;

final class MigracaoTest extends TestCase
{
    private function lote(string $statusFatura = 'aberta'): array
    {
        return [
            'assinaturas' => [[
                'matricula_id' => 77, 'aluno_id' => 7, 'unidade_id' => 5, 'plano_id' => 1, 'valor_mensal' => '89.90',
                'dia_vencimento' => 10, 'inicio' => '2026-01-10', 'fim' => '2027-01-10', 'status' => 'ativa',
            ]],
            'faturas' => [
                ['legado_id' => 900, 'codigo' => 'leg_900', 'matricula_id' => 77, 'competencia' => '2026-08-01', 'valor' => '89.90',
                 'vencimento' => '2026-08-10', 'status' => 'paga', 'pago_em' => '2026-08-09 10:00:00', 'gateway_ref' => 'gw_77_202608',
                 'valor_pago' => '89.90', 'qtd_pagamentos' => 1, 'metodo' => 'pix'],
                ['legado_id' => 901, 'codigo' => 'leg_901', 'matricula_id' => 77, 'competencia' => '2026-09-01', 'valor' => '89.90',
                 'vencimento' => '2026-09-10', 'status' => $statusFatura, 'pago_em' => null, 'gateway_ref' => 'gw_77_202609',
                 'valor_pago' => '0.00', 'qtd_pagamentos' => 0, 'metodo' => null],
            ],
        ];
    }

    public function testCargaEhSilenciosaEIdempotente(): void
    {
        (new Migracao())->importarLote($this->lote());
        (new Migracao())->importarLote($this->lote());

        $this->assertSame(1, $this->contar('assinaturas'));
        $this->assertSame(2, $this->contar('faturas'));
        $this->assertSame(1, $this->contar('pagamentos'));
        $this->assertSame(0, $this->contar('eventos'), 'O monólito já tem o histórico; não republica');
    }

    public function testWebhookDeFaturaHistoricaEncontraPelaReferenciaAntiga(): void
    {
        (new Migracao())->importarLote($this->lote());

        $r = (new Webhooks())->receber(['reference' => 'gw_77_202609', 'status' => 'approved', 'event_id' => 'x1']);

        $this->assertSame('paga', $r['resultado']);
        $this->assertSame('leg_901', Eventos::feed(0, 10)['eventos'][0]['fatura']['codigo'], 'Mesmo código da linha legada no modelo de leitura');
    }

    public function testNovaCargaNaoSobrescreveFaturaQueOServicoJaAlterou(): void
    {
        (new Migracao())->importarLote($this->lote());
        (new Webhooks())->receber(['reference' => 'gw_77_202609', 'status' => 'approved', 'event_id' => 'x2']);

        (new Migracao())->importarLote($this->lote('aberta'));

        $this->assertSame('paga', Db::um("SELECT status FROM faturas WHERE codigo = 'leg_901'")['status']);
    }

    public function testDepoisDaDevolucaoOLegadoVoltaASerAFonteNaRemigracao(): void
    {
        (new Migracao())->importarLote($this->lote());
        (new Webhooks())->receber(['reference' => 'gw_77_202609', 'status' => 'refused', 'event_id' => 'x3']);
        $novaDoServico = (new CronMensal())->executar('2026-10');
        $this->assertSame(1, $novaDoServico['geradas']);
        $codigoNova = Db::um("SELECT codigo FROM faturas WHERE competencia = '2026-10-01'")['codigo'];
        $versaoAntes = (int) Db::um("SELECT versao FROM faturas WHERE codigo = 'leg_901'")['versao'];

        (new Migracao())->devolver(5);
        // No legado, durante o rollback: a 901 foi paga e a fatura criada pelo serviço virou a 950.
        $lote = $this->lote();
        $lote['faturas'][1] = array_merge($lote['faturas'][1], ['status' => 'paga', 'pago_em' => '2026-10-02 09:00:00', 'valor_pago' => '89.90', 'qtd_pagamentos' => 1, 'metodo' => 'boleto']);
        $lote['faturas'][] = ['legado_id' => 950, 'codigo' => $codigoNova, 'matricula_id' => 77, 'competencia' => '2026-10-01', 'valor' => '89.90',
            'vencimento' => '2026-10-10', 'status' => 'aberta', 'pago_em' => null, 'gateway_ref' => null, 'valor_pago' => '0.00', 'qtd_pagamentos' => 0, 'metodo' => null];
        (new Migracao())->importarLote($lote);

        $this->assertSame('paga', Db::um("SELECT status FROM faturas WHERE codigo = 'leg_901'")['status']);
        $this->assertTrue((int) Db::um("SELECT versao FROM faturas WHERE codigo = 'leg_901'")['versao'] > $versaoAntes, 'Versão continua crescendo');
        $this->assertSame(950, (int) Db::um('SELECT legado_id FROM faturas WHERE codigo = ?', [$codigoNova])['legado_id']);
        $this->assertSame(3, $this->contar('faturas WHERE legado_id IS NOT NULL'), 'Reconciliação enxerga as três');
    }

    public function testCronNaoDuplicaCompetenciaImportada(): void
    {
        (new Migracao())->importarLote($this->lote());

        $r = (new CronMensal())->executar('2026-09');

        $this->assertSame(0, $r['geradas']);
        $this->assertSame(1, $r['existentes']);
    }

    public function testReconciliacaoEDevolucao(): void
    {
        (new Migracao())->importarLote($this->lote());

        $linhas = (new Migracao())->reconciliacao(5);
        $this->assertSame(2, count($linhas));
        $this->assertSame(1, (new Migracao())->devolver(5));
        $this->assertSame(0, (new CronMensal())->executar('2026-10')['geradas'], 'Unidade devolvida sai do cron');
    }
}
