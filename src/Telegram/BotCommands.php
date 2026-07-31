<?php

namespace App\Telegram;

use App\Services\SlaEngine;
use PDO;

/**
 * Implementa os comandos de texto do bot:
 *   /meuschamados - chamados abertos atribuidos a quem enviou o comando
 *   /criticos      - chamados abertos com prioridade Critica (todos, nao so os do usuario)
 *   /atrasados     - chamados abertos com SLA ja vencido (todos)
 *   /hoje          - chamados abertos hoje (todos)
 *   /sla           - chamados do proprio usuario ordenados por % de SLA consumido
 *   /painel        - resumo numerico geral (contagens por status/criticidade)
 *
 * Todos exigem que o remetente esteja cadastrado na tabela `tecnicos`
 * (mesmo criterio de seguranca usado nos botoes inline).
 */
class BotCommands
{
    private const LIMITE_LISTA = 10;

    public function __construct(
        private PDO $db,
        private TelegramClient $telegram,
        private SlaEngine $sla,
    ) {
    }

    public function processar(array $message): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $telegramUserId = (int) ($message['from']['id'] ?? 0);
        $texto = trim($message['text'] ?? '');

        if ($chatId === null || $texto === '' || $texto[0] !== '/') {
            return;
        }

        // Remove argumentos e "@nomedobot" do comando, se houver
        $comando = strtolower(explode(' ', explode('@', $texto)[0])[0]);

        $tecnico = $this->buscarTecnicoPorTelegramId($telegramUserId);

        // /start e tratado ANTES da checagem de cadastro: mesmo quem ainda
        // nao foi cadastrado precisa ver o proprio chat_id, senao nao tem
        // como o administrador cadastrar a pessoa sem consultar a API do
        // Telegram manualmente.
        if ($comando === '/start') {
            $this->comandoStart($chatId, $telegramUserId, $tecnico);
            return;
        }

        if ($comando === '/id') {
            $this->telegram->sendMessage($chatId, "Seu ID do Telegram e: <code>{$telegramUserId}</code>");
            return;
        }

        if ($tecnico === null) {
            $this->telegram->sendMessage(
                $chatId,
                "Voce nao esta cadastrado como tecnico neste sistema.\n\n" .
                "Envie /start para ver seu ID e repasse ao administrador para ser cadastrado."
            );
            return;
        }

        match ($comando) {
            '/meuschamados' => $this->comandoMeusChamados($chatId, $tecnico),
            '/criticos'     => $this->comandoCriticos($chatId),
            '/atrasados'    => $this->comandoAtrasados($chatId),
            '/hoje'         => $this->comandoHoje($chatId),
            '/sla'          => $this->comandoSla($chatId, $tecnico),
            '/painel'       => $this->comandoPainel($chatId),
            '/naolidos'     => $this->comandoNaoLidos($chatId),
            default         => null, // comando desconhecido: ignora silenciosamente
        };
    }

    private function comandoStart(int|string $chatId, int $telegramUserId, ?array $tecnico): void
    {
        if ($tecnico === null) {
            $this->telegram->sendMessage(
                $chatId,
                "Ola! Voce ainda nao esta cadastrado como tecnico neste sistema.\n\n" .
                "Seu ID do Telegram e: <code>{$telegramUserId}</code>\n\n" .
                "Envie este numero para o administrador para ser cadastrado e " .
                "comecar a receber notificacoes de chamados."
            );
            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            "Ola, {$tecnico['nome']}!\n\n" .
            "Comandos disponiveis:\n" .
            "/meuschamados - seus chamados em aberto\n" .
            "/criticos - chamados com prioridade critica\n" .
            "/atrasados - chamados com SLA vencido\n" .
            "/hoje - chamados abertos hoje\n" .
            "/sla - seus chamados ordenados por SLA restante\n" .
            "/painel - resumo geral\n" .
            "/naolidos - chamados atribuidos ainda nao confirmados como lidos\n" .
            "/id - mostra seu ID do Telegram"
        );
    }

    private function comandoMeusChamados(int|string $chatId, array $tecnico): void
    {
        $stmt = $this->db->prepare(
            "SELECT numero, titulo, criticidade, prazo_resolucao
             FROM chamados
             WHERE tecnico_atual_id = :tecnico AND status_glpi NOT IN ('resolvido','fechado')
             ORDER BY prazo_resolucao ASC
             LIMIT " . self::LIMITE_LISTA
        );
        $stmt->execute(['tecnico' => $tecnico['id']]);
        $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($linhas)) {
            $this->telegram->sendMessage($chatId, "Voce nao tem chamados em aberto no momento.");
            return;
        }

        $texto = "<b>Seus chamados em aberto</b>\n\n" . $this->formatarLista($linhas);
        $this->telegram->sendMessage($chatId, $texto);
    }

    private function comandoCriticos(int|string $chatId): void
    {
        $stmt = $this->db->query(
            "SELECT numero, titulo, criticidade, prazo_resolucao
             FROM chamados
             WHERE criticidade = 'CRITICA' AND status_glpi NOT IN ('resolvido','fechado')
             ORDER BY prazo_resolucao ASC
             LIMIT " . self::LIMITE_LISTA
        );
        $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($linhas)) {
            $this->telegram->sendMessage($chatId, "Nenhum chamado critico em aberto no momento.");
            return;
        }

        $texto = "<b>Chamados criticos em aberto</b>\n\n" . $this->formatarLista($linhas);
        $this->telegram->sendMessage($chatId, $texto);
    }

    private function comandoAtrasados(int|string $chatId): void
    {
        $stmt = $this->db->query(
            "SELECT numero, titulo, criticidade, prazo_resolucao
             FROM chamados
             WHERE sla_violado = 1 AND status_glpi NOT IN ('resolvido','fechado')
             ORDER BY prazo_resolucao ASC
             LIMIT " . self::LIMITE_LISTA
        );
        $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($linhas)) {
            $this->telegram->sendMessage($chatId, "Nenhum chamado com SLA vencido no momento. Bom trabalho!");
            return;
        }

        $texto = "<b>Chamados com SLA vencido</b>\n\n" . $this->formatarLista($linhas);
        $this->telegram->sendMessage($chatId, $texto);
    }

    private function comandoHoje(int|string $chatId): void
    {
        $stmt = $this->db->query(
            "SELECT numero, titulo, criticidade, prazo_resolucao
             FROM chamados
             WHERE DATE(aberto_em) = CURDATE()
             ORDER BY aberto_em DESC
             LIMIT " . self::LIMITE_LISTA
        );
        $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($linhas)) {
            $this->telegram->sendMessage($chatId, "Nenhum chamado foi aberto hoje ainda.");
            return;
        }

        $texto = "<b>Chamados abertos hoje</b>\n\n" . $this->formatarLista($linhas);
        $this->telegram->sendMessage($chatId, $texto);
    }

    private function comandoSla(int|string $chatId, array $tecnico): void
    {
        $stmt = $this->db->prepare(
            "SELECT numero, titulo, criticidade, prazo_resolucao, aberto_em
             FROM chamados
             WHERE tecnico_atual_id = :tecnico AND status_glpi NOT IN ('resolvido','fechado')
             ORDER BY prazo_resolucao ASC
             LIMIT " . self::LIMITE_LISTA
        );
        $stmt->execute(['tecnico' => $tecnico['id']]);
        $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($linhas)) {
            $this->telegram->sendMessage($chatId, "Voce nao tem chamados em aberto para acompanhar SLA.");
            return;
        }

        $texto = "<b>Seus chamados por SLA restante</b>\n\n";
        foreach ($linhas as $linha) {
            $abertoEm = new \DateTimeImmutable($linha['aberto_em']);
            $prazo = new \DateTimeImmutable($linha['prazo_resolucao']);
            $agora = new \DateTimeImmutable();

            $totalSegundos = max(1, $prazo->getTimestamp() - $abertoEm->getTimestamp());
            $decorridoSegundos = $agora->getTimestamp() - $abertoEm->getTimestamp();
            $percentual = (int) max(0, min(100, round(($decorridoSegundos / $totalSegundos) * 100)));

            $emoji = $this->sla->emojiCriticidade($linha['criticidade']);
            $texto .= "{$emoji} #{$linha['numero']} - {$linha['titulo']} - {$percentual}% do SLA consumido\n";
        }

        $this->telegram->sendMessage($chatId, $texto);
    }

    private function comandoNaoLidos(int|string $chatId): void
    {
        $stmt = $this->db->query(
            "SELECT c.numero, c.titulo, c.criticidade, c.atribuido_em, t.nome AS tecnico_nome
             FROM chamados c
             JOIN tecnicos t ON t.id = c.tecnico_atual_id
             WHERE c.tecnico_atual_id IS NOT NULL
               AND c.atribuido_em IS NOT NULL
               AND c.status_glpi NOT IN ('resolvido','fechado')
               AND NOT EXISTS (
                   SELECT 1 FROM confirmacoes_leitura cl
                   WHERE cl.chamado_id = c.id AND cl.tecnico_id = c.tecnico_atual_id
               )
             ORDER BY c.atribuido_em ASC
             LIMIT " . self::LIMITE_LISTA
        );
        $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($linhas)) {
            $this->telegram->sendMessage($chatId, "Todos os chamados atribuidos ja foram confirmados como lidos.");
            return;
        }

        $texto = "<b>Chamados atribuidos ainda nao lidos</b>\n\n";
        $agora = new \DateTimeImmutable();

        foreach ($linhas as $linha) {
            $atribuidoEm = new \DateTimeImmutable($linha['atribuido_em']);
            $minutosEspera = (int) (($agora->getTimestamp() - $atribuidoEm->getTimestamp()) / 60);
            $emoji = $this->sla->emojiCriticidade($linha['criticidade']);

            $texto .= "{$emoji} #{$linha['numero']} - {$linha['titulo']}\n";
            $texto .= "   Tecnico: {$linha['tecnico_nome']} - esperando ha " . $this->formatarMinutos($minutosEspera) . "\n\n";
        }

        $this->telegram->sendMessage($chatId, $texto);
    }

    private function comandoPainel(int|string $chatId): void
    {
        $totalAberto = (int) $this->db->query(
            "SELECT COUNT(*) FROM chamados WHERE status_glpi NOT IN ('resolvido','fechado')"
        )->fetchColumn();

        $semTecnico = (int) $this->db->query(
            "SELECT COUNT(*) FROM chamados
             WHERE tecnico_atual_id IS NULL AND status_glpi NOT IN ('resolvido','fechado')"
        )->fetchColumn();

        $vencidos = (int) $this->db->query(
            "SELECT COUNT(*) FROM chamados
             WHERE sla_violado = 1 AND status_glpi NOT IN ('resolvido','fechado')"
        )->fetchColumn();

        $porCriticidade = $this->db->query(
            "SELECT criticidade, COUNT(*) AS total FROM chamados
             WHERE status_glpi NOT IN ('resolvido','fechado')
             GROUP BY criticidade"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $texto = "<b>Painel geral</b>\n\n";
        $texto .= "Total em aberto: {$totalAberto}\n";
        $texto .= "Sem tecnico atribuido: {$semTecnico}\n";
        $texto .= "Com SLA vencido: {$vencidos}\n\n";
        $texto .= "<b>Por prioridade</b>\n";

        foreach (['CRITICA', 'ALTA', 'MEDIA', 'BAIXA'] as $nivel) {
            $qtd = $porCriticidade[$nivel] ?? 0;
            $emoji = $this->sla->emojiCriticidade($nivel);
            $label = $this->sla->labelCriticidade($nivel);
            $texto .= "{$emoji} {$label}: {$qtd}\n";
        }

        $this->telegram->sendMessage($chatId, $texto);
    }

    private function formatarLista(array $linhas): string
    {
        $texto = '';
        foreach ($linhas as $linha) {
            $emoji = $this->sla->emojiCriticidade($linha['criticidade']);
            $prazo = (new \DateTimeImmutable($linha['prazo_resolucao']))->format('d/m H:i');
            $texto .= "{$emoji} #{$linha['numero']} - {$linha['titulo']} (vence {$prazo})\n";
        }
        return $texto;
    }

    private function formatarMinutos(int $minutos): string
    {
        $horas = intdiv($minutos, 60);
        $min = $minutos % 60;

        if ($horas === 0) {
            return "{$min}min";
        }

        if ($min === 0) {
            return "{$horas}h";
        }

        return "{$horas}h{$min}min";
    }

    private function buscarTecnicoPorTelegramId(int $telegramUserId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM tecnicos WHERE telegram_chat_id = :id AND ativo = 1');
        $stmt->execute(['id' => $telegramUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
