<?php
/**
 * Application secrets template.
 *
 * Copy to config/config.php on the server and replace every YOUR_* value.
 * Do not commit config/config.php.
 */

return [
    'db_host'        => 'localhost',
    'db_port'        => '3306',
    'db_name'        => 'your_database_name',
    'db_user'        => 'your_db_user',
    'db_pass'        => 'your_db_password',
    'site_url'       => 'https://your-domain.example/parcel_delivery_system',
    'cron_url'       => 'https://your-domain.example/parcel_delivery_system/cron.php?key=YOUR_CRON_SECRET',
    'cron_secret'    => 'YOUR_CRON_SECRET',
    'recovery_key'   => 'YOUR_RECOVERY_KEY',
    'telegram_token' => 'YOUR_TELEGRAM_BOT_TOKEN',
    'telegram_chat'  => 'YOUR_TELEGRAM_CHAT_ID',
];
