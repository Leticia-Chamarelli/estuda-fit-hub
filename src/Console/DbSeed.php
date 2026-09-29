<?php

declare(strict_types=1);

namespace EstudaFitHub\Console;

use DateTimeImmutable;
use EstudaFitHub\Support\DB;

/**
 * Popula o banco com dados fictícios determinísticos (semente fixa).
 * Uso: php bin/console db:seed [--alunos=2000]
 */
final class DbSeed implements Comando
{
    private const NOMES = [
        'Ana', 'Bruno', 'Carla', 'Diego', 'Elaine', 'Fábio', 'Gabriela', 'Henrique', 'Isabela', 'João',
        'Karina', 'Leonardo', 'Mariana', 'Nicolas', 'Olívia', 'Paulo', 'Quésia', 'Rafael', 'Sabrina', 'Thiago',
    ];

    private const SOBRENOMES = [
        'Almeida', 'Barbosa', 'Cardoso', 'Duarte', 'Esteves', 'Ferreira', 'Gomes', 'Herrera', 'Ibrahim', 'Jesus',
        'Klein', 'Lima', 'Martins', 'Nogueira', 'Oliveira', 'Pereira', 'Queiroz', 'Ribeiro', 'Santos', 'Teixeira',
    ];

    private const TABELAS = [
        'notificacoes', 'checkins', 'pagamentos', 'faturas', 'matriculas', 'planos', 'alunos', 'unidades',
        'fin_faturas', 'cobranca_roteamento', 'cobranca_outbox', 'cobranca_consumo', 'cobranca_eventos_aplicados', 'webhook_inbox',
    ];

    public function executar(array $args): int
    {
        if (in_array('--se-vazio', $args, true) && DB::selectOne('SELECT 1 FROM alunos LIMIT 1') !== null) {
            echo "Banco já populado, nada a fazer\n";

            return 0;
        }

        $quantidadeAlunos = 2000;
        foreach ($args as $arg) {
            if (preg_match('/^--alunos=(\d+)$/', $arg, $m)) {
                $quantidadeAlunos = max(1, (int) $m[1]);
            }
        }

        mt_srand(42);
        $hoje = new DateTimeImmutable('today');

        $this->limpar();

        $unidades = [
            ['Centro', 'São Paulo'], ['Zona Sul', 'São Paulo'], ['Zona Norte', 'São Paulo'],
            ['Barra', 'Rio de Janeiro'], ['Cambuí', 'Campinas'],
        ];
        $this->inserirLote('unidades', ['nome', 'cidade'], $unidades);

        $planos = [
            ['Básico', '89.90', 1, 0, 1],
            ['Fit', '119.90', 1, 0, 1],
            ['Fit Anual', '99.90', 12, 0, 1],
            ['Multi', '159.90', 1, 1, 1],
            ['Multi Anual', '139.90', 12, 1, 1],
            ['Premium', '249.90', 12, 1, 1],
        ];
        $this->inserirLote('planos', ['nome', 'valor_mensal', 'duracao_meses', 'permite_multiunidade', 'ativo'], $planos);

        // Alunos
        $alunos = [];
        for ($i = 1; $i <= $quantidadeAlunos; $i++) {
            $nome = self::NOMES[mt_rand(0, 19)] . ' ' . self::SOBRENOMES[mt_rand(0, 19)] . ' ' . self::SOBRENOMES[mt_rand(0, 19)];
            $alunos[] = [
                $nome,
                sprintf('aluno%d@exemplo.com', $i),
                sprintf('119%08d', mt_rand(0, 99999999)),
                sprintf('%011d', 10000000000 + $i),
                mt_rand(1, count($unidades)),
                'ativo',
            ];
        }
        $this->inserirLote('alunos', ['nome', 'email', 'telefone', 'cpf', 'unidade_id', 'situacao'], $alunos);

        // Matrículas: uma por aluno
        $matriculas = [];
        $diasVencimento = [5, 10, 15, 20, 25];
        for ($alunoId = 1; $alunoId <= $quantidadeAlunos; $alunoId++) {
            $planoId = mt_rand(1, count($planos));
            $duracao = (int) $planos[$planoId - 1][2];
            $inicio = $hoje->modify('-' . mt_rand(0, 400) . ' days');
            $fim = $inicio->modify("+{$duracao} months");
            $sorteio = mt_rand(1, 100);
            $status = $sorteio <= 90 ? 'ativa' : ($sorteio <= 95 ? 'trancada' : 'cancelada');
            if ($status === 'ativa' && $fim < $hoje) {
                $fim = $hoje->modify('+1 month'); // renovada
            }
            $matriculas[] = [
                $alunoId, $planoId, $inicio->format('Y-m-d'), $fim->format('Y-m-d'), $status,
                $diasVencimento[mt_rand(0, 4)],
            ];
        }
        $this->inserirLote('matriculas', ['aluno_id', 'plano_id', 'inicio', 'fim', 'status', 'dia_vencimento'], $matriculas);

        // Faturas: últimas 6 competências das matrículas ativas
        $competencias = [];
        for ($i = 5; $i >= 0; $i--) {
            $competencias[] = $hoje->modify("first day of -{$i} months");
        }

        $faturas = [];
        $bloqueados = [];
        $inativos = [];
        foreach ($matriculas as $indice => $m) {
            $matriculaId = $indice + 1;
            [$alunoId, $planoId, $inicio, , $status, $diaVencimento] = $m;
            if ($status === 'cancelada') {
                $inativos[] = $alunoId;
                continue;
            }
            if ($status !== 'ativa') {
                continue;
            }
            $inicioMes = (new DateTimeImmutable($inicio))->modify('first day of this month');
            foreach ($competencias as $competencia) {
                if ($competencia < $inicioMes) {
                    continue;
                }
                $vencimento = $competencia->setDate((int) $competencia->format('Y'), (int) $competencia->format('m'), $diaVencimento);
                $valor = $planos[$planoId - 1][1];
                $pagoEm = null;
                if ($vencimento < $hoje) {
                    if (mt_rand(1, 100) <= 92) {
                        $statusFatura = 'paga';
                        $pagoEm = $vencimento->modify('-' . mt_rand(0, 5) . ' days')->format('Y-m-d 10:00:00');
                    } else {
                        $statusFatura = 'vencida';
                        $diasAtraso = (int) $vencimento->diff($hoje)->days;
                        if ($diasAtraso > 10 && mt_rand(1, 100) <= 60) {
                            $bloqueados[$alunoId] = true;
                        }
                    }
                } else {
                    $statusFatura = 'aberta';
                }
                $faturas[] = [
                    $matriculaId, $alunoId, $competencia->format('Y-m-d'), $valor, $vencimento->format('Y-m-d'),
                    $statusFatura, $pagoEm, sprintf('gw_%d_%s', $matriculaId, $competencia->format('Ym')),
                ];
            }
        }
        $this->inserirLote(
            'faturas',
            ['matricula_id', 'aluno_id', 'competencia', 'valor', 'vencimento', 'status', 'pago_em', 'gateway_ref'],
            $faturas,
        );

        // Pagamentos das faturas pagas
        $pagas = DB::select("SELECT id, valor, pago_em FROM faturas WHERE status = 'paga'");
        $metodos = ['pix', 'cartao', 'boleto'];
        $pagamentos = [];
        foreach ($pagas as $f) {
            $pagamentos[] = [$f['id'], $f['valor'], $metodos[mt_rand(0, 2)], null, $f['pago_em']];
        }
        $this->inserirLote('pagamentos', ['fatura_id', 'valor', 'metodo', 'gateway_payload', 'criado_em'], $pagamentos);

        // Situação dos alunos
        $this->atualizarSituacao(array_keys($bloqueados), 'bloqueado');
        $this->atualizarSituacao($inativos, 'inativo');

        // Check-ins da última semana
        $checkins = [];
        foreach ($alunos as $indice => $a) {
            $alunoId = $indice + 1;
            if (mt_rand(1, 100) > 40) {
                continue;
            }
            $bloqueado = isset($bloqueados[$alunoId]);
            $inativo = in_array($alunoId, $inativos, true);
            $vezes = mt_rand(1, 4);
            for ($k = 0; $k < $vezes; $k++) {
                $quando = $hoje->modify('-' . mt_rand(0, 6) . ' days')->setTime(mt_rand(6, 21), mt_rand(0, 59));
                $liberado = !$bloqueado && !$inativo;
                $checkins[] = [
                    $alunoId, $a[4], $quando->format('Y-m-d H:i:s'), $liberado ? 1 : 0,
                    $liberado ? null : ($bloqueado ? 'situacao_bloqueado' : 'situacao_inativo'),
                ];
            }
        }
        $this->inserirLote('checkins', ['aluno_id', 'unidade_id', 'ocorrido_em', 'liberado', 'motivo_bloqueio'], $checkins);

        foreach (self::TABELAS as $tabela) {
            $total = DB::selectOne("SELECT COUNT(*) AS total FROM `{$tabela}`")['total'] ?? 0;
            printf("%-14s %8d\n", $tabela, $total);
        }

        return 0;
    }

    private function limpar(): void
    {
        DB::raw('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABELAS as $tabela) {
            DB::raw("TRUNCATE TABLE `{$tabela}`");
        }
        DB::raw('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @param int[] $ids */
    private function atualizarSituacao(array $ids, string $situacao): void
    {
        foreach (array_chunk($ids, 500) as $lote) {
            DB::execute(
                sprintf('UPDATE alunos SET situacao = ? WHERE id IN (%s)', implode(', ', array_fill(0, count($lote), '?'))),
                array_merge([$situacao], $lote),
            );
        }
    }

    /**
     * @param string[] $colunas
     * @param array<int, array<int, mixed>> $linhas
     */
    private function inserirLote(string $tabela, array $colunas, array $linhas): void
    {
        if ($linhas === []) {
            return;
        }
        $marcadorLinha = '(' . implode(', ', array_fill(0, count($colunas), '?')) . ')';
        $colunasSql = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $colunas));

        foreach (array_chunk($linhas, 500) as $lote) {
            $sql = sprintf(
                'INSERT INTO `%s` (%s) VALUES %s',
                $tabela,
                $colunasSql,
                implode(', ', array_fill(0, count($lote), $marcadorLinha)),
            );
            DB::execute($sql, array_merge(...array_map('array_values', $lote)));
        }
    }
}
