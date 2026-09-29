<?php

declare(strict_types=1);

use Cobranca\Aplicacao\Assinaturas;
use Cobranca\Aplicacao\CronMensal;
use Cobranca\Aplicacao\Eventos;
use Cobranca\Aplicacao\Faturas;
use Cobranca\Http\Erro;
use Cobranca\Infra\Db;
use Cobranca\Infra\Gateway;

/** Caso de uso 1: gerar fatura para uma matrícula. */
final class GerarFaturaTest extends TestCase
{
    public function testMatriculaCriadaGeraAssinaturaPrimeiraFaturaEEvento(): void
    {
        $r = $this->criarAssinatura();

        $f = $r['primeira_fatura'];
        $this->assertTrue($r['criada']);
        $this->assertSame('2026-09-01', $f['competencia']);
        $this->assertSame('2026-09-18', $f['vencimento']);
        $this->assertSame('119.90', $f['valor']);
        $this->assertSame('aberta', $f['status']);
        $this->assertNotNull($f['gateway_ref'], 'Registrada no gateway');
        $this->assertSame(1, $this->contar("eventos WHERE tipo = 'FaturaGerada'"));
    }

    public function testComandoRepetidoNaoDuplicaNada(): void
    {
        $comando = $this->comando();
        $primeira = (new Assinaturas())->criar($comando);
        $segunda = (new Assinaturas())->criar($comando);

        $this->assertSame(false, $segunda['criada']);
        $this->assertSame($primeira['primeira_fatura']['codigo'], $segunda['primeira_fatura']['codigo']);
        $this->assertSame(1, $this->contar('assinaturas'));
        $this->assertSame(1, $this->contar('faturas'));
        $this->assertSame(1, $this->contar('eventos'));
    }

    public function testGerarFaturaDeUmaCompetenciaEhIdempotente(): void
    {
        $a = $this->criarAssinatura(['dia_vencimento' => 20]);
        $matricula = (int) $a['assinatura']['matricula_id'];

        $r1 = (new Assinaturas())->gerarFatura($matricula, '2026-10');
        $r2 = (new Assinaturas())->gerarFatura($matricula, '2026-10');

        $this->assertTrue($r1['criada']);
        $this->assertSame(false, $r2['criada']);
        $this->assertSame('2026-10-20', $r1['fatura']['vencimento']);
        $this->assertSame(2, $this->contar('faturas'));
    }

    public function testGatewayForaDoArNaoImpedeAFaturaEWorkerRecupera(): void
    {
        $gatewayQuebrado = new class extends Gateway {
            public function registrarCobranca(string $codigoFatura, string $valor, string $vencimento): string
            {
                throw new RuntimeException('timeout');
            }
        };
        $a = $this->criarAssinatura();
        $r = (new Faturas($gatewayQuebrado))->gerar($a['assinatura'], '2026-11');

        $this->assertSame(null, $r['fatura']['gateway_ref']);
        $this->assertSame(1, (new Faturas())->registrarPendentes());
        $this->assertNotNull(Db::um('SELECT gateway_ref FROM faturas WHERE codigo = ?', [$r['fatura']['codigo']])['gateway_ref']);
    }

    public function testAssinaturaCanceladaNaoGeraFatura(): void
    {
        $a = $this->criarAssinatura();
        $matricula = (int) $a['assinatura']['matricula_id'];
        $r = (new Assinaturas())->cancelar($matricula);

        $this->assertSame(1, $r['faturas_canceladas']);
        $this->assertSame(1, $this->contar("eventos WHERE tipo = 'FaturaCancelada'"));
        $this->assertLanca(Erro::class, fn () => (new Assinaturas())->gerarFatura($matricula, '2026-12'), 422);
    }

    public function testCronParticionadoGeraTudoUmaVezSo(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->criarAssinatura();
        }
        (new Assinaturas())->cancelar((int) Db::um('SELECT matricula_id FROM assinaturas ORDER BY id LIMIT 1')['matricula_id']);

        $cron = new CronMensal();
        $total = 0;
        foreach ([0, 1, 2] as $particao) {
            $total += $cron->executar('2027-01', $particao, 3, lote: 2)['geradas'];
        }
        $repeticao = $cron->executar('2027-01', 0, 1);

        $this->assertSame(6, $total, 'Só assinaturas ativas');
        $this->assertSame(0, $repeticao['geradas']);
        $this->assertSame(6, $repeticao['existentes']);
        $this->assertSame(6, $this->contar("faturas WHERE competencia = '2027-01-01'"));
    }

    public function testMarcarVencidasPublicaEventoComVersaoNova(): void
    {
        $f = $this->criarAssinatura()['primeira_fatura'];

        $n = (new Faturas())->marcarVencidas('2026-10-01');

        $this->assertSame(1, $n);
        $feed = Eventos::feed(0, 10)['eventos'];
        $this->assertSame('FaturaVencida', $feed[1]['tipo']);
        $this->assertSame('vencida', $feed[1]['fatura']['status']);
        $this->assertTrue($feed[1]['fatura']['versao'] > $feed[0]['fatura']['versao']);
        $this->assertSame($f['codigo'], $feed[1]['fatura']['codigo']);
    }
}
