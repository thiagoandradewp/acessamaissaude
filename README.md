# Acessa+ Saúde — repositório Vercel

Este repositório contém a versão PHP do Acessa+ Saúde preparada para o runtime PHP comunitário recomendado pelo Vercel. O frontend continua em `index.php` e as chamadas existentes para `api.php?action=...` são encaminhadas por `api/api.php`.

## Instalação em hospedagem compartilhada (cPanel) ou VPS

1. No painel da hospedagem, crie um banco MySQL vazio e um usuário, e vincule o usuário ao banco com todos os privilégios.
2. Envie todos os arquivos do projeto para a pasta do site (ex.: `public_html`).
3. Abra o endereço do site: na primeira visita ele leva ao instalador (`/install.php`).
4. Informe os dados do banco e crie a senha do administrador (Desenvolvedor). Se quiser testar, marque as UBS de demonstração e escolha a cidade/UF delas.
5. Ao terminar, apague `install.php` da hospedagem.

O instalador nunca apaga dados: se o banco já tiver o sistema instalado, ele para. As credenciais ficam em `config.local.php` (protegido pelo `.htaccess` e fora do Git); variáveis de ambiente `ACESSA_DB_*` continuam tendo prioridade. Requisitos: PHP 8.1+ com `pdo_mysql`.

## Importante antes do deploy

O Vercel não fornece um MySQL local persistente. Antes de publicar, crie um banco MySQL externo acessível pela internet e configure as variáveis `ACESSA_DB_*` no painel do Vercel. O arquivo `database.sql` contém o esquema inicial.

O armazenamento local do Vercel também não é persistente. Uploads de logo e anexos devem ser migrados para um serviço de arquivos persistente antes de uso em produção. Para demonstração, a interface e o banco funcionam com as configurações adequadas.

## TiDB Cloud Starter com Vercel

O TiDB Cloud Starter é uma opção MySQL-compatible com cota gratuita. A integração oficial Vercel/PingCAP adiciona `TIDB_HOST`, `TIDB_PORT`, `TIDB_USER`, `TIDB_PASSWORD` e `TIDB_DATABASE`; a conexão deste projeto aceita essas variáveis quando `ACESSA_DB_HOST` não estiver definido. O TiDB Starter usa normalmente a porta `4000`, exige TLS e usa o pacote de certificados CA do runtime. Para uma integração PHP, selecione o modo **General**, não Prisma.

Para evitar cobranças, mantenha o limite de gastos do Starter em `US$ 0`. Ao atingir a cota gratuita, o banco pode bloquear novas conexões até a renovação mensal. Confira os limites atuais em [TiDB Cloud Starter pricing](https://www.pingcap.com/tidb-cloud-starter-pricing-details/).

O banco ainda precisa do esquema inicial. `database.sql` começa com `DROP DATABASE`; execute-o somente em uma instalação nova e vazia, nunca sobre uma base que já contenha dados. As migrações compatíveis restantes são executadas pela aplicação após a primeira conexão.

Em instalações existentes com Supabase/PostgreSQL, aplique manualmente no SQL Editor as migrações necessárias de `migrations/`; para o filtro de UBS por localização, execute `migrations/20261009_ubs_location.sql` e `migrations/20261010_secretaria_location.sql`, e para os avisos da lista de espera execute `migrations/20261012_waitlist_ubs_notice.sql` antes de publicar o código. O bootstrap da aplicação não executa DDL no PostgreSQL.

Como este sistema pode armazenar informações de saúde, use dados fictícios no plano gratuito até avaliar requisitos de privacidade, backups e disponibilidade para o uso pretendido.

## Subir pelo GitHub

1. Crie um repositório vazio no GitHub.
2. Envie todos os arquivos desta pasta para esse repositório.
3. No Vercel, selecione **Add New Project** e importe o repositório.
4. Mantenha a raiz do projeto como a pasta que contém `vercel.json`.

> **Importante:** no Vercel, defina a raiz do projeto como a pasta que contém diretamente `vercel.json`, `index.php` e a pasta `api`. Não selecione uma pasta pai que contenha `acessa-saude-vercel` como subpasta.

5. Em **Project Settings → Environment Variables**, cadastre as variáveis do `.env.example` com os valores reais do seu MySQL.
6. Faça o deploy.

## Subir pelo terminal

Com Git e Vercel CLI instalados:

```bash
git init
git add .
git commit -m "Preparar projeto para Vercel"
vercel login
vercel link
vercel --prod
```

## Desenvolvimento local

O runtime PHP do Vercel exige PHP local para `vercel dev`. Como alternativa, use o servidor PHP embutido:

```bash
php -S 127.0.0.1:8000
```

## Modo demonstração

A aba de assinatura está em modo demonstração e não cria cobrança real no Asaas. Não configure `ASAAS_API_KEY` enquanto esse modo estiver sendo utilizado.

## Referências

- [Vercel — runtimes](https://vercel.com/docs/functions/runtimes)
- [Runtime PHP comunitário](https://github.com/vercel-community/php)
- [PHP no Vercel com Docker](https://vercel.com/kb/guide/deploy-php-on-vercel-with-docker)

## Suporte da clínica e gestão global da Secretaria

- Os chamados abertos a partir do link público de uma clínica são encaminhados primeiro à própria clínica. A clínica pode registrar uma resposta, resolver o chamado e, após a triagem/resposta, encaminhá-lo ao desenvolvedor com a descrição da falha técnica.
- A marcação particular valida novamente o horário disponível no servidor e grava paciente, vínculo e consulta em transação para reduzir colisões de agenda.
- O perfil **Secretaria** tem escopo global nas UBS: pode consultar e alterar dados de qualquer unidade, criar UBS e desativar/reativar unidades sem apagar o histórico. O painel agregado apresenta consultas do dia e metas de campanhas, vacinação e citologia; os valores de metas e realizados são cadastrados pela Secretaria.
- A primeira conta global deve ser criada por um usuário **Desenvolvedor** autenticado, na aba **Administrador da Secretaria** do painel. Use uma senha forte de pelo menos 12 caracteres. Nenhuma senha padrão é embutida no repositório.
- As migrações compatíveis de colunas e tabelas rodam pela aplicação ao conectar ao banco existente. `database.sql` serve apenas para instalação nova: começa com `DROP DATABASE`; **não o execute em uma base de produção existente**.


## Links de acesso separados

- **SUS / UBS:** `/ubs`
- **Clínica:** `/clinica`

No XAMPP/Apache, as rotas são tratadas pelo `.htaccess`; na Vercel, estão declaradas em `vercel.json`. A home `/` continua disponível como seletor.

Usuários UBS visualizam a equipe em modo somente leitura. Somente Secretaria e desenvolvedor podem criar, editar ou excluir funcionários; a API valida essa permissão além de ocultar os controles na interface.

## Secretarias municipais e lista de espera

- **Uma Secretaria por município:** o desenvolvedor cria cada conta (ex.: Secretaria de Saúde de Serra/ES) já com UF e cidade. A Secretaria só vê, edita, arquiva e acompanha indicadores das UBS da sua cidade, e toda UBS que ela cria nasce nessa cidade/UF. Contas antigas sem cidade continuam com visão global até receberem localização (o que acontece ao criarem a primeira UBS).
- **Localização das UBS:** só o desenvolvedor altera cidade/UF de uma UBS já localizada. A grafia da cidade é padronizada com a já cadastrada ("serra" vira "Serra"), evitando opções duplicadas no login.
- **Login do paciente:** Estado → Cidade → UBS. Quando há uma única opção ela é selecionada automaticamente, e o navegador lembra o último estado/cidade escolhidos.
- **Lista de espera:** quando uma consulta é cancelada — pelo paciente ou pela UBS — o primeiro da fila recebe a vaga automaticamente. Na aba **Lista de espera**, a UBS vê esses pacientes em "Vagas repassadas — avise o paciente" (com selo na aba) e envia o aviso pelo WhatsApp; o aviso fica registrado nas notificações do paciente. Cada paciente aguardando mostra quantas vagas livres existem na data, para a UBS encaixar quando houver.

