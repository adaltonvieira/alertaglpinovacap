<?php

namespace App\GLPI;

use RuntimeException;

/**
 * Cliente da API REST do GLPI.
 *
 * Autenticação conforme documentação oficial do GLPI (App-Token + User-Token
 * -> Session-Token), com renovação automática de sessão. Preferido sobre
 * autenticação usuário/senha por token, evitando manter credenciais em texto
 * puro no serviço.
 */
class GlpiClient
{
    private string $baseUrl;
    private string $appToken;
    private string $userToken;
    private ?string $sessionToken = null;
    private int $timeout;

    public function __construct(array $config)
    {
        $this->baseUrl   = rtrim($config['base_url'], '/');
        $this->appToken  = $config['app_token'];
        $this->userToken = $config['user_token'];
        $this->timeout   = $config['timeout'] ?? 10;
    }

    public function initSession(): void
    {
        $response = $this->request('GET', '/initSession', [], [
            'Authorization: user_token ' . $this->userToken,
            'App-Token: ' . $this->appToken,
        ]);

        if (empty($response['session_token'])) {
            throw new RuntimeException('Falha ao iniciar sessão GLPI: token não retornado.');
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

    /**
     * Busca chamados (Ticket) atualizados desde um timestamp, usado pelo
     * mecanismo de polling otimizado quando webhooks não estão disponíveis.
     *
     * TR/prompt: "Caso não seja possível utilizar Webhooks, implementar
     * monitoramento inteligente via API utilizando polling otimizado."
     */
    public function buscarTicketsAtualizadosDesde(\DateTimeImmutable $desde): array
    {
        $this->ensureSession();

        $criteria = [
            'criteria' => [
                [
                    'field'      => 19, // date_mod
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
        return $this->request('GET', "/Ticket/{$id}?with_devices=false", [], $this->authHeaders());
    }

    public function getTicketUsers(int $ticketId): array
    {
        $this->ensureSession();
        return $this->request('GET', "/Ticket/{$ticketId}/Ticket_User", [], $this->authHeaders());
    }

    public function getUser(int $userId): array
    {
        $this->ensureSession();
        return $this->request('GET', "/User/{$userId}", [], $this->authHeaders());
    }

    /**
     * Adiciona um followup (acompanhamento) ao chamado — usado, por exemplo,
     * quando o técnico confirma leitura ou assume o chamado via botão do bot.
     */
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
        ]);

        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("Erro de conexão com GLPI: {$error}");
        }

        $decoded = json_decode($raw, true);

        if ($httpCode >= 400) {
            // Sessão expirada -> tenta renovar uma vez
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

        return $decoded ?? [];
    }
}
