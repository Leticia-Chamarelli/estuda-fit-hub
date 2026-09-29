<?php

declare(strict_types=1);

use Cobranca\Aplicacao\Assinaturas;
use Cobranca\Infra\Db;

final class FalhaDeAssercao extends RuntimeException
{
}

abstract class TestCase
{
    private static int $matricula = 1000;

    public function limparBanco(): void
    {
        Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['eventos', 'webhooks_recebidos', 'pagamentos', 'faturas', 'assinaturas'] as $t) {
            Db::pdo()->exec("TRUNCATE TABLE `{$t}`");
        }
        Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function assertSame(mixed $esperado, mixed $atual, string $mensagem = ''): void
    {
        if ($esperado !== $atual) {
            throw new FalhaDeAssercao(sprintf('%sEsperava %s, recebi %s', $mensagem !== '' ? "{$mensagem}. " : '', var_export($esperado, true), var_export($atual, true)));
        }
    }

    protected function assertTrue(mixed $v, string $mensagem = ''): void
    {
        $this->assertSame(true, $v, $mensagem);
    }

    protected function assertNotNull(mixed $v, string $mensagem = ''): void
    {
        if ($v === null) {
            throw new FalhaDeAssercao($mensagem !== '' ? $mensagem : 'Esperava valor não nulo');
        }
    }

    protected function assertLanca(string $classe, callable $fn, ?int $status = null): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            if (!$e instanceof $classe || ($status !== null && ($e->status ?? null) !== $status)) {
                throw new FalhaDeAssercao(sprintf('Esperava %s %s, recebi %s: %s', $classe, $status ?? '', $e::class, $e->getMessage()));
            }

            return;
        }
        throw new FalhaDeAssercao("Esperava exceção {$classe}");
    }

    protected function contar(string $sql, array $params = []): int
    {
        return (int) Db::um("SELECT COUNT(*) AS c FROM {$sql}", $params)['c'];
    }

    /** Payload de MatriculaCriada como o relay do monólito envia. */
    protected function comando(array $extra = []): array
    {
        return array_merge([
            'matricula_id' => ++self::$matricula,
            'aluno_id' => 50,
            'unidade_id' => 5,
            'plano_id' => 2,
            'valor_mensal' => '119.90',
            'dia_vencimento' => 10,
            'inicio' => '2026-09-15',
            'fim' => '2027-09-15',
            'primeiro_vencimento' => '2026-09-18',
        ], $extra);
    }

    protected function criarAssinatura(array $extra = []): array
    {
        return (new Assinaturas())->criar($this->comando($extra));
    }
}
