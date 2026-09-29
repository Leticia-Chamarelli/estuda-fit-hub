<?php

declare(strict_types=1);

/**
 * Fluxo ponta a ponta contra a API em execução.
 * Uso (dentro do container): php scripts/smoke.php [http://localhost:8080]
 */

$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');

function chamar(string $metodo, string $caminho, ?array $corpo = null): array
{
    global $base;
    $opcoes = [
        'http' => [
            'method' => $metodo,
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'ignore_errors' => true,
            'timeout' => 30,
        ],
    ];
    if ($corpo !== null) {
        $opcoes['http']['content'] = json_encode($corpo);
    }
    $inicio = microtime(true);
    $resposta = file_get_contents($base . $caminho, false, stream_context_create($opcoes));
    $ms = (microtime(true) - $inicio) * 1000;
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    $dados = json_decode((string) $resposta, true) ?? [];
    printf("%-6s %-40s %d (%.0f ms)\n", $metodo, $caminho, $status, $ms);

    return ['status' => $status, 'dados' => is_array($dados) ? $dados : []];
}

function esperar(bool $condicao, string $descricao): void
{
    if (!$condicao) {
        echo "  FALHOU: {$descricao}\n";
        exit(1);
    }
    echo "  ok: {$descricao}\n";
}

$saude = chamar('GET', '/health');
esperar($saude['status'] === 200, 'API e banco respondem');

$cpf = sprintf('%011d', random_int(70000000000, 79999999999));
$aluno = chamar('POST', '/alunos', [
    'nome' => 'Aluna Smoke', 'email' => "smoke{$cpf}@exemplo.com", 'telefone' => '11988887777', 'cpf' => $cpf, 'unidade_id' => 1,
]);
esperar($aluno['status'] === 201, 'aluno criado');
$alunoId = (int) $aluno['dados']['id'];

$matricula = chamar('POST', '/matriculas', ['aluno_id' => $alunoId, 'plano_id' => 2, 'dia_vencimento' => 10]);
esperar($matricula['status'] === 201, 'matrícula criada com primeira fatura');
$referencia = $matricula['dados']['primeira_fatura']['gateway_ref'] ?? null;
esperar(is_string($referencia), 'primeira fatura tem referência no gateway');

$checkin = chamar('POST', '/checkins', ['cpf' => $cpf, 'unidade_id' => 1]);
esperar($checkin['dados']['liberado'] === true, 'check-in liberado com fatura em aberto');

$webhook = chamar('POST', '/webhooks/pagamento', [
    'reference' => $referencia, 'status' => 'approved', 'amount' => 119.90, 'method' => 'pix', 'event_id' => "evt_smoke_{$cpf}",
]);
esperar($webhook['status'] === 202, 'webhook de pagamento aceito na inbox');

// O webhook é processado pelo worker (container `worker`), não na requisição do gateway.
$paga = false;
for ($i = 0; $i < 20 && !$paga; $i++) {
    usleep(500_000);
    $faturas = chamar('GET', "/alunos/{$alunoId}/faturas");
    $paga = ($faturas['dados']['faturas'][0]['status'] ?? null) === 'paga';
}
esperar($paga, 'fatura marcada como paga pelo worker');

$relatorio = chamar('GET', '/relatorios/inadimplencia?unidade_id=1');
esperar($relatorio['status'] === 200, 'relatório de inadimplência responde');
printf("  %d alunos inadimplentes na unidade 1\n", count($relatorio['dados']['inadimplentes'] ?? []));

$receita = chamar('GET', '/relatorios/receita?competencia=' . date('Y-m'));
esperar($receita['status'] === 200, 'relatório de receita responde');

echo "\nSmoke concluído com sucesso.\n";
