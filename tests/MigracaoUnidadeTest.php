<?php

declare(strict_types=1);

use EstudaFitHub\Cobranca\MigracaoUnidade;
use EstudaFitHub\Cobranca\Roteamento;
use EstudaFitHub\Models\Fatura;
use EstudaFitHub\Models\Pagamento;
use EstudaFitHub\Support\DB;

final class MigracaoUnidadeTest extends TestCase
{
    private function migracao(): MigracaoUnidade
    {
        return new MigracaoUnidade($this->cobranca, tamanhoLote: 2, pausaAntesDoDeltaSegundos: 0);
    }

    public function testCorteEnviaHistoricoEmLotesETrocaODono(): void
    {
        $unidade = $this->criarUnidade();
        $outra = $this->criarUnidade();
        $aluno = $this->criarAluno(['unidade_id' => $unidade->id]);
        $matricula = $this->criarMatricula($aluno);
        $paga = $this->criarFatura($matricula, ['status' => 'paga', 'competencia' => '2026-07-01']);
        Pagamento::create(['fatura_id' => $paga->id, 'valor' => '99.90', 'metodo' => 'pix']);
        $this->criarFatura($matricula, ['competencia' => '2026-08-01']);
        $this->criarFatura($matricula, ['competencia' => '2026-09-01']);
        $this->criarFatura($this->criarMatricula($this->criarAluno(['unidade_id' => $outra->id])));

        $r = $this->migracao()->migrar((int) $unidade->id);

        $this->assertSame(1, $r['carga']['assinaturas']);
        $this->assertSame(3, $r['carga']['faturas'], 'Só faturas da unidade');
        $this->assertSame(3, $r['delta']['faturas'], 'Delta reenvia (idempotente)');
        $this->assertTrue(Roteamento::migrada((int) $unidade->id));
        $this->assertFalse(Roteamento::migrada((int) $outra->id));

        $primeiroLote = $this->cobranca->lotes[1]['faturas'];
        $this->assertCount(2, $primeiroLote);
        $this->assertSame('leg_' . $paga->id, $primeiroLote[0]['codigo']);
        $this->assertSame('99.90', $primeiroLote[0]['valor_pago']);
        $this->assertSame($paga->gateway_ref, $primeiroLote[0]['gateway_ref'], 'Referência do gateway preservada');
    }

    public function testReconciliacaoApontaDivergencia(): void
    {
        $unidade = $this->criarUnidade();
        $this->criarFatura($this->criarMatricula($this->criarAluno(['unidade_id' => $unidade->id])), ['competencia' => '2026-09-01', 'valor' => '99.90']);
        $this->cobranca->linhasReconciliacao = [['competencia' => '2026-09-01', 'status' => 'aberta', 'quantidade' => 1, 'total' => '99.90']];

        $this->assertCount(0, $this->migracao()->reconciliar((int) $unidade->id));

        $this->cobranca->linhasReconciliacao = [];
        $divergencias = $this->migracao()->reconciliar((int) $unidade->id);
        $this->assertCount(1, $divergencias);
        $this->assertSame('1/99.90', $divergencias[0]['legado']);
    }

    public function testRollbackTrazDeVoltaFaturasDoServicoSemDuplicarModeloDeLeitura(): void
    {
        $unidade = $this->criarUnidade();
        $aluno = $this->criarAluno(['unidade_id' => $unidade->id]);
        $matricula = $this->criarMatricula($aluno);
        $importada = $this->criarFatura($matricula, ['status' => 'aberta', 'competencia' => '2026-08-01']);
        $this->migrarUnidade((int) $unidade->id);

        // O que o serviço tem depois de alguns dias: a importada foi paga e uma nova foi gerada.
        $this->cobranca->faturasParaDevolver = [
            ['codigo' => 'leg_' . $importada->id, 'legado_id' => $importada->id, 'matricula_id' => $matricula->id, 'aluno_id' => $aluno->id,
             'competencia' => '2026-08-01', 'valor' => '99.90', 'vencimento' => '2026-08-10', 'status' => 'paga',
             'pago_em' => '2026-08-09 10:00:00', 'valor_pago' => '99.90', 'metodo' => 'pix', 'gateway_ref' => $importada->gateway_ref],
            ['codigo' => 'fat_novo', 'legado_id' => null, 'matricula_id' => $matricula->id, 'aluno_id' => $aluno->id,
             'competencia' => '2026-09-01', 'valor' => '99.90', 'vencimento' => '2026-09-10', 'status' => 'aberta',
             'pago_em' => null, 'valor_pago' => '0.00', 'metodo' => null, 'gateway_ref' => 'gwc_novo'],
        ];
        DB::insert('fin_faturas', [
            'codigo' => 'fat_novo', 'origem' => 'servico', 'matricula_id' => $matricula->id, 'aluno_id' => $aluno->id,
            'competencia' => '2026-09-01', 'valor' => '99.90', 'vencimento' => '2026-09-10', 'status' => 'aberta', 'versao' => 1,
        ]);

        $r = $this->migracao()->reverter((int) $unidade->id);
        $this->migracao()->reverter((int) $unidade->id); // repetir é seguro

        $this->assertSame(['atualizadas' => 1, 'inseridas' => 1], $r);
        $this->assertFalse(Roteamento::migrada((int) $unidade->id));
        $this->assertSame('paga', $importada->refresh()->status);
        $this->assertSame(1, Pagamento::where('fatura_id', $importada->id)->count());
        $this->assertSame(1, Fatura::where('codigo_externo', 'fat_novo')->count());
        $this->assertSame(2, (int) DB::selectOne('SELECT COUNT(*) AS c FROM fin_faturas')['c']);
        $this->assertSame('legado', DB::selectOne("SELECT origem FROM fin_faturas WHERE codigo = 'fat_novo'")['origem']);
        $this->assertSame('99.90', DB::selectOne('SELECT valor_pago FROM fin_faturas WHERE codigo = ?', ['leg_' . $importada->id])['valor_pago']);
    }
}
