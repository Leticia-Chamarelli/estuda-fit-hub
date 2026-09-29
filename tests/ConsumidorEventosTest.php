<?php

declare(strict_types=1);

use EstudaFitHub\Cobranca\ConsumidorEventos;
use EstudaFitHub\Models\Notificacao;
use EstudaFitHub\Services\NotificacaoService;
use EstudaFitHub\Support\DB;

final class ConsumidorEventosTest extends TestCase
{
    private function consumir(): array
    {
        return (new ConsumidorEventos($this->cobranca, new NotificacaoService()))->consumir();
    }

    private function retrato(object $aluno, object $matricula, array $extra = []): array
    {
        return array_merge([
            'codigo' => 'fat_abc', 'matricula_id' => (int) $matricula->id, 'aluno_id' => (int) $aluno->id,
            'unidade_id' => (int) $aluno->unidade_id, 'competencia' => date('Y-m-01'), 'valor' => '119.90',
            'vencimento' => date('Y-m-d', strtotime('+3 days')), 'status' => 'aberta', 'pago_em' => null,
            'valor_pago' => '0.00', 'qtd_pagamentos' => 0, 'versao' => 1,
        ], $extra);
    }

    private function linha(string $codigo): ?array
    {
        return DB::selectOne('SELECT * FROM fin_faturas WHERE codigo = ?', [$codigo]);
    }

    public function testFaturaGeradaEntraNoModeloDeLeituraENotificaUmaVez(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $this->cobranca->publicar('FaturaGerada', $this->retrato($aluno, $matricula));

        $this->consumir();
        // Reler o feed do início (cursor perdido, por exemplo) não repete efeito.
        DB::execute('UPDATE cobranca_consumo SET cursor_evento = 0');
        $r = $this->consumir();

        $this->assertSame(1, $r['ignorados']);
        $this->assertSame('aberta', $this->linha('fat_abc')['status']);
        $this->assertSame('servico', $this->linha('fat_abc')['origem']);
        $this->assertSame(1, Notificacao::where('aluno_id', $aluno->id)->where('template', 'fatura_gerada')->count());
    }

    public function testEventoAntigoChegandoDepoisNaoRegrideOEstado(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $this->cobranca->publicar('FaturaPaga', $this->retrato($aluno, $matricula, ['status' => 'paga', 'pago_em' => '2026-09-01 10:00:00', 'valor_pago' => '119.90', 'qtd_pagamentos' => 1, 'versao' => 3]));
        $this->cobranca->publicar('FaturaVencida', $this->retrato($aluno, $matricula, ['status' => 'vencida', 'versao' => 2]));

        $this->consumir();

        $linha = $this->linha('fat_abc');
        $this->assertSame('paga', $linha['status']);
        $this->assertSame(3, (int) $linha['versao']);
        $this->assertSame('119.90', $linha['valor_pago']);
    }

    public function testFaturaPagaDesbloqueiaAlunoEEnviaSms(): void
    {
        $aluno = $this->criarAluno(['situacao' => 'bloqueado']);
        $matricula = $this->criarMatricula($aluno);
        $this->cobranca->publicar('FaturaPaga', $this->retrato($aluno, $matricula, ['status' => 'paga', 'versao' => 2]));

        $this->consumir();

        $this->assertSame('ativo', $aluno->refresh()->situacao);
        $this->assertSame(1, Notificacao::where('aluno_id', $aluno->id)->where('template', 'pagamento_confirmado')->count());
    }

    public function testEventoDeFaturaImportadaAssumeALinhaDoLegado(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $legada = $this->criarFatura($matricula, ['status' => 'vencida', 'vencimento' => $this->diasAtras(8)]);
        $codigo = 'leg_' . $legada->id;
        $this->assertSame('legado', $this->linha($codigo)['origem']);
        $this->migrarUnidade((int) $aluno->unidade_id);

        $this->cobranca->publicar('FaturaPaga', $this->retrato($aluno, $matricula, ['codigo' => $codigo, 'status' => 'paga', 'versao' => 2]));
        $this->consumir();

        $this->assertSame('paga', $this->linha($codigo)['status']);
        $this->assertSame('servico', $this->linha($codigo)['origem']);
        $this->assertSame(1, (int) DB::selectOne('SELECT COUNT(*) AS c FROM fin_faturas')['c'], 'Mesma linha, sem duplicar');
    }

    public function testEventoAtrasadoDeUnidadeRevertidaNaoRetomaALinha(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $legada = $this->criarFatura($matricula, ['status' => 'aberta']);
        // Unidade foi migrada e revertida; o feed ainda tem um evento antigo dessa fatura.
        $this->cobranca->publicar('FaturaVencida', $this->retrato($aluno, $matricula, ['codigo' => 'leg_' . $legada->id, 'status' => 'vencida', 'versao' => 5]));

        $r = $this->consumir();

        $this->assertSame(1, $r['aplicados']);
        $linha = $this->linha('leg_' . $legada->id);
        $this->assertSame('legado', $linha['origem']);
        $this->assertSame('aberta', $linha['status']);
    }

    public function testServicoForaDoArNaoAvancaCursor(): void
    {
        $this->cobranca->foraDoAr = true;

        $this->assertLanca(\EstudaFitHub\Cobranca\CobrancaIndisponivel::class, fn () => $this->consumir());
        $this->assertNull(DB::selectOne('SELECT * FROM cobranca_consumo'));
    }
}
