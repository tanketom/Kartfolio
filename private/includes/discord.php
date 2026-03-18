<?php
/**
 * Discord Webhook Integration
 * Path: /cdnmk/private/includes/discord.php
 *
 * Usage: Send notifications to Discord without requiring a bot server
 */

class DiscordNotifier {
    private $webhookUrl;
    private $leagueName;

    public function __construct($webhookUrl = null, $leagueName = null) {
        $this->webhookUrl = $webhookUrl ?: getenv('DISCORD_WEBHOOK_URL');
        $this->leagueName = $leagueName;
    }

    /**
     * Send a message to Discord
     */
    public function sendMessage($content) {
        if (!$this->webhookUrl) return false;

        $data = ['content' => $content];
        return $this->sendWebhook($data);
    }

    /**
     * Send a rich embed to Discord
     */
    public function sendEmbed($title, $description, $color = 0xe60012, $fields = [], $footer = null) {
        if (!$this->webhookUrl) return false;

        $embed = [
            'title' => $title,
            'description' => $description,
            'color' => $color,
            'timestamp' => date('c'),
        ];

        if (!empty($fields)) {
            $embed['fields'] = $fields;
        }

        if ($footer) {
            $embed['footer'] = ['text' => $footer];
        } else {
            $embed['footer'] = ['text' => ($this->leagueName ?? 'Mario Kart') . ' League'];
        }

        $data = ['embeds' => [$embed]];
        return $this->sendWebhook($data);
    }

    /**
     * Notify when a new GP result is posted
     */
    public function notifyNewGP($gpid, $cupName, $date, $topResults) {
        $description = "**Cup:** $cupName\n**Date:** $date\n\n**Top 3:**\n";

        foreach (array_slice($topResults, 0, 3) as $i => $result) {
            $medal = ['🥇', '🥈', '🥉'][$i];
            $description .= "$medal **{$result['name']}** - {$result['gp_points']} pts\n";
        }

        return $this->sendEmbed(
            "🏁 New GP Results: " . strtoupper($gpid),
            $description,
            0x2ebd59
        );
    }

    /**
     * Notify standings update
     */
    public function notifyStandings($seasonId, $standings) {
        $description = "";
        foreach (array_slice($standings, 0, 5) as $i => $racer) {
            $rank = $i + 1;
            $medal = $rank <= 3 ? ['🥇', '🥈', '🥉'][$rank - 1] : "$rank.";
            $description .= "$medal **{$racer['name']}** - {$racer['score']}\n";
        }

        return $this->sendEmbed(
            "📊 " . strtoupper($seasonId) . " Standings Update",
            $description,
            0xe60012
        );
    }

    /**
     * Notify nemesis of the week
     */
    public function notifyNemesis($p1, $p2, $meetings, $p1Wins) {
        $p2Wins = $meetings - $p1Wins;
        $winRate = round(($p1Wins / $meetings) * 100, 1);

        $description = "**$p1** vs **$p2**\n\n";
        $description .= "**Record:** $p1Wins-$p2Wins\n";
        $description .= "**Meetings:** $meetings\n";
        $description .= "**Win Rate:** $winRate%";

        return $this->sendEmbed(
            "⚔️ Nemesis of the Week",
            $description,
            0xff8f00
        );
    }

    /**
     * Notify season end
     */
    public function notifySeasonEnd($seasonId, $champion, $finalScore) {
        $description = "🏆 **Champion:** {$champion['name']}\n";
        $description .= "**Final Score:** $finalScore\n\n";
        $description .= "View the full season report at:\n";
        $description .= "https://yoursite.com/view-season-report?season=$seasonId";

        return $this->sendEmbed(
            "🎉 " . strtoupper($seasonId) . " Season Concluded!",
            $description,
            0xffd700
        );
    }

    /**
     * Notify badge earned
     */
    public function notifyBadge($racerName, $badgeIcon, $badgeTitle, $badgeDesc) {
        $description = "**Racer:** $racerName\n";
        $description .= "**Achievement:** $badgeIcon $badgeTitle\n\n";
        $description .= "_$badgeDesc_";

        return $this->sendEmbed(
            "🎖️ New Badge Earned!",
            $description,
            0x009be0
        );
    }

    /**
     * Send custom embed with fields
     */
    public function sendStatsEmbed($racerName, $stats) {
        $fields = [];
        foreach ($stats as $label => $value) {
            $fields[] = [
                'name' => $label,
                'value' => (string)$value,
                'inline' => true
            ];
        }

        return $this->sendEmbed(
            "📊 Stats for $racerName",
            "Career statistics overview",
            0x009be0,
            $fields
        );
    }

    /**
     * Internal method to send webhook
     */
    private function sendWebhook($data) {
        if (!$this->webhookUrl) return false;

        $ch = curl_init($this->webhookUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    }
}
