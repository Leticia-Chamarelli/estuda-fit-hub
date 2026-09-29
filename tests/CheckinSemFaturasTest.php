<?php

declare(strict_types=1);

use EstudaFitHub\Cobranca\ConsumidorEventos;
use EstudaFitHub\Controllers\CheckinController;
use EstudaFitHub\Http\Request;
use EstudaFitHub\Services\NotificacaoService;
use EstudaFitHub\Support\DB;

/**
 * Ponto de acoplamento B. Os testes originais de CheckinTest continuam passando sem mudança
 * (a fatura legada chega ao modelo de leitura pelos triggers). Estes provam o resto:
 * a catraca não depende da tabela `faturas` nem do serviço estar no ar.
 */
final class CheckinSemFaturasTest extends TestCase
{
    private function checkin(string $cpf, int $unidadeId): array
    {
        return (new CheckinController())->registrar(new Request('POST', '/checkins', [], ['cpf' => $cpf, 'unidade_id' => $unidadeId]))->dados();
    }

    private function retrato(object $aluno, object $matricula, array $extra = []): array
    {
        return array_merge([
            'codigo' => 'fat_' . bin2hex(random_bytes(4)),
            'matricula_id' => (int) $matricula->id,
            'aluno_id' => (int) $aluno->id,
            'unidade_id' => (int) $aluno->unidade_id,
            'competencia' => date('Y-m-01'),
            'valor' => '99.90',
            'vencimento' => $this->diasAtras(10),
            'status' => 'vencida',
            'pago_em' => null,
            'valor_pago' => '0.00',
            'qtd_pagamentos' => 0,
            'versao' => 2,
        ], $extra);
    }

    public function testCatracaIgnoraATabelaFaturas(): void
    {
        $aluno = $this->criarAluno();
        $this->criarFatura($this->criarMatricula($aluno), ['status' => 'vencida', 'vencimento' => $this->diasAtras(10)]);
        // Se a catraca lesse `faturas`, continuaria bloqueando.
        DB::execute('DELETE FROM fin_faturas');

        $this->assertTrue($this->checkin($aluno->cpf, (int) $aluno->unidade_id)['liberado']);
    }

    public function testUnidadeMigradaBloqueiaPorEventoDoServicoComServicoForaDoAr(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $this->migrarUnidade((int) $aluno->unidade_id);

        $this->cobranca->publicar('FaturaVencida', $this->retrato($aluno, $matricula));
        (new ConsumidorEventos($this->cobranca, new NotificacaoService()))->consumir();
        $this->cobranca->foraDoAr = true;

        $inicio = microtime(true);
        $resposta = $this->checkin($aluno->cpf, (int) $aluno->unidade_id);
        $ms = (microtime(true) - $inicio) * 1000;

        $this->assertFalse($resposta['liberado']);
        $this->assertSame('inadimplente', $resposta['motivo_bloqueio']);
        $this->assertTrue($ms < 300, sprintf('Check-in levou %.0f ms', $ms));
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) AS c FROM faturas')['c']);
    }

    public function testPagamentoPublicadoPeloServicoLiberaCatraca(): void
    {
        $aluno = $this->criarAluno();
        $matricula = $this->criarMatricula($aluno);
        $this->migrarUnidade((int) $aluno->unidade_id);
        $retrato = $this->retrato($aluno, $matricula);

        $this->cobranca->publicar('FaturaVencida', $retrato);
        $this->cobranca->publicar('FaturaPaga', array_merge($retrato, ['status' => 'paga', 'pago_em' => date('Y-m-d H:i:s'), 'versao' => 3]));
        (new ConsumidorEventos($this->cobranca, new NotificacaoService()))->consumir();

        $this->assertTrue($this->checkin($aluno->cpf, (int) $aluno->unidade_id)['liberado']);
    }
}
