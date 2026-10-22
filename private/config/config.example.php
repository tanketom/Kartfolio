<?php
/**
 * System Configuration
 * Copy this file to config.php and fill in your values.
 */
return [
    // AI writers. The News desk tries Claude first and Gemini as the backup;
    // every other AI feature (power rankings, reports, awards, musings) uses
    // Gemini. Set the keys with:  php bin/set_api_key.php anthropic|gemini
    'anthropic_api_key' => '',           // Anthropic API key — Claude writes the broadcasts
    'gemini_api_key' => '',              // Google Gemini API key — backup writer, and the rest
    'news_writer'    => 'claude',        // which writer the News desk tries first: 'claude' | 'gemini'
    'claude_model'   => 'claude-opus-5-5',  // Claude model for broadcasts
    'claude_effort'  => 'medium',        // low | medium | high | xhigh | max — reasoning depth per broadcast
    'admin_password' => 'changeme',      // Admin login password (plaintext or bcrypt hash)
    // To generate a hash: php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"
    'model_name'     => 'gemini-2.5-flash', // Gemini model (backup writer, and every other AI feature)
    'session_cookie_secure' => null,     // null = auto (Secure unless local dev); false only for a plain-HTTP install
];
