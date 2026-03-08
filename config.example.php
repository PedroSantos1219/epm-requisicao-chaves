<?php

// 1 Ativa primeiro a verificação em 2 passos em https://myaccount.google.com/security
// 2 Cria uma App Password em https://myaccount.google.com/apppasswords
// 3 Cola os 16 caracteres abaixo (sem espaços)

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'email@gmail.com');
define('SMTP_PASS', 'xxxx xxxx xxxx xxxx');
define('SMTP_FROM_NAME', 'Sistema de Requisições');
