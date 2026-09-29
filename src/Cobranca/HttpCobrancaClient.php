<?php

declare(strict_types=1);

namespace EstudaFitHub\Cobranca;

final class HttpCobrancaClient implements CobrancaClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly float $timeoutSegundos = 3.0,
    ) {
    }

    public static function doAmbiente(): self
    {
        return new self(
            rtrim(getenv('COBRANCA_URL') ?: 'http://cobranca:8080', '/'),
            getenv('COBRANCA_TOKEN') ?: 'token-local',
            (float) (getenv('COBRANCA_TIMEOUT_S') ?: 3),
        );
    }

    public function enviarComando(string $tipo, array $payload): void
    {
        match ($tipo) {
            Outbox::MATRICULA_CRIADA => $this->chamar('POST', '/assinaturas', $payload),
            Outbox::MATRICULA_CANCELADA => $this->chamar('POST', "/assinaturas/{$payload['matricula_id']}/cancelamento", $payload),
            default => throw new ComandoRejeitado("Tipo de comando desconhecido: {$tipo}"),
        };
    }

    public function encaminharWebhook(array $payload): void
    {
        $this->chamar('POST', '/webhooks/pagamento', $payload);
    }

    public function eventos(int $apos, int $limite): array
    {
        return $this->chamar('GET', "/eventos?apos={$apos}&limite={$limite}");
    }

    public function importarLote(array $lote): array
    {
        return $this->chamar('POST', '/migracao/lotes', $lote);
    }

    public function reconciliacao(int $unidadeId): array
    {
        return $this->chamar('GET', "/migracao/reconciliacao?unidade_id={$unidadeId}")['linhas'] ?? [];
    }

    public function faturasDaUnidade(int $unidadeId): array
    {
        return $this->chamar('GET', "/migracao/faturas?unidade_id={$unidadeId}")['faturas'] ?? [];
    }

    public function devolverUnidade(int $unidadeId): void
    {
        $this->chamar('POST', "/migracao/unidades/{$unidadeId}/devolucao");
    }

    private function chamar(string $metodo, string $caminho, ?array $corpo = null): array
    {
        $opcoes = ['http' => [
            'method' => $metodo,
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\nX-Servico-Token: {$this->token}\r\n",
            'ignore_errors' => true,
            'timeout' => $this->timeoutSegundos,
        ]];
        if ($corpo !== null) {
            $opcoes['http']['content'] = json_encode($corpo, JSON_UNESCAPED_UNICODE);
        }

        $resposta = @file_get_contents($this->baseUrl . $caminho, false, stream_context_create($opcoes));
        $cabecalhos = $http_response_header ?? [];
        if ($resposta === false || $cabecalhos === []) {
            throw new CobrancaIndisponivel("Sem resposta de {$metodo} {$caminho}");
        }

        $status = 0;
        foreach ($cabecalhos as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        if ($status >= 500) {
            throw new CobrancaIndisponivel("{$metodo} {$caminho} respondeu {$status}");
        }
        if ($status >= 400) {
            throw new ComandoRejeitado("{$metodo} {$caminho} respondeu {$status}: " . substr($resposta, 0, 200));
        }

        $dados = json_decode($resposta, true);

        return is_array($dados) ? $dados : [];
    }
}
