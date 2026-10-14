<?php
// Acessa+ Saúde - plataforma web de agendamento e acompanhamento de saúde.
$styleAssetVersion = substr(hash_file('sha256', __DIR__ . '/style.css') ?: '1', 0, 16);
$scriptAssetVersion = substr(hash_file('sha256', __DIR__ . '/script.js') ?: '1', 0, 16);
$requestPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$portalPath = rtrim($requestPath, '/');
$portalQuery = strtolower(trim((string)($_GET['portal'] ?? '')));
$portalFromPath = $portalPath === '/ubs' ? 'ubs' : ($portalPath === '/clinica' ? 'clinica' : '');
$appPortal = in_array($portalQuery, ['ubs', 'clinica'], true) ? $portalQuery : $portalFromPath;
$appBasePath = '';
if (preg_match('~^(.*?)/(?:ubs|clinica)$~', $portalPath, $baseMatch)) {
    $appBasePath = rtrim($baseMatch[1], '/');
} else {
    $scriptPath = (string)parse_url($_SERVER['SCRIPT_NAME'] ?? '/', PHP_URL_PATH);
    $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptPath)), '/');
    if ($scriptDir !== '/api' && $scriptDir !== '.') $appBasePath = $scriptDir;
}
// Primeira visita numa hospedagem sem configuração: leva ao instalador web.
$hasDatabaseEnv = getenv('ACESSA_DB_HOST') || getenv('TIDB_HOST') || getenv('DATABASE_URL');
if (!$hasDatabaseEnv && is_file(__DIR__ . '/install.php') && !is_file(__DIR__ . '/config.local.php') && !is_file(__DIR__ . '/install.lock')) {
    header('Location: ' . $appBasePath . '/install.php', true, 302);
    exit;
}
if ($appPortal !== '' && $requestPath !== $portalPath) {
    $redirectUrl = $appBasePath . '/' . $appPortal;
    if ($appPortal === 'clinica') {
        $redirectQuery = http_build_query($_GET, '', '&', PHP_QUERY_RFC3986);
        if ($redirectQuery !== '') $redirectUrl .= '?' . $redirectQuery;
    }
    header('Location: ' . $redirectUrl, true, 302);
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="referrer" content="no-referrer">
<meta name="referrer" content="no-referrer">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Acessa+ Saúde | Agendamento e informações de saúde</title>
<link rel="stylesheet" href="style.css?v=<?= htmlspecialchars($styleAssetVersion, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body data-portal="<?= htmlspecialchars($appPortal, ENT_QUOTES, 'UTF-8') ?>" data-base-path="<?= htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8') ?>">

<div class="app-shell guest-mode" id="appShell">
    <aside class="sidebar">
        <div class="brand brand-top-logo">
            <img src="img/logo-white.png" alt="Acessa+ Saúde" onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
            <strong class="brand-fallback">Acessa+<br><span>Saúde</span></strong>
        </div>
        <nav class="side-nav">
            <button class="nav-item active" onclick="irDashboard()"><span>⌂</span> Início</button>
            <button class="nav-item" onclick="iniciarAgendamento()"><span>▣</span> Agendar consulta</button>
            <button class="nav-item" onclick="mostrarAgendamentos()"><span>▤</span> Minhas consultas</button>
            <button class="nav-item" onclick="mostrarExames()"><span>⌁</span> Resultados de exames</button>
            <button class="nav-item" onclick="mostrarAvisos()"><span>⚑</span> Campanhas e avisos</button>
            <button class="nav-item" onclick="mostrarAjuda()"><span>?</span> Ajuda</button>
        </nav>
        <div class="sidebar-note">
            <span>♡</span>
            <strong>Cuidar de você<br>também é o nosso compromisso!</strong>
        </div>
        <div class="sidebar-footer">Acessa+ Saúde<br><small>Informação e cuidado ao seu alcance</small></div>
    </aside>

    <div class="content-area">
        <header class="topbar">

            <div class="top-actions">
                <button id="notificationButton" class="icon-button" onclick="verificarNotificacoes()" title="Receber notificações">🔔<i id="notificationCount">0</i></button>
                <button id="adminTopEntry" class="btn secondary admin-entry admin-only-entry" onclick="abrirLogin()">Acesso administrativo</button>
                <div id="patientProfileMenuWrap" class="profile-menu-wrap hidden">
                    <button class="profile-button" onclick="alternarMenuPaciente()"><span class="avatar">♙</span><span><b id="nomeTopo">Olá!</b><small>Paciente</small></span><em>⌄</em></button>
                    <div id="patientMenu" class="patient-menu hidden">
                        <button onclick="irDashboard(); fecharMenuPaciente()">Minha área</button>
                        <button onclick="mostrarPerfilPaciente(); fecharMenuPaciente()">Perfil</button>
                        <button class="logout-link" onclick="sairPaciente()">Sair e voltar ao cadastro</button>
                    </div>
                </div>
            </div>
        </header>
        <section id="notificationPanel" class="notification-panel hidden" aria-live="polite" aria-label="Notificações">
            <div class="notification-panel-header"><h2>Notificações</h2><button type="button" class="close" onclick="fecharNotificacoes()" aria-label="Fechar notificações">×</button></div>
            <div id="notificationList"></div>
        </section>

        <main>
            <section class="card page-section" id="appLoadingSection" role="status" aria-live="polite" aria-busy="true">
                <div class="database-setup">
                    <span class="eyebrow">ACESSA+ SAÚDE</span>
                    <h1 id="appLoadingTitle">Carregando sua clínica</h1>
                    <p id="appLoadingMessage">Aguarde enquanto preparamos o agendamento.</p>
                </div>
            </section>
            <section class="card page-section hidden" id="portalChooserSection">
                <div class="database-setup portal-chooser">
                    <img src="img/logo-transparent.png" class="registration-hero-logo" alt="Acessa+ Saúde">
                    <span class="eyebrow">ACESSA+ SAÚDE</span>
                    <h1>Escolha como você deseja acessar</h1>
                    <p>Escolha entre o atendimento pela rede pública e o agendamento particular da clínica; cada opção tem seu próprio fluxo.</p>
                    <div class="portal-choice-grid">
                        <div class="portal-choice"><strong>UBS / rede pública</strong><span>Escolha uma UBS, informe seu Cartão SUS e agende pela rede pública.</span><div class="portal-choice-actions"><button class="btn primary" onclick="entrarPortalUBS()">Paciente SUS</button></div></div>
                        <div class="portal-choice"><strong>Profissional / clínica</strong><span>Entre na sua conta profissional ou crie seu cadastro para administrar sua clínica, agenda e link público.</span><div class="portal-choice-actions"><button class="btn primary" onclick="abrirLoginProfissional()">Entrar na clínica</button></div></div>
                    </div>
                </div>
            </section>

            <section class="card page-section hidden clinic-public-shell" id="publicClinicSection"><div class="public-clinic-hero"><div class="clinic-brandbar"><div class="platform-signature"><img src="img/logo-transparent.png" class="public-system-logo" alt="Acessa+ Saúde"><span>Uma experiência Acessa+ Saúde</span></div><span class="clinic-public-tag">AGENDAMENTO PARTICULAR</span></div><div class="clinic-hero-grid"><div class="clinic-hero-copy"><span class="eyebrow">CUIDADO PERSONALIZADO</span><h1 id="publicClinicName">Clínica</h1><p id="publicClinicPresentation">Agende sua consulta de forma simples, segura e acolhedora.</p><p id="publicClinicDetails" class="clinic-details"></p><div id="publicClinicNotice" class="public-clinic-notice"></div></div><div class="clinic-logo-card"><img src="img/logo-transparent.png" id="publicClinicLogo" class="clinic-public-logo" alt="Logo da clínica"></div></div></div><div id="publicClinicConfirmation" class="clinic-confirmation hidden"></div><div class="public-booking-grid"><div class="booking-intro"><span class="eyebrow">SEU PRÓXIMO CUIDADO</span><h2>Vamos marcar sua consulta?</h2><p>Preencha seus dados e escolha o melhor horário. A clínica já receberá as informações para organizar seu atendimento.</p><div class="booking-benefits"><span>✓ Atendimento organizado</span><span>✓ Histórico por CPF</span><span>✓ Confirmação da marcação</span></div></div><div class="registration-box public-clinic-form"><div class="form-heading"><span class="form-step">01</span><div><h2>Solicitar consulta</h2><p class="subtitle">Seus dados ficam protegidos e são usados para localizar seu histórico.</p></div></div><label>Nome completo</label><input id="publicPatientName" placeholder="Como podemos chamar você?"><label>E-mail</label><input id="publicPatientEmail" type="email" placeholder="voce@email.com"><label>CPF</label><input id="publicPatientCpf" inputmode="numeric" maxlength="14" placeholder="000.000.000-00"><small class="field-help">Usaremos o CPF para localizar e agrupar seu histórico na clínica.</small><label>Celular</label><input id="publicPatientPhone" placeholder="(00) 00000-0000"><div class="form-grid"><div><label>Data desejada</label><input id="publicPatientDate" type="date" required></div><div><label>Horário disponível</label><select id="publicPatientTime" required disabled><option value="">Escolha uma data primeiro</option></select><small id="publicSlotsHelp" class="field-help">A clínica exibirá somente horários livres.</small></div></div><label>Assunto da consulta</label><textarea id="publicPatientSubject" placeholder="Conte brevemente como podemos ajudar"></textarea><button class="btn primary full" onclick="solicitarConsultaClinica()">Enviar solicitação <span>→</span></button><button class="btn secondary full" onclick="sairPaginaClinica()">Sair</button><small class="privacy-note">Acessa+ Saúde • seus dados tratados com cuidado</small></div></div></section>

            <!-- CONFIGURAÇÃO DO BANCO -->
            <section class="card page-section hidden" id="databaseSetupSection">
                <div class="database-setup">
                    <span class="eyebrow">CARREGAMENTO DO PORTAL</span>
                    <h1>Não foi possível carregar o portal</h1>
                    <p id="databaseSetupMessage" role="alert">Não foi possível carregar os dados agora. Verifique sua conexão e tente novamente.</p>
                    <div class="actions database-actions">
                        <button id="databaseSetupRetryButton" type="button" class="btn primary" onclick="tentarRecarregarPortal()">Tentar novamente</button>
                    </div>
                    <details class="database-local-help">
                        <summary>Ajuda para instalação local com XAMPP</summary>
                        <div class="database-steps">
                            <div><b>1</b><span>Inicie o Apache e o MySQL no XAMPP.</span></div>
                            <div><b>2</b><span>Abra o instalador para criar o banco <strong>conecta_saude</strong>.</span></div>
                            <div><b>3</b><span>Volte para esta página e atualize o navegador.</span></div>
                        </div>
                        <div class="actions database-actions">
                            <a class="btn secondary" href="install.php">Instalar banco de dados</a>
                            <a class="btn secondary" href="diagnostico_xampp.php">Ver diagnóstico do XAMPP</a>
                        </div>
                        <p class="database-note">Se o MySQL usa outra porta ou senha, veja as instruções no arquivo <strong>ATUALIZACAO.txt</strong>.</p>
                    </details>
                </div>
            </section>

            <!-- DASHBOARD DO PACIENTE -->
            <section id="dashboardSection" class="page-section hidden">
                <section class="welcome-hero">
                    <div class="hero-copy">
                        <img src="img/logo-transparent.png" class="hero-logo" alt="Acessa+ Saúde">
                        <span class="eyebrow">TECNOLOGIA • CUIDADO • ACESSO</span>
                        <h1>Olá, <span id="nomeDashboard">Paciente</span>!</h1>
                        <p>Sua saúde merece atenção. Acesse seus agendamentos, acompanhe seu histórico e consulte seus resultados em um só lugar.</p><p class="current-ubs-label"><span id="modoSistemaLabel">Modo UBS / rede pública</span> • <strong id="ubsAtualDashboard">UBS de referência: Não informada</strong></p>
                        <div class="hero-pills"><span>✓ Mais acesso</span><span>+</span><span>✓ Mais cuidado</span><span>+</span><span>✓ Uma saúde melhor</span></div>
                    </div>
                    <div class="hero-art"><div class="heart-mark">♡<b>+</b></div><div>Tecnologia<br>e cuidado<br>lado a lado<br>com você!</div></div>
                </section>

                <div class="quick-grid">
                    <button class="quick-card" onclick="iniciarAgendamento()"><span class="quick-icon">▣</span><strong>Agendar consulta</strong><small>Escolha UBS, especialidade e horário.</small><b>→</b></button>
                    <button class="quick-card" onclick="mostrarAgendamentos()"><span class="quick-icon">▤</span><strong>Minhas consultas</strong><small>Veja consultas marcadas e realizadas.</small><b>→</b></button>
                    <button class="quick-card" onclick="mostrarUBSMenu()"><span class="quick-icon">♜</span><strong>UBS</strong><small>Encontre informações da sua unidade.</small><b>→</b></button>
                    <button class="quick-card" onclick="mostrarExames()"><span class="quick-icon">⌁</span><strong>Resultados de exames</strong><small>Acesse seus resultados disponibilizados.</small><b>→</b></button>
                </div>

                <div class="dashboard-grid">
                    <section class="panel upcoming-panel">
                        <div class="panel-title"><h2>Próximas consultas</h2><button onclick="mostrarAgendamentos()">Ver todas</button></div>
                        <div id="dashboardProximas"></div>
                    </section>
                    <section class="panel notice-panel">
                        <div class="panel-title"><h2>Quadro de avisos</h2><button onclick="mostrarAvisos()">Ver todos</button></div>
                        <div id="dashboardAvisos"></div>
                    </section>
                    <section class="panel campaign-panel">
                        <div class="panel-title"><h2>Campanhas em andamento</h2></div>
                        <div class="campaign-card"><div class="campaign-symbol">✚</div><strong>Informação e prevenção</strong><p>Acompanhe as campanhas e orientações da sua UBS.</p><button onclick="mostrarAvisos()">Saiba mais</button></div>
                    </section>
                </div>

                <div class="service-strip">
                    <div><span>♢</span><b>UBS</b><small>Unidades de atendimento</small></div>
                    <div><span>♙</span><b>Equipe qualificada</b><small>Profissionais e serviços</small></div>
                    <div><span>◷</span><b>Horários</b><small>Confira o funcionamento</small></div>
                    <div><span>▤</span><b>Documentos</b><small>Saiba o que levar</small></div>
                </div>
            </section>

            <!-- CADASTRO -->
            <section class="card page-section" id="cadastro">
                <div class="registration-layout">
                    <div class="registration-copy">
                        <img src="img/logo-transparent.png" class="registration-hero-logo" alt="Acessa+ Saúde">
                        <span class="eyebrow">BEM-VINDO AO ACESSA+ SAÚDE</span>
                        <h1>Seu cuidado mais simples, digital e acessível.</h1>
                        <p>Faça seu cadastro para agendar consultas e acompanhar suas consultas e resultados de exames.</p>
                        <div class="feature-list"><div>✓ Agendamento de consultas</div><div>✓ Histórico de atendimentos</div><div>✓ Resultados de exames</div><div>✓ Informações das UBS</div></div>
                    </div>
                    <div class="registration-box">
                        <h2>Identificação</h2><p class="subtitle">Informe seus dados para entrar na sua área do paciente.</p>
                        <label>Nome completo</label><input id="nomePaciente" type="text" placeholder="Digite seu nome completo">
                        <label>CPF</label><input id="cpfPaciente" type="text" inputmode="numeric" maxlength="14" placeholder="000.000.000-00"><label>Celular</label><input id="telefonePaciente" type="tel" placeholder="(00) 00000-0000"><label for="emailPaciente">E-mail (opcional)</label><input id="emailPaciente" type="email" autocomplete="email" placeholder="seuemail@exemplo.com"><small class="field-help">Para receber a confirmação das consultas.</small>
                        <label>Cartão SUS</label><input id="susPaciente" type="text" maxlength="15" placeholder="Digite o número do Cartão SUS"><label for="estadoPaciente">Estado</label><select id="estadoPaciente" disabled><option value="">Selecione o estado</option></select><label for="cidadePaciente">Cidade</label><select id="cidadePaciente" disabled><option value="">Selecione primeiro o estado</option></select><label for="ubsPaciente">UBS de referência</label><select id="ubsPaciente" disabled><option value="">Selecione primeiro a cidade</option></select><small id="ubsLocalizacaoAjuda" class="field-help">As unidades exibidas correspondem à cidade e ao estado selecionados.</small>
                        <button class="btn primary full" onclick="salvarPaciente()">Entrar na minha área →</button>

                    </div>
                </div>
            </section>

            <!-- PERFIL DO PACIENTE -->
            <section class="card page-section hidden" id="perfilSection">
                <div class="section-title"><div><span class="eyebrow">MEU PERFIL</span><h2>Informações importantes de saúde</h2><p class="subtitle">Mantenha seus dados atualizados para facilitar o atendimento.</p></div><button class="btn back" onclick="irDashboard()">← Minha área</button></div>
                <div class="profile-form-grid">
                    <div><label>Nome completo</label><input id="perfilNome" type="text"></div>
                    <div><label>Celular</label><input id="perfilTelefone" type="tel"></div><div><label for="perfilEmail">E-mail (para receber confirmações)</label><input id="perfilEmail" type="email" autocomplete="email"></div>
                    <div><label>Data de nascimento</label><input id="perfilNascimento" type="date"></div>
                    <div><label>Endereço</label><input id="perfilEndereco" type="text" placeholder="Rua, número, bairro, cidade"></div>
                </div>
                <label>Condições de saúde preexistentes</label><textarea id="perfilCondicoes" placeholder="Ex.: diabetes, hipertensão, asma. Se não houver, escreva Não tenho."></textarea>
                <label>Alergias e restrições</label><textarea id="perfilAlergias" placeholder="Medicamentos, alimentos ou outras alergias importantes"></textarea>
                <label>Medicamentos em uso</label><textarea id="perfilMedicamentos" placeholder="Informe medicamentos contínuos ou tratamentos relevantes"></textarea>
                <label>Outras informações importantes</label><textarea id="perfilInformacoes" placeholder="Outras informações que deseja comunicar à equipe de atendimento"></textarea>
                <div class="actions"><button class="btn primary" onclick="salvarPerfilPaciente()">Salvar perfil</button><button class="btn secondary" onclick="irDashboard()">Cancelar</button></div>
            </section>

            <!-- UBS -->
            <section class="card page-section hidden" id="ubsSection"><div class="section-title"><div><span class="eyebrow">MINHA UNIDADE</span><h2 id="ubsSectionTitle">Sua UBS de referência</h2><p class="subtitle">Consulte as informações da unidade escolhida no cadastro.</p></div><button class="btn back" onclick="irDashboard()">← Início</button></div><div id="ubsGrid" class="ubs-grid"></div></section>

            <section class="card page-section hidden" id="ubsDetalhes">
                <button class="btn back" onclick="voltarUBS()">← Voltar para UBS</button>
                <div class="ubs-header"><img src="img/logo-transparent.png" class="receipt-logo" alt="Acessa+ Saúde"><span class="tag">UNIDADE SELECIONADA</span><h2 id="detalheNomeUBS"></h2><p id="detalheEndereco"></p><p id="detalheTelefone"></p><p id="detalheHorario"></p></div>
                <div class="info-grid"><div class="info-box"><h3>🏥 Serviços oferecidos</h3><ul id="detalheServicos"></ul></div><div class="info-box"><h3>💉 Campanhas</h3><ul id="detalheCampanhas"></ul></div><div class="info-box"><h3>📄 Documentos necessários</h3><ul id="detalheDocumentos"></ul></div><div class="info-box"><h3>👩‍⚕️ Funcionários</h3><ul id="detalheFuncionarios"></ul></div></div>
                <button class="btn primary" onclick="irEspecialidades()">Continuar para especialidades →</button>
            </section>

            <section class="card page-section hidden" id="especialidadeSection"><button class="btn back" onclick="voltarDetalhes()">← Voltar</button><span class="eyebrow">AGENDAMENTO</span><h2>Escolha a especialidade</h2><p class="subtitle" id="especialidadeUBSText"></p><div id="especialidadesGrid" class="option-grid"></div></section>

            <section class="card page-section hidden" id="agendaSection"><button class="btn back" onclick="voltarEspecialidades()">← Voltar</button><span class="eyebrow">AGENDAMENTO POR FILA</span><h2>Fale sobre a consulta</h2><p id="agendaInfo" class="subtitle"></p><label for="consultaAssunto">Assunto ou motivo da consulta</label><textarea id="consultaAssunto" placeholder="Ex.: pré-natal, citologia, acompanhamento de hipertensão, vacinação..."></textarea><small class="field-help">Essa informação ajuda a equipe a preparar seu atendimento.</small><h2 class="agenda-date-title">Escolha a data</h2><div class="calendar-header"><button onclick="mesAnterior()">‹</button><strong id="mesAtual"></strong><button onclick="mesProximo()">›</button></div><div id="calendar" class="calendar"></div><div id="filaDisponibilidade" class="queue-box"><h3>Escolha um dia para ver a fila</h3><p>São 12 vagas por dia para cada especialidade. A posição é definida pela ordem do agendamento.</p></div></section>

            <section class="card page-section hidden" id="confirmacaoSection"><div class="success"><img src="img/logo-transparent.png" class="receipt-logo" alt="Acessa+ Saúde"><div class="success-icon">✓</div><h2>Consulta agendada!</h2><p>Sua consulta foi registrada com sucesso.</p></div><div id="comprovante" class="receipt"></div><div class="actions"><button class="btn primary" onclick="imprimirComprovante()">🖨 Imprimir comprovante</button><button class="btn secondary" onclick="mostrarAgendamentos()">Minhas consultas</button></div></section>

            <!-- CONSULTAS DO PACIENTE -->
            <section class="card page-section hidden" id="meusAgendamentos"><div class="section-title"><div><span class="eyebrow">ÁREA DO PACIENTE</span><h2>Minhas consultas</h2><p class="subtitle">Acompanhe consultas marcadas, realizadas e canceladas.</p></div><button class="btn back" onclick="irDashboard()">← Início</button></div><div class="stats-row"><div><b id="countProximas">0</b><span>Marcadas</span></div><div><b id="countRealizadas">0</b><span>Realizadas</span></div><div><b id="countCanceladas">0</b><span>Canceladas</span></div></div><div class="history-tabs"><button class="active" onclick="filtrarHistorico('todas',this)">Todas</button><button onclick="filtrarHistorico('agendado',this)">Marcadas</button><button onclick="filtrarHistorico('atendido',this)">Que eu fui</button><button onclick="filtrarHistorico('cancelado',this)">Canceladas</button></div><div id="listaAgendamentos"></div></section>

            <!-- EXAMES -->
            <section class="card page-section hidden" id="examesSection"><div class="section-title"><div><span class="eyebrow">ÁREA DO PACIENTE</span><h2>Resultados de exames</h2><p class="subtitle">Resultados disponibilizados pela unidade de atendimento.</p></div><button class="btn back" onclick="irDashboard()">← Início</button></div><div id="listaExames"></div></section>

            <!-- AVISOS -->
            <section class="card page-section hidden" id="avisosSection"><div class="section-title"><div><span class="eyebrow">INFORMAÇÃO</span><h2>Campanhas e avisos</h2><p class="subtitle">Confira campanhas e informações das unidades.</p></div><button class="btn back" onclick="irDashboard()">← Início</button></div><div id="listaAvisos"></div></section>
            <section class="card page-section hidden" id="ajudaSection"><div class="section-title"><div><span class="eyebrow">CENTRAL DE AJUDA</span><h2>Como usar o Acessa+ Saúde</h2><p class="subtitle">Orientações rápidas para pacientes e unidades.</p></div><button class="btn back" onclick="irDashboard()">← Início</button></div><div class="help-grid"><div class="info-box"><h3>Agendar consulta</h3><p>Escolha a especialidade, descreva o motivo do atendimento, selecione uma data e confirme sua posição na fila.</p></div><div class="info-box"><h3>Minha UBS</h3><p>O paciente vê somente a unidade escolhida no cadastro. Para alterar a unidade, saia e faça um novo cadastro com confirmação.</p></div><div class="info-box"><h3>Resultados</h3><p>Exames publicados pela sua UBS aparecem na área de resultados. Anexos podem ser abertos com acesso protegido.</p></div><div class="info-box"><h3>Suporte</h3><p>Envie sua dúvida pelo botão de suporte e guarde o protocolo para acompanhar a resposta.</p></div></div></section>
            <section id="professionalSection" class="card page-section hidden">
                <div class="section-title"><div><span class="eyebrow">PORTAL PROFISSIONAL</span><h2>Olá, <span id="professionalNomeTopo">profissional</span></h2><p class="subtitle">Pacientes, agenda, personalização, relacionamento e assinatura.</p></div><div class="clinic-session-actions"><button type="button" class="btn danger" onclick="sairProfissional()">Encerrar sessão</button></div></div>
                <div class="admin-tabs professional-tabs"><button onclick="abrirPortalProfissional('dashboard')">Resumo</button><button onclick="abrirPortalProfissional('pacientes')">Pacientes</button><button onclick="abrirPortalProfissional('agenda')">Agenda</button><button onclick="abrirPortalProfissional('financeiro')">Financeiro</button><button onclick="abrirPortalProfissional('relacionamento')">Pós-atendimento</button><button onclick="abrirPortalProfissional('marca')">Minha marca</button><button onclick="abrirPortalProfissional('assinatura')">Assinatura</button><button onclick="abrirPortalProfissional('suporte')">Suporte</button></div>
                <div id="profDashboard" class="professional-tab"><div class="stats-row"><div><b id="profCountPatients">0</b><span>Pacientes vinculados</span></div><div><b id="profCountAppointments">0</b><span>Consultas</span></div><div><b id="profSubscriptionStatusResumo">Pendente</b><span>Assinatura</span></div></div></div>
                <div id="profPacientes" class="professional-tab hidden">
<div class="admin-section-title"><h3>Meus pacientes</h3></div><input id="profPatientSus" type="hidden" value="">
<div class="info-box"><strong>Paciente novo sem agendamento:</strong> cadastre diretamente aqui quando ele chegar à clínica.</div>
<div class="form-grid"><div><label>Nome completo</label><input id="novoPacienteNome" placeholder="Nome do paciente"></div><div><label>Telefone</label><input id="novoPacienteTelefone" placeholder="Telefone"></div><div><label>CPF</label><input id="novoPacienteCpf" inputmode="numeric" maxlength="14" placeholder="000.000.000-00"></div><div><label>E-mail</label><input id="novoPacienteEmail" type="email" placeholder="E-mail"></div><div><label>Cartão SUS (opcional)</label><input id="novoPacienteSus" placeholder="Se possuir"></div></div>
<button class="btn primary" onclick="cadastrarPacienteNovo()">Cadastrar paciente novo</button>
<div id="profPatientsList"></div><div id="profPatientHistory" class="hidden"></div></div>
                <div id="profAgenda" class="professional-tab hidden"><div class="admin-section-title"><div><h3>Calendário da clínica</h3><p class="subtitle">Solicitações aguardam a clínica quando a confirmação manual estiver habilitada; confirme-as aqui.</p></div><button class="btn secondary" onclick="carregarAgendaProfissional()">Atualizar</button></div><div class="calendar-toolbar"><button class="btn secondary" onclick="mudarMesAgenda(-1)">← Mês anterior</button><h3 id="agendaMesTitulo"></h3><button class="btn secondary" onclick="mudarMesAgenda(1)">Próximo mês →</button></div><div class="calendar-legend"><span><i class="calendar-dot confirmed"></i> Confirmada</span><span><i class="calendar-dot attended"></i> Atendida</span><span><i class="calendar-dot canceled"></i> Cancelada</span></div><div id="professionalCalendar" class="professional-calendar"></div><div id="agendaDiaSelecionado" class="info-box"></div><div class="admin-section-title"><h3>Marcar nova consulta</h3></div><div class="form-grid"><div><label>Paciente</label><select id="profAgendaPaciente"></select></div><div><label>Data</label><input id="profAgendaData" type="date"></div><div><label>Horário</label><input id="profAgendaHorario" type="time"></div></div><label>Assunto</label><textarea id="profAgendaAssunto" placeholder="Motivo da consulta"></textarea><button class="btn primary" onclick="criarConsultaProfissional()">Confirmar e colocar no calendário</button><div id="profAppointmentsList"></div><div class="admin-section-title"><div><h3>Lista de espera</h3><p class="subtitle">Quando houver cancelamento, a vaga é oferecida pela ordem da fila; com confirmação manual, a clínica ainda precisa aprovar.</p></div><button class="btn secondary" onclick="carregarListaEsperaProfissional()">Atualizar</button></div><div id="profWaitlistList"></div></div>
                <div id="profRelacionamento" class="professional-tab hidden">
    <div class="admin-section-title"><div><h3>Relacionamento pós-atendimento</h3><p class="subtitle">Acompanhe os pacientes por CPF e abra o WhatsApp com uma mensagem padrão.</p></div><button class="btn secondary" onclick="carregarRelacionamentoProfissional()">Atualizar</button></div>
    <div class="relationship-toolbar"><div class="info-box relationship-message-editor"><strong>Mensagem padrão do pós-atendimento</strong><p>Use <code>{nome}</code> e <code>{clinica}</code> para personalizar automaticamente.</p><textarea id="relMensagemPadrao" rows="4" placeholder="Olá, {nome}! Aqui é da clínica {clinica}. Gostaríamos de saber como você está após sua consulta."></textarea><button class="btn primary" onclick="salvarMensagemPosVenda()">Salvar mensagem padrão</button><small class="field-help" id="relMensagemStatus"></small></div></div>
    <div id="relacionamentoResumo" class="stats-row"></div><div id="relationshipList"></div>
</div>
<div id="profFinanceiro" class="professional-tab hidden"><div class="admin-section-title"><div><h3>Financeiro</h3><p class="subtitle">Consulte os recebimentos e gere um relatório diário.</p></div><button class="btn secondary" onclick="carregarFinanceiroProfissional()">Pesquisar</button></div>
<div class="form-grid"><div><label>De</label><input id="finInicio" type="date"></div><div><label>Até</label><input id="finFim" type="date"></div><div><label>Forma de pagamento</label><select id="finForma"><option value="">Todas</option><option value="pix">PIX</option><option value="dinheiro">Dinheiro</option><option value="cartao">Cartão</option><option value="boleto">Boleto</option></select></div></div>
<div class="daily-report-box"><label>Relatório diário</label><div class="daily-report-actions"><input id="finRelatorioData" type="date"><button class="btn primary" onclick="gerarRelatorioDiario()">Gerar relatório do dia</button></div><small>O relatório mostra recebimentos, total e formas de pagamento e pode ser impresso.</small></div>
<div class="info-box"><strong>Total recebido: R$ <span id="finTotal">0,00</span></strong><br><small>Lançamentos financeiros são permanentes e não podem ser alterados após o salvamento.</small></div>
<div id="financeAppointmentsList"></div><div id="financeList"></div></div><div id="profMarca" class="professional-tab hidden"><h3>Minha marca, expediente e link público</h3><div class="info-box clinic-public-link-panel"><strong>Link público para os pacientes</strong><p>Este é o endereço público da clínica. Compartilhe-o para que pacientes vejam o perfil e solicitem horários; ele não dá acesso administrativo.</p><p><a id="profPublicLink" href="#" target="_blank" rel="noopener" style="overflow-wrap:anywhere;">Carregando link...</a></p><div class="actions"><button type="button" id="copyPublicClinicLinkButton" class="btn primary" onclick="copiarLinkPublicoClinica()" disabled>Copiar link público</button><a id="profPublicLinkOpen" class="btn secondary" href="#" target="_blank" rel="noopener" aria-disabled="true">Visualizar como paciente</a></div></div><div class="form-grid"><div><label>Nome exibido</label><input id="profSetNome"></div><div><label>CNPJ</label><input id="profSetCnpj" placeholder="00.000.000/0000-00"></div><div><label>Especialidade</label><input id="profSetEspecialidade"></div><div><label>Registro profissional</label><input id="profSetRegistro"></div><div><label>Telefone</label><input id="profSetTelefone"></div><div><label>WhatsApp</label><input id="profSetWhatsapp"></div><div><label>Modalidade</label><input id="profSetModalidade"></div><div><label>Horário de funcionamento (texto)</label><input id="profSetHorario" placeholder="Segunda a sexta, 08h às 18h"><small class="field-help">Texto informativo no perfil público; disponibilidade real é definida na grade abaixo.</small></div><div><label>Valor da consulta</label><input id="profSetValor" type="number" min="0" step="0.01"></div><div><label>Limite diário de atendimentos</label><input id="profSetLimiteDiario" type="number" min="1" max="1000" value="12"><small class="field-help">Ao atingir esse limite, novos pacientes poderão entrar na lista de espera.</small></div><div><label>Cor principal</label><input id="profSetCor" type="color" value="#0fa7a7"></div><div><label>Confirmação de agendamento</label><select id="profSetAutoConfirm"><option value="1">Aprovar automaticamente</option><option value="0">Confirmar manualmente</option></select></div><div><label>Prazo mínimo para cancelar (horas)</label><input id="profCancelHours" type="number" min="0" max="720" value="24"></div><div><label>Prazo mínimo para remarcar (horas)</label><input id="profRescheduleHours" type="number" min="0" max="720" value="24"></div></div><section class="schedule-config"><h3>Expediente e pausas semanais</h3><p class="subtitle">Escolha cada dia, ative o expediente e informe início, fim, duração da consulta e pausa opcional. Os horários salvos são os horários exibidos no link público. Configure os dias na grade e clique em “Salvar expediente e pausas semanais” para aplicar.</p><div id="professionalScheduleStatus" class="schedule-summary" aria-live="polite">Carregando expediente semanal…</div><div id="professionalScheduleEditor"></div><button type="button" class="btn secondary" id="saveProfessionalScheduleButton" onclick="salvarAgendaSemanal()">Salvar expediente e pausas semanais</button></section><label>Endereço</label><input id="profSetEndereco"><label>Apresentação</label><textarea id="profSetApresentacao"></textarea><label>Aviso público para pacientes</label><textarea id="profSetAviso" placeholder="Ex.: Não atendemos convênios nesta unidade."></textarea><label>Logo da clínica</label><div class="clinic-logo-preview"><img id="profLogoPreview" src="img/logo-transparent.png" alt="Pré-visualização da logo da clínica"><span>Pré-visualização</span></div><input id="profLogoFile" type="file" accept="image/png,image/jpeg,image/webp" onchange="previewProfessionalLogo(this)"><small class="field-help">PNG, JPG ou WEBP, até 5 MB.</small><button id="saveProfessionalProfileButton" type="button" class="btn primary" onclick="salvarPerfilProfissional()">Salvar minha marca</button></div>
                <div id="profSuporte" class="professional-tab hidden"><div class="admin-section-title"><div><h3>Suporte</h3><p class="subtitle">Os chamados chegam primeiro à clínica. Responda ou resolva; encaminhe ao desenvolvedor somente se identificar um problema técnico.</p></div><button class="btn secondary" onclick="carregarSuporteClinica()">Atualizar</button></div><div id="listaSuporteClinica"></div></div>
<div id="profAssinatura" class="professional-tab hidden"><h3>Planos e assinatura</h3><p class="subtitle">Área demonstrativa: escolha um plano para simular a liberação do acesso. Nenhuma cobrança real será criada.</p><div id="profSubscriptionStatus" class="info-box">Consultando assinatura...</div><div id="profPlansList" class="help-grid"></div><button class="btn secondary" onclick="cancelarAssinaturaProfissional()">Cancelar assinatura demonstrativa</button></div>
            </section>
        </main>
    </div>
</div>

<button id="supportFab" type="button" class="support-fab" onclick="abrirSuporte()" aria-label="Abrir suporte ao paciente"><span>?</span> <span id="supportFabLabel">Suporte ao paciente</span></button><div id="paymentModal" class="modal hidden"><div class="modal-content"><button type="button" class="close" onclick="fecharPagamento()">×</button><span class="eyebrow">FINANCEIRO</span><h2>Registrar pagamento e recibo</h2><p class="subtitle">Após salvar, este lançamento financeiro não poderá ser alterado.</p><input id="pagConsultaId" type="hidden"><input id="pagPacienteId" type="hidden"><label>Valor recebido (R$)</label><input id="pagValor" type="number" min="0.01" step="0.01" placeholder="0,00"><label>Forma de pagamento</label><select id="pagForma" onchange="atualizarCamposCartao()"><option value="pix">PIX</option><option value="dinheiro">Dinheiro</option><option value="cartao">Cartão</option><option value="boleto">Boleto</option></select><div id="pagCartaoFields" class="hidden"><label>Tipo de cartão</label><select id="pagTipoCartao" onchange="atualizarCamposCartao()"><option value="credito">Crédito</option><option value="debito">Débito</option></select><label>Quantidade de parcelas</label><select id="pagParcelas"></select></div><button class="btn primary full" onclick="salvarPagamentoFormulario()">Salvar pagamento e gerar recibo</button></div></div><div id="supportModal" class="modal hidden"><div class="modal-content support-modal"><button type="button" class="close" onclick="fecharSuporte()">×</button><span class="eyebrow">ATENDIMENTO</span><h2>Como podemos ajudar?</h2><p class="subtitle">Envie sua dúvida ou dificuldade e guarde o protocolo para acompanhar a resposta.</p><label>Nome</label><input id="suporteNome" placeholder="Seu nome"><label>Celular</label><input id="suporteTelefone" placeholder="(00) 00000-00000"><label>Mensagem</label><textarea id="suporteMensagem" placeholder="Descreva o que aconteceu"></textarea><button type="button" class="btn primary full" onclick="enviarSuporte()">Enviar solicitação</button><button type="button" class="btn secondary full" onclick="carregarMeuSuporte()">Ver meus protocolos</button><div id="supportHistory" class="support-history"></div></div></div>

<!-- LOGIN ADMIN -->
<div id="loginModal" class="modal hidden"><div class="modal-content"><button type="button" class="close" onclick="fecharLogin()">×</button><span class="eyebrow">ACESSO RESTRITO</span><h2>Área administrativa</h2><p class="subtitle">Entre com seu usuário e senha.</p><label>Usuário</label><input id="loginUsuario" type="text" placeholder="Usuário"><label>Senha</label><input id="loginSenha" type="password" placeholder="Senha"><button class="btn primary full" onclick="realizarLogin()">Entrar</button></div></div>

<div id="professionalAuthModal" class="modal hidden clinic-login-modal"><div class="modal-content clinic-auth-card">
    <section class="clinic-auth-story">
        <div class="clinic-auth-brand"><img src="img/logo-white.png" alt="Acessa+ Saúde"><span>PORTAL DA CLÍNICA</span></div>
        <div class="clinic-auth-story-copy"><span class="clinic-auth-kicker">CUIDADO COM MAIS CONEXÃO</span><h2>Seu consultório, organizado em um só lugar.</h2><p>Acesse sua agenda, acompanhe seus pacientes e cuide da presença digital da sua clínica com praticidade.</p>
            <div class="clinic-auth-benefits"><span><b>✓</b> Agenda e solicitações</span><span><b>✓</b> Pacientes e relacionamento</span><span><b>✓</b> Personalização do seu perfil</span></div>
        </div>

    </section>
    <section class="clinic-auth-form-panel">
        <span class="eyebrow">ÁREA EXCLUSIVA DA CLÍNICA</span><h2>Entre na sua conta</h2><p class="subtitle">Acesse com seu e-mail profissional e senha.</p>
        <div class="auth-switch"><button id="profAuthLoginTab" class="active" onclick="alternarAuthProfissional('login')">Entrar</button><button id="profAuthSignupTab" onclick="alternarAuthProfissional('signup')">Criar cadastro</button></div>
        <label>E-mail profissional</label><input id="professionalEmail" type="email" placeholder="profissional@email.com" autocomplete="username">
        <label>Senha</label><input id="professionalSenha" type="password" minlength="8" placeholder="Sua senha" autocomplete="current-password">
        <div id="professionalSignupFields" class="hidden"><label>Nome profissional ou clínica</label><input id="professionalNome" placeholder="Nome completo ou clínica" autocomplete="organization"><label>Especialidade</label><input id="professionalEspecialidade" placeholder="Ex.: Psicologia, Enfermagem"><small class="field-help">Seu cadastro criará um link público individual para os pacientes da clínica agendarem.</small></div>
        <button id="professionalLoginButton" class="btn primary full clinic-auth-submit" onclick="loginProfissional()">Entrar na clínica <span>→</span></button><button id="professionalSignupButton" class="btn primary full clinic-auth-submit hidden" onclick="cadastrarProfissional()">Criar conta profissional <span>→</span></button>
        <div id="professionalRecoveryActions" class="auth-recovery"><button type="button" class="btn secondary" onclick="solicitarRecuperacaoSenha()">Esqueci minha senha</button></div><small id="professionalRecoveryMessage" class="field-help" aria-live="polite"></small>
    </section>
</div></div>
<div id="professionalResetModal" class="modal hidden"><div class="modal-content"><button type="button" class="close" onclick="fecharRedefinicaoSenha()">×</button><span class="eyebrow">SEGURANÇA DA CONTA</span><h2>Definir nova senha</h2><p class="subtitle">Use uma senha com pelo menos 8 caracteres. O link recebido por e-mail é de uso único e expira em 1 hora.</p><input id="professionalResetToken" type="hidden"><label>Nova senha</label><input id="professionalNewPassword" type="password" minlength="8" autocomplete="new-password"><label>Confirme a nova senha</label><input id="professionalConfirmPassword" type="password" minlength="8" autocomplete="new-password"><button type="button" class="btn primary full" onclick="confirmarRedefinicaoSenha()">Redefinir senha</button></div></div>
<!-- PAINEL ADMIN -->
<div id="adminModal" class="modal hidden"><div class="modal-content admin-modal"><div class="admin-header"><div><span class="tag">PAINEL ADMINISTRATIVO</span><h2 id="adminTitulo">Administração</h2></div><div class="admin-header-actions"><button id="adminChangePasswordButton" type="button" class="btn secondary hidden" onclick="abrirTrocaSenhaAdmin()">Trocar senha</button><button class="btn danger" onclick="logoutAdmin()">Sair</button></div></div><div class="admin-tabs"><button id="adminDashboardTab" class="hidden" onclick="abrirAbaAdmin('secretaria')">Visão geral da Secretaria</button><button id="adminSecretariaAccountsTab" class="hidden" onclick="abrirAbaAdmin('secretaria-accounts')">Administrador da Secretaria</button><button onclick="abrirAbaAdmin('dados')">Informações</button><button onclick="abrirAbaAdmin('funcionarios')">Funcionários</button><button onclick="abrirAbaAdmin('consultas')">Consultas</button><button onclick="abrirAbaAdmin('waitlist')">Lista de espera <span id="waitlistNoticeBadge" class="tab-badge hidden" aria-label="avisos pendentes"></span></button><button onclick="abrirAbaAdmin('exames')">Resultados de exames</button><button id="adminConfigTab" class="hidden" onclick="abrirAbaAdmin('config')">Modo e personalização</button><button id="adminIntegracoesTab" class="hidden" onclick="abrirAbaAdmin('integracoes')">Integrações (e-mail e WhatsApp)</button><button id="adminSuporteTab" class="hidden" onclick="abrirAbaAdmin('suporte')">Mensagens de suporte</button><button id="adminAuditoriaTab" class="hidden" onclick="abrirAbaAdmin('auditoria')">Auditoria</button></div><div id="seletorDesenvolvedor" class="admin-ubs-picker hidden"><label for="adminUBSSelect">UBS selecionada</label><div class="admin-ubs-picker-row"><select id="adminUBSSelect" onchange="trocarUBSAdmin()"></select><button type="button" class="btn secondary admin-new-ubs" onclick="abrirNovaUBSForm()">+ Nova UBS</button></div></div><div id="formNovaUBS" class="employee-form hidden"><h3>Nova UBS</h3><div class="form-grid"><div><label>Nome</label><input id="novaUBSNome"></div><div><label>Identificador</label><input id="novaUBSId" placeholder="Ex.: ubsG"></div><div><label>Endereço</label><input id="novaUBSEndereco"></div><div id="novaUBSCidadeField"><label>Cidade</label><input id="novaUBSCidade" maxlength="120" required></div><div id="novaUBSEstadoField"><label>Estado (UF)</label><input id="novaUBSEstado" maxlength="2" pattern="[A-Za-z]{2}" placeholder="PE" required></div><div><label>Telefone</label><input id="novaUBSTelefone"></div><div><label>Horário</label><input id="novaUBSHorario" value="07:00 às 18:00"></div><div><label>Usuário de acesso</label><input id="novaUBSUsuario"></div><div><label>Senha inicial</label><input id="novaUBSSenha" type="password"></div></div><p id="novaUBSSecretariaLocationHelp" class="field-help hidden" aria-live="polite"></p><label>Especialidades</label><textarea id="novaUBSEspecialidades">Clínico Geral</textarea><div class="actions"><button class="btn primary" onclick="salvarNovaUBS()">Criar UBS</button><button class="btn secondary" onclick="fecharNovaUBSForm()">Cancelar</button></div></div>
<div id="adminSecretariaDashboard" class="admin-tab hidden"><div class="admin-section-title"><div><span class="eyebrow">GESTÃO MUNICIPAL</span><h3>Visão geral das UBS</h3><p id="secDashboardSubtitle" class="subtitle">Indicadores agregados de todas as unidades ativas.</p></div><button type="button" class="btn secondary" onclick="carregarPainelSecretaria()">Atualizar</button></div><div class="stats-row secretaria-stats"><div><b id="secConsultasHoje">0</b><span>Consultas agendadas hoje</span></div><div><b id="secUnidadesAtivas">0</b><span>UBS ativas</span></div><div><b id="secIndicadoresAtivos">0</b><span>Metas/indicadores ativos</span></div></div><div class="secretaria-dashboard-grid"><section class="info-box"><h3>Consultas de hoje por UBS</h3><div id="secAppointmentsChart" class="secretaria-chart"></div></section><section class="info-box"><h3>Metas de saúde</h3><div id="secHealthSummary" class="secretaria-health-grid"></div></section></div><div class="admin-section-title"><div><h3>Campanhas, vacinas e citologia</h3><p class="subtitle">Cadastre a meta e atualize a quantidade realizada em cada UBS.</p></div><button type="button" class="btn primary" onclick="abrirFormIndicadorSecretaria()">+ Nova meta</button></div><div id="secIndicatorForm" class="employee-form hidden"><h3>Indicador de saúde</h3><input id="secIndicatorId" type="hidden"><div class="form-grid"><div><label>Unidade</label><select id="secIndicatorUBS"></select></div><div><label>Tipo</label><select id="secIndicatorType"><option value="campanha">Campanha</option><option value="vacina">Vacinação</option><option value="citologia">Exame citológico</option></select></div><div><label>Nome / descrição</label><input id="secIndicatorName" maxlength="180" placeholder="Ex.: Campanha contra influenza"></div><div><label>Meta</label><input id="secIndicatorGoal" type="number" min="1" step="1"></div><div><label>Realizado</label><input id="secIndicatorDone" type="number" min="0" step="1" value="0"></div><div><label>Início do período (opcional)</label><input id="secIndicatorStart" type="date"></div><div><label>Fim do período (opcional)</label><input id="secIndicatorEnd" type="date"></div></div><div class="actions"><button type="button" class="btn primary" onclick="salvarIndicadorSecretaria()">Salvar meta</button><button type="button" class="btn secondary" onclick="cancelarFormIndicadorSecretaria()">Cancelar</button></div></div><div id="secIndicatorsList"></div></div>
<div id="adminSecretariaAccounts" class="admin-tab hidden"><h3>Criar conta da Secretaria Municipal de Saúde</h3><p class="subtitle">Crie uma conta por município (ex.: Secretaria de Saúde de Serra/ES). A Secretaria só enxerga e administra as UBS da sua cidade, e toda UBS que ela criar já nasce nessa cidade/UF.</p><div class="form-grid"><div><label>Usuário</label><input id="secAdminUsuario" autocomplete="off" minlength="3" maxlength="80" required></div><div><label>Senha inicial (mínimo 12 caracteres)</label><input id="secAdminSenha" type="password" autocomplete="new-password" minlength="12" maxlength="200" required></div><div><label>Estado (UF)</label><select id="secAdminEstado" onchange="carregarMunicipiosSecretaria()" required><option value="">Selecione o estado</option><option value="AC">Acre</option><option value="AL">Alagoas</option><option value="AP">Amapá</option><option value="AM">Amazonas</option><option value="BA">Bahia</option><option value="CE">Ceará</option><option value="DF">Distrito Federal</option><option value="ES">Espírito Santo</option><option value="GO">Goiás</option><option value="MA">Maranhão</option><option value="MT">Mato Grosso</option><option value="MS">Mato Grosso do Sul</option><option value="MG">Minas Gerais</option><option value="PA">Pará</option><option value="PB">Paraíba</option><option value="PR">Paraná</option><option value="PE">Pernambuco</option><option value="PI">Piauí</option><option value="RJ">Rio de Janeiro</option><option value="RN">Rio Grande do Norte</option><option value="RS">Rio Grande do Sul</option><option value="RO">Rondônia</option><option value="RR">Roraima</option><option value="SC">Santa Catarina</option><option value="SP">São Paulo</option><option value="SE">Sergipe</option><option value="TO">Tocantins</option></select></div><div><label for="secAdminCidade">Cidade</label><input id="secAdminCidade" list="secAdminCidadeOptions" autocomplete="off" maxlength="120" placeholder="Selecione ou digite a cidade" required><datalist id="secAdminCidadeOptions"></datalist><small class="field-help">Ao escolher a UF, as cidades são sugeridas pelo IBGE.</small></div></div><button type="button" class="btn primary" onclick="criarContaSecretaria()">Criar administrador</button><h3 style="margin-top:28px">Contas da Secretaria</h3><div id="listaContasSecretaria"></div></div>
<div id="adminDados" class="admin-tab"><h3>Informações da UBS</h3><div class="form-grid"><div><label>Nome da UBS</label><input id="editNomeUBS"></div><div><label>Endereço</label><input id="editEndereco"></div><div><label>Cidade</label><input id="editCidade" maxlength="120" required></div><div><label>Estado (UF)</label><input id="editEstado" maxlength="2" pattern="[A-Za-z]{2}" placeholder="PE" required></div><div><label>Telefone</label><input id="editTelefone"></div><div><label>Horário de funcionamento</label><input id="editHorario"><label>Limite diário por especialidade</label><input id="editLimiteDiario" type="number" min="1" max="1000" value="12"><small class="field-help">Quando atingir esse limite, o paciente poderá optar pela lista de espera.</small></div></div><label>Especialidades</label><textarea id="editEspecialidades" placeholder="Uma especialidade por linha"></textarea><label>Serviços oferecidos</label><textarea id="editServicos" placeholder="Um serviço por linha"></textarea><div class="campaign-events-editor"><label for="editCampanhas">Campanhas e eventos</label><textarea id="editCampanhas" placeholder="Um item por linha. Ex.: campanha de vacinação ou mutirão"></textarea><div class="campaign-events-actions"><button type="button" id="saveCampaignsEventsButton" class="btn secondary" onclick="salvarCampanhasEventos()">Salvar campanhas e eventos</button><small id="campaignsEventsStatus" aria-live="polite">Esta lista pode ser salva sem alterar os demais dados da UBS.</small></div></div><label>Documentos necessários</label><textarea id="editDocumentos" placeholder="Um documento por linha"></textarea><h3>Login da UBS</h3><div class="form-grid"><div><label>Usuário</label><input id="editUsuario"></div><div><label>Senha</label><input id="editSenha"></div></div><div class="actions"><button type="button" class="btn primary" onclick="salvarInformacoesUBS()">Salvar alterações</button><button id="adminArchiveButton" type="button" class="btn danger hidden" onclick="alternarUBSAtiva()">Desativar e arquivar UBS</button></div></div>
<div id="adminFuncionarios" class="admin-tab hidden"><div class="admin-section-title"><h3>Funcionários</h3><button id="adminAddEmployeeButton" class="btn primary" onclick="abrirFuncionarioForm()">+ Adicionar</button></div><p id="employeesReadOnlyNotice" class="info-box employee-readonly-note hidden">Acesso somente para consulta. A Secretaria de Saúde e o desenvolvedor administram os funcionários.</p><div id="listaFuncionariosAdmin"></div><div id="formFuncionario" class="employee-form hidden"><h3 id="tituloFuncionarioForm">Novo funcionário</h3><input type="hidden" id="funcionarioId"><label>Nome</label><input id="funcionarioNome" placeholder="Nome completo"><label>Cargo / função</label><input id="funcionarioCargo" placeholder="Ex.: Enfermeira"><div class="actions"><button class="btn primary" onclick="salvarFuncionario()">Salvar</button><button class="btn secondary" onclick="fecharFuncionarioForm()">Cancelar</button></div></div></div>
<div id="adminWaitlist" class="admin-tab hidden"><div class="admin-section-title"><div><h3>Lista de espera</h3><p class="subtitle">Pacientes que aceitaram aguardar uma vaga. Quando uma consulta é cancelada (pelo paciente ou pela UBS), o primeiro da fila recebe a vaga automaticamente e aparece abaixo para você avisá-lo.</p></div><button class="btn secondary" onclick="carregarListaEsperaUBS()">Atualizar</button></div><section id="avisosListaEsperaBox" class="waitlist-notices hidden" aria-live="polite"><h4>Vagas repassadas — avise o paciente</h4><p class="subtitle">Estes pacientes saíram da lista de espera porque alguém cancelou. Envie o aviso pelo WhatsApp para confirmar que eles sabem da consulta.</p><div id="avisosListaEspera"></div></section><h4 class="waitlist-pending-title">Aguardando vaga</h4><div id="listaEsperaUBS"></div></div><div id="adminConsultas" class="admin-tab hidden"><h3>Consultas agendadas</h3><div class="filters"><input type="date" id="filtroData"><select id="filtroEspecialidade"><option value="">Todas as especialidades</option></select><button class="btn primary" onclick="renderConsultasAdmin()">Filtrar</button></div><div id="listaConsultasAdmin"></div></div>
<div id="adminIntegracoes" class="admin-tab hidden">
<div class="admin-section-title"><div><h3>Integrações de envio</h3><p class="subtitle">Configure como o sistema envia mensagens <strong>para o paciente</strong>: confirmação de consulta, vaga liberada na lista de espera, confirmação de e-mail e recuperação de senha da clínica.</p></div></div>
<div class="integration-status"><span id="integracaoEmailStatus" class="integration-pill">E-mail: desativado</span><span id="integracaoWhatsStatus" class="integration-pill">WhatsApp: manual</span></div>
<section class="integration-card"><h4>E-mail</h4>
<div class="form-grid"><div><label for="intEmailProvedor">Envio de e-mail</label><select id="intEmailProvedor" onchange="atualizarCamposIntegracao()"><option value="">Desativado</option><option value="smtp">SMTP (e-mail da hospedagem, Gmail, Outlook…)</option><option value="resend">Resend (API)</option></select></div><div><label for="intAppUrl">Endereço do site</label><input id="intAppUrl" type="url" placeholder="https://seudominio.com.br"><small id="intAppUrlAjuda" class="field-help">Usado nos links dos e-mails. Em branco, usa o endereço atual.</small></div></div>
<div id="intSmtpCampos" class="form-grid hidden"><div><label for="intSmtpHost">Servidor SMTP</label><input id="intSmtpHost" placeholder="smtp.hostinger.com"></div><div><label for="intSmtpPorta">Porta</label><input id="intSmtpPorta" inputmode="numeric" placeholder="465"></div><div><label for="intSmtpSeguranca">Segurança</label><select id="intSmtpSeguranca"><option value="ssl">SSL (porta 465)</option><option value="tls">TLS/STARTTLS (porta 587)</option><option value="nenhuma">Nenhuma</option></select></div><div><label for="intSmtpUsuario">Usuário (e-mail completo)</label><input id="intSmtpUsuario" autocomplete="off" placeholder="naoresponda@seudominio.com.br"></div><div><label for="intSmtpSenha">Senha do e-mail</label><input id="intSmtpSenha" type="password" autocomplete="new-password"></div></div>
<div id="intResendCampos" class="form-grid hidden"><div><label for="intResendKey">Chave da API do Resend</label><input id="intResendKey" type="password" autocomplete="new-password"></div></div>
<div id="intRemetenteCampos" class="form-grid hidden"><div><label for="intEmailRemetente">E-mail do remetente</label><input id="intEmailRemetente" type="email" placeholder="naoresponda@seudominio.com.br"></div><div><label for="intEmailNome">Nome do remetente</label><input id="intEmailNome" placeholder="Acessa+ Saúde"></div></div>
<p class="field-help"><strong>Hostinger:</strong> crie um e-mail em hPanel → E-mails (ex.: naoresponda@seudominio) e use servidor <code>smtp.hostinger.com</code>, porta <code>465</code>, SSL, com o e-mail e a senha dessa caixa. O remetente deve ser o mesmo e-mail.</p>
<div class="integration-test"><input id="intTesteEmail" type="email" placeholder="E-mail para teste"><button type="button" class="btn secondary" onclick="testarIntegracao('email')">Enviar e-mail de teste</button></div>
</section>
<section class="integration-card"><h4>WhatsApp</h4>
<label for="intWhatsProvedor">Como enviar pelo WhatsApp</label><select id="intWhatsProvedor" onchange="atualizarCamposIntegracao()"><option value="manual">Manual — a UBS envia pelo próprio WhatsApp (o botão abre a conversa com a mensagem pronta)</option><option value="meta">Automático — API oficial do WhatsApp (Meta)</option></select>
<div id="intMetaCampos" class="hidden"><div class="form-grid"><div><label for="intMetaToken">Token de acesso</label><input id="intMetaToken" type="password" autocomplete="new-password"></div><div><label for="intMetaPhoneId">ID do número (Phone number ID)</label><input id="intMetaPhoneId" inputmode="numeric"></div><div><label for="intMetaTemplate">Nome do modelo aprovado</label><input id="intMetaTemplate" placeholder="aviso_paciente"></div><div><label for="intMetaIdioma">Idioma do modelo</label><input id="intMetaIdioma" placeholder="pt_BR"></div></div>
<p class="field-help">No Meta Business, crie um modelo de mensagem da categoria <strong>Utilidade</strong> com uma única variável no corpo, por exemplo: <code>Acessa+ Saúde: {{1}}</code>. O sistema coloca o texto do aviso nessa variável. A Meta cobra por conversa iniciada.</p>
<div class="integration-test"><input id="intTesteWhats" type="tel" placeholder="Celular para teste (com DDD)"><button type="button" class="btn secondary" onclick="testarIntegracao('whatsapp')">Enviar WhatsApp de teste</button></div></div>
</section>
<div class="actions"><button type="button" class="btn primary" onclick="salvarIntegracoes()">Salvar integrações</button></div>
<p class="field-help">Senhas e tokens ficam guardados no banco e nunca são exibidos novamente; deixe o campo em branco para manter o valor salvo.</p>
</div>
<div id="adminConfig" class="admin-tab hidden"><h3>Modo de operação e personalização</h3><p class="subtitle">Use UBS/rede pública ou profissional/clínica. O modo profissional reutiliza a agenda e permite personalizar a apresentação.</p><div class="form-grid"><div><label>Modo</label><select id="appModo"><option value="ubs">UBS / rede pública</option><option value="profissional">Profissional / clínica</option></select></div><div><label>Nome exibido</label><input id="appNomeExibicao" placeholder="Nome da UBS, profissional ou clínica"></div><div><label>Especialidade</label><input id="appEspecialidade"></div><div><label>Registro profissional</label><input id="appRegistro"></div><div><label>Telefone</label><input id="appTelefone"></div><div><label>WhatsApp</label><input id="appWhatsapp"></div><div><label>Endereço</label><input id="appEndereco"></div><div><label>Modalidade</label><input id="appModalidade" placeholder="Presencial, online ou domiciliar"></div><div><label>Valor da consulta</label><input id="appValor" type="number" min="0" step="0.01"></div><div><label>Cor principal</label><input id="appCorPrimaria" type="color" value="#0fa7a7"></div><div><label>Cor secundária</label><input id="appCorSecundaria" type="color" value="#075b5d"></div></div><label>Apresentação</label><textarea id="appApresentacao" placeholder="Texto público sobre o profissional ou serviço"></textarea><div class="actions"><button class="btn primary" onclick="salvarConfiguracaoApp()">Salvar configuração</button></div><div class="info-box"><strong>Importante:</strong> esta configuração personaliza o modo e a identidade. Para uso profissional real, ainda é necessário configurar consentimento, prontuário conforme a profissão e meios de pagamento.</div><hr><div class="admin-section-title"><h3>Pagamentos profissionais</h3><button class="btn secondary" onclick="carregarPagamentosProfissionais()">Atualizar pagamentos</button></div><div id="listaPagamentosProfissionais"></div></div>
<div id="adminSuporte" class="admin-tab hidden"><div class="admin-section-title"><div><h3>Mensagens de suporte</h3><p class="subtitle">Demandas enviadas pelos pacientes para atendimento do desenvolvedor.</p></div><div><select id="filtroSuporte" onchange="carregarSuporteAdmin()"><option value="">Todas</option><option value="aberto">Abertas</option><option value="em_atendimento">Em atendimento</option><option value="resolvido">Resolvidas</option></select><button class="btn secondary" onclick="carregarSuporteAdmin()">Atualizar</button></div></div><div id="listaSuporteAdmin"></div></div>
<div id="adminAuditoria" class="admin-tab hidden"><div class="admin-section-title"><div><h3>Auditoria do sistema</h3><p class="subtitle">Registro das ações administrativas.</p></div><button class="btn secondary" onclick="carregarAuditoria()">Atualizar</button></div><div id="listaAuditoria"></div></div>
<div id="adminExames" class="admin-tab hidden"><div class="admin-section-title"><div><h3>Resultados de exames</h3><p class="subtitle">Cadastre o resultado para o paciente consultar na área dele.</p></div><button class="btn primary" onclick="abrirExameForm()">+ Novo resultado</button></div><div id="formExame" class="employee-form hidden"><h3>Novo resultado</h3><label>Cartão SUS do paciente</label><input id="exameSus" placeholder="Número do Cartão SUS"><label>Nome do exame</label><input id="exameNome" placeholder="Ex.: Hemograma completo"><label>Data do exame</label><input id="exameData" type="date"><label>Resultado</label><textarea id="exameResultado" placeholder="Digite o resultado ou observações do profissional"></textarea><label>Observações</label><textarea id="exameObservacoes" placeholder="Orientações adicionais, se houver"></textarea><label>Anexo do resultado <small>(PDF, JPG ou PNG, até 10 MB)</small></label><input id="exameArquivo" type="file" accept="application/pdf,image/jpeg,image/png"><div class="actions"><button class="btn primary" onclick="salvarResultadoExame()">Salvar resultado</button><button class="btn secondary" onclick="fecharExameForm()">Cancelar</button></div></div><div id="listaExamesAdmin"></div></div>
</div></div>
<div id="adminChangePasswordModal" class="modal hidden"><div class="modal-content"><button type="button" class="close" onclick="fecharTrocaSenhaAdmin()" aria-label="Fechar">×</button><span class="eyebrow">SEGURANÇA DA CONTA</span><h2>Trocar senha do desenvolvedor</h2><p class="subtitle">Confirme sua senha atual e escolha uma nova senha com pelo menos 12 caracteres.</p><label for="adminCurrentPassword">Senha atual</label><input id="adminCurrentPassword" type="password" autocomplete="current-password" required><label for="adminNewPassword">Nova senha</label><input id="adminNewPassword" type="password" minlength="12" maxlength="200" autocomplete="new-password" required><label for="adminConfirmPassword">Confirme a nova senha</label><input id="adminConfirmPassword" type="password" minlength="12" maxlength="200" autocomplete="new-password" required><div class="actions"><button id="adminChangePasswordSubmit" type="button" class="btn primary" onclick="alterarSenhaDesenvolvedor()">Salvar nova senha</button><button type="button" class="btn secondary" onclick="fecharTrocaSenhaAdmin()">Cancelar</button></div></div></div>
<div id="toast"></div>
<script src="script.js?v=<?= htmlspecialchars($scriptAssetVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
