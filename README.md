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

## Funcionalidades
- Requisição e devolução de chaves em tempo real
- Dashboard com histórico e filtros por período
- Sistema de QR Codes para requisição e devolução por chave
- Backups automáticos da base de dados (retenção de 72h)
- Exportação de relatórios em TXT
- Autenticação com proteção brute-force
- Alteração de password com verificação por email
- Registo de auditoria de eventos de segurança
- Interface responsiva (telemóvel, tablet, computador)

## Instalação
1. Clonar o repositório
2. Copiar `config.example.php` para `config.php` e preencher as credenciais SMTP do seu email
3. Colocar os ficheiros em servidor (Apache) com PHP 8.x e extensão PDO SQLite
4. Garantir permissões de escrita na pasta `data/`
5. Aceder a `html/index.html`  a base de dados é criada automaticamente

## Licença
Copyright © 2026 Pedro Santos. Todos os direitos reservados.

Este projeto foi desenvolvido no âmbito de uma Prova de Aptidão Profissional e é propriedade intelectual do autor. Não é permitida a reprodução, distribuição ou utilização comercial sem autorização expressa.
