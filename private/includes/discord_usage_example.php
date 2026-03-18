<?php
/**
 * Discord Webhook Integration - Usage Examples
 * Path: /cdnmk/private/includes/discord_usage_example.php
 *
 * Setup:
 * 1. Create a webhook in your Discord server:
 *    - Server Settings → Integrations → Webhooks → New Webhook
 *    - Copy the webhook URL
 * 2. Set the DISCORD_WEBHOOK_URL environment variable or pass it directly
 */

require_once __DIR__ . '/discord.php';

// Initialize the notifier
// Option 1: Use environment variable
$discord = new DiscordNotifier();

// Option 2: Pass webhook URL directly
// $discord = new DiscordNotifier('https://discord.com/api/webhooks/YOUR_WEBHOOK_URL');

// Example 1: Simple text message
// $discord->sendMessage('🏁 New GP results are in!');

// Example 2: Send new GP notification
/*
$topResults = [
    ['name' => 'Mario', 'gp_points' => 85],
    ['name' => 'Luigi', 'gp_points' => 78],
    ['name' => 'Peach', 'gp_points' => 72]
];
$discord->notifyNewGP('2025w01', 'Mushroom Cup', '2025-01-15', $topResults);
*/

// Example 3: Send standings update
/*
$standings = [
    ['name' => 'Mario', 'score' => 425.5],
    ['name' => 'Luigi', 'score' => 398.2],
    ['name' => 'Peach', 'score' => 375.8],
    ['name' => 'Yoshi', 'score' => 350.1],
    ['name' => 'Bowser', 'score' => 325.7]
];
$discord->notifyStandings('2025', $standings);
*/

// Example 4: Notify badge earned
/*
$discord->notifyBadge(
    'Mario',
    '🏆',
    'Champion',
    'Won the season with the highest GPScore'
);
*/

// Example 5: Notify nemesis matchup
/*
$discord->notifyNemesis('Mario', 'Luigi', 15, 10);
*/

// Example 6: Season end notification
/*
$champion = ['name' => 'Mario'];
$discord->notifySeasonEnd('2025', $champion, '425.5');
*/

// Example 7: Send custom stats embed
/*
$stats = [
    'Total Races' => '24',
    'Podiums' => '18 (75%)',
    'Avg Points' => '85.2',
    'Best Finish' => '1st',
    'Main Character' => 'Mario'
];
$discord->sendStatsEmbed('Mario', $stats);
*/

// Example 8: Custom embed
/*
$fields = [
    ['name' => 'Field 1', 'value' => 'Value 1', 'inline' => true],
    ['name' => 'Field 2', 'value' => 'Value 2', 'inline' => true]
];
$discord->sendEmbed(
    'Custom Title',
    'Custom description here',
    0xff0000, // Red color
    $fields,
    'Custom footer text'
);
*/
