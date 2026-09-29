<?php

declare(strict_types=1);

namespace Cobranca\Http;

final class Requisicao
{
    public function __construct(
        public readonly string $metodo,
        public readonly string $caminho,
        public readonly array $query = [],
        public readonly array $corpo = [],
        public readonly array $cabecalhos = [],
        public array $parametros = [],
    ) {
    }

    public static function daGlobal(): self
    {
        $bruto = (string) file_get_contents('php://input');
        $corpo = $bruto !== '' ? json_decode($bruto, true) : [];
        if (!is_array($corpo)) {
            throw new Erro(400, 'JSON inválido');
        }
        $cabecalhos = [];
        foreach ($_SERVER as $chave => $valor) {
            if (str_starts_with($chave, 'HTTP_')) {
                $cabecalhos[strtolower(str_replace('_', '-', substr($chave, 5)))] = $valor;
            }
        }
        $caminho = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';

        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $caminho, $_GET, $corpo, $cabecalhos);
    }

    public function campo(string $nome, mixed $padrao = null): mixed
    {
        return $this->corpo[$nome] ?? $padrao;
    }

    /** @param string[] $campos */
    public function exigir(array $campos): void
    {
        foreach ($campos as $campo) {
            if (!isset($this->corpo[$campo]) || $this->corpo[$campo] === '') {
                throw new Erro(422, "Campo obrigatório: {$campo}");
            }
        }
    }
}
