<?php

declare(strict_types=1);

use EstudaFitHub\Cobranca\RelayOutbox;
use EstudaFitHub\Console\FaturasGerarMensais;
use EstudaFitHub\Console\FaturasMarcarVencidas;
use EstudaFitHub\Models\Fatura;
use EstudaFitHub\Models\Notificacao;
use EstudaFitHub\Services\MatriculaService;
use EstudaFitHub\Support\DB;

/**
 * Pontos A, D e G numa unidade migrada: o monólito para de escrever `faturas` e passa a
 * mandar comandos pela outbox. Unidades legadas seguem exatamente como antes.
 */
final class RoteamentoPorUnidadeTest extends TestCase
{
    public function testMatriculaEmUnidadeMigradaGravaOutboxENaoCriaFaturaLegada(): void
    {
        $aluno = $this->criarAluno();
        $this->migrarUnidade((int) $aluno->unidade_id);
        $plano = $this->criarPlano(['valor_mensal' => '119.90']);

        $matricula = (new MatriculaService())->criar($aluno, $plano, 15);

        $this->assertSame(0, Fatura::where('matricula_id', $matricula->id)->count());
        $this->assertSame(0, Notificacao::where('aluno_id', $aluno->id)->count(), 'E-mail sai só quando a fatura existir');
        $outbox = DB::select('SELECT * FROM cobranca_outbox');
        $this->assertCount(1, $outbox);
        $this->assertSame('MatriculaCriada', $outbox[0]['tipo']);
        $payload = json_decode($outbox[0]['payload'], true);
        $this->assertSame('119.90', $payload['valor_mensal']);
        $this->assertSame(15, $payload['dia_vencimento']);
    }

    public function testMatriculaCriadaComServicoForaDoArFicaNaOutboxEEntregaDepois(): void
    {
        $aluno = $this->criarAluno();
        $this->migrarUnidade((int) $aluno->unidade_id);
        (new MatriculaService())->criar($aluno, $this->criarPlano(), 10);

        $this->cobranca->foraDoAr = true;
        $r = (new RelayOutbox($this->cobranca))->publicar();
        $this->assertSame(1, $r['pendentes']);

        $this->cobranca->foraDoAr = false;
        $r = (new RelayOutbox($this->cobranca))->publicar();
        $this->assertSame(1, $r['enviados']);
        $this->assertSame('MatriculaCriada', $this->cobranca->comandos[0][0]);
    }

    public function testCancelamentoEmUnidadeMigradaNaoMexeEmFaturasEVaiPelaOutbox(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $antiga = $this->criarFatura($matricula, ['status' => 'aberta']);
        $this->migrarUnidade((int) $aluno->unidade_id);

        (new MatriculaService())->cancelar($matricula);

        $this->assertSame('aberta', $antiga->refresh()->status);
        $this->assertSame('inativo', $aluno->refresh()->situacao);
        $this->assertSame('MatriculaCancelada', DB::selectOne('SELECT tipo FROM cobranca_outbox')['tipo']);
    }

    public function testCronsLegadosIgnoramUnidadesMigradas(): void
    {
        $legado = $this->criarAluno();
        $migrado = $this->criarAluno();
        $mLegado = $this->criarMatricula($legado);
        $mMigrado = $this->criarMatricula($migrado);
        $this->migrarUnidade((int) $migrado->unidade_id);
        $this->criarFatura($mLegado, ['competencia' => '2026-01-01', 'vencimento' => $this->diasAtras(3)]);
        $congelada = $this->criarFatura($mMigrado, ['competencia' => '2026-01-01', 'vencimento' => $this->diasAtras(3)]);

        ob_start();
        (new FaturasGerarMensais())->executar(['2026-05']);
        (new FaturasMarcarVencidas())->executar([]);
        ob_end_clean();

        $this->assertSame(1, Fatura::where('matricula_id', $mLegado->id)->where('competencia', '2026-05-01')->count());
        $this->assertSame(0, Fatura::where('matricula_id', $mMigrado->id)->where('competencia', '2026-05-01')->count());
        $this->assertSame('aberta', $congelada->refresh()->status);
    }
}
