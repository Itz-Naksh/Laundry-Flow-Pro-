<?php
/*
 * SMTP credentials for PHPMailer.
 *
 * All values are sourced from Backend/.env (see .env.example) so that no
 * secrets live in version-controlled code. Edit .env, not this file.
 */

use App\Core\Env;

$fromEmail = Env::get('MAIL_FROM_EMAIL', 'laundryflowpro2026new@gmail.com');

return [
    'host'       => Env::get('MAIL_HOST', 'smtp.gmail.com'),
    'port'       => (int) Env::get('MAIL_PORT', 587),
    'encryption' => strtolower((string) Env::get('MAIL_ENCRYPTION', 'tls')), // tls | ssl | none
    'username'   => Env::get('MAIL_USERNAME', ''),
    'password'   => Env::get('MAIL_PASSWORD', ''),
    'from_email' => $fromEmail,
    'from_name'  => Env::get('MAIL_FROM_NAME', 'Laundry Flow Pro'),
    'reply_to'   => Env::get('MAIL_REPLY_TO', '') ?: $fromEmail,
    'debug'      => (int) Env::get('MAIL_DEBUG', 0) === 1,
];



