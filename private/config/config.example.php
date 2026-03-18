<?php
/**
 * System Configuration
 * Copy this file to config.php and fill in your values.
 */
return [
    'gemini_api_key' => '',              // Google Gemini API key for AI recap generation
    'admin_password' => 'changeme',      // Admin login password (plaintext or bcrypt hash)
    // To generate a hash: php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"
    'model_name'     => 'gemini-2.5-flash', // Gemini model to use for recaps
];
