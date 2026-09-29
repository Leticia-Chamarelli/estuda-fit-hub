<?php

declare(strict_types=1);

use EstudaFitHub\Models\Aluno;
use EstudaFitHub\Models\Fatura;
use EstudaFitHub\Models\Matricula;
use EstudaFitHub\Models\Plano;
use EstudaFitHub\Models\Unidade;
use EstudaFitHub\Support\DB;

final class FalhaDeAssercao extends RuntimeException
{
}

abstract class TestCase
{
    private const TABELAS = [
        'notificacoes', 'checkins', 'pagamentos', 'faturas', 'matriculas', 'planos', 'alunos', 'unidades',
        'fin_faturas', 'cobranca_roteamento', 'cobranca_outbox', 'cobranca_consumo', 'cobranca_eventos_aplicados', 'webhook_inbox',
    ];

    private static int $sequencia = 0;

    protected FakeCobrancaClient $cobranca;

    public function setUp(): void
    {
        // Nenhum teste do monólito fala com o serviço de verdade.
        $this->cobranca = new FakeCobrancaClient();
        \EstudaFitHub\Cobranca\Servico::usar($this->cobranca);
    }

    protected function migrarUnidade(int $unidadeId): void
    {
        \EstudaFitHub\Cobranca\Roteamento::definir($unidadeId, 'servico');
    }

    public function limparBanco(): void
    {
        DB::raw('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABELAS as $tabela) {
            DB::raw("TRUNCATE TABLE `{$tabela}`");
        }
        DB::raw('SET FOREIGN_KEY_CHECKS = 1');
    }

    // Asserções

    protected function assertTrue(mixed $condicao, string $mensagem = ''): void
    {
        if ($condicao !== true) {
            throw new FalhaDeAssercao($mensagem !== '' ? $mensagem : 'Esperava true, recebi ' . var_export($condicao, true));
        }
    }

    protected function assertFalse(mixed $condicao, string $mensagem = ''): void
    {
        if ($condicao !== false) {
            throw new FalhaDeAssercao($mensagem !== '' ? $mensagem : 'Esperava false, recebi ' . var_export($condicao, true));
        }
    }

    protected function assertSame(mixed $esperado, mixed $atual, string $mensagem = ''): void
    {
        if ($esperado !== $atual) {
            throw new FalhaDeAssercao(sprintf(
                '%sEsperava %s, recebi %s',
                $mensagem !== '' ? $mensagem . '. ' : '',
                var_export($esperado, true),
                var_export($atual, true),
            ));
        }
    }

    protected function assertNotNull(mixed $valor, string $mensagem = ''): void
    {
        if ($valor === null) {
            throw new FalhaDeAssercao($mensagem !== '' ? $mensagem : 'Esperava valor não nulo');
        }
    }

    protected function assertNull(mixed $valor, string $mensagem = ''): void
    {
        if ($valor !== null) {
            throw new FalhaDeAssercao($mensagem !== '' ? $mensagem : 'Esperava null, recebi ' . var_export($valor, true));
        }
    }

    protected function assertCount(int $esperado, array $itens, string $mensagem = ''): void
    {
        $this->assertSame($esperado, count($itens), $mensagem !== '' ? $mensagem : 'Quantidade de itens');
    }

    /** @param class-string<Throwable> $classe */
    protected function assertLanca(string $classe, callable $fn, ?int $status = null): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            if (!$e instanceof $classe) {
                throw new FalhaDeAssercao(sprintf('Esperava %s, recebi %s: %s', $classe, $e::class, $e->getMessage()));
            }
            if ($status !== null && property_exists($e, 'status') && $e->status !== $status) {
                throw new FalhaDeAssercao(sprintf('Esperava status %d, recebi %d', $status, $e->status));
            }

            return;
        }
        throw new FalhaDeAssercao("Esperava exceção {$classe}, nada foi lançado");
    }

    // Fábricas

    protected function criarUnidade(array $extra = []): Unidade
    {
        return Unidade::create(array_merge(['nome' => 'Unidade Teste', 'cidade' => 'São Paulo'], $extra));
    }

    protected function criarPlano(array $extra = []): Plano
    {
        return Plano::create(array_merge([
            'nome' => 'Plano Teste',
            'valor_mensal' => '99.90',
            'duracao_meses' => 1,
            'permite_multiunidade' => 0,
            'ativo' => 1,
        ], $extra));
    }

    protected function criarAluno(array $extra = []): Aluno
    {
        $n = ++self::$sequencia;
        $unidadeId = $extra['unidade_id'] ?? $this->criarUnidade()->id;

        return Aluno::create(array_merge([
            'nome' => "Aluno {$n}",
            'email' => "aluno{$n}@exemplo.com",
            'telefone' => '11999990000',
            'cpf' => sprintf('%011d', 90000000000 + $n),
            'unidade_id' => $unidadeId,
            'situacao' => 'ativo',
        ], $extra));
    }

    protected function criarMatricula(Aluno $aluno, ?Plano $plano = null, array $extra = []): Matricula
    {
        $plano ??= $this->criarPlano();

        return Matricula::create(array_merge([
            'aluno_id' => $aluno->id,
            'plano_id' => $plano->id,
            'inicio' => date('Y-m-d', strtotime('-2 months')),
            'fim' => date('Y-m-d', strtotime('+10 months')),
            'status' => 'ativa',
            'dia_vencimento' => 10,
        ], $extra));
    }

    protected function criarFatura(Matricula $matricula, array $extra = []): Fatura
    {
        $n = ++self::$sequencia;

        return Fatura::create(array_merge([
            'matricula_id' => $matricula->id,
            'aluno_id' => $matricula->aluno_id,
            'competencia' => date('Y-m-01'),
            'valor' => '99.90',
            'vencimento' => date('Y-m-d', strtotime('+5 days')),
            'status' => 'aberta',
            'gateway_ref' => "gw_teste_{$n}",
        ], $extra));
    }

    protected function diasAtras(int $dias): string
    {
        return date('Y-m-d', strtotime("-{$dias} days"));
    }
}
