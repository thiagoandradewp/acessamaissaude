DROP DATABASE IF EXISTS `conecta_saude`;
CREATE DATABASE `conecta_saude` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `conecta_saude`;


CREATE TABLE IF NOT EXISTS ubs (
    id VARCHAR(20) PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    endereco VARCHAR(255) NOT NULL,
    cidade VARCHAR(120) NOT NULL DEFAULT '',
    estado CHAR(2) NOT NULL DEFAULT '',
    telefone VARCHAR(30) NOT NULL,
    horario VARCHAR(100) NOT NULL,
    usuario VARCHAR(80) NOT NULL UNIQUE,
    ativa TINYINT(1) NOT NULL DEFAULT 1,
    limite_diario INT UNSIGNED NOT NULL DEFAULT 12,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ubs_especialidades (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ubs_id VARCHAR(20) NOT NULL,
    nome VARCHAR(120) NOT NULL,
    UNIQUE KEY uq_ubs_especialidade (ubs_id, nome),
    CONSTRAINT fk_esp_ubs FOREIGN KEY (ubs_id) REFERENCES ubs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ubs_servicos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ubs_id VARCHAR(20) NOT NULL,
    nome VARCHAR(255) NOT NULL,
    UNIQUE KEY uq_ubs_servico (ubs_id, nome),
    CONSTRAINT fk_serv_ubs FOREIGN KEY (ubs_id) REFERENCES ubs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ubs_campanhas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ubs_id VARCHAR(20) NOT NULL,
    nome VARCHAR(255) NOT NULL,
    UNIQUE KEY uq_ubs_campanha (ubs_id, nome),
    CONSTRAINT fk_camp_ubs FOREIGN KEY (ubs_id) REFERENCES ubs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ubs_documentos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ubs_id VARCHAR(20) NOT NULL,
    nome VARCHAR(255) NOT NULL,
    UNIQUE KEY uq_ubs_documento (ubs_id, nome),
    CONSTRAINT fk_doc_ubs FOREIGN KEY (ubs_id) REFERENCES ubs(id) ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS funcionarios (
    id VARCHAR(40) PRIMARY KEY,
    ubs_id VARCHAR(20) NOT NULL,
    nome VARCHAR(150) NOT NULL,
    cargo VARCHAR(120) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_func_ubs (ubs_id),
    CONSTRAINT fk_func_ubs FOREIGN KEY (ubs_id) REFERENCES ubs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pacientes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(30) NULL UNIQUE,
    nome VARCHAR(150) NOT NULL,
    telefone VARCHAR(30) NOT NULL,
    email VARCHAR(180) NULL,
    cpf VARCHAR(14) NULL,
    INDEX idx_pacientes_cpf (cpf),
    sus VARCHAR(40) NOT NULL UNIQUE,
    ubs_id VARCHAR(20) NULL,
    endereco VARCHAR(255) NULL,
    data_nascimento DATE NULL,
    condicoes_saude TEXT NULL,
    alergias TEXT NULL,
    medicamentos TEXT NULL,
    informacoes_adicionais TEXT NULL,
    CONSTRAINT fk_paciente_ubs FOREIGN KEY (ubs_id) REFERENCES ubs(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS consultas (
    id VARCHAR(60) PRIMARY KEY,
    ubs_id VARCHAR(20) NOT NULL,
    paciente_id INT UNSIGNED NOT NULL,
    especialidade VARCHAR(120) NOT NULL,
    assunto TEXT NULL,
    data_consulta DATE NOT NULL,
    horario TIME NOT NULL,
    fila INT UNSIGNED NOT NULL,
    status ENUM('agendado','atendido','faltou','cancelado') NOT NULL DEFAULT 'agendado',
    cancelamento_motivo VARCHAR(255) NULL,
    lembrete TINYINT(1) NOT NULL DEFAULT 0,
    notificado TINYINT(1) NOT NULL DEFAULT 0,
    criada_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_consultas_slot (ubs_id, especialidade, data_consulta, horario, status),
    INDEX idx_consultas_paciente_dia (paciente_id, ubs_id, especialidade, data_consulta, status),
    INDEX idx_consultas_paciente (paciente_id),
    INDEX idx_consultas_ubs_data (ubs_id, data_consulta),
    CONSTRAINT fk_consulta_ubs FOREIGN KEY (ubs_id) REFERENCES ubs(id) ON DELETE RESTRICT,
    CONSTRAINT fk_consulta_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notificacoes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, paciente_id INT UNSIGNED NOT NULL, consulta_id VARCHAR(60) NULL,
    canal ENUM('sms','whatsapp','email','push') NOT NULL DEFAULT 'sms', tipo VARCHAR(60) NOT NULL, mensagem TEXT NOT NULL,
    agendada_para DATETIME NOT NULL, enviada_em DATETIME NULL, status ENUM('pendente','enviada','erro') NOT NULL DEFAULT 'pendente', criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notificacoes_status (status, agendada_para),
    CONSTRAINT fk_notif_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_consulta FOREIGN KEY (consulta_id) REFERENCES consultas(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS configuracoes (
    chave VARCHAR(80) PRIMARY KEY,
    valor TEXT NOT NULL,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS lista_espera (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    paciente_id INT UNSIGNED NOT NULL,
    ubs_id VARCHAR(20) NOT NULL,
    especialidade VARCHAR(120) NOT NULL,
    data_consulta DATE NOT NULL,
    status ENUM('pendente','agendado','cancelado') NOT NULL DEFAULT 'pendente',
    consulta_id VARCHAR(60) NULL,
    aviso_enviado_em DATETIME NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lista_espera (paciente_id, ubs_id, especialidade, data_consulta),
    INDEX idx_lista_espera_fila (ubs_id,especialidade,data_consulta,status,id),
    CONSTRAINT fk_lista_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
    CONSTRAINT fk_lista_ubs FOREIGN KEY (ubs_id) REFERENCES ubs(id) ON DELETE CASCADE,
    CONSTRAINT fk_lista_consulta FOREIGN KEY (consulta_id) REFERENCES consultas(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS suporte_mensagens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, protocolo VARCHAR(30) NOT NULL UNIQUE, acesso_token VARCHAR(64) NOT NULL, paciente_id INT UNSIGNED NULL, nome VARCHAR(150) NOT NULL, telefone VARCHAR(30) NOT NULL, mensagem TEXT NOT NULL,
    resposta TEXT NULL, resposta_clinica TEXT NULL, resposta_desenvolvedor TEXT NULL, destino_tipo VARCHAR(20) NOT NULL DEFAULT 'desenvolvedor', destino_id VARCHAR(60) NULL, encaminhado_desenvolvedor TINYINT(1) NOT NULL DEFAULT 0, encaminhamento_motivo TEXT NULL, status ENUM('aberto','em_atendimento','resolvido') NOT NULL DEFAULT 'aberto', criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP, atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_suporte_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS exames_resultados (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    paciente_id INT UNSIGNED NOT NULL,
    ubs_id VARCHAR(20) NOT NULL,
    nome_exame VARCHAR(180) NOT NULL,
    data_exame DATE NOT NULL,
    resultado TEXT NOT NULL,
    observacoes TEXT NULL,
    anexo_nome VARCHAR(255) NULL,
    anexo_mime VARCHAR(80) NULL,
    anexo_arquivo VARCHAR(255) NULL,
    enviado_por INT UNSIGNED NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_exames_paciente (paciente_id, data_exame),
    INDEX idx_exames_ubs (ubs_id, data_exame),
    CONSTRAINT fk_exame_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE RESTRICT,
    CONSTRAINT fk_exame_ubs FOREIGN KEY (ubs_id) REFERENCES ubs(id) ON DELETE RESTRICT
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS administradores (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(80) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    tipo ENUM('desenvolvedor','ubs','secretaria') NOT NULL,
    ubs_id VARCHAR(20) NULL UNIQUE,
    cidade VARCHAR(120) NOT NULL DEFAULT '',
    estado CHAR(2) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_admin_ubs FOREIGN KEY (ubs_id) REFERENCES ubs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS indicadores_saude (
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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS auditoria (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NULL,
    profissional_id INT UNSIGNED NULL,
    tipo_usuario VARCHAR(30) NULL,
    acao VARCHAR(100) NOT NULL,
    entidade VARCHAR(80) NULL,
    entidade_id VARCHAR(80) NULL,
    detalhes TEXT NULL,
    ip VARCHAR(45) NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auditoria_data (criado_em),
    INDEX idx_auditoria_admin (admin_id),
    INDEX idx_auditoria_profissional_data (profissional_id, criado_em),
    CONSTRAINT fk_auditoria_admin FOREIGN KEY (admin_id) REFERENCES administradores(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS configuracao_app (
    id TINYINT UNSIGNED PRIMARY KEY,
    modo ENUM('ubs','profissional') NOT NULL DEFAULT 'ubs',
    nome_exibicao VARCHAR(150) NOT NULL DEFAULT 'Acessa+ Saúde',
    especialidade VARCHAR(150) NULL,
    registro_profissional VARCHAR(100) NULL,
    telefone VARCHAR(40) NULL,
    whatsapp VARCHAR(40) NULL,
    endereco VARCHAR(255) NULL,
    modalidade VARCHAR(100) NULL,
    valor_consulta DECIMAL(10,2) NULL,
    apresentacao TEXT NULL,
    logo_arquivo VARCHAR(255) NULL,
    horario_funcionamento VARCHAR(180) NULL,
    aviso_publico TEXT NULL,
    cor_primaria VARCHAR(20) NOT NULL DEFAULT '#0fa7a7',
    cor_secundaria VARCHAR(20) NOT NULL DEFAULT '#075b5d',
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO configuracao_app (id) VALUES (1);


CREATE TABLE IF NOT EXISTS profissionais (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    email VARCHAR(180) NOT NULL UNIQUE,
    slug VARCHAR(160) NOT NULL UNIQUE, cnpj VARCHAR(30) NULL,
    senha_hash VARCHAR(255) NOT NULL,
    especialidade VARCHAR(150) NULL,
    registro_profissional VARCHAR(100) NULL,
    telefone VARCHAR(40) NULL,
    whatsapp VARCHAR(40) NULL,
    endereco VARCHAR(255) NULL,
    modalidade VARCHAR(100) NULL,
    valor_consulta DECIMAL(10,2) NULL,
    apresentacao TEXT NULL,
    logo_arquivo VARCHAR(255) NULL,
    horario_funcionamento VARCHAR(180) NULL,
    aviso_publico TEXT NULL,
    mensagem_pos_venda TEXT NULL,
    limite_diario INT UNSIGNED NOT NULL DEFAULT 12,
    confirmacao_automatica TINYINT(1) NOT NULL DEFAULT 1,
    cancelamento_ate_horas SMALLINT UNSIGNED NOT NULL DEFAULT 24,
    remarcacao_ate_horas SMALLINT UNSIGNED NOT NULL DEFAULT 24,
    email_verificado_em DATETIME NULL,
    auth_version INT UNSIGNED NOT NULL DEFAULT 1,
    cor_primaria VARCHAR(20) NOT NULL DEFAULT '#0fa7a7',
    cor_secundaria VARCHAR(20) NOT NULL DEFAULT '#075b5d',
    status ENUM('ativo','suspenso','pendente') NOT NULL DEFAULT 'ativo',
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS profissional_pacientes (
    profissional_id INT UNSIGNED NOT NULL,
    paciente_id INT UNSIGNED NOT NULL,
    consentimento_em DATETIME NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (profissional_id, paciente_id),
    CONSTRAINT fk_pp_profissional FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE CASCADE,
    CONSTRAINT fk_pp_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS agendas_profissionais (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    profissional_id INT UNSIGNED NOT NULL,
    dia_semana TINYINT UNSIGNED NOT NULL,
    inicio TIME NOT NULL,
    fim TIME NOT NULL,
    duracao_minutos SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    pausa_inicio TIME NULL,
    pausa_fim TIME NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_agenda_profissional (profissional_id,dia_semana,inicio,fim),
    CONSTRAINT fk_agenda_profissional FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS consultas_profissionais (
    id VARCHAR(60) PRIMARY KEY,
    profissional_id INT UNSIGNED NOT NULL,
    paciente_id INT UNSIGNED NOT NULL,
    data_consulta DATE NOT NULL,
    horario TIME NOT NULL,
    duracao_minutos SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    assunto TEXT NULL,
    valor DECIMAL(10,2) NULL,
    forma_pagamento VARCHAR(40) NULL,
    tipo_cartao VARCHAR(20) NULL,
    parcelas TINYINT UNSIGNED NULL,
    recibo_valor DECIMAL(10,2) NULL,
    pagamento_status ENUM('pendente','pago','dispensado') NOT NULL DEFAULT 'pendente',
    pago_em DATETIME NULL,
    status ENUM('solicitada','agendada','confirmada','atendida','cancelada','faltou') NOT NULL DEFAULT 'solicitada',
    cancelamento_motivo VARCHAR(255) NULL,
    confirmada_em DATETIME NULL,
    atendida_em DATETIME NULL,
    manage_token_hash CHAR(64) NULL,
    criada_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cp_prof_data (profissional_id,data_consulta,horario),
    INDEX idx_cp_paciente (paciente_id,data_consulta),
    CONSTRAINT fk_cp_profissional FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cp_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS lista_espera_profissionais (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    paciente_id INT UNSIGNED NOT NULL,
    profissional_id INT UNSIGNED NOT NULL,
    data_consulta DATE NOT NULL,
    status ENUM('pendente','agendado','cancelado') NOT NULL DEFAULT 'pendente',
    consulta_id VARCHAR(60) NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lista_espera_prof (paciente_id,profissional_id,data_consulta),
    INDEX idx_lista_espera_prof_fila (profissional_id,data_consulta,status,id),
    CONSTRAINT fk_lwp_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
    CONSTRAINT fk_lwp_profissional FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE CASCADE,
    CONSTRAINT fk_lwp_consulta FOREIGN KEY (consulta_id) REFERENCES consultas_profissionais(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notificacoes_profissionais (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    profissional_id INT UNSIGNED NOT NULL,
    paciente_id INT UNSIGNED NOT NULL,
    consulta_id VARCHAR(60) NOT NULL,
    tipo ENUM('confirmacao','lembrete','lista_espera_agendada') NOT NULL,
    canal ENUM('whatsapp','email') NOT NULL DEFAULT 'whatsapp',
    mensagem TEXT NOT NULL,
    agendada_para DATETIME NOT NULL,
    enviada_em DATETIME NULL,
    status ENUM('pendente','enviada','erro') NOT NULL DEFAULT 'pendente',
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notif_prof_consulta_tipo (consulta_id,tipo),
    INDEX idx_notif_prof_agendada (profissional_id,status,agendada_para),
    CONSTRAINT fk_notif_prof_profissional FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_prof_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_prof_consulta FOREIGN KEY (consulta_id) REFERENCES consultas_profissionais(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pagamentos_pacientes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    profissional_id INT UNSIGNED NOT NULL,
    paciente_id INT UNSIGNED NOT NULL,
    consulta_id VARCHAR(60) NULL,
    valor DECIMAL(10,2) NOT NULL,
    forma_pagamento VARCHAR(40) NOT NULL,
    tipo_cartao VARCHAR(20) NULL,
    parcelas TINYINT UNSIGNED NULL,
    recebido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pagamento_consulta (consulta_id),
    INDEX idx_fin_prof_data (profissional_id,recebido_em),
    INDEX idx_fin_paciente (paciente_id,recebido_em),
    CONSTRAINT fk_fin_prof FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fin_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fin_consulta FOREIGN KEY (consulta_id) REFERENCES consultas_profissionais(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS relacionamento_contatos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    profissional_id INT UNSIGNED NOT NULL,
    paciente_id INT UNSIGNED NOT NULL,
    canal ENUM('whatsapp') NOT NULL DEFAULT 'whatsapp',
    mensagem TEXT NOT NULL,
    enviado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rel_prof_paciente (profissional_id,paciente_id,enviado_em),
    CONSTRAINT fk_rel_profissional FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE CASCADE,
    CONSTRAINT fk_rel_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS prontuarios_profissionais (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    profissional_id INT UNSIGNED NOT NULL,
    paciente_id INT UNSIGNED NOT NULL,
    consulta_id VARCHAR(60) NULL,
    tipo VARCHAR(80) NOT NULL DEFAULT 'evolucao',
    conteudo MEDIUMTEXT NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_prontuario_paciente (profissional_id,paciente_id,criado_em),
    CONSTRAINT fk_pront_profissional FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pront_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tokens_profissionais (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    profissional_id INT UNSIGNED NOT NULL,
    tipo ENUM('verificar_email','redefinir_senha') NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expira_em DATETIME NOT NULL,
    consumido_em DATETIME NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token_prof_tipo (profissional_id,tipo,expira_em),
    CONSTRAINT fk_token_profissional FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tentativas_autenticacao (
    chave CHAR(64) PRIMARY KEY,
    tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    inicio_janela DATETIME NOT NULL,
    bloqueado_ate DATETIME NULL,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS planos_assinatura (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(40) NOT NULL UNIQUE,
    nome VARCHAR(100) NOT NULL,
    valor_mensal DECIMAL(10,2) NOT NULL,
    limite_pacientes INT UNSIGNED NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS assinaturas_profissionais (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    profissional_id INT UNSIGNED NOT NULL,
    plano_id SMALLINT UNSIGNED NOT NULL,
    status ENUM('pendente','ativa','inadimplente','cancelada','expirada') NOT NULL DEFAULT 'pendente',
    inicio DATE NULL,
    fim DATE NULL,
    gateway VARCHAR(40) NULL,
    referencia_externa VARCHAR(180) NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_assinatura_prof (profissional_id,status),
    CONSTRAINT fk_assinatura_prof FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE CASCADE,
    CONSTRAINT fk_assinatura_plano FOREIGN KEY (plano_id) REFERENCES planos_assinatura(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pagamentos_profissionais (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    profissional_id INT UNSIGNED NOT NULL,
    assinatura_id BIGINT UNSIGNED NULL,
    valor DECIMAL(10,2) NOT NULL,
    status ENUM('pendente','aprovado','recusado','estornado','manual') NOT NULL DEFAULT 'pendente',
    metodo VARCHAR(40) NULL,
    gateway VARCHAR(40) NULL,
    referencia_externa VARCHAR(180) NULL,
    pago_em DATETIME NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pagamento_prof (profissional_id,status),
    CONSTRAINT fk_pagamento_prof FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE CASCADE,
    CONSTRAINT fk_pagamento_assinatura FOREIGN KEY (assinatura_id) REFERENCES assinaturas_profissionais(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO planos_assinatura (codigo,nome,valor_mensal,limite_pacientes) VALUES
('essencial','Plano 50 pacientes',49.99,50),('profissional','Plano 100 pacientes',99.99,100)
ON DUPLICATE KEY UPDATE nome=VALUES(nome),valor_mensal=VALUES(valor_mensal),limite_pacientes=VALUES(limite_pacientes),ativo=1;
UPDATE planos_assinatura SET ativo=0 WHERE codigo NOT IN ('essencial','profissional');

USE conecta_saude;

INSERT IGNORE INTO ubs (id,nome,endereco,telefone,horario,usuario) VALUES ('ubsA','UBS A','Rua Principal, 100 - Centro','(87) 0000-0001','07:00 às 18:00','adminA');
INSERT IGNORE INTO ubs_especialidades (ubs_id,nome) VALUES ('ubsA','Clínico Geral');
INSERT IGNORE INTO ubs_especialidades (ubs_id,nome) VALUES ('ubsA','Dentista');
INSERT IGNORE INTO ubs_especialidades (ubs_id,nome) VALUES ('ubsA','Enfermeira');
INSERT IGNORE INTO ubs_servicos (ubs_id,nome) VALUES ('ubsA','Consulta médica');
INSERT IGNORE INTO ubs_servicos (ubs_id,nome) VALUES ('ubsA','Atendimento de enfermagem');
INSERT IGNORE INTO ubs_servicos (ubs_id,nome) VALUES ('ubsA','Atendimento odontológico');
INSERT IGNORE INTO ubs_servicos (ubs_id,nome) VALUES ('ubsA','Vacinação');
INSERT IGNORE INTO ubs_servicos (ubs_id,nome) VALUES ('ubsA','Acompanhamento de hipertensão e diabetes');
INSERT IGNORE INTO ubs_campanhas (ubs_id,nome) VALUES ('ubsA','Campanha de vacinação');
INSERT IGNORE INTO ubs_campanhas (ubs_id,nome) VALUES ('ubsA','Prevenção do câncer');
INSERT IGNORE INTO ubs_campanhas (ubs_id,nome) VALUES ('ubsA','Saúde da mulher');
INSERT IGNORE INTO ubs_documentos (ubs_id,nome) VALUES ('ubsA','Cartão SUS');
INSERT IGNORE INTO ubs_documentos (ubs_id,nome) VALUES ('ubsA','Documento com foto');
INSERT IGNORE INTO ubs_documentos (ubs_id,nome) VALUES ('ubsA','Comprovante de residência');
INSERT IGNORE INTO funcionarios (id,ubs_id,nome,cargo) VALUES ('a1','ubsA','Maria Silva','Enfermeira');
INSERT IGNORE INTO funcionarios (id,ubs_id,nome,cargo) VALUES ('a2','ubsA','João Santos','Clínico Geral');
INSERT IGNORE INTO funcionarios (id,ubs_id,nome,cargo) VALUES ('a3','ubsA','Ana Oliveira','Dentista');
INSERT IGNORE INTO ubs (id,nome,endereco,telefone,horario,usuario) VALUES ('ubsC','UBS C','Rua da Saúde, 200 - Centro','(87) 0000-0003','07:00 às 18:00','adminC');
INSERT IGNORE INTO ubs_especialidades (ubs_id,nome) VALUES ('ubsC','Clínico Geral');
INSERT IGNORE INTO ubs_especialidades (ubs_id,nome) VALUES ('ubsC','Dentista');
INSERT IGNORE INTO ubs_especialidades (ubs_id,nome) VALUES ('ubsC','Enfermeira');
INSERT IGNORE INTO ubs_servicos (ubs_id,nome) VALUES ('ubsC','Consulta médica');
INSERT IGNORE INTO ubs_servicos (ubs_id,nome) VALUES ('ubsC','Enfermagem');
INSERT IGNORE INTO ubs_servicos (ubs_id,nome) VALUES ('ubsC','Odontologia');
INSERT IGNORE INTO ubs_servicos (ubs_id,nome) VALUES ('ubsC','Vacinação');
INSERT IGNORE INTO ubs_campanhas (ubs_id,nome) VALUES ('ubsC','Vacinação contra gripe');
INSERT IGNORE INTO ubs_campanhas (ubs_id,nome) VALUES ('ubsC','Saúde da mulher');
INSERT IGNORE INTO ubs_documentos (ubs_id,nome) VALUES ('ubsC','Cartão SUS');
INSERT IGNORE INTO ubs_documentos (ubs_id,nome) VALUES ('ubsC','Documento com foto');
INSERT IGNORE INTO funcionarios (id,ubs_id,nome,cargo) VALUES ('c1','ubsC','Carlos Souza','Clínico Geral');
INSERT IGNORE INTO funcionarios (id,ubs_id,nome,cargo) VALUES ('c2','ubsC','Fernanda Lima','Enfermeira');
INSERT IGNORE INTO ubs (id,nome,endereco,telefone,horario,usuario) VALUES ('ubsD','UBS D','Avenida Saúde, 300','(87) 0000-0004','07:00 às 18:00','adminD');
INSERT IGNORE INTO ubs_especialidades (ubs_id,nome) VALUES ('ubsD','Clínico Geral');
INSERT IGNORE INTO ubs_especialidades (ubs_id,nome) VALUES ('ubsD','Dentista');
INSERT IGNORE INTO ubs_especialidades (ubs_id,nome) VALUES ('ubsD','Enfermeira');
INSERT IGNORE INTO ubs_servicos (ubs_id,nome) VALUES ('ubsD','Consultas');
INSERT IGNORE INTO ubs_servicos (ubs_id,nome) VALUES ('ubsD','Vacinação');
INSERT IGNORE INTO ubs_servicos (ubs_id,nome) VALUES ('ubsD','Enfermagem');
INSERT IGNORE INTO ubs_servicos (ubs_id,nome) VALUES ('ubsD','Odontologia');
INSERT IGNORE INTO ubs_campanhas (ubs_id,nome) VALUES ('ubsD','Campanha de vacinação');
INSERT IGNORE INTO ubs_campanhas (ubs_id,nome) VALUES ('ubsD','Saúde do idoso');
INSERT IGNORE INTO ubs_documentos (ubs_id,nome) VALUES ('ubsD','Cartão SUS');
INSERT IGNORE INTO ubs_documentos (ubs_id,nome) VALUES ('ubsD','Documento com foto');
INSERT IGNORE INTO ubs_documentos (ubs_id,nome) VALUES ('ubsD','Comprovante de residência');
INSERT IGNORE INTO funcionarios (id,ubs_id,nome,cargo) VALUES ('d1','ubsD','Paulo Costa','Enfermeiro');
INSERT IGNORE INTO funcionarios (id,ubs_id,nome,cargo) VALUES ('d2','ubsD','Juliana Alves','Dentista');
INSERT IGNORE INTO ubs (id,nome,endereco,telefone,horario,usuario) VALUES ('ubsE','UBS E','Rua da Esperança, 400','(87) 0000-0005','07:00 às 18:00','adminE');
INSERT IGNORE INTO ubs_especialidades (ubs_id,nome) VALUES ('ubsE','Clínico Geral');
INSERT IGNORE INTO ubs_especialidades (ubs_id,nome) VALUES ('ubsE','Dentista');
INSERT IGNORE INTO ubs_especialidades (ubs_id,nome) VALUES ('ubsE','Enfermeira');
INSERT IGNORE INTO ubs_servicos (ubs_id,nome) VALUES ('ubsE','Consulta médica');
INSERT IGNORE INTO ubs_servicos (ubs_id,nome) VALUES ('ubsE','Enfermagem');
INSERT IGNORE INTO ubs_servicos (ubs_id,nome) VALUES ('ubsE','Vacinação');
INSERT IGNORE INTO ubs_campanhas (ubs_id,nome) VALUES ('ubsE','Vacinação');
INSERT IGNORE INTO ubs_campanhas (ubs_id,nome) VALUES ('ubsE','Saúde da criança');
INSERT IGNORE INTO ubs_documentos (ubs_id,nome) VALUES ('ubsE','Cartão SUS');
INSERT IGNORE INTO ubs_documentos (ubs_id,nome) VALUES ('ubsE','Documento com foto');
INSERT IGNORE INTO funcionarios (id,ubs_id,nome,cargo) VALUES ('e1','ubsE','Roberta Lima','Enfermeira');
INSERT IGNORE INTO funcionarios (id,ubs_id,nome,cargo) VALUES ('e2','ubsE','Marcos Souza','Clínico Geral');
INSERT IGNORE INTO ubs (id,nome,endereco,telefone,horario,usuario) VALUES ('ubsF','UBS F','Avenida Central, 500','(87) 0000-0006','07:00 às 18:00','adminF');
INSERT IGNORE INTO ubs_especialidades (ubs_id,nome) VALUES ('ubsF','Clínico Geral');
INSERT IGNORE INTO ubs_especialidades (ubs_id,nome) VALUES ('ubsF','Dentista');
INSERT IGNORE INTO ubs_especialidades (ubs_id,nome) VALUES ('ubsF','Enfermeira');
INSERT IGNORE INTO ubs_servicos (ubs_id,nome) VALUES ('ubsF','Consulta médica');
INSERT IGNORE INTO ubs_servicos (ubs_id,nome) VALUES ('ubsF','Enfermagem');
INSERT IGNORE INTO ubs_servicos (ubs_id,nome) VALUES ('ubsF','Odontologia');
INSERT IGNORE INTO ubs_servicos (ubs_id,nome) VALUES ('ubsF','Vacinação');
INSERT IGNORE INTO ubs_campanhas (ubs_id,nome) VALUES ('ubsF','Campanha de vacinação');
INSERT IGNORE INTO ubs_campanhas (ubs_id,nome) VALUES ('ubsF','Saúde do homem');
INSERT IGNORE INTO ubs_documentos (ubs_id,nome) VALUES ('ubsF','Cartão SUS');
INSERT IGNORE INTO ubs_documentos (ubs_id,nome) VALUES ('ubsF','Documento com foto');
INSERT IGNORE INTO funcionarios (id,ubs_id,nome,cargo) VALUES ('f1','ubsF','Patrícia Souza','Enfermeira');
INSERT IGNORE INTO funcionarios (id,ubs_id,nome,cargo) VALUES ('f2','ubsF','Ricardo Lima','Dentista');
INSERT IGNORE INTO administradores (usuario,senha_hash,tipo,ubs_id) VALUES
('desenvolvedor','$2y$12$2L29DCMyfH3dWZTI9uS98OxXlOYg7d9jqun/WcAhzl87SQhpuUDj2','desenvolvedor',NULL),
('adminA','$2y$12$NYoqt32Jx7uyL21MbVl/uu6kaHEZ83eC0OKe1VMHrVNwVwAmxYp3.','ubs','ubsA'),
('adminC','$2y$12$NYoqt32Jx7uyL21MbVl/uu6kaHEZ83eC0OKe1VMHrVNwVwAmxYp3.','ubs','ubsC'),
('adminD','$2y$12$NYoqt32Jx7uyL21MbVl/uu6kaHEZ83eC0OKe1VMHrVNwVwAmxYp3.','ubs','ubsD'),
('adminE','$2y$12$NYoqt32Jx7uyL21MbVl/uu6kaHEZ83eC0OKe1VMHrVNwVwAmxYp3.','ubs','ubsE'),
('adminF','$2y$12$NYoqt32Jx7uyL21MbVl/uu6kaHEZ83eC0OKe1VMHrVNwVwAmxYp3.','ubs','ubsF');

-- Atualização Acessa+ Saúde: roteamento de suporte
ALTER TABLE suporte_mensagens ADD COLUMN IF NOT EXISTS destino_tipo VARCHAR(20) NOT NULL DEFAULT 'desenvolvedor';
ALTER TABLE suporte_mensagens ADD COLUMN IF NOT EXISTS destino_id VARCHAR(60) NULL;
ALTER TABLE suporte_mensagens ADD COLUMN IF NOT EXISTS encaminhado_desenvolvedor TINYINT(1) NOT NULL DEFAULT 0;
