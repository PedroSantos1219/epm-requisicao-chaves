# Sistema de Requisição de Chaves

Sistema web para gestão e controlo de requisição de chaves numa escola profissional, desenvolvido como Prova de Aptidão Profissional (PAP).

## Autor
**Pedro Santos**  
PAP — 2026  


## Descrição
Substitui o registo em papel por uma plataforma web acessível via QR Code, com controlo em tempo real de qual chave está em uso, por quem e desde quando. Com a funcionalidade de descarregar o relatório dos usos.

Suporta três tipos de utilizadores:
- **Aluno** — requisita e devolve chaves com verificação por número de telefone
- **Professor** — acesso via PIN com lista de professores registados
- **Administrador** — gestão completa de utilizadores, chaves, backups e configurações

## Tecnologias
- PHP 8.x + SQLite (PDO)
- HTML5 + Bootstrap 5 + JavaScript
- PHPMailer (Gmail SMTP)
- Apache + .htaccess
