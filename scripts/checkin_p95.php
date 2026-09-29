<?php

declare(strict_types=1);

/**
 * Mede a latência do check-in (p50, p95, máx) com chamadas concorrentes.
 * Rode com o serviço de Cobrança parado para provar que a catraca não depende dele:
 *   docker compose stop cobranca cobranca-worker
 *   docker compose exec app php scripts/checkin_p95.php 400 8
 * Uso: php scripts/checkin_p95.php [chamadas=400] [concorrencia=8] [http://localhost:8080]
 */

$total = (int) ($argv[1] ?? 400);
$concorrencia = max(1, (int) ($argv[2] ?? 8));
$base = rtrim($argv[3] ?? 'http://localhost:8080', '/');

$novo = static function () use ($base) {
    // CPFs do seed: 10000000001 a 10000002000.
    $h = curl_init($base . '/checkins');
    curl_setopt_array($h, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['cpf' => sprintf('%011d', 10000000000 + random_int(1, 2000)), 'unidade_id' => 1]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);

    return $h;
};

$multi = curl_multi_init();
$enviados = 0;
for (; $enviados < min($concorrencia, $total); $enviados++) {
    curl_multi_add_handle($multi, $novo());
}

$tempos = [];
$erros = 0;
do {
    curl_multi_exec($multi, $ativos);
    curl_multi_select($multi, 0.05);
    while ($info = curl_multi_info_read($multi)) {
        $h = $info['handle'];
        if (curl_getinfo($h, CURLINFO_HTTP_CODE) === 200) {
            $tempos[] = curl_getinfo($h, CURLINFO_TOTAL_TIME) * 1000;
        } else {
            $erros++;
        }
        curl_multi_remove_handle($multi, $h);
        if ($enviados < $total) {
            curl_multi_add_handle($multi, $novo());
            $enviados++;
            $ativos = 1;
        }
    }
} while ($ativos > 0);

if ($tempos === []) {
    fwrite(STDERR, "Nenhuma resposta 200 ({$erros} erros)\n");
    exit(1);
}
sort($tempos);
$pct = static fn (float $q): float => $tempos[(int) floor($q * (count($tempos) - 1))];
printf(
    "%d check-ins, concorrência %d: p50 %.0f ms, p95 %.0f ms, máx %.0f ms, %d erros\n",
    count($tempos), $concorrencia, $pct(0.5), $pct(0.95), end($tempos), $erros,
);
exit($pct(0.95) < 300 && $erros === 0 ? 0 : 1);
