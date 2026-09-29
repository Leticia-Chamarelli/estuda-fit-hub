<?php

declare(strict_types=1);

/**
 * Fluxo ponta a ponta de uma unidade MIGRADA, contra os containers em execução.
 * Pré-requisito: `php bin/console cobranca:migrar-unidade 5` (ou `make migrar U=5`).
 * Uso (dentro do container app): php scripts/smoke_servico.php [unidade] [http://localhost:8080] [http://cobranca:8080]
 *
 * Mostra: matrícula grava outbox -> relay -> serviço cria assinatura e fatura -> feed ->
 * consumidor atualiza o modelo de leitura -> check-in -> webhook duplicado e fora de ordem.
 */

$unidade = (int) ($argv[1] ?? 5);
$monolito = rtrim($argv[2] ?? 'http://localhost:8080', '/');
$servico = rtrim($argv[3] ?? (getenv('COBRANCA_URL') ?: 'http://cobranca:8080'), '/');
$token = getenv('COBRANCA_TOKEN') ?: 'token-local';

function chamar(string $base, string $metodo, string $caminho, ?array $corpo = null, string $token = ''): array
{
    $opcoes = ['http' => [
        'method' => $metodo,
        'header' => "Content-Type: application/json\r\nX-Servico-Token: {$token}\r\n",
        'ignore_errors' => true,
        'timeout' => 30,
    ]];
    if ($corpo !== null) {
        $opcoes['http']['content'] = json_encode($corpo);
    }
    $inicio = microtime(true);
    $resposta = file_get_contents($base . $caminho, false, stream_context_create($opcoes));
    $ms = (microtime(true) - $inicio) * 1000;
    preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $m);
    $status = (int) ($m[1] ?? 0);
    printf("%-6s %-58s %d (%.0f ms)\n", $metodo, parse_url($base, PHP_URL_HOST) . $caminho, $status, $ms);

    return ['status' => $status, 'dados' => json_decode((string) $resposta, true) ?? [], 'ms' => $ms];
}

function esperar(bool $condicao, string $descricao): void
{
    echo ($condicao ? '  ok: ' : '  FALHOU: ') . $descricao . "\n";
    if (!$condicao) {
        exit(1);
    }
}

/** Repete $fn até devolver algo diferente de null (o worker roda a cada 2 s e o feed segura 1 s). */
function aguardar(callable $fn, int $segundos = 15): mixed
{
    $limite = microtime(true) + $segundos;
    do {
        $r = $fn();
        if ($r !== null) {
            return $r;
        }
        usleep(500_000);
    } while (microtime(true) < $limite);

    return null;
}

$cpf = sprintf('%011d', random_int(60000000000, 69999999999));
$aluno = chamar($monolito, 'POST', '/alunos', [
    'nome' => 'Aluna Migrada', 'email' => "m{$cpf}@exemplo.com", 'telefone' => '11977776666', 'cpf' => $cpf, 'unidade_id' => $unidade,
]);
esperar($aluno['status'] === 201, "aluno criado na unidade {$unidade}");
$alunoId = (int) $aluno['dados']['id'];

$matricula = chamar($monolito, 'POST', '/matriculas', ['aluno_id' => $alunoId, 'plano_id' => 2, 'dia_vencimento' => 10]);
esperar(($matricula['dados']['cobranca'] ?? null) === 'em_processamento', 'matrícula criada; fatura fica com o serviço (outbox)');

$fatura = aguardar(static function () use ($servico, $alunoId, $token): ?array {
    $r = chamar($servico, 'GET', "/faturas?aluno_id={$alunoId}", null, $token);
    $f = $r['dados']['faturas'][0] ?? null;

    return ($f['gateway_ref'] ?? null) !== null ? $f : null;
});
esperar($fatura !== null, 'serviço criou assinatura e primeira fatura com referência no gateway');
$ref = $fatura['gateway_ref'];

$projecao = aguardar(static function () use ($monolito, $alunoId): ?array {
    $f = chamar($monolito, 'GET', "/alunos/{$alunoId}/faturas")['dados']['faturas'][0] ?? null;

    return ($f['origem'] ?? null) === 'servico' ? $f : null;
});
esperar($projecao !== null, 'monólito consumiu FaturaGerada no modelo de leitura');

$checkin = chamar($monolito, 'POST', '/checkins', ['cpf' => $cpf, 'unidade_id' => $unidade]);
esperar($checkin['dados']['liberado'] === true, 'check-in liberado lendo só o modelo de leitura');

$evento = 'evt_' . $cpf;
chamar($monolito, 'POST', '/webhooks/pagamento', ['reference' => $ref, 'status' => 'approved', 'amount' => 119.90, 'method' => 'pix', 'event_id' => $evento]);
$dup = chamar($monolito, 'POST', '/webhooks/pagamento', ['reference' => $ref, 'status' => 'approved', 'amount' => 119.90, 'method' => 'pix', 'event_id' => $evento]);
esperar(($dup['dados']['duplicado'] ?? false) === true, 'webhook duplicado barrado na inbox do monólito');
chamar($monolito, 'POST', '/webhooks/pagamento', ['reference' => $ref, 'status' => 'refused', 'event_id' => $evento . '_antigo']);

$paga = aguardar(static function () use ($monolito, $alunoId): ?bool {
    $f = chamar($monolito, 'GET', "/alunos/{$alunoId}/faturas")['dados']['faturas'][0] ?? null;

    return ($f['status'] ?? null) === 'paga' ? true : null;
});
esperar($paga === true, 'pagamento encaminhado ao serviço e refletido no monólito');

// Webhook direto no serviço (estado final, gateway apontando para ele): repetido não paga de novo.
$direto = chamar($servico, 'POST', '/webhooks/pagamento', ['reference' => $ref, 'status' => 'approved', 'event_id' => $evento . '_direto']);
esperar(($direto['dados']['resultado'] ?? '') === 'ignorado_fatura_ja_paga', 'recusa atrasada e aprovado repetido não regridem nem duplicam');

$relatorio = chamar($monolito, 'GET', "/relatorios/inadimplencia?unidade_id={$unidade}");
esperar($relatorio['status'] === 200, 'relatório de inadimplência responde para a unidade migrada');

echo "\nSmoke da unidade migrada concluído com sucesso.\n";
