<?php

namespace App\GLPI;

use RuntimeException;

class GlpiClient
{
    private string $baseUrl;
    private string $appToken;
    private string $userToken;
    private ?string $sessionToken = null;
    private int $timeout;
    private bool $verifySsl;
    private ?string $caBundle;

    public function __construct(array $config)
    {
        $this->baseUrl   = rtrim($config['base_url'], '/');
        $this->appToken  = $config['app_token'];
        $this->userToken = $config['user_token'];
        $this->timeout   = $config['timeout'] ?? 10;
        $this->verifySsl = $config['verify_ssl'] ?? true;
        $this->caBundle  = $config['ca_bundle'] ?? null;
    }

    public function initSession(): void
    {
        $response = $this->request('GET', '/initSession', [], [
            'Authorization: user_token ' . $this->userToken,
            'App-Token: ' . $this->appToken,
        ]);

        if (empty($response['session_token'])) {
            throw new RuntimeException('Falha ao iniciar sessao GLPI: token nao retornado.');
        }

        $this->sessionToken = $response['session_token'];
    }

    public function killSession(): void
    {
        if ($this->sessionToken) {
            $this->request('GET', '/killSession', [], $this->authHeaders());
            $this->sessionToken = null;
        }
    }

    public function buscarTicketsAtualizadosDesde(\DateTimeImmutable $desde): array
    {
        $this->ensureSession();

        $criteria = [
            'criteria' => [
                [
                    'field'      => 19,
                    'searchtype' => 'morethan',
                    'value'      => $desde->format('Y-m-d H:i:s'),
                ],
            ],
            'range' => '0-199',
        ];

        $query = http_build_query($criteria);

        return $this->request('GET', "/search/Ticket?{$query}", [], $this->authHeaders());
    }

    public function getTicket(int $id): array
    {
        $this->ensureSession();
        return $this->request('GET', "/Ticket/{$id}?with_devices=false&expand_dropdowns=true", [], $this->authHeaders());
    }

    public function getTicketUsers(int $ticketId): array
    {
        $this->ensureSession();
        return $this->request('GET', "/Ticket/{$ticketId}/Ticket_User", [], $this->authHeaders());
    }

    public function atribuirTecnico(int $ticketId, int $userId): array
    {
        $this->ensureSession();

        return $this->request('POST', '/Ticket_User', [
            'input' => [
                'tickets_id' => $ticketId,
                'users_id'   => $userId,
                'type'       => 2,
            ],
        ], $this->authHeaders());
    }

    public function removerAtribuicaoTecnico(int $ticketId, int $userId): bool
    {
        $this->ensureSession();

        $vinculos = $this->getTicketUsers($ticketId);

        foreach ($vinculos as $vinculo) {
            if ((int) ($vinculo['type'] ?? 0) === 2 && (int) ($vinculo['users_id'] ?? 0) === $userId) {
                $relacaoId = (int) ($vinculo['id'] ?? 0);
                if ($relacaoId > 0) {
                    $this->request('DELETE', "/Ticket_User/{$relacaoId}", [], $this->authHeaders());
                    return true;
                }
            }
        }

        return false;
    }

    public function listarSearchOptions(string $itemtype): array
    {
        $this->ensureSession();
        return $this->request('GET', "/listSearchOptions/{$itemtype}", [], $this->authHeaders());
    }

    public function getUser(int $userId): array
    {
        $this->ensureSession();
        return $this->request('GET', "/User/{$userId}", [], $this->authHeaders());
    }

    public function adicionarAcompanhamento(int $ticketId, string $conteudo, bool $privado = true): array
    {
        $this->ensureSession();

        return $this->request('POST', "/Ticket/{$ticketId}/ITILFollowup", [
            'input' => [
                'content'    => $conteudo,
                'is_private' => $privado ? 1 : 0,
            ],
        ], $this->authHeaders());
    }

    public function atualizarTicket(int $ticketId, array $campos): array
    {
        $this->ensureSession();

        return $this->request('PUT', "/Ticket/{$ticketId}", [
            'input' => $campos,
        ], $this->authHeaders());
    }

    private function ensureSession(): void
    {
        if (!$this->sessionToken) {
            $this->initSession();
        }
    }

    private function authHeaders(): array
    {
        return [
            'Session-Token: ' . $this->sessionToken,
            'App-Token: ' . $this->appToken,
        ];
    }

    private function request(string $method, string $path, array $body = [], array $headers = []): array
    {
        $ch = curl_init($this->baseUrl . $path);

        $defaultHeaders = ['Content-Type: application/json'];
        $allHeaders = array_merge($defaultHeaders, $headers);

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $allHeaders,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);

        if ($this->caBundle) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caBundle);
        }

        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("Erro de conexao com GLPI: {$error}");
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            $decoded = ['result' => $decoded];
        }

        if ($httpCode >= 400) {
            if ($httpCode === 401 && $this->sessionToken !== null) {
                $this->sessionToken = null;
                $this->initSession();
                return $this->request($method, $path, $body, array_merge(
                    array_filter($headers, fn($h) => !str_starts_with($h, 'Session-Token')),
                    ['Session-Token: ' . $this->sessionToken]
                ));
            }

            throw new RuntimeException("Erro GLPI HTTP {$httpCode}: {$raw}");
        }

        return $decoded;
    }
}
