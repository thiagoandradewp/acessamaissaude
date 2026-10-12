<?php
declare(strict_types=1);

require_once __DIR__ . '/postgres_compat.php';

const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'conecta_saude';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $databaseUrl = trim((string)(getenv('DATABASE_URL') ?: ''));
    if ($databaseUrl !== '') {
        $connection = postgres_connection_details($databaseUrl);
        $pdo = new PostgresCompatPDO($connection['dsn'], $connection['user'], $connection['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // O esquema Supabase é mantido fora do bootstrap; nenhuma DDL ou migração
        // de dados deve ser executada automaticamente durante uma requisição.
        return $pdo;
    }
    $manualHost = getenv('ACESSA_DB_HOST') ?: '';
    $tidbHost = getenv('TIDB_HOST') ?: '';
    $useTiDB = $manualHost === '' && $tidbHost !== '';
    $host = $manualHost ?: ($tidbHost ?: DB_HOST);
    $port = getenv('ACESSA_DB_PORT') ?: ($useTiDB ? (getenv('TIDB_PORT') ?: '4000') : DB_PORT);
    $user = getenv('ACESSA_DB_USER') ?: ($useTiDB ? (getenv('TIDB_USER') ?: DB_USER) : DB_USER);
    $pass = getenv('ACESSA_DB_PASS');
    if ($pass === false || $pass === '') $pass = $useTiDB ? (getenv('TIDB_PASSWORD') ?: '') : DB_PASS;
    $dbName = getenv('ACESSA_DB_NAME') ?: ($useTiDB ? (getenv('TIDB_DATABASE') ?: DB_NAME) : DB_NAME);
    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $dbName . ';charset=utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $sslCaPath = getenv('ACESSA_DB_SSL_CA') ?: '';
    if ($useTiDB || $sslCaPath !== '') {
        if (!defined('PDO::MYSQL_ATTR_SSL_CA')) {
            throw new RuntimeException('O driver PDO MySQL não disponibiliza configuração TLS para o banco.');
        }
        if ($sslCaPath === '') {
            foreach (['/etc/ssl/certs/ca-certificates.crt', '/etc/ssl/cert.pem', '/etc/pki/tls/certs/ca-bundle.crt'] as $candidate) {
                if (is_file($candidate)) {
                    $sslCaPath = $candidate;
                    break;
                }
            }
        }
        if ($sslCaPath === '' || !is_file($sslCaPath)) {
            throw new RuntimeException('O TiDB Cloud exige TLS. Configure ACESSA_DB_SSL_CA com o caminho do pacote de certificados CA.');
        }
        $options[constant('PDO::MYSQL_ATTR_SSL_CA')] = $sslCaPath;
        if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')] = true;
        }
    }
    $pdo = new PDO($dsn, $user, $pass, $options);
    ensure_schema_compatibility($pdo, $dbName);
    return $pdo;
}

function ensure_schema_compatibility(PDO $pdo, string $dbName): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql') {
        $pdo->exec('CREATE TABLE IF NOT EXISTS php_sessions (id_hash CHAR(64) PRIMARY KEY, payload MEDIUMTEXT NOT NULL, expires_at INT UNSIGNED NOT NULL, INDEX idx_php_sessions_expires_at (expires_at)) ENGINE=InnoDB');
    }
    $quotedDb = $pdo->quote($dbName);
    $columnExists = static function (string $table, string $column) use ($pdo, $quotedDb): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '.$quotedDb.' AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    };
    $addColumn = static function (string $table, string $column, string $definition) use ($pdo, $columnExists): void {
        if (!$columnExists($table, $column)) $pdo->exec('ALTER TABLE `'.$table.'` ADD COLUMN `'.$column.'` '.$definition);
    };

    // Bases antigas podem não conter os limites adicionados depois da instalação inicial.
    $addColumn('ubs', 'limite_diario', 'INT UNSIGNED NOT NULL DEFAULT 12 AFTER usuario');
    $addColumn('ubs', 'cidade', "VARCHAR(120) NOT NULL DEFAULT '' AFTER endereco");
    $addColumn('ubs', 'estado', "CHAR(2) NOT NULL DEFAULT '' AFTER cidade");
    $addColumn('administradores', 'cidade', "VARCHAR(120) NOT NULL DEFAULT '' AFTER ubs_id");
    $addColumn('administradores', 'estado', "CHAR(2) NOT NULL DEFAULT '' AFTER cidade");
    $addColumn('pacientes', 'email', 'VARCHAR(180) NULL AFTER telefone');
    $addColumn('profissionais', 'slug', 'VARCHAR(160) NULL AFTER email');
    $addColumn('profissionais', 'cnpj', 'VARCHAR(30) NULL AFTER slug');
    $addColumn('profissionais', 'horario_funcionamento', 'VARCHAR(180) NULL AFTER logo_arquivo');
    $addColumn('profissionais', 'aviso_publico', 'TEXT NULL AFTER horario_funcionamento');
    $addColumn('profissionais', 'mensagem_pos_venda', 'TEXT NULL AFTER aviso_publico');
    $addColumn('profissionais', 'limite_diario', 'INT UNSIGNED NOT NULL DEFAULT 12 AFTER mensagem_pos_venda');
    try { $pdo->exec("UPDATE profissionais SET slug = CONCAT('profissional-', id) WHERE slug IS NULL OR slug = ''"); $pdo->exec("ALTER TABLE profissionais MODIFY slug VARCHAR(160) NOT NULL UNIQUE"); } catch (Throwable $ignored) {}
    $addColumn('consultas', 'assunto', 'TEXT NULL AFTER especialidade');
    $addColumn('consultas', 'cancelamento_motivo', 'VARCHAR(255) NULL AFTER status');
    $addColumn('exames_resultados', 'enviado_por', 'INT UNSIGNED NULL AFTER anexo_arquivo');
    $addColumn('suporte_mensagens', 'protocolo', 'VARCHAR(30) NULL AFTER id');
    $addColumn('suporte_mensagens', 'resposta', 'TEXT NULL AFTER mensagem');
    $addColumn('suporte_mensagens', 'resposta_clinica', 'TEXT NULL AFTER resposta');
    $addColumn('suporte_mensagens', 'resposta_desenvolvedor', 'TEXT NULL AFTER resposta_clinica');
    $addColumn('suporte_mensagens', 'atualizado_em', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER criado_em');
    $addColumn('suporte_mensagens', 'encaminhamento_motivo', 'TEXT NULL AFTER encaminhado_desenvolvedor');
    try {
        $pdo->exec("UPDATE suporte_mensagens SET protocolo = CONCAT('SUP-LEGACY-', LPAD(id, 10, '0')) WHERE protocolo IS NULL OR protocolo = ''");
        $pdo->exec('ALTER TABLE suporte_mensagens MODIFY protocolo VARCHAR(30) NOT NULL');
        $indexes = $pdo->query("SHOW INDEX FROM suporte_mensagens WHERE Key_name = 'uq_suporte_protocolo'")->fetchAll();
        if (!$indexes) $pdo->exec('ALTER TABLE suporte_mensagens ADD UNIQUE KEY uq_suporte_protocolo (protocolo)');
    } catch (Throwable $ignored) {}

    $pdo->exec('CREATE TABLE IF NOT EXISTS lista_espera (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, paciente_id INT UNSIGNED NOT NULL, ubs_id VARCHAR(20) NOT NULL, especialidade VARCHAR(120) NOT NULL, data_consulta DATE NOT NULL, criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_lista_espera (paciente_id,ubs_id,especialidade,data_consulta)) ENGINE=InnoDB');
    $addColumn('lista_espera', 'status', "ENUM('pendente','agendado','cancelado') NOT NULL DEFAULT 'pendente' AFTER data_consulta");
    $addColumn('lista_espera', 'consulta_id', 'VARCHAR(60) NULL AFTER status');
    if (!$columnExists('lista_espera', 'aviso_enviado_em')) {
        $addColumn('lista_espera', 'aviso_enviado_em', 'DATETIME NULL AFTER consulta_id');
        // Agendamentos anteriores ao aviso manual não devem aparecer como pendentes para a UBS.
        $pdo->exec("UPDATE lista_espera SET aviso_enviado_em = criado_em WHERE status <> 'pendente'");
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS auditoria (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, admin_id INT UNSIGNED NULL, tipo_usuario VARCHAR(30) NULL, acao VARCHAR(100) NOT NULL, entidade VARCHAR(80) NULL, entidade_id VARCHAR(80) NULL, detalhes TEXT NULL, ip VARCHAR(45) NULL, criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_auditoria_data (criado_em)) ENGINE=InnoDB');
    $pdo->exec("CREATE TABLE IF NOT EXISTS configuracao_app (id TINYINT UNSIGNED PRIMARY KEY, modo ENUM('ubs','profissional') NOT NULL DEFAULT 'ubs', nome_exibicao VARCHAR(150) NOT NULL DEFAULT 'Acessa+ Saúde', especialidade VARCHAR(150) NULL, registro_profissional VARCHAR(100) NULL, telefone VARCHAR(40) NULL, whatsapp VARCHAR(40) NULL, endereco VARCHAR(255) NULL, modalidade VARCHAR(100) NULL, valor_consulta DECIMAL(10,2) NULL, apresentacao TEXT NULL, logo_arquivo VARCHAR(255) NULL, horario_funcionamento VARCHAR(180) NULL, aviso_publico TEXT NULL, cor_primaria VARCHAR(20) NOT NULL DEFAULT '#0fa7a7', cor_secundaria VARCHAR(20) NOT NULL DEFAULT '#075b5d', atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $pdo->exec('INSERT IGNORE INTO configuracao_app (id) VALUES (1)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS profissionais (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(150) NOT NULL, email VARCHAR(180) NOT NULL UNIQUE, slug VARCHAR(160) NOT NULL UNIQUE, cnpj VARCHAR(30) NULL, senha_hash VARCHAR(255) NOT NULL, especialidade VARCHAR(150) NULL, registro_profissional VARCHAR(100) NULL, telefone VARCHAR(40) NULL, whatsapp VARCHAR(40) NULL, endereco VARCHAR(255) NULL, modalidade VARCHAR(100) NULL, valor_consulta DECIMAL(10,2) NULL, apresentacao TEXT NULL, logo_arquivo VARCHAR(255) NULL, cor_primaria VARCHAR(20) NOT NULL DEFAULT '#0fa7a7', cor_secundaria VARCHAR(20) NOT NULL DEFAULT '#075b5d', status ENUM('ativo','suspenso','pendente') NOT NULL DEFAULT 'ativo', criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP, atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $addColumn('profissionais', 'mensagem_pos_venda', 'TEXT NULL AFTER aviso_publico');
    $pdo->exec("CREATE TABLE IF NOT EXISTS profissional_pacientes (profissional_id INT UNSIGNED NOT NULL, paciente_id INT UNSIGNED NOT NULL, consentimento_em DATETIME NULL, criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (profissional_id,paciente_id)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agendas_profissionais (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, profissional_id INT UNSIGNED NOT NULL, dia_semana TINYINT UNSIGNED NOT NULL, inicio TIME NOT NULL, fim TIME NOT NULL, duracao_minutos SMALLINT UNSIGNED NOT NULL DEFAULT 30, ativo TINYINT(1) NOT NULL DEFAULT 1, UNIQUE KEY uq_agenda_profissional (profissional_id,dia_semana,inicio,fim)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS consultas_profissionais (id VARCHAR(60) PRIMARY KEY, profissional_id INT UNSIGNED NOT NULL, paciente_id INT UNSIGNED NOT NULL, data_consulta DATE NOT NULL, horario TIME NOT NULL, assunto TEXT NULL, valor DECIMAL(10,2) NULL, forma_pagamento VARCHAR(40) NULL, pagamento_status ENUM('pendente','pago','dispensado') NOT NULL DEFAULT 'pendente', pago_em DATETIME NULL, status ENUM('solicitada','agendada','confirmada','atendida','cancelada','faltou') NOT NULL DEFAULT 'solicitada', cancelamento_motivo VARCHAR(255) NULL, confirmada_em DATETIME NULL, atendida_em DATETIME NULL, criada_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_cp_prof_data (profissional_id,data_consulta,horario), INDEX idx_cp_paciente (paciente_id,data_consulta)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pagamentos_pacientes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, profissional_id INT UNSIGNED NOT NULL, paciente_id INT UNSIGNED NOT NULL, consulta_id VARCHAR(60) NULL, valor DECIMAL(10,2) NOT NULL, forma_pagamento VARCHAR(40) NOT NULL, tipo_cartao VARCHAR(20) NULL, parcelas TINYINT UNSIGNED NULL, recebido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_pagamento_consulta (consulta_id), INDEX idx_fin_prof_data (profissional_id,recebido_em), INDEX idx_fin_paciente (paciente_id,recebido_em)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS relacionamento_contatos (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, profissional_id INT UNSIGNED NOT NULL, paciente_id INT UNSIGNED NOT NULL, canal ENUM('whatsapp') NOT NULL DEFAULT 'whatsapp', mensagem TEXT NOT NULL, enviado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_rel_prof_paciente (profissional_id,paciente_id,enviado_em)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS notificacoes_profissionais (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, profissional_id INT UNSIGNED NOT NULL, paciente_id INT UNSIGNED NOT NULL, consulta_id VARCHAR(60) NOT NULL, tipo ENUM('confirmacao','lembrete','lista_espera_agendada') NOT NULL, canal ENUM('whatsapp','email') NOT NULL DEFAULT 'whatsapp', mensagem TEXT NOT NULL, agendada_para DATETIME NOT NULL, enviada_em DATETIME NULL, status ENUM('pendente','enviada','erro') NOT NULL DEFAULT 'pendente', criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_notif_prof_consulta_tipo (consulta_id,tipo), INDEX idx_notif_prof_agendada (profissional_id,status,agendada_para)) ENGINE=InnoDB");
    $addColumn('consultas_profissionais', 'forma_pagamento', 'VARCHAR(40) NULL AFTER valor');
    $addColumn('consultas_profissionais', 'tipo_cartao', 'VARCHAR(20) NULL AFTER forma_pagamento');
    $addColumn('consultas_profissionais', 'parcelas', 'TINYINT UNSIGNED NULL AFTER tipo_cartao');
    $addColumn('consultas_profissionais', 'recibo_valor', 'DECIMAL(10,2) NULL AFTER parcelas');
    $addColumn('pagamentos_pacientes', 'tipo_cartao', 'VARCHAR(20) NULL AFTER forma_pagamento');
    $addColumn('pagamentos_pacientes', 'parcelas', 'TINYINT UNSIGNED NULL AFTER tipo_cartao');
    $addColumn('consultas_profissionais', 'pagamento_status', "ENUM('pendente','pago','dispensado') NOT NULL DEFAULT 'pendente' AFTER forma_pagamento");
    $addColumn('consultas_profissionais', 'pago_em', 'DATETIME NULL AFTER pagamento_status');
    $addColumn('consultas_profissionais', 'confirmada_em', 'DATETIME NULL AFTER cancelamento_motivo');
    $addColumn('consultas_profissionais', 'atendida_em', 'DATETIME NULL AFTER confirmada_em');
    $pdo->exec("CREATE TABLE IF NOT EXISTS prontuarios_profissionais (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, profissional_id INT UNSIGNED NOT NULL, paciente_id INT UNSIGNED NOT NULL, consulta_id VARCHAR(60) NULL, tipo VARCHAR(80) NOT NULL DEFAULT 'evolucao', conteudo MEDIUMTEXT NOT NULL, criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP, atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_prontuario_paciente (profissional_id,paciente_id,criado_em)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS planos_assinatura (id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, codigo VARCHAR(40) NOT NULL UNIQUE, nome VARCHAR(100) NOT NULL, valor_mensal DECIMAL(10,2) NOT NULL, limite_pacientes INT UNSIGNED NULL, ativo TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS assinaturas_profissionais (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, profissional_id INT UNSIGNED NOT NULL, plano_id SMALLINT UNSIGNED NOT NULL, status ENUM('pendente','ativa','inadimplente','cancelada','expirada') NOT NULL DEFAULT 'pendente', inicio DATE NULL, fim DATE NULL, gateway VARCHAR(40) NULL, referencia_externa VARCHAR(180) NULL, criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP, atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_assinatura_prof (profissional_id,status)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pagamentos_profissionais (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, profissional_id INT UNSIGNED NOT NULL, assinatura_id BIGINT UNSIGNED NULL, valor DECIMAL(10,2) NOT NULL, status ENUM('pendente','aprovado','recusado','estornado','manual') NOT NULL DEFAULT 'pendente', metodo VARCHAR(40) NULL, gateway VARCHAR(40) NULL, referencia_externa VARCHAR(180) NULL, pago_em DATETIME NULL, criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_pagamento_prof (profissional_id,status)) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO planos_assinatura (codigo,nome,valor_mensal,limite_pacientes) VALUES ('essencial','Plano 50 pacientes',49.99,50),('profissional','Plano 100 pacientes',99.99,100) ON DUPLICATE KEY UPDATE nome=VALUES(nome),valor_mensal=VALUES(valor_mensal),limite_pacientes=VALUES(limite_pacientes),ativo=1");
    $pdo->exec("UPDATE planos_assinatura SET ativo=0 WHERE codigo NOT IN ('essencial','profissional')");

    // Segurança, agenda por slots e gestão segura de marcações particulares.
    $addColumn('profissionais', 'email_verificado_em', 'DATETIME NULL AFTER email');
    $addColumn('profissionais', 'auth_version', 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER status');
    $addColumn('profissionais', 'confirmacao_automatica', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER limite_diario');
    $addColumn('profissionais', 'cancelamento_ate_horas', 'SMALLINT UNSIGNED NOT NULL DEFAULT 24 AFTER confirmacao_automatica');
    $addColumn('profissionais', 'remarcacao_ate_horas', 'SMALLINT UNSIGNED NOT NULL DEFAULT 24 AFTER cancelamento_ate_horas');
    $pdo->exec("UPDATE profissionais SET email_verificado_em = COALESCE(email_verificado_em, criado_em) WHERE status='ativo'");
    $addColumn('agendas_profissionais', 'pausa_inicio', 'TIME NULL AFTER duracao_minutos');
    $addColumn('agendas_profissionais', 'pausa_fim', 'TIME NULL AFTER pausa_inicio');
    $addColumn('consultas_profissionais', 'duracao_minutos', 'SMALLINT UNSIGNED NOT NULL DEFAULT 30 AFTER horario');
    $addColumn('consultas_profissionais', 'manage_token_hash', 'CHAR(64) NULL AFTER atendida_em');
    $addColumn('auditoria', 'profissional_id', 'INT UNSIGNED NULL AFTER admin_id');

    // CPF não é chave global: o mesmo CPF não pode fundir prontuário SUS e prontuário particular.
    try {
        $cpfIndexes = $pdo->query("SHOW INDEX FROM pacientes WHERE Column_name='cpf' AND Non_unique=0")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cpfIndexes as $idx) {
            $key = str_replace('`', '``', (string)$idx['Key_name']);
            if ($key !== 'PRIMARY') $pdo->exec('ALTER TABLE pacientes DROP INDEX `' . $key . '`');
        }
    } catch (Throwable $ignored) {}
    try { $pdo->exec('CREATE INDEX idx_pacientes_cpf ON pacientes (cpf)'); } catch (Throwable $ignored) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS tokens_profissionais (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        profissional_id INT UNSIGNED NOT NULL,
        tipo ENUM('verificar_email','redefinir_senha') NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expira_em DATETIME NOT NULL,
        consumido_em DATETIME NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token_prof_tipo (profissional_id,tipo,expira_em),
        CONSTRAINT fk_token_profissional FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tentativas_autenticacao (
        chave CHAR(64) PRIMARY KEY,
        tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        inicio_janela DATETIME NOT NULL,
        bloqueado_ate DATETIME NULL,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    try { $pdo->exec('CREATE INDEX idx_auditoria_profissional_data ON auditoria (profissional_id,criado_em)'); } catch (Throwable $ignored) {}
    // Legacy private-patient isolation: split SUS/clinic rows and shared clinic tenants once.
    $hasTable = static function (string $table) use ($pdo, $quotedDb): bool {
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='.$quotedDb.' AND TABLE_NAME=?');$stmt->execute([$table]);return (bool)$stmt->fetchColumn();
    };
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_migrations (version VARCHAR(120) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $splitDone=(bool)$pdo->query("SELECT 1 FROM system_migrations WHERE version='private_patient_tenant_split_v1'")->fetchColumn();
    if (!$splitDone && $hasTable('profissional_pacientes') && $hasTable('pacientes')) {
        $pdo->beginTransaction();
        try {
            $claim=$pdo->prepare("INSERT IGNORE INTO system_migrations (version) VALUES ('private_patient_tenant_split_v1')");$claim->execute();
            if($claim->rowCount()>0){
                $shared=$pdo->query("SELECT pp.profissional_id,pp.paciente_id,pp.consentimento_em,p.codigo,p.nome,p.telefone,p.email,p.cpf,p.endereco,p.data_nascimento,p.ubs_id FROM profissional_pacientes pp INNER JOIN pacientes p ON p.id=pp.paciente_id WHERE p.ubs_id IS NOT NULL OR (SELECT COUNT(*) FROM profissional_pacientes pp2 WHERE pp2.paciente_id=p.id)>1")->fetchAll(PDO::FETCH_ASSOC);
                foreach($shared as $row) {
                    $professional=(int)$row['profissional_id'];$oldPatient=(int)$row['paciente_id'];$newPatient=0;
                    if(!empty($row['cpf'])){$find=$pdo->prepare('SELECT p.id FROM profissional_pacientes pp INNER JOIN pacientes p ON p.id=pp.paciente_id WHERE pp.profissional_id=? AND pp.paciente_id<>? AND p.cpf=? AND p.ubs_id IS NULL LIMIT 1');$find->execute([$professional,$oldPatient,$row['cpf']]);$newPatient=(int)($find->fetchColumn()?:0);}
                    if($newPatient){$pdo->prepare('UPDATE profissional_pacientes SET consentimento_em=COALESCE(consentimento_em,?) WHERE profissional_id=? AND paciente_id=?')->execute([$row['consentimento_em'],$professional,$newPatient]);}
                    else{$newSus='CLI-'.$professional.'-'.strtoupper(bin2hex(random_bytes(8)));$newCode='PRV-'.$professional.'-'.strtoupper(bin2hex(random_bytes(5)));$ins=$pdo->prepare('INSERT INTO pacientes (codigo,nome,telefone,email,cpf,sus,ubs_id,endereco,data_nascimento) VALUES (?,?,?,?,?,?,NULL,?,?)');$ins->execute([$newCode,$row['nome'],$row['telefone'],$row['email'],$row['cpf'],$newSus,$row['endereco'],$row['data_nascimento']]);$newPatient=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO profissional_pacientes (profissional_id,paciente_id,consentimento_em) VALUES (?,?,?)')->execute([$professional,$newPatient,$row['consentimento_em']]);}
                    $pdo->prepare('DELETE FROM profissional_pacientes WHERE profissional_id=? AND paciente_id=?')->execute([$professional,$oldPatient]);
                    foreach(['consultas_profissionais','prontuarios_profissionais','pagamentos_pacientes','relacionamento_contatos','lista_espera_profissionais','notificacoes_profissionais'] as $table) {
                        if (!$hasTable($table)) continue;
                        $stmt=$pdo->prepare('UPDATE `'.$table.'` SET paciente_id=? WHERE profissional_id=? AND paciente_id=?');$stmt->execute([$newPatient,$professional,$oldPatient]);
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $migrationError) { if($pdo->inTransaction())$pdo->rollBack(); throw $migrationError; }
    }

    // Secretaria municipal, arquivamento reversível de UBS e indicadores agregados.
    $addColumn('ubs', 'ativa', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER usuario');
    $adminTypeStmt = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '.$quotedDb.' AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $adminTypeStmt->execute(['administradores', 'tipo']);
    $adminType = (string)($adminTypeStmt->fetchColumn() ?: '');
    if ($adminType !== '' && !str_contains($adminType, "'secretaria'")) {
        $pdo->exec("ALTER TABLE administradores MODIFY tipo ENUM('desenvolvedor','ubs','secretaria') NOT NULL");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS indicadores_saude (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ubs_id VARCHAR(20) NOT NULL,
        tipo ENUM('campanha','vacina','citologia') NOT NULL,
        nome VARCHAR(180) NOT NULL,
        meta INT UNSIGNED NOT NULL,
        realizado INT UNSIGNED NOT NULL DEFAULT 0,
        periodo_inicio DATE NULL,
        periodo_fim DATE NULL,
        criado_por INT UNSIGNED NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_indicadores_periodo (ubs_id,tipo,periodo_inicio,periodo_fim),
        CONSTRAINT fk_indicadores_ubs FOREIGN KEY (ubs_id) REFERENCES ubs(id) ON DELETE RESTRICT,
        CONSTRAINT fk_indicadores_admin FOREIGN KEY (criado_por) REFERENCES administradores(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");

}
