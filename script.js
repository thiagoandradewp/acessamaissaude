/* ============================================================
   ACESSA+ SAÚDE
   SISTEMA COMPLETO DE AGENDAMENTO UBS
   ============================================================ */

"use strict";

function appPath(path) {
    const base = document.body?.dataset.basePath || "";
    const normalized = path.startsWith("/") ? path : `/${path}`;
    return base + normalized;
}

/* ============================================================
   CONFIGURAÇÕES
   ============================================================ */

const API_URL = appPath("/api.php");
const STORAGE_PATIENT = "acessaMaisSaude_patient_v1";
const STORAGE_LAST_UBS = "acessaMaisSaude_last_ubs_v1";
const STORAGE_LAST_LOCATION = "acessaMaisSaude_last_location_v1";
const CPF_STORAGE_KEY = 'acessaMaisSaude_cpf_v1';

const HORARIOS_MANHA = [
    "07:00","07:30","08:00","08:30","09:00",
    "09:30","10:00","10:30","11:00","11:30"
];

const HORARIOS_TARDE = [
    "13:00","13:30","14:00","14:30","15:00",
    "15:30","16:00","16:30","17:00","17:30"
];
/* ============================================================
   ESTADO DO SISTEMA
   ============================================================ */

let ubsList = [];
let appointments = [];
let occupiedSlots = [];
let databaseReady = false;

let currentUBS = null;
let currentSpecialty = null;
let currentDate = null;
let currentTime = null;
let currentSubject = "";

let calendarDate = new Date();

let adminSession = null;
let professionalSession = null;
let csrfToken = null;
let professionalPatients = [];
let clinicManagementToken = null;
let appConfig = {modo:"ubs", nomeExibicao:"Acessa+ Saúde"};
let editingEmployeeId = null;

/* ============================================================
   INICIALIZAÇÃO
   ============================================================ */

document.addEventListener("DOMContentLoaded", async function () {

    // Começa oculto. Só será liberado quando a tela inicial realmente estiver visível.
    atualizarVisibilidadeAcessoAdministrativo();

    const urlParams = new URLSearchParams(location.search);
    const verifyToken = urlParams.get("verificar_email");
    const resetToken = urlParams.get("redefinir_senha");

    const supportFab = $("supportFab");
    if (supportFab) supportFab.addEventListener("click", abrirSuporte);

    // O cadastro do paciente vale somente enquanto esta página está aberta.
    // Assim, voltar ao início ou abrir o site novamente não reaproveita dados pessoais.
    sessionStorage.removeItem(STORAGE_PATIENT);

    await carregarDados();

    if (verifyToken && databaseReady) {
        try { const v = await api("professional_verify_email", {params:{token:verifyToken}}); toast(v.message); }
        catch(e) { toast(e.message); }
        const clean = new URLSearchParams(location.search); clean.delete("verificar_email"); history.replaceState({}, "", location.pathname + (clean.size ? "?" + clean.toString() : ""));
    }
    if (resetToken) {
        $("professionalResetToken").value = resetToken;
        $("professionalResetModal").classList.remove("hidden");
        const clean = new URLSearchParams(location.search); clean.delete("redefinir_senha"); history.replaceState({}, "", location.pathname + (clean.size ? "?" + clean.toString() : ""));
    }

    const publicDate = $("publicPatientDate");
    if (publicDate) { publicDate.min = hojeISO(); publicDate.addEventListener("change", carregarHorariosDisponiveis); }

    aplicarMascaraTelefone();

    document
        .getElementById("susPaciente")
        .addEventListener("input", function () {
            this.value = this.value.replace(/\D/g, "").slice(0, 15);
        });

    renderUBS();
    preencherSelectUBSPaciente();

    const pacienteSalvo = JSON.parse(sessionStorage.getItem(STORAGE_PATIENT) || "null");
    const clinicSlug = new URLSearchParams(location.search).get("clinica");
    const portal = document.body.dataset.portal || "";
    if (databaseReady && clinicSlug) {
        definirModoUsuario(false);
        carregarClinicaPublica(clinicSlug);
    } else if (databaseReady && portal === "clinica") {
        definirModoUsuario(false);
        window.clinicLoginReturnPath = "/clinica";
        if (await restaurarSessaoProfissional()) {
            await abrirPortalProfissional("dashboard");
        } else {
            mostrarApenas("portalChooserSection");
            abrirLoginProfissional();
        }
    } else if (pacienteSalvo && databaseReady) {
        definirModoUsuario(true);
        preencherDadosPaciente(pacienteSalvo);
        atualizarDashboard();
        mostrarApenas("dashboardSection");
    } else if (databaseReady && portal === "ubs") {
        definirModoUsuario(false);
        document.body.classList.remove("clinic-login-route");
        mostrarApenas("cadastro");
    } else {
        definirModoUsuario(false);
        mostrarApenas(databaseReady ? "portalChooserSection" : "databaseSetupSection");
    }

    verificarLembretes();
    verificarNotificacoesPendentes();
    setInterval(verificarNotificacoesPendentes, 30000);

});

async function respostaJson(response, action) {
    const raw = await response.text();
    let data = null;
    try {
        data = raw ? JSON.parse(raw) : null;
    } catch (_error) {
        data = null;
    }
    if (data && typeof data === "object" && !Array.isArray(data)) return data;

    const contentType = response.headers.get("content-type") || "tipo não informado";
    const requestId = response.headers.get("x-vercel-id") || response.headers.get("x-request-id") || "";
    const reference = requestId ? ` Referência: ${requestId}.` : "";
    const message = response.status >= 500
        ? `Falha no servidor ao executar “${action}” (HTTP ${response.status}). Tente novamente e, se persistir, informe o horário.${reference}`
        : `A API respondeu em formato inesperado para “${action}” (HTTP ${response.status}; ${contentType}). Recarregue a página e tente novamente.${reference}`;
    const error = new Error(message);
    error.data = { success: false, code: "INVALID_JSON_RESPONSE", action, httpStatus: response.status, contentType, requestId };
    throw error;
}

async function obterTokenCSRF() {
    if (csrfToken) return csrfToken;
    const response = await fetch(`${API_URL}?action=csrf_token`, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
    const data = await respostaJson(response, "csrf_token");
    if (!response.ok || !data.csrf_token) throw new Error(data.message || "Não foi possível validar a sessão.");
    csrfToken = data.csrf_token;
    return csrfToken;
}

function hojeISO() { const d=new Date(); return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,"0")}-${String(d.getDate()).padStart(2,"0")}`; }

async function api(action, options = {}) {
    const params = options.params || {};
    const retriedCsrf = options.__retryCsrf === true;
    const retriedProfessionalSession = options.__retryProfessionalSession === true;
    const query = new URLSearchParams({ action, ...params });
    const config = { ...options, headers: { ...(options.headers || {}) } };
    delete config.params;
    delete config.__retryCsrf;
    delete config.__retryProfessionalSession;
    config.credentials = "same-origin";
    const method = String(config.method || "GET").toUpperCase();
    const csrfRequired = method === "POST" && action !== "asaas_webhook" && !action.startsWith("professional_");
    if (csrfRequired) config.headers["X-CSRF-Token"] = await obterTokenCSRF();
    if (config.body instanceof FormData) {
        // O navegador define automaticamente o Content-Type e o boundary.
    } else if (config.body && typeof config.body !== "string") {
        config.headers["Content-Type"] = "application/json";
        config.body = JSON.stringify(config.body);
    }
    config.headers.Accept = "application/json";
    config.cache = "no-store";
    const response = await fetch(`${API_URL}?${query.toString()}`, config);
    const data = await respostaJson(response, action);
    if (response.status === 419 && csrfRequired && !retriedCsrf) {
        csrfToken = typeof data.csrf_token === "string" && data.csrf_token ? data.csrf_token : null;
        return api(action, { ...options, __retryCsrf: true });
    }
    if (data.csrf_token) csrfToken = data.csrf_token;
    if (response.status === 419) csrfToken = null;
    if (response.status === 401 && action.startsWith("professional_") && !["professional_login", "professional_register", "professional_me", "professional_verify_email", "professional_request_password_reset", "professional_reset_password"].includes(action)) {
        if (!retriedProfessionalSession) {
            await new Promise(resolve => setTimeout(resolve, 250));
            return api(action, { ...options, __retryProfessionalSession: true });
        }
        invalidarSessaoProfissional(data.message || "A sessão da clínica expirou. Entre novamente para continuar.");
    }
    if (!response.ok || data.success === false) {
        const error = new Error(data.message || "Erro no servidor.");
        error.data = { ...data, httpStatus: response.status };
        throw error;
    }
    return data;
}

function invalidarSessaoProfissional(message) {
    professionalSession = null;
    atualizarLinkPublicoClinica("");
    if (document.body?.dataset.portal === "clinica" && typeof abrirLoginProfissional === "function") {
        abrirLoginProfissional();
        toast(message);
    }
}
async function apiWithTransientRetry(action, options = {}) {
    for (let attempt = 0; attempt < 2; attempt++) {
        try {
            return await api(action, options);
        } catch (error) {
            const status = Number(error?.data?.httpStatus || 0);
            const retryable = status === 0 || status >= 500;
            if (!retryable || attempt === 1) throw error;
            await new Promise(resolve => setTimeout(resolve, 500));
        }
    }
    throw new Error("Não foi possível carregar os dados do sistema.");
}
async function carregarDados() {

    try {

        const configData = await apiWithTransientRetry("get_app_config");
        appConfig = configData.config || appConfig;
        aplicarConfiguracaoApp();
        const data =
            await apiWithTransientRetry("get_ubs");

        ubsList =
            data.ubs || [];
        databaseReady = true;

        const paciente =
            JSON.parse(
                sessionStorage.getItem(
                    STORAGE_PATIENT
                ) || "null"
            );

        if (paciente) {

            await carregarConsultasPaciente(
                paciente.sus
            );

        }

    } catch (error) {
        databaseReady = false;
        ubsList = [];
        appointments = [];
        renderUBS();
        preencherSelectUBSPaciente();

        const setupMessage = $("databaseSetupMessage");
        if (setupMessage) {
            const networkError = error?.name === "TypeError" && !error?.data?.httpStatus;
            setupMessage.textContent = networkError
                ? "Não foi possível comunicar com o servidor. Verifique sua conexão e tente novamente."
                : (typeof error?.message === "string" && error.message.trim()
                    ? error.message
                    : "Não foi possível carregar os dados do sistema. Tente novamente.");
        }

    }

}

function tentarRecarregarPortal() {
    window.location.reload();
}

async function carregarConsultasPaciente(sus) {

    try {

        const data =
            await api(
                "get_patient_appointments",
                {
                    params: { sus }
                }
            );

        appointments =
            data.appointments || [];

    } catch (error) {

        appointments = [];

    }

}

async function carregarHorariosOcupados() {

    occupiedSlots = [];

    if (
        !currentUBS ||
        !currentSpecialty ||
        !currentDate
    ) {
        return;
    }

    try {

        const data =
            await api(
                "get_occupied_slots",
                {
                    params: {
                        ubs_id: currentUBS.id,
                        especialidade: currentSpecialty,
                        assunto: currentSubject,
                        data: currentDate
                    }
                }
            );

        occupiedSlots = data;

    } catch (error) {

        occupiedSlots = [];

    }

}

function salvarUBS() {
    return Promise.resolve();
}

function salvarConsultas() {
    return Promise.resolve();
}

/* ============================================================
   UTILIDADES
   ============================================================ */

function $(id) {
    return document.getElementById(id);
}

function escapeHTML(text) {

    if (text === null || text === undefined) {
        return "";
    }

    return String(text)
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

function normalizarCpf(value) {
    return String(value ?? "").replace(/\D/g, "");
}

function validarCpf(value) {
    const cpf = normalizarCpf(value);
    if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
    const digit = (base, weight) => {
        let sum = 0;
        for (let i = 0; i < base.length; i++) sum += Number(base[i]) * (weight - i);
        const remainder = (sum * 10) % 11;
        return remainder === 10 ? 0 : remainder;
    };
    const first = digit(cpf.slice(0, 9), 10);
    const second = digit(cpf.slice(0, 10), 11);
    return first === Number(cpf[9]) && second === Number(cpf[10]);
}

function formatarCpf(value) {
    const cpf = normalizarCpf(value);
    return cpf.length === 11 ? cpf.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, "$1.$2.$3-$4") : String(value ?? "");
}

function toast(mensagem) {

    const elemento = $("toast");

    elemento.textContent = mensagem;
    elemento.classList.add("show");

    setTimeout(function () {
        elemento.classList.remove("show");
    }, 3000);
}

function atualizarVisibilidadeAcessoAdministrativo(forceHome = false) {
    const adminEntry = $("adminTopEntry");
    if (!adminEntry) return;

    const params = new URLSearchParams(location.search);
    const temLinkClinica = !!params.get("clinica");
    const portalProfissional = $("professionalSection") && !$("professionalSection").classList.contains("hidden");
    const modalAdminAberto = $("adminModal") && !$("adminModal").classList.contains("hidden");
    const modalLoginAberto = $("loginModal") && !$("loginModal").classList.contains("hidden");
    const modalLoginProfissional = $("professionalAuthModal") && !$("professionalAuthModal").classList.contains("hidden");
    const portalChooserVisivel = $("portalChooserSection") && !$("portalChooserSection").classList.contains("hidden");
        const portal = document.body.dataset.portal || "";
    const entradaDiretaVisivel = portal === "ubs";

    // Acesso global fica disponível nas entradas dos portais, não nas áreas de trabalho.
    const mostrar = !temLinkClinica && (portalChooserVisivel || entradaDiretaVisivel) && !portalProfissional && !modalAdminAberto && !modalLoginProfissional && !modalLoginAberto;
    const deveMostrar = (forceHome || mostrar) && !temLinkClinica && (portalChooserVisivel || entradaDiretaVisivel) && !portalProfissional && !modalAdminAberto && !modalLoginProfissional && !modalLoginAberto;

    adminEntry.classList.toggle("hidden", !deveMostrar);
    adminEntry.style.display = deveMostrar ? "" : "none";
}

function mostrarApenas(id) {
    const secoes = ["cadastro","appLoadingSection","databaseSetupSection","perfilSection","dashboardSection","ubsSection","ubsDetalhes","especialidadeSection","agendaSection","confirmacaoSection","meusAgendamentos","examesSection","avisosSection","ajudaSection","professionalSection","portalChooserSection","publicClinicSection"];
    secoes.forEach(function(secao){ const elemento=$(secao); if(elemento) elemento.classList.add("hidden"); });
    const alvo=$(id); if(alvo) alvo.classList.remove("hidden");
    atualizarVisibilidadeAcessoAdministrativo();
    window.scrollTo({top:0,behavior:"smooth"});
}

/* ============================================================
   PACIENTE
   ============================================================ */

async function salvarPaciente() {

    const nome =
        $("nomePaciente").value.trim();

    const telefone =
        $("telefonePaciente").value.trim();

    const sus =
        $("susPaciente").value.trim();

    if (!nome) {

        toast("Digite seu nome completo.");
        $("nomePaciente").focus();

        return;

    }

    if (
        telefone.replace(/\D/g, "").length < 10
    ) {

        toast("Digite um celular válido.");
        $("telefonePaciente").focus();

        return;

    }

    if (sus.length < 8) {

        toast("Digite o número do Cartão SUS.");
        $("susPaciente").focus();

        return;

    }

    const ubsId = $("ubsPaciente").value;
    if (!ubsId) {
        toast("Selecione sua UBS de referência.");
        $("ubsPaciente").focus();
        return;
    }

    try {

        const data =
            await api(
                "save_patient",
                {
                    method: "POST",
                    body: {
                        nome,
                        telefone,
                        sus,
                        ubs_id: ubsId
                    }
                }
            );

        sessionStorage.setItem(
            STORAGE_PATIENT,
            JSON.stringify(data.patient)
        );
        sessionStorage.setItem(STORAGE_LAST_UBS, String(data.patient?.ubsId || ubsId));
        salvarLocalizacaoPaciente($("estadoPaciente").value, $("cidadePaciente").value);

        definirModoUsuario(true);

        await carregarConsultasPaciente(sus);
        await carregarExamesPaciente(sus);
        preencherDadosPaciente(data.patient);
        atualizarDashboard();
        mostrarApenas("dashboardSection");
        toast("Cadastro realizado. Bem-vindo ao Acessa+ Saúde!");

    } catch (error) {

        toast(error.message);

    }

}

function mostrarPerfilPaciente() {
    const paciente = JSON.parse(sessionStorage.getItem(STORAGE_PATIENT) || "null");
    if (!paciente) {
        mostrarApenas("cadastro");
        toast("Faça seu cadastro primeiro.");
        return;
    }
    preencherPerfilPaciente(paciente);
    mostrarApenas("perfilSection");
}

function preencherPerfilPaciente(paciente) {
    const campos = {
        perfilNome: paciente.nome,
        perfilTelefone: paciente.telefone,
        perfilNascimento: paciente.dataNascimento,
        perfilEndereco: paciente.endereco,
        perfilCondicoes: paciente.condicoesSaude,
        perfilAlergias: paciente.alergias,
        perfilMedicamentos: paciente.medicamentos,
        perfilInformacoes: paciente.informacoesAdicionais
    };
    Object.entries(campos).forEach(([id, value]) => { if ($(id)) $(id).value = value || ""; });
}

async function salvarPerfilPaciente() {
    const paciente = JSON.parse(sessionStorage.getItem(STORAGE_PATIENT) || "null");
    if (!paciente) return;
    const body = {
        sus: paciente.sus,
        nome: $("perfilNome").value.trim(),
        telefone: $("perfilTelefone").value.trim(),
        data_nascimento: $("perfilNascimento").value,
        endereco: $("perfilEndereco").value.trim(),
        condicoes_saude: $("perfilCondicoes").value.trim(),
        alergias: $("perfilAlergias").value.trim(),
        medicamentos: $("perfilMedicamentos").value.trim(),
        informacoes_adicionais: $("perfilInformacoes").value.trim()
    };
    if (!body.nome || body.telefone.replace(/\D/g, "").length < 10) {
        toast("Informe nome e celular válidos.");
        return;
    }
    try {
        const data = await api("update_patient_profile", {method: "POST", body});
        sessionStorage.setItem(STORAGE_PATIENT, JSON.stringify(data.patient));
        preencherDadosPaciente(data.patient);
        toast("Perfil atualizado com sucesso.");
        mostrarApenas("dashboardSection");
    } catch (error) {
        toast(error.message);
    }
}

function renderUBS(showAll = false) {
    const grid = $("ubsGrid");
    if (!grid) return;
    grid.innerHTML = "";
    const paciente = JSON.parse(sessionStorage.getItem(STORAGE_PATIENT) || "null");
    const preferredId = paciente?.ubsId || sessionStorage.getItem(STORAGE_LAST_UBS);
    const hasPreferred = preferredId && ubsList.some(ubs => String(ubs.id) === String(preferredId));
    const preferida = ubsList.find(ubs => String(ubs.id) === String(preferredId));
    const lembrada = lerLocalizacaoPaciente();
    const estado = String(preferida?.estado || lembrada.estado || "").trim().toUpperCase();
    const cidade = preferida?.cidade || lembrada.cidade || "";
    const daCidade = estado && cidade ? filtrarUBSPorLocalizacao(ubsList, estado, cidade) : [];
    const unidades = !showAll && hasPreferred
        ? ubsList.filter(ubs => String(ubs.id) === String(preferredId))
        : (daCidade.length ? daCidade : ubsList);
    unidades.forEach(ubs => {
        const card = document.createElement("div");
        card.className = "ubs-card";
        card.innerHTML = `<div class="ubs-icon">🏥</div><h3>${escapeHTML(ubs.nome)}</h3><p>📍 ${escapeHTML(ubs.endereco)}</p><p>☎ ${escapeHTML(ubs.telefone)}</p><p>🕐 ${escapeHTML(ubs.horario)}</p><br><button class="btn primary" type="button">Entrar na UBS →</button>`;
        card.addEventListener("click", () => abrirUBS(ubs.id));
        grid.appendChild(card);
    });
}

function abrirUBS(id) {

    const ubs = ubsList.find(function (item) {
        return String(item.id) === String(id);
    });

    if (!ubs) {
        toast("UBS não encontrada.");
        return;
    }

    currentUBS = ubs;
    sessionStorage.setItem(STORAGE_LAST_UBS, String(ubs.id));

    $("detalheNomeUBS").textContent = ubs.nome;
    $("detalheEndereco").textContent = "📍 " + ubs.endereco;
    $("detalheTelefone").textContent = "☎ " + ubs.telefone;
    $("detalheHorario").textContent = "🕐 " + ubs.horario;

    preencherLista("detalheServicos", ubs.servicos);
    preencherLista("detalheCampanhas", ubs.campanhas);
    preencherLista("detalheDocumentos", ubs.documentos);

    const funcionarios = ubs.funcionarios
        .map(function (funcionario) {
            return `${funcionario.nome} — ${funcionario.cargo}`;
        });

    preencherLista("detalheFuncionarios", funcionarios);

    mostrarApenas("ubsDetalhes");

    atualizarPasso(2);
}

function preencherLista(id, itens) {

    const elemento = $(id);

    elemento.innerHTML = "";

    if (!itens || itens.length === 0) {

        elemento.innerHTML = "<li>Nenhuma informação cadastrada.</li>";

        return;
    }

    itens.forEach(function (item) {

        const li = document.createElement("li");

        li.textContent = item;

        elemento.appendChild(li);

    });
}

function voltarUBS() {

    currentUBS = null;

    mostrarApenas("ubsSection");

    atualizarPasso(2);
}

/* ============================================================
   ESPECIALIDADES
   ============================================================ */

function irEspecialidades() {

    if (!currentUBS) {
        toast("Escolha uma UBS primeiro.");
        return;
    }

    renderEspecialidades();

    mostrarApenas("especialidadeSection");

    atualizarPasso(3);
}

function renderEspecialidades() {

    const grid = $("especialidadesGrid");

    grid.innerHTML = "";

    $("especialidadeUBSText").textContent =
        currentUBS.nome + " • Escolha uma especialidade";

    const icones = {
        "Clínico Geral": "🩺",
        "Dentista": "🦷",
        "Enfermeira": "👩‍⚕️"
    };

    currentUBS.especialidades.forEach(function (especialidade) {

        const div = document.createElement("div");

        div.className = "option";

        div.innerHTML = `
            <div class="option-icon">
                ${icones[especialidade] || "🏥"}
            </div>

            <h3>${escapeHTML(especialidade)}</h3>

            <p>Ver horários disponíveis</p>
        `;

        div.addEventListener("click", function () {

            selecionarEspecialidade(especialidade);

        });

        grid.appendChild(div);

    });
}

function selecionarEspecialidade(especialidade) {

    currentSpecialty = especialidade;
    currentSubject = "";
    currentDate = null;
    currentTime = null;
    if ($("consultaAssunto")) $("consultaAssunto").value = "";

    $("agendaInfo").textContent =
        currentUBS.nome +
        " • " +
        currentSpecialty;

    calendarDate = new Date();

    renderCalendario();

    mostrarApenas("agendaSection");

    atualizarPasso(4);
}

function voltarDetalhes() {

    if (currentUBS) {
        mostrarApenas("ubsDetalhes");
    }
}

function voltarEspecialidades() {

    mostrarApenas("especialidadeSection");

    atualizarPasso(3);
}

/* ============================================================
   CALENDÁRIO
   ============================================================ */

function renderCalendario() {

    const calendar = $("calendar");

    calendar.innerHTML = "";

    const ano = calendarDate.getFullYear();
    const mes = calendarDate.getMonth();

    $("mesAtual").textContent =
        new Intl.DateTimeFormat("pt-BR", {
            month: "long",
            year: "numeric"
        }).format(calendarDate);

    const diasSemana = [
        "Dom",
        "Seg",
        "Ter",
        "Qua",
        "Qui",
        "Sex",
        "Sáb"
    ];

    diasSemana.forEach(function (dia) {

        const elemento = document.createElement("div");

        elemento.className = "day-name";

        elemento.textContent = dia;

        calendar.appendChild(elemento);

    });

    const primeiroDia = new Date(
        ano,
        mes,
        1
    ).getDay();

    const quantidadeDias =
        new Date(
            ano,
            mes + 1,
            0
        ).getDate();

    for (let i = 0; i < primeiroDia; i++) {

        const vazio = document.createElement("div");

        calendar.appendChild(vazio);
    }

    for (let dia = 1; dia <= quantidadeDias; dia++) {

        const data = new Date(
            ano,
            mes,
            dia
        );

        const elemento = document.createElement("button");

        elemento.className = "day";

        elemento.textContent = dia;

        const dataISO = formatarData(data);

        if (
            dataISO < hojeISO() ||
            data.getDay() === 0 ||
            data.getDay() === 6 ||
            ehFeriado(data)
        ) {

            elemento.classList.add("disabled");

            elemento.disabled = true;

        } else {

            elemento.addEventListener(
                "click",
                function () {
                    selecionarData(dataISO);
                }
            );

        }

        if (dataISO === currentDate) {
            elemento.classList.add("selected");
        }

        calendar.appendChild(elemento);
    }
}

function selecionarData(data) {

    currentDate = data;

    renderCalendario();

    renderHorarios();

    atualizarPasso(4);
}

function mudarMesCalendario(delta) {
    const proximoMes = new Date(calendarDate.getFullYear(), calendarDate.getMonth() + delta, 1);
    const hoje = new Date();
    const primeiroMesDisponivel = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
    if (proximoMes < primeiroMesDisponivel) return;

    calendarDate = proximoMes;
    if (currentDate && currentDate.slice(0, 7) !== formatarData(calendarDate).slice(0, 7)) {
        currentDate = null;
        currentTime = null;
        void renderHorarios();
    }
    renderCalendario();
}

function mesAnterior() { mudarMesCalendario(-1); }
function mesProximo() { mudarMesCalendario(1); }

function formatarData(data) {

    const ano = data.getFullYear();

    const mes = String(
        data.getMonth() + 1
    ).padStart(2, "0");

    const dia = String(
        data.getDate()
    ).padStart(2, "0");

    return `${ano}-${mes}-${dia}`;
}

/* ============================================================
   FERIADOS
   ============================================================ */

function ehFeriado(data) {

    const dia = data.getDate();
    const mes = data.getMonth() + 1;

    const fixos = [
        "01-01",
        "04-21",
        "05-01",
        "09-07",
        "10-12",
        "11-02",
        "11-15",
        "12-25"
    ];

    const chave =
        String(mes).padStart(2, "0") +
        "-" +
        String(dia).padStart(2, "0");

    return fixos.includes(chave);
}

/* ============================================================
   HORÁRIOS
   ============================================================ */

async function renderHorarios(){const box=$("filaDisponibilidade");if(!currentDate){box.innerHTML='<h3>Escolha um dia para ver a fila</h3><p>O limite diário é definido pela UBS.</p>';return;}box.innerHTML='<p>Consultando vagas...</p>';await carregarHorariosOcupados();const ocupadas=occupiedSlots.ocupadas||0;const limite=occupiedSlots.limite||12;if(ocupadas>=limite){box.innerHTML=`<h3>Limite diário atingido</h3><p>A UBS atingiu o limite de ${limite} atendimentos para esta especialidade nesta data.</p><p>Você deseja entrar na <strong>lista de espera</strong>? Se houver cancelamento, sua vez será agendada automaticamente e você receberá uma notificação.</p><div class="actions"><button class="btn primary" onclick="entrarListaEspera()">Sim, quero entrar na lista de espera</button></div>`;return;}const fila=occupiedSlots.proximaFila||ocupadas+1;box.innerHTML=`<h3>${fila}ª posição disponível</h3><p>${ocupadas} de ${limite} vagas preenchidas.</p><button class="btn primary" onclick="agendarConsulta()">Confirmar agendamento — entrar na fila</button>`;}

async function entrarListaEspera(){const paciente=JSON.parse(sessionStorage.getItem(STORAGE_PATIENT)||'null');if(!paciente||!currentUBS||!currentSpecialty||!currentDate)return;try{const d=await api('join_waitlist',{method:'POST',body:{sus:paciente.sus,ubs_id:currentUBS.id,especialidade:currentSpecialty,data:currentDate}});toast(d.message);}catch(e){toast(e.message);}}

async function agendarConsulta() {

    const paciente =
        JSON.parse(
            sessionStorage.getItem(
                STORAGE_PATIENT
            ) || "null"
        );

    if (!paciente) {

        toast(
            "Preencha seus dados primeiro."
        );

        mostrarApenas("cadastro");

        return;

    }

    if (
        !currentUBS ||
        !currentSpecialty ||
        !currentDate
    ) {

        toast(
            "Selecione UBS, especialidade e data."
        );

        return;

    }

    currentSubject = $("consultaAssunto")?.value.trim() || "";

    let whatsappWindow = null;
    try {
        whatsappWindow = window.open("about:blank", "_blank");

        const data =
            await api(
                "book_appointment",
                {
                    method: "POST",
                    body: {
                        ubs_id: currentUBS.id,
                        sus: paciente.sus,
                        especialidade: currentSpecialty,
                        assunto: currentSubject,
                        data: currentDate
                    }
                }
            );

        appointments.push(data.appointment);
        abrirLembreteWhatsApp(data.appointment, paciente.nome, whatsappWindow);

        currentTime = null;

        mostrarComprovante(
            data.appointment
        );

        mostrarApenas(
            "confirmacaoSection"
        );

        atualizarPasso(5);

        toast(
            "Consulta agendada com sucesso!"
        );

    } catch (error) {

        if (whatsappWindow) whatsappWindow.close();
        toast(error.message);

        await renderHorarios();

    }

}

function abrirLembreteWhatsApp(consulta, nome, janela = null) {
    const phone = String(consulta?.telefone || '').replace(/\D/g, '');
    if (!phone) {
        if (janela) janela.close();
        return;
    }
    const horario = consulta.horario ? ` às ${consulta.horario}` : ` na posição ${Number(consulta.fila || 0)} da fila`;
    const mensagem = `Olá, ${nome || 'paciente'}! Este é um lembrete da sua consulta na ${consulta.ubsNome || 'UBS'}. Data: ${formatarDataBR(consulta.data)}${horario}. Especialidade: ${consulta.especialidade || 'atendimento'}. Protocolo: ${consulta.id}.`;
    const telefoneWhatsApp = phone.startsWith('55') && phone.length >= 12 ? phone : `55${phone}`;
    const url = `https://wa.me/${telefoneWhatsApp}?text=${encodeURIComponent(mensagem)}`;
    if (janela) janela.location.href = url;
    else window.open(url, '_blank');
}

function mostrarComprovante(consulta) {

    const dataFormatada =
        formatarDataBR(consulta.data);

    $("comprovante").innerHTML = `

        <img src="img/logo.png" class="receipt-logo" alt="Acessa+ Saúde">

        <div class="receipt-row">
            <strong>Paciente</strong>
            <span>${escapeHTML(consulta.nome)}</span>
        </div>

        <div class="receipt-row">
            <strong>UBS</strong>
            <span>${escapeHTML(consulta.ubsNome)}</span>
        </div>

        <div class="receipt-row">
            <strong>Especialidade</strong>
            <span>${escapeHTML(consulta.especialidade)}</span>
        </div>

        ${consulta.assunto ? `<div class="receipt-row"><strong>Assunto</strong><span>${escapeHTML(consulta.assunto)}</span></div>` : ""}

        <div class="receipt-row">
            <strong>Data</strong>
            <span>${dataFormatada}</span>
        </div>

        <div class="receipt-row">
            <strong>Fila</strong>
            <span>${consulta.fila}º atendimento do dia</span>
        </div>

        <div class="receipt-row">
            <strong>Posição na fila</strong>
            <span>${consulta.fila}º</span>
        </div>

        <div class="receipt-row">
            <strong>Protocolo</strong>
            <span>${consulta.id}</span>
        </div>

    `;
}

function imprimirComprovante() {

    window.print();

}

/* ============================================================
   MEUS AGENDAMENTOS
   ============================================================ */


let patientExams = [];
let historicoFiltro = "todas";

function preencherDadosPaciente(paciente) {
    if (!paciente) return;
    if ($("nomePaciente")) $("nomePaciente").value = paciente.nome || "";
    if ($("telefonePaciente")) $("telefonePaciente").value = paciente.telefone || "";
    if ($("susPaciente")) $("susPaciente").value = paciente.sus || "";
    if ($("ubsPaciente")) $("ubsPaciente").value = paciente.ubsId || "";
    if ($("nomeTopo")) $("nomeTopo").textContent = "Olá, " + ((paciente.nome || "").split(" ")[0] || "Paciente");
    if ($("nomeDashboard")) $("nomeDashboard").textContent = ((paciente.nome || "").split(" ")[0] || "Paciente");
}

async function carregarExamesPaciente(sus) {
    try {
        const data = await api("get_patient_exams", { params: { sus } });
        patientExams = data.exams || [];
    } catch (error) {
        patientExams = [];
    }
}

function atualizarDashboard() {
    const paciente = JSON.parse(sessionStorage.getItem(STORAGE_PATIENT) || "null");
    if (!paciente) return;
    const pacienteUbsId = paciente.ubsId || sessionStorage.getItem(STORAGE_LAST_UBS);
    preencherDadosPaciente(paciente);
    const unidadeAtual = ubsList.find(u => String(u.id) === String(pacienteUbsId));
    if ($("ubsAtualDashboard")) $("ubsAtualDashboard").textContent = unidadeAtual?.nome || "Não informada";
    const futuras = appointments.filter(a => a.status === "agendado").sort((a,b) => (a.data+a.horario).localeCompare(b.data+b.horario));
    const realizadas = appointments.filter(a => a.status === "atendido");
    const canceladas = appointments.filter(a => a.status === "cancelado");
    $("countProximas") && ($("countProximas").textContent = futuras.length);
    $("countRealizadas") && ($("countRealizadas").textContent = realizadas.length);
    $("countCanceladas") && ($("countCanceladas").textContent = canceladas.length);

    const prox = $("dashboardProximas");
    if (prox) {
        prox.innerHTML = futuras.length ? futuras.slice(0,2).map(c => `<div class="dashboard-consult"><strong>${escapeHTML(c.especialidade)}</strong>${c.assunto ? `<p>📝 ${escapeHTML(c.assunto)}</p>` : ""}<p>📍 ${escapeHTML(c.ubsNome)}</p><p>📅 ${formatarDataBR(c.data)} às ${escapeHTML(c.horario)}</p></div>`).join("") : '<div class="info-box">Você não possui consultas marcadas.</div>';
    }
    const avisos = $("dashboardAvisos");
    if (avisos) {
        const unidades = pacienteUbsId ? ubsList.filter(u => String(u.id) === String(pacienteUbsId)) : [];
        const itens = unidades.flatMap(u => (u.campanhas || []).slice(0,2).map(c => ({nome:c,ubs:u.nome})));
        avisos.innerHTML = itens.length ? itens.slice(0,4).map(i => `<div class="notice"><b>${escapeHTML(i.nome)}</b><small>${escapeHTML(i.ubs)}</small></div>`).join("") : '<div class="info-box">Nenhum aviso cadastrado.</div>';
    }
}

function iniciarAgendamento() {
    const paciente = JSON.parse(sessionStorage.getItem(STORAGE_PATIENT) || "null");
    if (!paciente) { mostrarApenas("cadastro"); toast("Faça seu cadastro para agendar uma consulta."); return; }
    const preferredId = sessionStorage.getItem(STORAGE_LAST_UBS) || paciente.ubsId;
    const preferred = ubsList.find(ubs => String(ubs.id) === String(preferredId));
    if (preferred) { abrirUBS(preferred.id); return; }
    renderUBS();
    mostrarApenas("ubsSection");
    atualizarPasso(2);
}

function mostrarUBSMenu() {
    renderUBS(true);
    mostrarApenas("ubsSection");
    atualizarPasso(2);
}

function mostrarExames() {
    const paciente = JSON.parse(sessionStorage.getItem(STORAGE_PATIENT) || "null");
    if (!paciente) { mostrarApenas("cadastro"); toast("Faça seu cadastro para acessar seus resultados."); return; }
    carregarExamesPaciente(paciente.sus).then(() => renderExamesPaciente());
    mostrarApenas("examesSection");
}

function renderExamesPaciente() {
    const container = $("listaExames");
    if (!container) return;
    if (!patientExams.length) { container.innerHTML = '<div class="info-box"><h3>Nenhum resultado disponível</h3><p>Quando a UBS disponibilizar um resultado, ele aparecerá aqui.</p></div>'; return; }
    container.innerHTML = patientExams.map(e => `<article class="exam-card"><div class="exam-head"><div><h3>${escapeHTML(e.nome)}</h3><span class="exam-date">Data: ${formatarDataBR(e.data)} • ${escapeHTML(e.ubsNome)}</span></div><span class="status">Disponível</span></div><div class="exam-result"><strong>Resultado</strong><br>${escapeHTML(e.resultado)}</div>${e.observacoes ? `<div class="subtitle"><strong>Observações:</strong> ${escapeHTML(e.observacoes)}</div>` : ""}${e.anexo ? `<a class="btn secondary exam-attachment" href="api.php?action=download_exam&id=${encodeURIComponent(e.id)}&sus=${encodeURIComponent(JSON.parse(sessionStorage.getItem(STORAGE_PATIENT) || "{}").sus || "")}" target="_blank" rel="noopener">Abrir anexo: ${escapeHTML(e.anexoNome || "resultado")}</a>` : ""}</article>`).join("");
}

async function mostrarAvisos() {
    const container = $("listaAvisos");
    if (!container) return;
    const paciente = JSON.parse(sessionStorage.getItem(STORAGE_PATIENT) || "null");
    const pacienteUbsId = paciente?.ubsId || sessionStorage.getItem(STORAGE_LAST_UBS);
    mostrarApenas("avisosSection");
    container.innerHTML = '<div class="info-box" role="status">Atualizando campanhas e eventos...</div>';
    try {
        const data = await api("get_ubs");
        const unidades = (data.ubs || []).filter(u => String(u.id) === String(pacienteUbsId));
        const itens = unidades.flatMap(u => (Array.isArray(u.campanhas) ? u.campanhas : []).map(c => ({nome:c,ubs:u.nome,horario:u.horario})));
        container.innerHTML = itens.length
            ? itens.map(i => `<article class="notice-card"><span class="tag">CAMPANHA / EVENTO</span><h3>${escapeHTML(i.nome)}</h3><p>${escapeHTML(i.ubs)}${i.horario ? ` • Atendimento: ${escapeHTML(i.horario)}` : ""}</p></article>`).join("")
            : '<div class="info-box">Nenhuma campanha ou evento cadastrado para sua UBS no momento.</div>';
    } catch (error) {
        container.innerHTML = `<div class="info-box">Não foi possível carregar campanhas e eventos: ${escapeHTML(error.message)}</div>`;
    }
}

async function verificarNotificacoes() {
    const paciente = JSON.parse(sessionStorage.getItem(STORAGE_PATIENT) || "null");
    if (!paciente) { toast("Faça seu cadastro para receber notificações."); return; }
    try {
        if ("Notification" in window && Notification.permission === "default") {
            await Notification.requestPermission();
        }
        const data = await api("get_patient_notifications", {params:{sus:paciente.sus}});
        const notificacoes = data.notifications || [];
        const pendentes = notificacoes.filter(n => n.status === "pendente");
        const badge = $("notificationCount");
        if (badge) badge.textContent = String(pendentes.length);
        const list = $("notificationList");
        if (list) list.innerHTML = notificacoes.length ? notificacoes.map(n => `<article class="notification-item ${n.status === 'pendente' ? 'unread' : ''}"><strong>${escapeHTML(n.tipo || 'Notificação')}</strong><p>${escapeHTML(n.mensagem || '')}</p><small>${escapeHTML(n.agendadaPara || '')}</small></article>`).join('') : '<div class="info-box">Nenhuma notificação encontrada.</div>';
        $("notificationPanel")?.classList.remove('hidden');
        if (pendentes.length && "Notification" in window && Notification.permission === "granted") {
            pendentes.slice(0,3).forEach(n => new Notification("Acessa+ Saúde", {body:n.mensagem}));
        }
    } catch (error) { toast(error.message); }
}

function fecharNotificacoes() { $("notificationPanel")?.classList.add('hidden'); }

function mostrarAjuda() { mostrarApenas("ajudaSection"); }

function mostrarAgendamentos() {

    const paciente =
        JSON.parse(
            sessionStorage.getItem(
                STORAGE_PATIENT
            ) || "null"
        );

    if (!paciente) {

        toast(
            "Faça seu cadastro primeiro."
        );

        mostrarApenas(
            "cadastro"
        );

        return;

    }

    renderMeusAgendamentos();
    atualizarDashboard();
    mostrarApenas("meusAgendamentos");

}


function renderMeusAgendamentos() {
    const container = $("listaAgendamentos");
    if (!container) return;
    const paciente = JSON.parse(sessionStorage.getItem(STORAGE_PATIENT) || "null");
    if (!paciente) { container.innerHTML = ""; return; }
    const lista = historicoFiltro === "todas" ? appointments : appointments.filter(a => a.status === historicoFiltro);
    if (!lista.length) { container.innerHTML = '<div class="info-box">Nenhuma consulta encontrada nesta categoria.</div>'; return; }
    const ordenada = [...lista].sort((a,b) => (b.data+b.horario).localeCompare(a.data+a.horario));
    container.innerHTML = ordenada.map(c => {
        const statusTexto = c.status === "atendido" ? "Realizada" : c.status === "cancelado" ? "Cancelada" : c.status === "faltou" ? "Não compareceu" : "Marcada";
        const classe = c.status;
        return `<article class="appointment"><div style="display:flex;justify-content:space-between;gap:10px;align-items:center"><h3>${escapeHTML(c.especialidade)}</h3><span class="status ${classe}">${statusTexto}</span></div>${c.assunto ? `<p>📝 <strong>Assunto:</strong> ${escapeHTML(c.assunto)}</p>` : ""}<p>📍 ${escapeHTML(c.ubsNome)}</p><p>📅 ${formatarDataBR(c.data)} às ${escapeHTML(c.horario)}</p><p>Protocolo: ${escapeHTML(c.id)}</p>${c.status === "agendado" ? `<div class="appointment-actions"><button class="btn secondary" onclick="ativarLembrete('${escapeHTML(c.id)}')">🔔 ${c.lembrete ? "Lembrete ativado" : "Ativar lembrete"}</button><button class="btn danger" onclick="cancelarConsultaPaciente('${escapeHTML(c.id)}')">Cancelar</button><button class="btn secondary" onclick="imprimirConsulta('${escapeHTML(c.id)}')">Imprimir</button></div>` : ""}</article>`;
    }).join("");
}

function filtrarHistorico(filtro, botao) {
    historicoFiltro = filtro;
    document.querySelectorAll(".history-tabs button").forEach(b => b.classList.remove("active"));
    if (botao) botao.classList.add("active");
    renderMeusAgendamentos();
}

async function cancelarConsultaPaciente(id) {
    const paciente = JSON.parse(sessionStorage.getItem(STORAGE_PATIENT) || "null");
    if (!paciente) return;
    const motivo = prompt("Informe o motivo do cancelamento (opcional):", "");
    if (motivo === null) return;
    try {
        await api("cancel_appointment", {method:"POST", body:{id, sus:paciente.sus, motivo:motivo.trim()}});
        await carregarConsultasPaciente(paciente.sus);
        renderMeusAgendamentos();
        atualizarDashboard();
        toast("Consulta cancelada.");
    } catch(error) { toast(error.message); }
}

function filtrarUBSPorLocalizacao(unidades, estado, cidade) {
    return unidades.filter(unidade => String(unidade.estado || "").trim().toUpperCase() === estado && chaveCidade(unidade.cidade) === chaveCidade(cidade));
}
function chaveCidade(cidade) {
    return String(cidade || "").trim().replace(/\s+/g, " ").toLocaleLowerCase("pt-BR");
}
// Uma opção por cidade, mesmo que as UBS tenham sido cadastradas com grafias diferentes ("Serra" e "serra").
function cidadesDoEstado(unidades, estado) {
    const porChave = new Map();
    unidades.filter(u => String(u.estado || "").trim().toUpperCase() === estado).forEach(u => {
        const nome = String(u.cidade || "").trim().replace(/\s+/g, " ");
        if (nome && !porChave.has(chaveCidade(nome))) porChave.set(chaveCidade(nome), nome);
    });
    return [...porChave.values()].sort((a, b) => a.localeCompare(b, "pt-BR"));
}
function lerLocalizacaoPaciente() {
    try { return JSON.parse(localStorage.getItem(STORAGE_LAST_LOCATION) || "null") || {}; } catch (_e) { return {}; }
}
function salvarLocalizacaoPaciente(estado, cidade) {
    try {
        if (estado && cidade) localStorage.setItem(STORAGE_LAST_LOCATION, JSON.stringify({ estado, cidade }));
        else localStorage.removeItem(STORAGE_LAST_LOCATION);
    } catch (_e) {}
}
function preencherSelectUBSPaciente() {
    const estadoSelect = $("estadoPaciente");
    const cidadeSelect = $("cidadePaciente");
    const ubsSelect = $("ubsPaciente");
    if (!estadoSelect || !cidadeSelect || !ubsSelect) return;

    const disponiveis = ubsList.filter(u => String(u.estado || "").trim() && String(u.cidade || "").trim());
    const preferidaId = sessionStorage.getItem(STORAGE_LAST_UBS);
    const preferida = disponiveis.find(u => String(u.id) === String(preferidaId));
    const lembrada = lerLocalizacaoPaciente();
    const estados = [...new Set(disponiveis.map(u => String(u.estado).trim().toUpperCase()))].sort();
    const nomesEstados = {AC:"Acre",AL:"Alagoas",AP:"Amapá",AM:"Amazonas",BA:"Bahia",CE:"Ceará",DF:"Distrito Federal",ES:"Espírito Santo",GO:"Goiás",MA:"Maranhão",MT:"Mato Grosso",MS:"Mato Grosso do Sul",MG:"Minas Gerais",PA:"Pará",PB:"Paraíba",PR:"Paraná",PE:"Pernambuco",PI:"Piauí",RJ:"Rio de Janeiro",RN:"Rio Grande do Norte",RS:"Rio Grande do Sul",RO:"Rondônia",RR:"Roraima",SC:"Santa Catarina",SP:"São Paulo",SE:"Sergipe",TO:"Tocantins"};

    estadoSelect.innerHTML = '<option value="">Selecione o estado</option>' + estados.map(uf => `<option value="${escapeAttr(uf)}">${escapeHTML(nomesEstados[uf] ? `${nomesEstados[uf]} (${uf})` : uf)}</option>`).join("");
    estadoSelect.disabled = estados.length === 0;
    const localizacaoAjuda = $("ubsLocalizacaoAjuda");
    if (localizacaoAjuda && disponiveis.length < ubsList.length) {
        const semLocalizacao = ubsList.length - disponiveis.length;
        localizacaoAjuda.textContent = disponiveis.length === 0
            ? "Nenhuma UBS tem cidade e UF cadastradas. Peça à Secretaria ou ao administrador para atualizar a localização das unidades."
            : `${semLocalizacao} UBS sem cidade/UF cadastradas não aparecem até que a localização seja atualizada.`;
    }
    const estadoInicial = preferida?.estado ? String(preferida.estado).trim().toUpperCase() : String(lembrada.estado || "");
    estadoSelect.value = estados.includes(estadoInicial) ? estadoInicial : (estados.length === 1 ? estados[0] : "");

    const atualizarUBSs = () => {
        const localizadas = cidadeSelect.value ? filtrarUBSPorLocalizacao(disponiveis, estadoSelect.value, cidadeSelect.value) : [];
        ubsSelect.innerHTML = `<option value="">${cidadeSelect.value ? "Selecione sua UBS" : "Selecione primeiro a cidade"}</option>` + localizadas.map(u => `<option value="${escapeAttr(u.id)}">${escapeHTML(u.nome)}</option>`).join("");
        ubsSelect.disabled = localizadas.length === 0;
        if (preferidaId && localizadas.some(u => String(u.id) === String(preferidaId))) ubsSelect.value = String(preferidaId);
        else if (localizadas.length === 1) ubsSelect.value = String(localizadas[0].id);
    };
    const atualizarCidades = (cidadeInicial = "") => {
        const cidades = estadoSelect.value ? cidadesDoEstado(disponiveis, estadoSelect.value) : [];
        cidadeSelect.innerHTML = `<option value="">${estadoSelect.value ? "Selecione a cidade" : "Selecione primeiro o estado"}</option>` + cidades.map(cidade => `<option value="${escapeAttr(cidade)}">${escapeHTML(cidade)}</option>`).join("");
        cidadeSelect.disabled = !estadoSelect.value || cidades.length === 0;
        const inicial = cidades.find(cidade => chaveCidade(cidade) === chaveCidade(cidadeInicial));
        cidadeSelect.value = inicial || (cidades.length === 1 ? cidades[0] : "");
        atualizarUBSs();
    };

    estadoSelect.onchange = () => {
        sessionStorage.removeItem(STORAGE_LAST_UBS);
        atualizarCidades();
        salvarLocalizacaoPaciente(estadoSelect.value, cidadeSelect.value);
    };
    cidadeSelect.onchange = () => {
        sessionStorage.removeItem(STORAGE_LAST_UBS);
        atualizarUBSs();
        salvarLocalizacaoPaciente(estadoSelect.value, cidadeSelect.value);
    };
    ubsSelect.onchange = () => {
        if (ubsSelect.value) sessionStorage.setItem(STORAGE_LAST_UBS, String(ubsSelect.value));
        else sessionStorage.removeItem(STORAGE_LAST_UBS);
    };
    atualizarCidades(preferida?.cidade || lembrada.cidade || "");
}
function abrirSuporte(){const p=JSON.parse(sessionStorage.getItem(STORAGE_PATIENT)||'null');if(p){$("suporteNome").value=p.nome||'';$("suporteTelefone").value=p.telefone||'';}const clinic=!!window.publicClinicSlug;const title=$("supportModal")?.querySelector('h2');const hint=$("supportModal")?.querySelector('.subtitle');if(title)title.textContent=clinic?'Fale com a clínica':'Como podemos ajudar?';const fabLabel=$("supportFabLabel");if(fabLabel)fabLabel.textContent=clinic?'Fale com a clínica':'Suporte ao paciente';if(hint)hint.textContent=clinic?'Sua solicitação será recebida primeiro pela clínica. Ela poderá responder, resolver ou encaminhar apenas problemas técnicos.':'Envie sua dúvida e guarde o protocolo para acompanhar a resposta.';$("supportModal").classList.remove('hidden');carregarMeuSuporte();}
function fecharSuporte(){$("supportModal").classList.add('hidden');}
async function carregarMeuSuporte(){
    const box=$("supportHistory");
    const entries=JSON.parse(localStorage.getItem('CONectaSupportProtocols')||'[]');
    if(!entries.length){box.innerHTML='<div class="info-box">Envie um chamado para receber um protocolo e acompanhar a resposta. Somente quem possui a chave de acesso poderá consultar o chamado.</div>';return;}
    try{
        let msgs=[];
        for(const item of entries.slice(-10)){
            try{const d=await api('get_support',{params:{protocolo:item.protocolo,token:item.token}});for(const m of (d.messages||[])){if(!msgs.some(x=>x.protocolo===m.protocolo))msgs.push(m);}}catch(_e){}
        }
        msgs.sort((a,b)=>String(b.atualizadoEm||b.criadoEm||'').localeCompare(String(a.atualizadoEm||a.criadoEm||'')));
        box.innerHTML=msgs.length?msgs.map(m=>`<div class="info-box"><strong>${escapeHTML(m.protocolo)}</strong><p>Status: ${escapeHTML(m.status)}</p><p>${escapeHTML(m.mensagem)}</p>${m.resposta?`<div class="support-reply"><strong>${m.destinoTipo==='clinica'?'Resposta da clínica':m.destinoTipo==='ubs'?'Resposta da UBS':'Resposta do suporte'}:</strong><p>${escapeHTML(m.resposta)}</p><small>Atualizado em: ${escapeHTML(m.atualizadoEm||m.criadoEm||'')}</small></div>`:'<p><em>Aguardando resposta.</em></p>'}</div>`).join(''):'<div class="info-box">Nenhum chamado acessível neste dispositivo.</div>';
    }catch(e){box.innerHTML='<div class="info-box">'+escapeHTML(e.message)+'</div>';}
}
async function enviarSuporte(){
    const p=JSON.parse(sessionStorage.getItem(STORAGE_PATIENT)||'null');
    const body={nome:$("suporteNome").value.trim(),telefone:$("suporteTelefone").value.trim(),mensagem:$("suporteMensagem").value.trim(),sus:p?.sus||'',clinic_slug:window.publicClinicSlug||''};
    if(!body.nome||!body.telefone||!body.mensagem){toast('Preencha nome, celular e mensagem.');return;}
    try{
        const d=await api('support_message',{method:'POST',body});$("suporteMensagem").value='';
        const lista=JSON.parse(localStorage.getItem('CONectaSupportProtocols')||'[]');
        if(d.protocolo&&d.acesso_token){lista.push({protocolo:d.protocolo,token:d.acesso_token});localStorage.setItem('CONectaSupportProtocols',JSON.stringify(lista.slice(-20)));}
        toast(d.message);await carregarMeuSuporte();
    }catch(e){toast(e.message);}
}

async function carregarMunicipiosSecretaria(){const uf=$("secAdminEstado")?.value||"",input=$("secAdminCidade"),list=$("secAdminCidadeOptions");if(!input||!list)return;input.value="";list.innerHTML="";if(!uf)return;try{const response=await fetch(`https://servicodados.ibge.gov.br/api/v1/localidades/estados/${encodeURIComponent(uf)}/municipios?orderBy=nome`,{cache:"force-cache"});if(!response.ok)throw new Error("Falha ao carregar municípios.");const municipalities=await response.json();if($("secAdminEstado")?.value!==uf)return;list.innerHTML=municipalities.map(city=>`<option value="${escapeAttr(city.nome)}"></option>`).join("");}catch(error){list.innerHTML="";toast("Não foi possível carregar as sugestões do IBGE. Você ainda pode digitar a cidade.");}}
function abrirNovaUBSForm(){const secretaria=adminSession?.tipo==="secretaria",cidade=String(adminSession?.cidade||"").trim(),estado=String(adminSession?.estado||"").trim().toUpperCase(),temLocalizacao=secretaria&&cidade&&estado;$("novaUBSCidadeField")?.classList.toggle("hidden",!!temLocalizacao);$("novaUBSEstadoField")?.classList.toggle("hidden",!!temLocalizacao);const help=$("novaUBSSecretariaLocationHelp");if(help){help.classList.toggle("hidden",!secretaria);help.textContent=temLocalizacao?`Esta UBS será cadastrada automaticamente em ${cidade} / ${estado}.`:"Esta conta antiga ainda não tem localização definida. Informe cidade e UF nesta primeira UBS; a Secretaria guardará esses dados para as próximas.";}if(temLocalizacao){$("novaUBSCidade").value=cidade;$("novaUBSEstado").value=estado;}$("formNovaUBS").classList.remove('hidden');}function fecharNovaUBSForm(){$("formNovaUBS").classList.add('hidden');}
async function salvarNovaUBS(){const body={id:$("novaUBSId").value.trim(),nome:$("novaUBSNome").value.trim(),endereco:$("novaUBSEndereco").value.trim(),cidade:$("novaUBSCidade").value.trim(),estado:$("novaUBSEstado").value.trim().toUpperCase(),telefone:$("novaUBSTelefone").value.trim(),horario:$("novaUBSHorario").value.trim(),usuario:$("novaUBSUsuario").value.trim(),senha:$("novaUBSSenha").value,especialidades:converterTextoLista($("novaUBSEspecialidades").value)};try{const d=await api('create_ubs',{method:'POST',body});if(adminSession?.tipo==="secretaria"&&!String(adminSession.cidade||"").trim()){adminSession.cidade=d.ubs.cidade;adminSession.estado=String(d.ubs.estado||"").trim();}ubsList.push(d.ubs);ubsList.sort((a,b)=>a.nome.localeCompare(b.nome));preencherSelectAdmin();$("adminUBSSelect").value=d.ubs.id;$("novaUBSSenha").value='';fecharNovaUBSForm();await carregarDadosAdmin(d.ubs.id);atualizarBotaoArquivarUBS();toast('UBS criada com sucesso.');if(adminSession?.tipo==='secretaria')await carregarPainelSecretaria();}catch(e){toast(e.message);}}

function imprimirConsulta(id) {

    const consulta =
        appointments.find(
            item => item.id === id
        );

    if (!consulta) return;

    mostrarComprovante(consulta);

    window.print();

}

async function ativarLembrete(id) {

    const consulta =
        appointments.find(
            item => item.id === id
        );

    if (!consulta) return;

    const paciente =
        JSON.parse(
            sessionStorage.getItem(
                STORAGE_PATIENT
            ) || "null"
        );

    try {

        await api(
            "set_reminder",
            {
                method: "POST",
                body: {
                    id,
                    sus: paciente?.sus || "",
                    lembrete: true
                }
            }
        );

        consulta.lembrete =
            true;

        if ("Notification" in window) {

            const permissao =
                await Notification.requestPermission();

            if (
                permissao === "granted"
            ) {

                new Notification(
                    "Acessa+ Saúde",
                    {
                        body:
                            "Lembrete ativado para sua consulta em " +
                            formatarDataBR(
                                consulta.data
                            ) +
                            " às " +
                            consulta.horario
                    }
                );

            }

        }

        toast(
            "Lembrete ativado neste navegador."
        );

    } catch (error) {

        toast(error.message);

    }

}

function abrirLogin() {

    $("loginUsuario").value = "";
    $("loginSenha").value = "";

    $("loginModal").classList.remove("hidden");

    setTimeout(function () {
        $("loginUsuario").focus();
    }, 100);

}

function fecharLogin() {

    $("loginModal").classList.add("hidden");

}

async function realizarLogin() {

    const usuario =
        $("loginUsuario").value.trim();

    const senha =
        $("loginSenha").value;

    if (!usuario || !senha) {

        toast(
            "Informe usuário e senha."
        );

        return;

    }

    try {

        const data =
            await api(
                "login",
                {
                    method: "POST",
                    body: {
                        usuario,
                        senha
                    }
                }
            );

        adminSession =
            data.session;

        fecharLogin();

        abrirPainelAdmin();

    } catch (error) {

        toast(
            error.message
        );

    }

}

async function carregarDadosAdmin(id){try{const data=await api('admin_ubs_data',{params:{ubs_id:id}});const i=ubsList.findIndex(u=>u.id===id);if(i>=0)ubsList[i]=data.ubs;const u=data.ubs;[ ['editNomeUBS','nome'],['editEndereco','endereco'],['editCidade','cidade'],['editEstado','estado'],['editTelefone','telefone'],['editHorario','horario'],['editUsuario','usuario'],['editLimiteDiario','limiteDiario'] ].forEach(([a,b])=>$(a).value=u[b]||'');$("editEspecialidades").value=(u.especialidades||[]).join('\n');$("editServicos").value=(u.servicos||[]).join('\n');$("editCampanhas").value=(u.campanhas||[]).join('\n');$("editDocumentos").value=(u.documentos||[]).join('\n');travarLocalizacaoUBS(u);renderFuncionariosAdmin();void atualizarSeloListaEsperaAdmin();}catch(e){toast(e.message);}}
// Só o desenvolvedor muda cidade/UF de uma UBS já localizada; a Secretaria fica no próprio município.
function travarLocalizacaoUBS(u){const travar=adminSession?.tipo!=='desenvolvedor'&&!!(String(u?.cidade||'').trim()&&String(u?.estado||'').trim());["editCidade","editEstado"].forEach(id=>{const campo=$(id);if(!campo)return;campo.readOnly=travar;campo.classList.toggle('location-locked',travar);campo.title=travar?'A localização da UBS é definida pela Secretaria/desenvolvedor.':'';});}

function tituloPainelSecretaria(session){const cidade=String(session?.cidade||'').trim(),uf=String(session?.estado||'').trim().toUpperCase();return cidade&&uf?`Secretaria de Saúde — ${cidade}/${uf}`:'Painel da Secretaria de Saúde';}
async function abrirPainelAdmin(){
    if(!adminSession)return;
    $("adminModal").classList.remove('hidden');
    const globalRole=['desenvolvedor','secretaria'].includes(adminSession.tipo);
    $("seletorDesenvolvedor").classList.toggle('hidden',!globalRole);
    $("adminSuporteTab").classList.toggle('hidden',adminSession.tipo!=='desenvolvedor');
    $("adminChangePasswordButton")?.classList.toggle('hidden',adminSession.tipo!=='desenvolvedor');
    $("adminAuditoriaTab").classList.toggle('hidden',adminSession.tipo!=='desenvolvedor');
    $("adminConfigTab").classList.toggle('hidden',adminSession.tipo!=='desenvolvedor');
    $("adminDashboardTab")?.classList.toggle('hidden',adminSession.tipo!=='secretaria');
    $("adminSecretariaAccountsTab")?.classList.toggle('hidden',adminSession.tipo!=='desenvolvedor');
    $("adminArchiveButton")?.classList.toggle('hidden',!globalRole);
    $("adminAddEmployeeButton")?.classList.toggle("hidden", !canManageUBSEmployees());
    $("employeesReadOnlyNotice")?.classList.toggle("hidden", canManageUBSEmployees());
    $("adminTitulo").textContent=adminSession.tipo==='desenvolvedor'?'Painel do Desenvolvedor':adminSession.tipo==='secretaria'?tituloPainelSecretaria(adminSession):'Painel '+(getUBS(adminSession.ubsId)?.nome||'UBS');
    if(globalRole){try{const d=await api('admin_list_ubs');ubsList=d.ubs||[];preencherSelectAdmin();if(adminSession.tipo==='secretaria'&&d.regiao){adminSession.cidade=d.regiao.cidade;adminSession.estado=d.regiao.estado;$("adminTitulo").textContent=tituloPainelSecretaria(adminSession);}}catch(e){toast(e.message);}}
    const firstActive=ubsList.find(u=>u.ativa!==false)?.id||ubsList[0]?.id||'';
    const id=globalRole?firstActive:adminSession.ubsId;
    if(id){$("adminUBSSelect").value=id;await carregarDadosAdmin(id);}
    if(adminSession.tipo==='secretaria'){await carregarPainelSecretaria();abrirAbaAdmin('secretaria');}
    else abrirAbaAdmin(adminSession.tipo==='desenvolvedor'?'secretaria-accounts':'dados');
    atualizarBotaoArquivarUBS();
}
function abrirTrocaSenhaAdmin(){if(adminSession?.tipo!=="desenvolvedor"){toast("Somente o desenvolvedor pode alterar esta senha.");return;}["adminCurrentPassword","adminNewPassword","adminConfirmPassword"].forEach(id=>$(id).value="");$("adminChangePasswordModal").classList.remove("hidden");$("adminCurrentPassword").focus();}
function fecharTrocaSenhaAdmin(){$("adminChangePasswordModal").classList.add("hidden");["adminCurrentPassword","adminNewPassword","adminConfirmPassword"].forEach(id=>$(id).value="");}
async function alterarSenhaDesenvolvedor(){const atual=$("adminCurrentPassword").value,nova=$("adminNewPassword").value,confirmacao=$("adminConfirmPassword").value;if(!atual||!nova||!confirmacao){toast("Preencha todos os campos.");return;}if(nova.length<12){toast("A nova senha precisa ter pelo menos 12 caracteres.");return;}if(nova!==confirmacao){toast("A confirmação da nova senha não corresponde.");return;}const button=$("adminChangePasswordSubmit");button.disabled=true;try{const data=await api("admin_change_password",{method:"POST",body:{senha_atual:atual,nova_senha:nova}});fecharTrocaSenhaAdmin();toast(data.message||"Senha alterada com sucesso.");}catch(error){toast(error.message);}finally{button.disabled=false;}}
function fecharAdmin(){if(adminSession){void logoutAdmin();return;}$("adminModal").classList.add('hidden');}
async function logoutAdmin(){
    const hadSession=!!adminSession;$("adminModal").classList.add('hidden');adminSession=null;
    if(hadSession){try{await api('admin_logout',{method:'POST',body:{}});}catch(e){console.warn('Não foi possível confirmar o logout administrativo no servidor.',e);}}
    definirModoUsuario(false);voltarEntradaPortais();
    try{const d=await api('get_ubs');ubsList=d.ubs||[];renderUBS();preencherSelectUBSPaciente();}catch(_e){}
    toast('Sessão administrativa encerrada.');
}
function getUBS(id) {

    return ubsList.find(
        item => item.id === id
    );
}

function UBSAdminAtual(){
    if(!adminSession)return null;
    if(['desenvolvedor','secretaria'].includes(adminSession.tipo))return getUBS($("adminUBSSelect").value);
    return getUBS(adminSession.ubsId);
}
function preencherSelectAdmin() {

    const select =
        $("adminUBSSelect");

    select.innerHTML = "";

    ubsList.forEach(function (ubs) {

        const option =
            document.createElement("option");

        option.value = ubs.id;

        option.textContent = ubs.nome;

        select.appendChild(option);

    });

}

async function trocarUBSAdmin(){
    await carregarDadosAdmin($("adminUBSSelect").value);atualizarBotaoArquivarUBS();
    const atual=window.adminAbaAtual;
    if(ABAS_ADMIN_POR_UBS.includes(atual))abrirAbaAdmin(atual);
}
const ABAS_ADMIN_POR_UBS = ["dados", "funcionarios", "consultas", "waitlist", "exames"];
function abrirAbaAdmin(aba) {
    window.adminAbaAtual = aba;
    // O seletor de UBS só aparece nas abas que dependem de uma unidade.
    const gestaoGlobal = ["desenvolvedor", "secretaria"].includes(adminSession?.tipo);
    const abaDeUBS = ABAS_ADMIN_POR_UBS.includes(aba);
    $("seletorDesenvolvedor")?.classList.toggle("hidden", !(gestaoGlobal && abaDeUBS));
    if (!abaDeUBS) $("formNovaUBS")?.classList.add("hidden");
    document.querySelectorAll(".admin-tabs button").forEach(botao => botao.classList.toggle("active", botao.getAttribute("onclick") === `abrirAbaAdmin('${aba}')`));

    $("adminDados")
        .classList.add("hidden");

    $("adminFuncionarios")
        .classList.add("hidden");

    $("adminConsultas")
        .classList.add("hidden");
    if($("adminWaitlist")) $("adminWaitlist").classList.add("hidden");

    $("adminSuporte")
        .classList.add("hidden");

    $("adminConfig")
        .classList.add("hidden");

    $("adminAuditoria")
        .classList.add("hidden");
    $("adminExames")?.classList.add("hidden");
    $("adminSecretariaDashboard")?.classList.add("hidden");
    $("adminSecretariaAccounts")?.classList.add("hidden");

    if (aba === "waitlist" && $("adminWaitlist")) { $("adminWaitlist").classList.remove("hidden"); carregarListaEsperaUBS(); }
    if (aba === "secretaria" && adminSession?.tipo === "secretaria") { $("adminSecretariaDashboard").classList.remove("hidden"); carregarPainelSecretaria(); }
    if (aba === "secretaria-accounts" && adminSession?.tipo === "desenvolvedor") { $("adminSecretariaAccounts").classList.remove("hidden"); carregarContasSecretaria(); }

    if (aba === "dados") {

        $("adminDados")
            .classList.remove("hidden");

    }

    if (aba === "config" && adminSession?.tipo === "desenvolvedor") {
        $("adminConfig").classList.remove("hidden");
        carregarConfiguracaoAdmin();
        carregarPagamentosProfissionais();
    }

    if (aba === "funcionarios") {

        $("adminFuncionarios")
            .classList.remove("hidden");

        renderFuncionariosAdmin();

    }

    if (aba === "exames") {
        $("adminExames").classList.remove("hidden");
        carregarExamesAdmin();
    }

    if (aba === "consultas") {

        $("adminConsultas")
            .classList.remove("hidden");

        preencherFiltroEspecialidades();

        renderConsultasAdmin();

    }

    if (aba === "suporte" && ["desenvolvedor","ubs"].includes(adminSession?.tipo)) {
        $("adminSuporte").classList.remove("hidden");
        carregarSuporteAdmin();
    }
    if (aba === "auditoria" && adminSession?.tipo === "desenvolvedor") {
        $("adminAuditoria").classList.remove("hidden");
        carregarAuditoria();
    }

}

async function carregarSuporteAdmin() {
    if (!adminSession || adminSession.tipo !== 'desenvolvedor') return;
    const container = $("listaSuporteAdmin");if(!container)return;
    try{
        const data=await api('admin_support_messages',{params:{status:$("filtroSuporte")?.value||''}}),messages=data.messages||[];
        container.innerHTML=messages.length?messages.map(m=>`<article class="admin-appointment"><div class="appointment-topline"><strong>${escapeHTML(m.protocolo)} • ${escapeHTML(m.nome)}</strong><span class="status ${m.status==='resolvido'?'atendido':''}">${escapeHTML(m.status)}</span></div><p>Telefone: ${escapeHTML(m.telefone)} • ${escapeHTML(m.criadoEm||'')}</p><p>${escapeHTML(m.mensagem)}</p>${m.destinoTipo==='clinica'?`${m.respostaClinica?`<div class="info-box"><strong>Triagem/resposta da clínica:</strong><p>${escapeHTML(m.respostaClinica)}</p></div>`:''}${m.respostaTecnica?`<div class="info-box"><strong>Orientação técnica anterior:</strong><p>${escapeHTML(m.respostaTecnica)}</p></div>`:''}<div class="info-box"><strong>Escalonado pela clínica:</strong><p>${escapeHTML(m.encaminhamentoMotivo||'')}</p></div><label for="respostaSuporte_${Number(m.id)}">Orientação técnica (visível somente à clínica)</label><textarea id="respostaSuporte_${Number(m.id)}" placeholder="Explique a análise ou a correção técnica para a clínica"></textarea><div class="admin-actions"><button type="button" class="btn primary" onclick="alterarStatusSuporte(${Number(m.id)},'em_atendimento')">Enviar orientação à clínica</button></div>`: `${m.resposta?`<div class="info-box"><strong>Resposta:</strong><p>${escapeHTML(m.resposta)}</p></div>`:''}<textarea id="respostaSuporte_${Number(m.id)}" placeholder="Digite uma resposta para o paciente"></textarea><div class="admin-actions"><button type="button" class="btn secondary" onclick="alterarStatusSuporte(${Number(m.id)},'em_atendimento')">Em atendimento</button><button type="button" class="btn primary" onclick="alterarStatusSuporte(${Number(m.id)},'resolvido')">Responder e resolver</button></div>`}</article>`).join(''):'<div class="info-box">Nenhuma mensagem de suporte recebida.</div>';
    }catch(e){container.innerHTML='<div class="info-box">'+escapeHTML(e.message)+'</div>';}
}
async function carregarSuporteClinica(){
    const box=$("listaSuporteClinica");if(!box)return;
    try{
        const d=await api('support_inbox'),list=d.messages||[];
        box.innerHTML=list.length?list.map(m=>`<article class="admin-appointment"><div class="appointment-topline"><strong>${escapeHTML(m.protocolo)} • ${escapeHTML(m.nome)}</strong><span class="status ${m.status==='resolvido'?'atendido':''}">${escapeHTML(m.status)}</span></div><p>Telefone: ${escapeHTML(m.telefone)} • ${escapeHTML(m.criadoEm||'')}</p><p class="support-message">${escapeHTML(m.mensagem)}</p>${m.resposta?`<div class="info-box"><strong>Resposta enviada ao paciente:</strong><p>${escapeHTML(m.resposta)}</p></div>`:''}${m.respostaTecnica?`<div class="info-box"><strong>Orientação técnica do desenvolvedor (somente para a clínica):</strong><p>${escapeHTML(m.respostaTecnica)}</p></div>`:''}${m.encaminhadoDesenvolvedor?`<div class="info-box"><strong>Encaminhado para análise técnica:</strong><p>${escapeHTML(m.encaminhamentoMotivo||'')}</p></div>`:''}${m.status!=='resolvido'?`<label for="respostaClinica_${Number(m.id)}">Resposta da clínica ao paciente</label><textarea id="respostaClinica_${Number(m.id)}" maxlength="5000" placeholder="Responda ao paciente ou atualize-o com a orientação recebida"></textarea><div class="admin-actions"><button type="button" class="btn secondary" onclick="responderChamadoClinica(${Number(m.id)},'em_atendimento')">Assumir / salvar resposta</button><button type="button" class="btn primary" onclick="responderChamadoClinica(${Number(m.id)},'resolvido')">Responder e resolver</button>${m.status==='em_atendimento'&&m.resposta?`<button type="button" class="btn danger" onclick="encaminharSuporteDesenvolvedor(${Number(m.id)},true)">Encaminhar problema técnico</button>`:''}</div>`:'<p><strong>Chamado resolvido pela clínica.</strong></p>'}</article>`).join(''):'<div class="info-box">Nenhum chamado para sua clínica.</div>';
    }catch(e){box.innerHTML='<div class="info-box">'+escapeHTML(e.message)+'</div>';}
}
async function responderChamadoClinica(id,status){
    try{const resposta=$("respostaClinica_"+id)?.value.trim()||'';await api('support_update_clinic',{method:'POST',body:{id,status,resposta}});toast(status==='resolvido'?'Chamado respondido e resolvido.':'Chamado assumido pela clínica.');await carregarSuporteClinica();}catch(e){toast(e.message);}
}
async function encaminharSuporteDesenvolvedor(id,isClinic=false){
    const motivo=prompt('Descreva o problema técnico encontrado (mínimo de 10 caracteres):','');if(motivo===null)return;
    try{await api('support_forward_developer',{method:'POST',body:{id,motivo:motivo.trim()}});toast('Chamado técnico encaminhado ao desenvolvedor.');if(isClinic)await carregarSuporteClinica();else await carregarSuporteAdmin();}catch(e){toast(e.message);}
}
async function alterarStatusSuporte(id, status) {
    try {
        const resposta = $("respostaSuporte_" + id)?.value.trim() || "";
        const result=await api("admin_update_support", {method:"POST", body:{id, status, resposta}});
        await carregarSuporteAdmin();
        toast(result.message||"Status da demanda atualizado.");
    } catch (error) {
        toast(error.message);
    }
}

async function carregarPainelSecretaria(){
    if(adminSession?.tipo!=='secretaria')return;
    try{const d=await api('admin_secretaria_dashboard');const data=d.data||{};ubsList=data.ubs||ubsList;window.secretariaIndicators=data.indicadores||[];preencherSelectAdmin();renderPainelSecretaria(data);popularSeletorIndicadorUBS();}
    catch(e){const box=$("secIndicatorsList");if(box)box.innerHTML=`<div class="info-box">${escapeHTML(e.message)}</div>`;toast(e.message);}
}
function renderPainelSecretaria(data){
    const total=(data.consultasHoje||[]).reduce((n,u)=>n+Number(u.consultas||0),0),active=(data.ubs||[]).filter(u=>u.ativa!==false).length,inds=data.indicadores||[];
    const subtitulo=$("secDashboardSubtitle");if(subtitulo)subtitulo.textContent=data.regiao?`Indicadores agregados das unidades ativas de ${data.regiao.cidade}/${data.regiao.estado}.`:'Indicadores agregados de todas as unidades ativas.';
    if($("secConsultasHoje"))$("secConsultasHoje").textContent=total;if($("secUnidadesAtivas"))$("secUnidadesAtivas").textContent=active;if($("secIndicadoresAtivos"))$("secIndicadoresAtivos").textContent=inds.length;
    const chart=$("secAppointmentsChart"),max=Math.max(1,...(data.consultasHoje||[]).map(u=>Number(u.consultas||0)));
    if(chart)chart.innerHTML=(data.consultasHoje||[]).map(u=>`<div class="secretaria-chart-row"><span>${escapeHTML(u.unidade)}</span><div class="secretaria-bar-track"><i style="width:${Math.round(Number(u.consultas||0)/max*100)}%"></i></div><b>${Number(u.consultas||0)}</b></div>`).join('')||'<p>Nenhuma unidade ativa.</p>';
    const categories=[['campanha','Campanhas'],['vacina','Vacinas'],['citologia','Exames citológicos']],summary=$("secHealthSummary");
    if(summary)summary.innerHTML=categories.map(([type,label])=>{const rows=inds.filter(i=>i.tipo===type),goal=rows.reduce((a,i)=>a+Number(i.meta||0),0),done=rows.reduce((a,i)=>a+Number(i.realizado||0),0),pct=goal?Math.min(100,Math.round(done/goal*100)):0;return `<article class="secretaria-health-card"><strong>${label}</strong><div class="secretaria-health-values"><b>${done.toLocaleString('pt-BR')}</b><span>de ${goal.toLocaleString('pt-BR')} previstos</span></div><div class="secretaria-bar-track"><i style="width:${pct}%"></i></div><small>${pct}% da meta • ${rows.length} indicador(es)</small></article>`;}).join('');
    const box=$("secIndicatorsList");if(box)box.innerHTML=inds.length?inds.map(i=>{const pct=i.meta?Math.min(100,Math.round(Number(i.realizado)/Number(i.meta)*100)):0;return `<article class="admin-appointment"><div class="appointment-topline"><strong>${escapeHTML(i.nome)}</strong><span class="status">${escapeHTML(({campanha:'Campanha',vacina:'Vacina',citologia:'Citologia'})[i.tipo]||i.tipo)}</span></div><p>${escapeHTML(i.unidade)} • ${Number(i.realizado).toLocaleString('pt-BR')} / ${Number(i.meta).toLocaleString('pt-BR')} • ${pct}%</p><div class="secretaria-bar-track"><i style="width:${pct}%"></i></div><small>${i.periodoInicio?formatarDataBR(i.periodoInicio):'Início não informado'}${i.periodoFim?' a '+formatarDataBR(i.periodoFim):' • sem data final'}</small><div class="admin-actions"><button type="button" class="btn secondary" onclick="editarIndicadorSecretaria(${Number(i.id)})">Editar</button><button type="button" class="btn danger" onclick="removerIndicadorSecretaria(${Number(i.id)})">Remover indicador</button></div></article>`;}).join(''):'<div class="info-box">Nenhuma meta cadastrada ainda. Cadastre a primeira campanha, meta de vacinação ou indicador de citologia.</div>';
}
function popularSeletorIndicadorUBS(){const s=$("secIndicatorUBS");if(s)s.innerHTML=ubsList.filter(u=>u.ativa!==false).map(u=>`<option value="${escapeAttr(u.id)}">${escapeHTML(u.nome)}</option>`).join('');}
function abrirFormIndicadorSecretaria(){const form=$("secIndicatorForm");form?.classList.remove('hidden');$("secIndicatorId").value='';$("secIndicatorName").value='';$("secIndicatorGoal").value='';$("secIndicatorDone").value='0';$("secIndicatorStart").value='';$("secIndicatorEnd").value='';popularSeletorIndicadorUBS();}
function cancelarFormIndicadorSecretaria(){$("secIndicatorForm")?.classList.add('hidden');}
function editarIndicadorSecretaria(id){const i=(window.secretariaIndicators||[]).find(x=>Number(x.id)===Number(id));if(!i)return;abrirFormIndicadorSecretaria();$("secIndicatorId").value=i.id;$("secIndicatorType").value=i.tipo;$("secIndicatorName").value=i.nome;$("secIndicatorUBS").value=i.ubsId;$("secIndicatorGoal").value=i.meta;$("secIndicatorDone").value=i.realizado;$("secIndicatorStart").value=i.periodoInicio||'';$("secIndicatorEnd").value=i.periodoFim||'';$("secIndicatorForm").scrollIntoView({behavior:'smooth',block:'center'});}
async function salvarIndicadorSecretaria(){const body={id:Number($("secIndicatorId").value)||undefined,ubs_id:$("secIndicatorUBS").value,tipo:$("secIndicatorType").value,nome:$("secIndicatorName").value.trim(),meta:Number($("secIndicatorGoal").value),realizado:Number($("secIndicatorDone").value||0),periodo_inicio:$("secIndicatorStart").value,periodo_fim:$("secIndicatorEnd").value};try{await api('admin_save_health_indicator',{method:'POST',body});cancelarFormIndicadorSecretaria();toast('Meta salva.');await carregarPainelSecretaria();}catch(e){toast(e.message);}}
async function removerIndicadorSecretaria(id){if(!confirm('Remover este indicador?'))return;try{await api('admin_delete_health_indicator',{method:'POST',body:{id}});toast('Indicador removido.');await carregarPainelSecretaria();}catch(e){toast(e.message);}}
async function carregarContasSecretaria(){if(adminSession?.tipo!=='desenvolvedor')return;const box=$("listaContasSecretaria");if(!box)return;try{const d=await api('admin_secretaria_accounts');const rows=d.accounts||[];box.innerHTML=rows.length?rows.map(a=>`<article class="employee"><div class="employee-info"><strong>${escapeHTML(a.usuario)}</strong><span>${escapeHTML(a.cidade||'Localização pendente')} / ${escapeHTML(String(a.estado||'').trim())} • Secretaria • criada ${escapeHTML(a.criadoEm||'')}</span></div><button type="button" class="btn danger" onclick="removerContaSecretaria(${Number(a.id)})">Remover acesso</button></article>`).join(''):'<div class="info-box">Nenhuma conta de Secretaria cadastrada.</div>';}catch(e){box.innerHTML=`<div class="info-box">${escapeHTML(e.message)}</div>`;}}
async function criarContaSecretaria(){const body={usuario:$("secAdminUsuario").value.trim(),senha:$("secAdminSenha").value,cidade:$("secAdminCidade").value.trim(),estado:$("secAdminEstado").value.trim().toUpperCase()};try{const d=await api('admin_create_secretaria',{method:'POST',body});$("secAdminUsuario").value='';$("secAdminSenha").value='';$("secAdminCidade").value='';$("secAdminEstado").value='';$("secAdminCidadeOptions").innerHTML='';toast(d.message);await carregarContasSecretaria();}catch(e){toast(e.message);}}
async function removerContaSecretaria(id){if(!confirm('Remover o acesso desta conta da Secretaria?'))return;try{await api('admin_delete_secretaria',{method:'POST',body:{id}});toast('Acesso removido.');await carregarContasSecretaria();}catch(e){toast(e.message);}}
function atualizarBotaoArquivarUBS(){const b=$("adminArchiveButton"),unit=UBSAdminAtual();if(!b)return;if(!adminSession||!['desenvolvedor','secretaria'].includes(adminSession.tipo)||!unit){b.classList.add('hidden');return;}b.classList.remove('hidden');b.textContent=unit.ativa===false?'Reativar UBS':'Desativar e arquivar UBS';b.classList.toggle('secondary',unit.ativa===false);b.classList.toggle('danger',unit.ativa!==false);}
async function alternarUBSAtiva(){const unit=UBSAdminAtual();if(!unit)return;const restore=unit.ativa===false;if(!confirm(restore?'Reativar esta UBS?':'Desativar esta UBS? A unidade sairá das opções públicas; dados e histórico serão preservados.'))return;try{const d=await api('admin_toggle_ubs',{method:'POST',body:{ubs_id:unit.id,ativa:restore}});const i=ubsList.findIndex(u=>u.id===unit.id);if(i>=0)ubsList[i]=d.ubs;else ubsList.push(d.ubs);preencherSelectAdmin();$("adminUBSSelect").value=unit.id;atualizarBotaoArquivarUBS();toast(d.message);if(adminSession.tipo==='secretaria')await carregarPainelSecretaria();}catch(e){toast(e.message);}}

/* ============================================================
   SALVAR INFORMAÇÕES UBS
   ============================================================ */

function ubsIdAdminAtual(){return adminSession?.tipo==='ubs'?adminSession.ubsId:$("adminUBSSelect")?.value;}
function atualizarSeloListaEspera(total){const badge=$("waitlistNoticeBadge");if(!badge)return;badge.textContent=total>0?String(total):"";badge.classList.toggle("hidden",!(total>0));}
function textoVagasLivres(vagas){return vagas>0?`${vagas} vaga${vagas>1?'s':''} livre${vagas>1?'s':''} nesta data`:"Sem vagas livres nesta data";}
async function carregarListaEsperaUBS(){const box=$("listaEsperaUBS");const ubsId=ubsIdAdminAtual();if(!box||!adminSession||!ubsId)return;try{const d=await api('admin_waitlist',{params:{ubs_id:ubsId}});const avisos=d.avisosPendentes||[];atualizarSeloListaEspera(avisos.length);$("avisosListaEsperaBox")?.classList.toggle("hidden",avisos.length===0);const avisosBox=$("avisosListaEspera");if(avisosBox)avisosBox.innerHTML=avisos.map(a=>`<article class="admin-appointment"><strong>${escapeHTML(a.codigo||'PAC')} — ${escapeHTML(a.nome)}</strong><p>${escapeHTML(a.especialidade)} • ${formatarDataBR(a.data)} • posição ${Number(a.fila)||'-'} na fila</p><small>Protocolo: ${escapeHTML(a.consultaId||'')}${a.telefone?'':' • sem telefone cadastrado'}</small><div class="appointment-actions"><button class="btn primary" type="button" onclick="avisarPacienteListaEspera(${Number(a.id)})">${a.telefone?'Avisar pelo WhatsApp':'Marcar como avisado'}</button></div></article>`).join('');const pendentes=d.waitlist||[];box.innerHTML=pendentes.length?pendentes.map(w=>{const vagas=Number(w.vagasLivres)||0;return `<article class="admin-appointment"><strong>${escapeHTML(w.codigo||'PAC')} — ${escapeHTML(w.nome)}</strong><p>${escapeHTML(w.especialidade)} • ${formatarDataBR(w.data)}</p><small>Entrada: ${escapeHTML(w.criadoEm||'')}</small><br><span class="waitlist-slot${vagas>0?'':' is-full'}">${textoVagasLivres(vagas)}</span><div class="appointment-actions"><button class="btn ${vagas>0?'primary':'secondary'}" type="button" onclick="adicionarPacienteFilaPrincipal(${Number(w.id)})">${vagas>0?'Encaixar na vaga e avisar':'Adicionar à fila principal (encaixe extra)'}</button></div></article>`;}).join(''):'<div class="info-box">Nenhum paciente aguardando.</div>';}catch(e){box.innerHTML='<div class="info-box">'+escapeHTML(e.message)+'</div>';}}
async function atualizarSeloListaEsperaAdmin(){const ubsId=ubsIdAdminAtual();if(!adminSession||!ubsId)return;try{const d=await api('admin_waitlist',{params:{ubs_id:ubsId}});atualizarSeloListaEspera((d.avisosPendentes||[]).length);}catch(_e){}}
async function avisarPacienteListaEspera(waitlistId){const ubsId=ubsIdAdminAtual();if(!Number.isInteger(Number(waitlistId))||Number(waitlistId)<1||!ubsId)return;let whatsappWindow=null;try{whatsappWindow=window.open('about:blank','_blank');const d=await api('admin_notify_waitlist',{method:'POST',body:{ubs_id:ubsId,waitlist_id:Number(waitlistId)}});if(d.whatsapp_url){if(whatsappWindow)whatsappWindow.location.href=d.whatsapp_url;else window.open(d.whatsapp_url,'_blank');}else if(whatsappWindow)whatsappWindow.close();toast(d.message||'Aviso registrado.');await carregarListaEsperaUBS();}catch(e){if(whatsappWindow)whatsappWindow.close();toast(e.message);}}
function avisoVagaRepassada(resposta){return resposta?.vagaRepassada?` A vaga foi repassada a ${resposta.listaEspera?.nome||'um paciente'} da lista de espera — avise-o na aba "Lista de espera".`:'';}

async function adicionarPacienteFilaPrincipal(waitlistId){if(!Number.isInteger(Number(waitlistId))||Number(waitlistId)<1||!adminSession)return;if(!confirm('Adicionar este paciente à fila principal? Ele receberá uma notificação e o WhatsApp será aberto com a mensagem preenchida.'))return;const ubsId=ubsIdAdminAtual();if(!ubsId)return;let whatsappWindow=null;try{whatsappWindow=window.open('about:blank','_blank');const d=await api('admin_add_waitlist_to_queue',{method:'POST',body:{ubs_id:ubsId,waitlist_id:Number(waitlistId)}});if(d.whatsapp_url){if(whatsappWindow)whatsappWindow.location.href=d.whatsapp_url;else window.open(d.whatsapp_url,'_blank');toast('Paciente adicionado. O WhatsApp foi aberto com a mensagem preenchida; toque em Enviar.');}else{if(whatsappWindow)whatsappWindow.close();toast('Paciente adicionado e notificado no sistema, mas não possui telefone cadastrado.');}await carregarListaEsperaUBS();}catch(e){if(whatsappWindow)whatsappWindow.close();toast(e.message);}}
async function salvarCampanhasEventos() {
    const ubs = UBSAdminAtual();
    const button = $("saveCampaignsEventsButton");
    const status = $("campaignsEventsStatus");
    if (!ubs) { toast("Nenhuma UBS selecionada."); return; }
    if (button) button.disabled = true;
    try {
        const campanhas = converterTextoLista($("editCampanhas").value);
        const data = await api("update_ubs_campaigns", {method:"POST", body:{ubs_id:ubs.id, campanhas}});
        const i = ubsList.findIndex(item => String(item.id) === String(ubs.id));
        if (data.ubs && i >= 0) ubsList[i] = data.ubs;
        if (data.ubs && currentUBS && String(currentUBS.id) === String(ubs.id)) currentUBS = data.ubs;
        if (status) status.textContent = `Salvo em ${new Date().toLocaleString("pt-BR", {dateStyle:"short", timeStyle:"short"})}`;
        toast(data.message || "Campanhas e eventos atualizados.");
    } catch (error) {
        if (status) status.textContent = `Não foi possível salvar: ${error.message}`;
        toast(error.message);
    } finally {
        if (button) button.disabled = false;
    }
}

async function salvarInformacoesUBS() {

    const ubs =
        UBSAdminAtual();

    if (!ubs) {

        toast(
            "Nenhuma UBS selecionada."
        );

        return;

    }

    const payload = {

        ubs_id: ubs.id,

        nome:
            $("editNomeUBS")
                .value
                .trim(),

        endereco:
            $("editEndereco")
                .value
                .trim(),

        cidade: $("editCidade").value.trim(),
        estado: $("editEstado").value.trim().toUpperCase(),

        telefone:
            $("editTelefone")
                .value
                .trim(),

        horario:
            $("editHorario")
                .value
                .trim(),

        especialidades:
            converterTextoLista(
                $("editEspecialidades")
                    .value
            ),

        servicos:
            converterTextoLista(
                $("editServicos")
                    .value
            ),

        campanhas:
            converterTextoLista(
                $("editCampanhas")
                    .value
            ),

        documentos:
            converterTextoLista(
                $("editDocumentos")
                    .value
            ),

        usuario:
            $("editUsuario")
                .value
                .trim(),

        senha:
            $("editSenha")
                .value
                .trim(),

        limite_diario: Number($("editLimiteDiario")?.value || 12)

    };

    if (!payload.usuario) {

        toast(
            "Usuário não pode ficar vazio."
        );

        return;

    }

    try {

        const data =
            await api(
                "update_ubs",
                {
                    method: "POST",
                    body: payload
                }
            );

        const idx =
            ubsList.findIndex(
                u => u.id === ubs.id
            );

        if (idx >= 0) {

            ubsList[idx] =
                data.ubs;

        }

        if (
            currentUBS &&
            currentUBS.id === ubs.id
        ) {

            currentUBS =
                data.ubs;

        }

        $("editSenha").value = "";

        renderUBS();

        toast(
            "Informações da UBS atualizadas!"
        );

    } catch (error) {

        toast(
            error.message
        );

    }

}

function converterTextoLista(texto) {

    return texto
        .split("\n")
        .map(item => item.trim())
        .filter(item => item.length > 0);

}

/* ============================================================
   FUNCIONÁRIOS
   ============================================================ */

function canManageUBSEmployees() {
    return !!adminSession && ["desenvolvedor", "secretaria"].includes(adminSession.tipo);
}

function renderFuncionariosAdmin() {

    const container =
        $("listaFuncionariosAdmin");

    container.innerHTML = "";
    const canManage = canManageUBSEmployees();
    $("adminAddEmployeeButton")?.classList.toggle("hidden", !canManage);
    $("employeesReadOnlyNotice")?.classList.toggle("hidden", canManage);
    if (!canManage) $("formFuncionario")?.classList.add("hidden");

    const ubs =
        UBSAdminAtual();

    if (!ubs) return;

    if (
        !ubs.funcionarios ||
        ubs.funcionarios.length === 0
    ) {

        container.innerHTML = `
            <div class="info-box">
                Nenhum funcionário cadastrado.
            </div>
        `;

        return;
    }

    ubs.funcionarios.forEach(function (funcionario) {

        const div =
            document.createElement("div");

        div.className = "employee";

        div.innerHTML = `

            <div class="employee-info">

                <strong>
                    ${escapeHTML(funcionario.nome)}
                </strong>

                <span>
                    ${escapeHTML(funcionario.cargo)}
                </span>

            </div>

            ${canManage ? `<div class="employee-actions">
                <button class="btn secondary" onclick="editarFuncionario('${funcionario.id}')">Editar</button>
                <button class="btn danger" onclick="excluirFuncionario('${funcionario.id}')">Excluir</button>
            </div>` : ""}

        `;

        container.appendChild(div);

    });

}

function abrirFuncionarioForm() {
    if (!canManageUBSEmployees()) { toast("A gestão de funcionários é exclusiva da Secretaria de Saúde."); return; }
    editingEmployeeId = null;

    $("funcionarioId").value = "";

    $("funcionarioNome").value = "";

    $("funcionarioCargo").value = "";

    $("tituloFuncionarioForm")
        .textContent = "Novo funcionário";

    $("formFuncionario")
        .classList.remove("hidden");

}

function fecharFuncionarioForm() {

    editingEmployeeId = null;

    $("formFuncionario")
        .classList.add("hidden");

}

async function salvarFuncionario() {
    if (!canManageUBSEmployees()) { toast("A gestão de funcionários é exclusiva da Secretaria de Saúde."); return; }

    const ubs =
        UBSAdminAtual();

    if (!ubs) return;

    const nome =
        $("funcionarioNome")
            .value
            .trim();

    const cargo =
        $("funcionarioCargo")
            .value
            .trim();

    if (!nome || !cargo) {

        toast(
            "Preencha nome e cargo."
        );

        return;

    }

    try {

        const data =
            await api(
                "save_employee",
                {
                    method: "POST",
                    body: {
                        id:
                            editingEmployeeId ||
                            "",
                        ubs_id:
                            ubs.id,
                        nome,
                        cargo
                    }
                }
            );

        ubs.funcionarios =
            data.funcionarios;

        renderFuncionariosAdmin();

        fecharFuncionarioForm();

        atualizarDetalhesUBS();

        toast(
            "Funcionário salvo com sucesso."
        );

    } catch (error) {

        toast(
            error.message
        );

    }

}

function editarFuncionario(id) {
    if (!canManageUBSEmployees()) { toast("A gestão de funcionários é exclusiva da Secretaria de Saúde."); return; }

    const ubs =
        UBSAdminAtual();

    if (!ubs) return;

    const funcionario =
        ubs.funcionarios.find(
            item => item.id === id
        );

    if (!funcionario) return;

    editingEmployeeId = id;

    $("funcionarioId").value = id;

    $("funcionarioNome").value =
        funcionario.nome;

    $("funcionarioCargo").value =
        funcionario.cargo;

    $("tituloFuncionarioForm")
        .textContent =
        "Editar funcionário";

    $("formFuncionario")
        .classList.remove("hidden");

}

async function excluirFuncionario(id) {
    if (!canManageUBSEmployees()) { toast("A gestão de funcionários é exclusiva da Secretaria de Saúde."); return; }

    const ubs =
        UBSAdminAtual();

    if (!ubs) return;

    const funcionario =
        ubs.funcionarios.find(
            item => item.id === id
        );

    if (!funcionario) return;

    if (
        !confirm(
            "Deseja excluir " +
            funcionario.nome +
            "?"
        )
    ) {
        return;
    }

    try {

        const data =
            await api(
                "delete_employee",
                {
                    method: "POST",
                    body: {
                        id
                    }
                }
            );

        ubs.funcionarios =
            data.funcionarios;

        renderFuncionariosAdmin();

        atualizarDetalhesUBS();

        toast(
            "Funcionário excluído."
        );

    } catch (error) {

        toast(
            error.message
        );

    }

}

function atualizarDetalhesUBS() {

    if (!currentUBS) return;

    const ubs =
        getUBS(currentUBS.id);

    if (!ubs) return;

    currentUBS = ubs;

    $("detalheNomeUBS").textContent =
        ubs.nome;

    $("detalheEndereco").textContent =
        "📍 " + ubs.endereco;

    $("detalheTelefone").textContent =
        "☎ " + ubs.telefone;

    $("detalheHorario").textContent =
        "🕐 " + ubs.horario;

    preencherLista(
        "detalheServicos",
        ubs.servicos
    );

    preencherLista(
        "detalheCampanhas",
        ubs.campanhas
    );

    preencherLista(
        "detalheDocumentos",
        ubs.documentos
    );

    preencherLista(
        "detalheFuncionarios",
        ubs.funcionarios.map(
            f =>
                `${f.nome} — ${f.cargo}`
        )
    );

}

/* ============================================================
   CONSULTAS ADMIN
   ============================================================ */

function preencherFiltroEspecialidades() {

    const select =
        $("filtroEspecialidade");

    const ubs =
        UBSAdminAtual();

    if (!ubs) return;

    select.innerHTML =
        `<option value="">Todas as especialidades</option>`;

    ubs.especialidades.forEach(
        function (especialidade) {

            const option =
                document.createElement("option");

            option.value =
                especialidade;

            option.textContent =
                especialidade;

            select.appendChild(option);

        }
    );

}

async function renderConsultasAdmin() {

    const container =
        $("listaConsultasAdmin");

    if (!container) return;

    container.innerHTML =
        "<p>Carregando...</p>";

    const ubs =
        UBSAdminAtual();

    if (!ubs) return;

    try {

        const params = {
            ubs_id: ubs.id
        };

        if ($("filtroData").value) {

            params.data =
                $("filtroData").value;

        }

        if (
            $("filtroEspecialidade").value
        ) {

            params.especialidade =
                $("filtroEspecialidade")
                    .value;

        }

        const data =
            await api(
                "admin_appointments",
                {
                    params
                }
            );

        const lista =
            data.appointments || [];

        container.innerHTML = "";

        if (!lista.length) {

            container.innerHTML =
                '<div class="info-box">Nenhuma consulta encontrada.</div>';

            return;

        }

        lista.forEach(
            function (consulta) {

                const div =
                    document.createElement(
                        "div"
                    );

                div.className =
                    "admin-appointment";

                const cancelado =
                    consulta.status ===
                    "cancelado";

                div.innerHTML = `

                    <strong>
                        ${escapeHTML(
                            consulta.nome
                        )}
                    </strong>

                    <p>
                        SUS:
                        ${escapeHTML(
                            consulta.sus
                        )}
                    </p>

                    <p>
                        ${escapeHTML(
                            consulta.especialidade
                        )}
                    </p>

                    ${consulta.assunto ? `<p>📝 <strong>Assunto:</strong> ${escapeHTML(consulta.assunto)}</p>` : ""}

                    <p>
                        📅 ${formatarDataBR(
                            consulta.data
                        )}
                        às
                        ${escapeHTML(
                            consulta.horario
                        )}
                    </p>

                    <p>
                        Fila:
                        ${consulta.fila}º
                    </p>

                    <p>
                        Status:
                        <span class="status ${escapeHTML(consulta.status)}">
                            ${consulta.status === "atendido" ? "Realizada" : consulta.status === "faltou" ? "Não compareceu" : cancelado ? "Cancelada" : "Agendada"}
                        </span>
                    </p>
                    <div class="admin-actions">
                    ${!cancelado && consulta.status !== "atendido" ? `<button class="btn primary" onclick="atualizarStatusConsultaAdmin('${consulta.id}','atendido')">✓ Marcar como realizada</button>` : ""}
                    ${!cancelado && consulta.status !== "faltou" && consulta.status !== "atendido" ? `<button class="btn secondary" onclick="atualizarStatusConsultaAdmin('${consulta.id}','faltou')">Não compareceu</button>` : ""}
                    ${!cancelado && consulta.status !== "atendido" ? `<button class="btn danger" onclick="cancelarConsultaAdmin('${consulta.id}')">Cancelar</button>` : ""}
                    </div>

                `;

                container.appendChild(
                    div
                );

            }
        );

    } catch (error) {

        container.innerHTML = "";

        toast(
            error.message
        );

    }

}


async function atualizarStatusConsultaAdmin(id, status) {
    try {
        const resposta = await api("admin_update_appointment_status", {method:"POST", body:{id,status}});
        await renderConsultasAdmin();
        toast((status === "atendido" ? "Consulta marcada como realizada." : "Status atualizado.") + avisoVagaRepassada(resposta));
        if (resposta?.vagaRepassada) await atualizarSeloListaEsperaAdmin();
    } catch(error) { toast(error.message); }
}

async function cancelarConsultaAdmin(id) {

    const consulta =
        appointments.find(
            item => item.id === id
        );

    if (
        !confirm(
            "Deseja cancelar esta consulta?"
        )
    ) {
        return;
    }

    try {

        const resposta = await api(
            "cancel_appointment",
            {
                method: "POST",
                body: { id }
            }
        );

        await renderConsultasAdmin();

        toast(
            "Consulta cancelada pelo administrador." + avisoVagaRepassada(resposta)
        );
        if (resposta?.vagaRepassada) await atualizarSeloListaEsperaAdmin();

    } catch (error) {

        toast(
            error.message
        );

    }

}


function abrirExameForm() { $("formExame").classList.remove("hidden"); $("exameData").value = new Date().toISOString().slice(0,10); }
function fecharExameForm() { $("formExame").classList.add("hidden"); ["exameSus","exameNome","exameData","exameResultado","exameObservacoes"].forEach(id => $(id).value = ""); if ($("exameArquivo")) $("exameArquivo").value = ""; }
async function salvarResultadoExame() {
    const ubs = UBSAdminAtual();
    if (!ubs) return;
    try {
        const body = new FormData();
        body.append("ubs_id", ubs.id);
        body.append("sus", $("exameSus").value.trim());
        body.append("nome", $("exameNome").value.trim());
        body.append("data_exame", $("exameData").value);
        body.append("resultado", $("exameResultado").value.trim());
        body.append("observacoes", $("exameObservacoes").value.trim());
        if ($("exameArquivo")?.files?.[0]) body.append("anexo", $("exameArquivo").files[0]);
        await api("admin_save_exam", {method:"POST", body});
        fecharExameForm(); await carregarExamesAdmin(); toast("Resultado cadastrado com sucesso.");
    } catch(error) { toast(error.message); }
}
async function carregarExamesAdmin() {
    const ubs=UBSAdminAtual(), container=$("listaExamesAdmin"); if(!ubs||!container)return;
    container.innerHTML="<p>Carregando...</p>";
    try { const data=await api("admin_exams",{params:{ubs_id:ubs.id}}); const lista=data.exams||[]; container.innerHTML=lista.length?lista.map(e=>`<div class="admin-appointment"><strong>${escapeHTML(e.nome)}</strong><p>Paciente: ${escapeHTML(e.paciente)} • SUS: ${escapeHTML(e.sus)}</p><p>Data: ${formatarDataBR(e.data)}</p><p>${escapeHTML(e.resultado)}</p>${e.anexo ? `<a class="btn secondary exam-attachment" href="api.php?action=download_exam&id=${encodeURIComponent(e.id)}" target="_blank" rel="noopener">Abrir anexo: ${escapeHTML(e.anexoNome || "resultado")}</a>` : ""}</div>`).join(""):'<div class="info-box">Nenhum resultado cadastrado.</div>'; } catch(error){container.innerHTML="";toast(error.message);}
}

function formatarDataBR(data) {

    if (!data) return "";

    const partes =
        data.split("-");

    if (partes.length !== 3) {
        return data;
    }

    return (
        partes[2] +
        "/" +
        partes[1] +
        "/" +
        partes[0]
    );
}

/* ============================================================
   TELEFONE
   ============================================================ */

function aplicarMascaraTelefone() {

    const campo =
        $("telefonePaciente");

    campo.addEventListener(
        "input",
        function () {

            let valor =
                this.value.replace(/\D/g, "");

            valor =
                valor.substring(0, 11);

            if (valor.length <= 10) {

                valor =
                    valor.replace(
                        /^(\d{2})(\d)/,
                        "($1) $2"
                    );

                valor =
                    valor.replace(
                        /(\d{4})(\d)/,
                        "$1-$2"
                    );

            } else {

                valor =
                    valor.replace(
                        /^(\d{2})(\d)/,
                        "($1) $2"
                    );

                valor =
                    valor.replace(
                        /(\d{5})(\d)/,
                        "$1-$2"
                    );
            }

            this.value = valor;

        }
    );

}

/* ============================================================
   NAVEGAÇÃO
   ============================================================ */

function irDashboard() { voltarInicio(); }

function alternarMenuPaciente() {
    $("patientMenu").classList.toggle("hidden");
}

function fecharMenuPaciente() {
    $("patientMenu").classList.add("hidden");
}

function sairPaciente() {
    sessionStorage.removeItem(STORAGE_PATIENT);
    appointments = [];
    patientExams = [];
    currentUBS = null;
    currentSpecialty = null;
    currentDate = null;
    fecharMenuPaciente();
    definirModoUsuario(false);
    $("nomeTopo").textContent = "Olá!";
    $("nomeDashboard").textContent = "Paciente";
    mostrarApenas("cadastro");
    atualizarPasso(1);
    history.replaceState({}, "", location.pathname);
    toast("Você saiu da área do paciente UBS.");
}

function definirModoUsuario(logado) {
    const shell = $("appShell");
    if (shell) shell.classList.toggle("guest-mode", !logado);
    const profileMenu = $("patientProfileMenuWrap");
    if (profileMenu) profileMenu.classList.toggle("hidden", !logado);

    // O acesso administrativo não é um item permanente do cabeçalho.
    // Ele só é liberado pela função de visibilidade quando a tela inicial
    // (Escolha como você deseja acessar) estiver realmente aberta.
    atualizarVisibilidadeAcessoAdministrativo();
}

function voltarInicio() {
    const paciente = JSON.parse(sessionStorage.getItem(STORAGE_PATIENT) || "null");
    if (paciente) { atualizarDashboard(); mostrarApenas("dashboardSection"); }
    else { mostrarApenas("cadastro"); atualizarPasso(1); }
}

function atualizarPasso(numero) {

    const passos =
        document.querySelectorAll(".step");

    passos.forEach(function (step, index) {

        if (index < numero) {
            step.classList.add("active");
        } else {
            step.classList.remove("active");
        }

    });

}

/* ============================================================
   LEMBRETES
   ============================================================ */

async function verificarNotificacoesPendentes(){const paciente=JSON.parse(sessionStorage.getItem(STORAGE_PATIENT)||'null');if(!paciente)return;try{const d=await api('get_patient_notifications',{params:{sus:paciente.sus}});const pendentes=(d.notifications||[]).filter(n=>n.status==='pendente');const vistos=JSON.parse(sessionStorage.getItem('acessa_notificacoes_vistas')||'[]');for(const n of pendentes){if(vistos.includes(String(n.id)))continue;if('Notification' in window && Notification.permission==='granted'){new Notification('Acessa+ Saúde',{body:n.mensagem});}vistos.push(String(n.id));}sessionStorage.setItem('acessa_notificacoes_vistas',JSON.stringify(vistos.slice(-100)));const badge=$('notificationCount');if(badge){badge.textContent=String(pendentes.length);badge.classList.toggle('hidden',pendentes.length===0);}}catch(_e){}}

function verificarLembretes() {

    const agora =
        new Date();

    const paciente =
        JSON.parse(
            sessionStorage.getItem(STORAGE_PATIENT)
        );

    if (!paciente) return;

    appointments.forEach(function (consulta) {

        if (
            consulta.status === "cancelado" ||
            !consulta.lembrete ||
            consulta.sus !== paciente.sus
        ) {
            return;
        }

        const dataConsulta =
            new Date(
                consulta.data +
                "T" +
                consulta.horario +
                ":00"
            );

        const diferenca =
            dataConsulta.getTime() -
            agora.getTime();

        /*
          Aproximadamente 24 horas antes.
        */

        const vinteQuatroHoras =
            24 * 60 * 60 * 1000;

        if (
            diferenca > 0 &&
            diferenca <= vinteQuatroHoras &&
            !consulta.notificado
        ) {

            if (
                "Notification" in window &&
                Notification.permission === "granted"
            ) {

                new Notification(
                    "Acessa+ Saúde - Lembrete",
                    {
                        body:
                            "Você tem consulta amanhã às " +
                            consulta.horario +
                            " na " +
                            consulta.ubsNome +
                            "."
                    }
                );

            }

            consulta.notificado = true;

            salvarConsultas();

        }

    });

}

/*
   Verifica os lembretes enquanto
   a página estiver aberta.
*/

setInterval(
    verificarLembretes,
    60000
);

function aplicarConfiguracaoApp(){
    const profissional=appConfig.modo==='profissional';
    document.body.dataset.appMode=appConfig.modo||'ubs';
    document.title=(appConfig.nomeExibicao||'Acessa+ Saúde')+' | Agendamento';
    if ($("modoSistemaLabel")) $("modoSistemaLabel").textContent=profissional?'Modo profissional da clínica':'Modo UBS / rede pública';
    if ($("ubsAtualDashboard")) $("ubsAtualDashboard").textContent=profissional?(appConfig.nomeExibicao||'Profissional'):'UBS de referência: Não informada';
    document.querySelectorAll('.brand img,.hero-logo,.registration-hero-logo').forEach(img=>{
        const fallback=appPath(img.closest('.brand')?'/img/logo-white.png':'/img/logo-transparent.png');
        img.onerror=()=>{img.onerror=null;img.dataset.customLogo='false';img.src=fallback;};
        if(appConfig.logoArquivo){img.dataset.customLogo='true';img.src=appPath('/uploads/marca/'+encodeURIComponent(appConfig.logoArquivo));}
        else img.dataset.customLogo='false';
    });
}
async function carregarConfiguracaoAdmin(){try{const d=await api('get_app_config');const c=d.config||{};['modo','nomeExibicao','especialidade','registroProfissional','telefone','whatsapp','endereco','modalidade','valorConsulta','apresentacao','corPrimaria','corSecundaria'].forEach(k=>{const id={'nomeExibicao':'appNomeExibicao','registroProfissional':'appRegistro','valorConsulta':'appValor','corPrimaria':'appCorPrimaria','corSecundaria':'appCorSecundaria','modo':'appModo','especialidade':'appEspecialidade','telefone':'appTelefone','whatsapp':'appWhatsapp','endereco':'appEndereco','modalidade':'appModalidade','apresentacao':'appApresentacao'}[k];if(id&&$(id))$(id).value=c[k]??'';});}catch(e){toast(e.message);}}
async function salvarConfiguracaoApp(){const body={modo:$("appModo").value,nome_exibicao:$("appNomeExibicao").value.trim(),especialidade:$("appEspecialidade").value.trim(),registro_profissional:$("appRegistro").value.trim(),telefone:$("appTelefone").value.trim(),whatsapp:$("appWhatsapp").value.trim(),endereco:$("appEndereco").value.trim(),modalidade:$("appModalidade").value.trim(),valor_consulta:$("appValor").value,apresentacao:$("appApresentacao").value.trim(),cor_primaria:$("appCorPrimaria").value,cor_secundaria:$("appCorSecundaria").value};if(!body.nome_exibicao){toast('Informe o nome que será exibido.');return;}try{const d=await api('update_app_config',{method:'POST',body});appConfig=d.config||body;aplicarConfiguracaoApp();toast('Modo e personalização salvos.');}catch(e){toast(e.message);}}
async function carregarPagamentosProfissionais(){const box=$("listaPagamentosProfissionais");if(!box||adminSession?.tipo!=="desenvolvedor")return;try{const d=await api('admin_professional_payments');box.innerHTML=(d.payments||[]).length?d.payments.map(p=>`<article class="admin-appointment"><strong>${escapeHTML(p.profissional)} — ${escapeHTML(p.plano||'Plano')}</strong><p>R$ ${Number(p.valor).toFixed(2).replace('.',',')} • ${escapeHTML(p.status)} • ${escapeHTML(p.metodo||'')}</p>${p.status==='pendente'?`<button class="btn primary" onclick="aprovarPagamentoProfissional(${Number(p.id)})">Aprovar pagamento</button>`:''}</article>`).join(''):'<div class="info-box">Nenhum pagamento encontrado.</div>';}catch(e){box.innerHTML='<div class="info-box">'+escapeHTML(e.message)+'</div>';}}
async function aprovarPagamentoProfissional(id){try{await api('admin_update_professional_payment',{method:'POST',body:{id,status:'aprovado'}});toast('Pagamento aprovado e assinatura ativada.');carregarPagamentosProfissionais();}catch(e){toast(e.message);}}

async function carregarAuditoria() {
    if (!adminSession || adminSession.tipo !== "desenvolvedor") return;
    const box = $("listaAuditoria");
    try { const data = await api("admin_audit"); box.innerHTML = (data.events || []).length ? data.events.map(e => `<article class="admin-appointment"><strong>${escapeHTML(e.acao)}</strong><p>${escapeHTML(e.tipoUsuario || "")} • ${escapeHTML(e.criadoEm || "")}</p><p>${escapeHTML(e.entidade || "")} ${escapeHTML(e.entidadeId || "")} ${escapeHTML(e.detalhes || "")}</p></article>`).join("") : '<div class="info-box">Nenhum evento registrado.</div>'; } catch (error) { box.innerHTML = ""; toast(error.message); }
}

function entrarPortalUBS(){
    if (!new URLSearchParams(location.search).get("clinica")) history.replaceState({}, "", appPath("/ubs"));
    document.body.dataset.portal = "ubs"; document.body.classList.remove("clinic-login-route");
    mostrarApenas("cadastro"); definirModoUsuario(false);
}
function voltarEntradaPortais(){
    window.publicClinicSlug=null;document.body.classList.remove('clinic-public-active');document.body.style.removeProperty('--clinic-primary');document.body.style.removeProperty('--clinic-secondary');
    const portal=document.body.dataset.portal||"";
    if(portal==="ubs") { history.replaceState({},"",appPath("/ubs")); mostrarApenas("cadastro"); }
    else if(portal==="clinica") { history.replaceState({},"",appPath("/clinica")); document.body.classList.add("clinic-login-route"); mostrarApenas("portalChooserSection"); $('professionalAuthModal').classList.remove('hidden'); alternarAuthProfissional('login'); }
    else { document.body.dataset.portal=""; history.replaceState({},"",appPath("/")); document.body.classList.remove("clinic-login-route"); document.title=(appConfig.nomeExibicao||'Acessa+ Saúde')+' | Agendamento'; mostrarApenas('portalChooserSection'); }
    atualizarVisibilidadeAcessoAdministrativo();
}
function sairPaginaClinica(){sessionStorage.removeItem('acessa_clinic_public_form');if(professionalSession){void sairProfissional();return;}window.location.assign(appPath('/clinica'));}

function abrirPortalClinicaPorSlug(){const slug=prompt('Cole o link ou informe o identificador público da clínica:');if(!slug)return;const m=slug.match(/clinica=([^&]+)/);carregarClinicaPublica(decodeURIComponent(m?m[1]:slug.trim()));}
async function carregarClinicaPublica(slug){
    try {
        const d=await api('public_clinic',{params:{slug}}),c=d.clinic;window.publicClinicSlug=c.slug;
        document.body.style.setProperty('--clinic-primary',c.corPrimaria||'#0b9f9f');document.body.style.setProperty('--clinic-secondary',c.corSecundaria||'#075e61');document.body.classList.add('clinic-public-active');mostrarApenas('publicClinicSection');atualizarVisibilidadeAcessoAdministrativo();
        $('publicClinicName').textContent=c.nome;$('publicClinicPresentation').textContent=c.apresentacao||'Agende sua consulta na clínica de forma simples.';$('publicClinicDetails').textContent=[c.especialidade,c.modalidade,c.endereco,c.whatsapp||c.telefone,c.horarioFuncionamento?'Horário: '+c.horarioFuncionamento:''].filter(Boolean).join(' • ');
        const notice=$('publicClinicNotice');if(notice)notice.textContent=c.avisoPublico||'';
        const clinicLogo=$('publicClinicLogo');if(clinicLogo){clinicLogo.onerror=()=>{clinicLogo.onerror=null;clinicLogo.src=appPath('/img/logo-transparent.png');};clinicLogo.src=c.logoArquivo?appPath('/uploads/marca/'+encodeURIComponent(c.logoArquivo)):appPath('/img/logo-transparent.png');}
        document.title=c.nome+' | Agendamento';
        const submit=document.querySelector('.public-clinic-form .btn.primary');if(submit)submit.innerHTML=c.confirmacaoAutomatica?'Agendar consulta <span>→</span>':'Solicitar horário <span>→</span>';
        $('publicPatientDate').min=hojeISO();
        const urlParams=new URLSearchParams(location.search);const token=urlParams.get('gerenciar')||sessionStorage.getItem('acessa_clinic_management_token');
        history.replaceState({},'',location.pathname+'?clinica='+encodeURIComponent(c.slug));
        await carregarHorariosDisponiveis();
        if(token){clinicManagementToken=token;sessionStorage.setItem('acessa_clinic_management_token',token);await carregarGerenciamentoClinica(token);}
    }catch(e){
        const title=$('appLoadingTitle');
        const message=$('appLoadingMessage');
        if(title)title.textContent='Não foi possível abrir esta clínica';
        if(message)message.textContent='Verifique o link público e tente novamente. Se o problema continuar, fale com a clínica.';
        const loading=$('appLoadingSection');
        if(loading)loading.setAttribute('aria-busy','false');
        mostrarApenas('appLoadingSection');
        toast(e.message||'Não foi possível carregar a clínica.');
    }
}
async function carregarHorariosDisponiveis(){
    const date=$('publicPatientDate')?.value,select=$('publicPatientTime'),help=$('publicSlotsHelp');if(!select)return;
    select.innerHTML='<option value="">Carregando horários...</option>';select.disabled=true;
    if(!date||!window.publicClinicSlug){select.innerHTML='<option value="">Escolha uma data primeiro</option>';if(help)help.textContent='A clínica exibirá somente horários livres.';return;}
    try{const d=await api('public_clinic_slots',{params:{slug:window.publicClinicSlug,data:date}});const slots=d.slots||[];select.innerHTML=slots.length?'<option value="">Selecione um horário</option>'+slots.map(s=>`<option value="${escapeAttr(s.horario)}">${escapeHTML(s.horario)}</option>`).join(''):'<option value="">Sem horários disponíveis nesta data</option>';select.disabled=!slots.length;if(help)help.textContent=slots.length?`${slots.length} horário(s) livre(s). A vaga é validada novamente ao confirmar.`:'Não há horários livres. Escolha outra data ou fale com a clínica.';}catch(e){select.innerHTML='<option value="">Não foi possível carregar</option>';if(help)help.textContent=e.message;}
}
async function entrarListaEsperaClinica(body=window.publicWaitlistData){if(!body)return;try{const d=await api('public_clinic_join_waitlist',{method:'POST',body});const box=$('publicClinicConfirmation');if(box){box.innerHTML=`<strong>✓ Você entrou na lista de espera</strong><p>${escapeHTML(d.message||'Seu pedido foi registrado.')}</p>`;box.classList.remove('hidden');box.scrollIntoView({behavior:'smooth',block:'center'});}toast(d.message);}catch(e){toast(e.message);}}
async function solicitarConsultaClinica(){
    const body={slug:window.publicClinicSlug,nome:$('publicPatientName').value.trim(),cpf:normalizarCpf($('publicPatientCpf').value),email:$('publicPatientEmail').value.trim(),telefone:$('publicPatientPhone').value.trim(),data_consulta:$('publicPatientDate').value,horario:$('publicPatientTime').value,assunto:$('publicPatientSubject').value.trim()};
    if(!body.nome||!validarCpf(body.cpf)||!body.email||!body.telefone||!body.data_consulta||!body.horario){toast('Preencha um CPF válido, nome, e-mail, celular, data e horário disponível.');return;}
    const botao=document.querySelector('.public-clinic-form .btn.primary');if(botao)botao.disabled=true;
    try{const d=await api('public_clinic_book',{method:'POST',body});const c=d.confirmation||{};clinicManagementToken=d.manage_token||null;if(clinicManagementToken)sessionStorage.setItem('acessa_clinic_management_token',clinicManagementToken);
        const box=$('publicClinicConfirmation');if(box){box.innerHTML=`<strong>✓ ${c.status==='confirmada'?'Consulta confirmada':'Solicitação recebida'}</strong><p>${escapeHTML(d.message||'Seu agendamento foi registrado.')}</p><p><b>Protocolo:</b> ${escapeHTML(c.id||d.appointment_id||'')}</p><p><b>Data:</b> ${formatarDataBR(c.date||body.data_consulta)} às ${escapeHTML(c.time||body.horario)}</p><p>${c.email_sent?'Enviamos uma confirmação para o e-mail informado.':'O e-mail automático da clínica ainda não está configurado; guarde o acesso de gerenciamento abaixo.'}</p><button class="btn secondary" type="button" onclick="carregarGerenciamentoClinica()">Ver opções de cancelamento e remarcação</button>`;box.classList.remove('hidden');box.scrollIntoView({behavior:'smooth',block:'center'});}
        $('publicPatientSubject').value='';toast(d.message||'Solicitação registrada.');
    }catch(e){if(e.data?.listaEspera){window.publicWaitlistData=body;const box=$('publicClinicConfirmation');if(box){box.innerHTML=`<strong>Lista de espera disponível</strong><p>${escapeHTML(e.data.message)}</p><button class="btn primary" onclick="entrarListaEsperaClinica()">Sim, quero entrar na lista de espera</button>`;box.classList.remove('hidden');box.scrollIntoView({behavior:'smooth',block:'center'});}}else{toast(e.message||'Não foi possível agendar.');if(e.data?.code==='SLOT_UNAVAILABLE')carregarHorariosDisponiveis();}}
    finally{if(botao)botao.disabled=false;}
}
async function carregarGerenciamentoClinica(token=clinicManagementToken){
    token=token||sessionStorage.getItem('acessa_clinic_management_token');if(!token){toast('Link de gerenciamento não encontrado.');return;}clinicManagementToken=token;
    try{
        const d=await api('public_clinic_manage',{method:'POST',body:{token,operation:'view'}}),a=d.appointment,box=$('publicClinicConfirmation');if(!box)return;
        if(window.publicClinicSlug&&a.slug!==window.publicClinicSlug){clinicManagementToken=null;sessionStorage.removeItem('acessa_clinic_management_token');toast('Este link pertence a outra clínica. Abra o link original do agendamento.');return;}
        const encerrado=['cancelada','atendida','faltou'].includes(a.status);
        const actions=encerrado?'<p>Este agendamento está encerrado e não pode mais ser alterado.</p>':`<div class="form-grid"><div><label>Nova data</label><input id="publicManageDate" type="date" min="${hojeISO()}" value="${escapeAttr(a.data)}" onchange="carregarHorariosRemarcacao()"></div><div><label>Horário livre</label><select id="publicManageTime"><option value="">Selecione uma data</option></select></div></div><div class="appointment-actions"><button type="button" class="btn primary" onclick="remarcarAgendamentoPublico()">Remarcar</button><button type="button" class="btn danger" onclick="cancelarAgendamentoPublico()">Cancelar consulta</button><button type="button" class="btn secondary" onclick="copiarLinkGerenciamento()">Copiar link de gerenciamento</button></div>`;
        box.innerHTML=`<strong>Gerenciar agendamento</strong><p><b>${escapeHTML(a.clinica)}</b> • ${formatarDataBR(a.data)} às ${escapeHTML(a.horario)}</p><p>Protocolo: ${escapeHTML(a.id)} • Status: ${escapeHTML(statusConsultaLabel(a.status))}</p><p>Cancelamento/remarcação on-line disponível até ${Number(a.cancelamentoAteHoras)} h / ${Number(a.remarcacaoAteHoras)} h antes do horário.</p>${actions}`;
        box.classList.remove('hidden');if(!encerrado)await carregarHorariosRemarcacao();
    }catch(e){toast(e.message);}
}
async function carregarHorariosRemarcacao(){const date=$('publicManageDate')?.value,select=$('publicManageTime');if(!select||!date)return;select.innerHTML='<option value="">Carregando...</option>';try{const d=await api('public_clinic_slots',{params:{slug:window.publicClinicSlug,data:date}});select.innerHTML=(d.slots||[]).length?'<option value="">Selecione um horário</option>'+d.slots.map(s=>`<option value="${escapeAttr(s.horario)}">${escapeHTML(s.horario)}</option>`).join(''):'<option value="">Sem horários livres</option>';}catch(e){select.innerHTML='<option value="">Erro ao carregar</option>';}}
async function cancelarAgendamentoPublico(){if(!confirm('Cancelar esta consulta?'))return;try{const d=await api('public_clinic_manage',{method:'POST',body:{token:clinicManagementToken,operation:'cancel'}});toast(d.message);await carregarGerenciamentoClinica();}catch(e){toast(e.message);}}
async function remarcarAgendamentoPublico(){const data=$('publicManageDate')?.value,horario=$('publicManageTime')?.value;if(!data||!horario){toast('Escolha uma nova data e um horário livre.');return;}try{const d=await api('public_clinic_manage',{method:'POST',body:{token:clinicManagementToken,operation:'reschedule',data_consulta:data,horario}});toast(d.message);await carregarGerenciamentoClinica();}catch(e){toast(e.message);}}
async function copiarLinkGerenciamento(){const url=location.origin+location.pathname+'?clinica='+encodeURIComponent(window.publicClinicSlug)+'&gerenciar='+encodeURIComponent(clinicManagementToken||'');try{await navigator.clipboard.writeText(url);toast('Link de gerenciamento copiado. Guarde-o em local privado.');}catch(_e){prompt('Copie e guarde este link privado:',url);}}


async function restaurarSessaoProfissional() {
    try {
        const data = await apiWithTransientRetry("professional_me");
        if (!data.professional || !data.professional.id) return false;
        professionalSession = data.professional;
        atualizarLinkPublicoClinica(data.professional.slug || "");
        return true;
    } catch (error) {
        if (Number(error?.data?.httpStatus || 0) !== 401) {
            toast(error?.message || "Não foi possível verificar a sessão profissional.");
        }
        return false;
    }
}

function abrirLoginProfissional(){
    fecharLogin();
    if (!new URLSearchParams(location.search).get("clinica")) {
        const currentPortal=document.body.dataset.portal||"";
        if(currentPortal!=="clinica") window.clinicLoginReturnPath=location.pathname.endsWith("/ubs")?"/ubs":"/";
        document.body.dataset.portal="clinica"; document.body.classList.add("clinic-login-route"); history.replaceState({},"",appPath("/clinica"));
    }
    $('professionalAuthModal').classList.remove('hidden');alternarAuthProfissional('login');$('professionalEmail').focus();
}
function alternarAuthProfissional(mode){const signup=mode==='signup';$('professionalSignupFields').classList.toggle('hidden',!signup);$('professionalLoginButton').classList.toggle('hidden',signup);$('professionalSignupButton').classList.toggle('hidden',!signup);$('professionalRecoveryActions')?.classList.toggle('hidden',signup);$('profAuthLoginTab').classList.toggle('active',!signup);$('profAuthSignupTab').classList.toggle('active',signup);}
function fecharLoginProfissional(){
    $('professionalAuthModal').classList.add('hidden');
    if(professionalSession){document.body.classList.remove("clinic-login-route");return;}
    const back=window.clinicLoginReturnPath||"/";document.body.classList.remove("clinic-login-route");
    if(back==="/ubs"){document.body.dataset.portal="ubs";history.replaceState({},"",appPath("/ubs"));mostrarApenas("cadastro");}
    else{document.body.dataset.portal="";history.replaceState({},"",appPath("/"));mostrarApenas("portalChooserSection");}
}
async function loginProfissional(){
    const email=$('professionalEmail').value.trim(),senha=$('professionalSenha').value;
    if(!email||!senha){toast('Informe e-mail e senha.');return;}
    const button=$('professionalLoginButton'),original=button?.innerHTML;
    if(button){button.disabled=true;button.setAttribute('aria-busy','true');button.textContent='Entrando…';}
    try{const d=await api('professional_login',{method:'POST',body:{email,senha}});professionalSession=d.professional;atualizarLinkPublicoClinica(d.professional?.slug);fecharLoginProfissional();abrirPortalProfissional('dashboard');}
    catch(e){toast(e.message);}
    finally{if(button){button.disabled=false;button.removeAttribute('aria-busy');button.innerHTML=original;}}
}
async function cadastrarProfissional(){
    const nome=$('professionalNome').value.trim(),email=$('professionalEmail').value.trim(),senha=$('professionalSenha').value;
    if(nome.length<2){toast('Informe o nome profissional ou da clínica.');$('professionalNome').focus();return;}
    if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){toast('Informe um e-mail válido.');$('professionalEmail').focus();return;}
    if(senha.length<8){toast('A senha precisa ter pelo menos 8 caracteres.');$('professionalSenha').focus();return;}
    const button=$('professionalSignupButton'),original=button?.innerHTML;
    if(button){button.disabled=true;button.setAttribute('aria-busy','true');button.textContent='Criando cadastro…';}
    try{
        const d=await api('professional_register',{method:'POST',body:{nome,email,senha,especialidade:$('professionalEspecialidade').value.trim()}});
        professionalSession=d.professional;atualizarLinkPublicoClinica(d.professional?.slug);
        fecharLoginProfissional();
        await carregarPerfilProfissional();
        abrirPortalProfissional('dashboard');
        toast(d.message||'Conta profissional criada. Complete os dados e configure o expediente em Minha marca.');
    }catch(e){toast(e.message);}
    finally{if(button){button.disabled=false;button.removeAttribute('aria-busy');button.innerHTML=original;}}
}
async function solicitarRecuperacaoSenha(){const email=$('professionalEmail').value.trim(),status=$('professionalRecoveryMessage');if(!email){if(status)status.textContent='Informe seu e-mail profissional acima para receber o link de recuperação.';$('professionalEmail')?.focus();return;}if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){if(status)status.textContent='Digite um e-mail válido.';return;}const button=document.querySelector('#professionalRecoveryActions button');if(button){button.disabled=true;button.setAttribute('aria-busy','true');}if(status)status.textContent='Solicitando link de recuperação…';try{const d=await api('professional_request_password_reset',{method:'POST',body:{email}});if(status)status.textContent=d.message||'Se o e-mail estiver cadastrado, enviaremos as instruções.';toast(d.message);}catch(e){if(status)status.textContent=e.message||'Não foi possível solicitar a recuperação.';toast(e.message);}finally{if(button){button.disabled=false;button.removeAttribute('aria-busy');}}}

function fecharRedefinicaoSenha(){$('professionalResetModal').classList.add('hidden');}
async function confirmarRedefinicaoSenha(){const token=$('professionalResetToken').value,password=$('professionalNewPassword').value,confirmPassword=$('professionalConfirmPassword').value;if(password.length<8){toast('A nova senha precisa ter pelo menos 8 caracteres.');return;}if(password!==confirmPassword){toast('As senhas não conferem.');return;}try{const d=await api('professional_reset_password',{method:'POST',body:{token,senha:password}});fecharRedefinicaoSenha();toast(d.message);}catch(e){toast(e.message);}}
async function sairProfissional(){
    const logged=!!professionalSession;try{if(logged)await api('professional_logout',{method:'POST',body:{}});}catch(e){console.warn('Falha ao encerrar sessão da clínica no servidor.',e);}
    professionalSession=null;professionalPatients=[];clinicManagementToken=null;sessionStorage.removeItem('acessa_clinic_management_token');
    if($("adminTopEntry")){$("adminTopEntry").classList.remove('hidden');$("adminTopEntry").style.display='';}
    if(document.body.dataset.portal==="clinica"){abrirLoginProfissional();toast('Sessão da clínica encerrada.');return;}
    fecharLoginProfissional();voltarEntradaPortais();toast('Sessão da clínica encerrada.');
}
async function abrirPortalProfissional(tab='dashboard'){document.body.classList.remove('clinic-login-route');if(!professionalSession){abrirLoginProfissional();return;}if($("adminTopEntry")){ $("adminTopEntry").classList.add("hidden"); $("adminTopEntry").style.display="none"; }mostrarApenas('professionalSection');document.querySelectorAll('.professional-tab').forEach(x=>x.classList.add('hidden'));const id='prof'+tab.charAt(0).toUpperCase()+tab.slice(1);if($(id))$(id).classList.remove('hidden');$('professionalNomeTopo').textContent=professionalSession.nome||'profissional';if(tab==='dashboard'||tab==='pacientes'||tab==='agenda'||tab==='financeiro'||tab==='relacionamento')carregarPortalProfissional();if(tab==='relacionamento')setTimeout(carregarRelacionamentoProfissional,50);if(tab==='financeiro')setTimeout(carregarFinanceiroProfissional,50);if(tab==='marca')carregarPerfilProfissional();if(tab==='assinatura')carregarPlanosProfissional();if(tab==='agenda'&&typeof window.carregarListaEsperaProfissional==='function')setTimeout(window.carregarListaEsperaProfissional,50);if(tab==='waitlist'&&typeof window.carregarListaEsperaProfissional==='function')window.carregarListaEsperaProfissional();if(tab==='suporte')carregarSuporteClinica();}
async function carregarListaEsperaProfissional(){const box=$('profWaitlistList');if(!box)return;box.innerHTML='<div class="info-box">Carregando lista de espera…</div>';try{const d=await api('professional_waitlist');const rows=d.waitlist||[];box.innerHTML=rows.length?rows.map(w=>`<article class="admin-appointment"><strong>${escapeHTML(w.codigo||'PAC')} — ${escapeHTML(w.nome)}</strong><p>${formatarDataBR(w.data)} • ${escapeHTML(w.telefone||'Sem telefone')}</p><small>Entrada: ${escapeHTML(w.criadoEm||'')}</small><div class="appointment-actions"><button class="btn danger" type="button" onclick="cancelarEsperaProfissional(${Number(w.id)})">Remover da fila</button></div></article>`).join(''):'<div class="info-box">Nenhum paciente aguardando.</div>';}catch(e){box.innerHTML=`<div class="info-box">${escapeHTML(e.message||'Não foi possível carregar a lista de espera.')}</div>`;}}
async function cancelarEsperaProfissional(id){if(!Number.isInteger(Number(id))||Number(id)<1)return;if(!confirm('Remover este paciente da lista de espera?'))return;try{const d=await api('professional_cancel_waitlist',{method:'POST',body:{id:Number(id)}});toast(d.message);await carregarListaEsperaProfissional();}catch(e){toast(e.message);}}
async function carregarPortalProfissional(){
    const calendarBox=$('professionalCalendar');
    const patientsBox=$('profPatientsList');
    const appointmentsBox=$('profAppointmentsList');
    const patientSelect=$('profAgendaPaciente');
    professionalPatients=[];
    window.professionalAppointments=[];
    if(calendarBox)renderCalendarioProfissional([]);
    if(patientsBox)patientsBox.innerHTML='<div class="info-box">Carregando pacientes...</div>';
    if(appointmentsBox)appointmentsBox.innerHTML='<div class="info-box">Carregando consultas...</div>';
    if(patientSelect){patientSelect.disabled=true;patientSelect.innerHTML='<option value="">Carregando pacientes...</option>';}
    if($('profCountPatients'))$('profCountPatients').textContent='…';
    if($('profCountAppointments'))$('profCountAppointments').textContent='…';

    const [patientsResult,appointmentsResult,profileResult]=await Promise.allSettled([
        api('professional_patients'),
        api('professional_appointments'),
        api('professional_me')
    ]);
    const errors=[];

    if(patientsResult.status==='fulfilled'){
        professionalPatients=Array.isArray(patientsResult.value.patients)?patientsResult.value.patients:[];
        if($('profCountPatients'))$('profCountPatients').textContent=professionalPatients.length;
        renderPatientsProfissional();
        preencherSelectsProfissional();
        if(patientSelect)patientSelect.disabled=professionalPatients.length===0;
    }else{
        if($('profCountPatients'))$('profCountPatients').textContent='—';
        if(patientsBox)patientsBox.innerHTML='<div class="info-box">Não foi possível carregar os pacientes. Clique em Atualizar para tentar novamente.</div>';
        if(patientSelect){patientSelect.disabled=true;patientSelect.innerHTML='<option value="">Pacientes indisponíveis</option>';}
        errors.push(patientsResult.reason);
    }

    if(appointmentsResult.status==='fulfilled'){
        const appointments=Array.isArray(appointmentsResult.value.appointments)?appointmentsResult.value.appointments:[];
        if($('profCountAppointments'))$('profCountAppointments').textContent=appointments.length;
        renderAppointmentsProfissional(appointments);
        renderCalendarioProfissional(appointments);
    }else{
        if($('profCountAppointments'))$('profCountAppointments').textContent='—';
        renderAppointmentsProfissional([]);
        renderCalendarioProfissional([]);
        if(appointmentsBox)appointmentsBox.innerHTML='<div class="info-box">Não foi possível carregar as consultas. A grade do calendário continua visível; clique em Atualizar para tentar novamente.</div>';
        errors.push(appointmentsResult.reason);
    }

    if(profileResult.status==='fulfilled'&&profileResult.value.professional){
        professionalSession=profileResult.value.professional;
        const name=$('professionalNomeTopo');
        if(name)name.textContent=professionalSession.nome||'profissional';
    }else{
        errors.push(profileResult.status==='rejected'?profileResult.reason:new Error('Não foi possível carregar o perfil profissional.'));
    }

    if(errors.length){
        const message=errors.find(error=>error&&error.message)?.message||'Parte dos dados da clínica não pôde ser carregada.';
        toast(message);
    }
}
function renderPatientsProfissional(){
 const box=$('profPatientsList');
 box.innerHTML=professionalPatients.length?professionalPatients.map(p=>`<article class="admin-appointment"><strong>${escapeHTML(p.codigo||'PAC')} — ${escapeHTML(p.nome)}</strong><p>Contato: ${escapeHTML(p.email||'')} • ${escapeHTML(p.telefone||'')} • CPF: ${escapeHTML(formatarCpf(p.cpf||''))}</p><button class="btn secondary" onclick="abrirHistoricoPaciente(${Number(p.id)})">Histórico de consultas</button></article>`).join(''):'<div class="info-box">Nenhum paciente vinculado. Use o Cartão SUS ou cadastre um paciente novo.</div>';
}
function statusConsultaLabel(s){return ({solicitada:'Solicitada',agendada:'Agendada',confirmada:'Confirmada',atendida:'Atendida',cancelada:'Cancelada',faltou:'Não compareceu'}[s]||s||'');}
function escapeAttr(v){return escapeHTML(v).replaceAll('`','&#096;');}
function renderAppointmentsProfissional(list){const box=$('profAppointmentsList');if(!box)return;const selected=agendaDiaAtual;const filtered=selected?list.filter(a=>a.data===selected):list;box.innerHTML=filtered.length?filtered.map(a=>{const status=a.status||'confirmada';return `<article class="admin-appointment clinic-appointment status-${escapeAttr(status)}"><div class="appointment-topline"><strong>${escapeHTML(a.paciente)}</strong><span class="status confirmado">${statusConsultaLabel(status)}</span></div><p>${escapeHTML(formatarDataBR(a.data))} às ${escapeHTML(a.horario)} • ${escapeHTML(a.telefone||'')}</p><p>${escapeHTML(a.assunto||'Sem assunto')}</p><p class="notification-planned">${status==='solicitada'?'Aguardando confirmação da clínica':'Confirmação registrada'}</p><div class="appointment-actions">${status==='solicitada'?`<button class="btn primary" onclick="confirmarSolicitacaoClinica('${escapeAttr(a.id)}')">Confirmar solicitação</button>`:''}<button class="btn secondary" onclick="reagendarConsulta('${escapeAttr(a.id)}','${escapeAttr(a.data)}','${escapeAttr(a.horario)}')">Reagendar</button><button class="btn danger" onclick="cancelarConsultaProfissional('${escapeAttr(a.id)}')">Cancelar</button><button class="btn secondary" onclick="enviarConfirmacaoWhatsApp('${escapeAttr(a.id)}')">Confirmação WhatsApp</button></div></article>`;}).join(''):'<div class="info-box">Nenhuma consulta para este dia.</div>';window.professionalAppointments=list;}
function renderCalendarioProfissional(list){const box=$('professionalCalendar');if(!box)return;const y=agendaMesAtual.getFullYear(),m=agendaMesAtual.getMonth(),first=new Date(y,m,1),last=new Date(y,m+1,0),days=['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];$('agendaMesTitulo').textContent=first.toLocaleDateString('pt-BR',{month:'long',year:'numeric'});let html=days.map(d=>`<div class="calendar-weekday">${d}</div>`).join('');for(let i=0;i<first.getDay();i++)html+='<div class="calendar-cell empty"></div>';for(let d=1;d<=last.getDate();d++){const iso=`${y}-${String(m+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;const events=list.filter(a=>a.data===iso);const cls=events.some(a=>a.status==='cancelada')?'has-canceled':events.length?'has-confirmed':'';html+=`<button class="calendar-cell ${cls} ${agendaDiaAtual===iso?'selected':''}" onclick="selecionarDiaAgenda('${iso}')"><b>${d}</b><span>${events.length?events.length+' consulta'+(events.length>1?'s':''):''}</span>${events.slice(0,3).map(a=>`<i class="calendar-event ${a.status==='atendida'?'attended':a.status==='cancelada'?'canceled':'confirmed'}">${escapeHTML((a.horario||'').slice(0,5))} ${escapeHTML(a.paciente||'')}</i>`).join('')}</button>`;}box.innerHTML=html;const info=$('agendaDiaSelecionado');if(info)info.innerHTML=agendaDiaAtual?`<strong>Dia selecionado:</strong> ${formatarDataBR(agendaDiaAtual)}. Clique em uma data para ver as consultas.`:'<strong>Selecione um dia</strong> para filtrar as consultas abaixo.';}
function mudarMesAgenda(delta){agendaMesAtual=new Date(agendaMesAtual.getFullYear(),agendaMesAtual.getMonth()+delta,1);agendaDiaAtual=null;renderCalendarioProfissional(window.professionalAppointments||[]);renderAppointmentsProfissional(window.professionalAppointments||[]);}
function selecionarDiaAgenda(iso){agendaDiaAtual=agendaDiaAtual===iso?null:iso;const a=window.professionalAppointments||[];renderCalendarioProfissional(a);renderAppointmentsProfissional(a);if($('profAgendaData')&&iso)$('profAgendaData').value=iso;}

async function confirmarSolicitacaoClinica(id){const a=(window.professionalAppointments||[]).find(x=>x.id===id);if(!a)return;try{await api('professional_update_appointment',{method:'POST',body:{id,status:'confirmada',data_consulta:a.data,horario:a.horario}});toast('Consulta confirmada; aviso enviado ao paciente quando o e-mail está configurado.');await carregarAgendaProfissional();}catch(e){toast(e.message);}}
async function salvarStatusConsulta(id){const a=(window.professionalAppointments||[]).find(x=>x.id===id);if(!a)return;try{await api('professional_update_appointment',{method:'POST',body:{id,status:$('status-'+id).value,forma_pagamento:$('method-'+id).value,pagamento_status:$('payment-'+id).value,data_consulta:a.data,horario:a.horario}});toast('Consulta atualizada.');carregarAgendaProfissional();}catch(e){toast(e.message);}}
async function cancelarConsultaProfissional(id){const motivo=prompt('Informe o motivo do cancelamento:', '');if(motivo===null)return;const a=(window.professionalAppointments||[]).find(x=>x.id===id);try{await api('professional_update_appointment',{method:'POST',body:{id,status:'cancelada',cancelamento_motivo:motivo,data_consulta:a.data,horario:a.horario}});toast('Consulta cancelada.');carregarAgendaProfissional();}catch(e){toast(e.message);}}
async function reagendarConsulta(id,data,horario){const novaData=prompt('Nova data (AAAA-MM-DD):',data);if(novaData===null)return;const novoHorario=prompt('Novo horário (HH:MM):',horario.slice(0,5));if(novoHorario===null)return;const a=(window.professionalAppointments||[]).find(x=>x.id===id);try{await api('professional_update_appointment',{method:'POST',body:{id,status:'agendada',data_consulta:novaData,horario:novoHorario,forma_pagamento:a.formaPagamento||'',pagamento_status:a.pagamentoStatus||''}});toast('Consulta reagendada.');carregarAgendaProfissional();}catch(e){toast(e.message);}}
function renderFinanceAppointments(list){const box=$('financeAppointmentsList');if(!box)return;const open=list.filter(a=>!['cancelada','faltou'].includes(a.status));box.innerHTML=open.length?`<div class="admin-section-title"><h3>Consultas para lançar pagamento</h3></div>`+open.map(a=>{const paid=a.pagamentoStatus==='pago';return `<article class="admin-appointment"><strong>${escapeHTML(a.paciente)}</strong><p>${escapeHTML(formatarDataBR(a.data))} às ${escapeHTML(a.horario)} • ${escapeHTML(paid?'Pagamento finalizado':'Pagamento pendente')}</p>${paid?`<small>Lançamento financeiro finalizado e bloqueado.</small><div class="receipt-actions"><button class="btn secondary" onclick="gerarReciboConsulta('${escapeAttr(a.id)}')">Imprimir comprovante</button><button class="btn primary" onclick="enviarReciboWhatsApp('${escapeAttr(a.id)}')">Enviar comprovante pelo WhatsApp</button></div>`:`<button class="btn primary" onclick="abrirPagamento('${escapeAttr(a.id)}',${Number(a.patientId||0)})">Registrar pagamento</button>`}</article>`;}).join(''):'<div class="info-box">Nenhuma consulta disponível para lançamento financeiro.</div>';}
function dadosReciboConsulta(id){return (window.professionalAppointments||[]).find(x=>x.id===id);}
function reciboHTML(a){return `<html><head><title>Recibo ${escapeHTML(a.id)}</title><style>body{font-family:Arial;color:#16474b;padding:35px;max-width:720px;margin:auto}h1{color:#087c7f}hr{border:0;border-top:1px solid #ddd}.clinic{background:#f3fafa;padding:16px;border-radius:10px;margin-bottom:15px}.clinic strong{font-size:18px}.row{padding:9px 0;border-bottom:1px solid #eee;display:flex;justify-content:space-between;gap:20px}</style></head><body><h1>Recibo de atendimento</h1><div class="clinic"><strong>${escapeHTML(a.clinicaNome||professionalSession?.nome||'Clínica')}</strong><div>CNPJ: ${escapeHTML(a.clinicaCnpj||'Não informado')}</div><div>Endereço: ${escapeHTML(a.clinicaEndereco||'Não informado')}</div><div>Telefone: ${escapeHTML(a.clinicaTelefone||'Não informado')}</div></div><hr><div class="row"><b>Paciente</b><span>${escapeHTML(a.codigo||'PAC')} — ${escapeHTML(a.paciente)}</span></div><div class="row"><b>Protocolo</b><span>${escapeHTML(a.id)}</span></div><div class="row"><b>Data e horário</b><span>${escapeHTML(formatarDataBR(a.data))} às ${escapeHTML(a.horario)}</span></div><div class="row"><b>Valor</b><span>R$ ${Number(a.reciboValor??a.valor??0).toFixed(2).replace('.',',')}</span></div><div class="row"><b>Pagamento</b><span>${escapeHTML(a.pagamentoStatus==='pago'?'Pago':a.pagamentoStatus==='dispensado'?'Dispensado':'Pendente')} • ${escapeHTML(a.formaPagamento||'Não informado')}</span></div><p>Documento gerado pelo sistema para impressão ou envio ao paciente.</p></body></html>`;}
function gerarReciboConsulta(id){const a=dadosReciboConsulta(id);if(!a)return;const w=window.open('','_blank');if(!w){toast('Permita pop-ups para gerar o recibo.');return;}w.document.write(reciboHTML(a));w.document.close();w.focus();setTimeout(()=>w.print(),250);}
function enviarReciboWhatsApp(id){const a=dadosReciboConsulta(id);if(!a)return;const phone=String(a.telefone||'').replace(/\D/g,'');if(!phone){toast('Este paciente não possui celular cadastrado.');return;}const msg=`Olá, ${a.paciente}! Segue o recibo da consulta ${a.id}. ${a.clinicaNome||professionalSession?.nome||'Clínica'} | CNPJ: ${a.clinicaCnpj||'Não informado'} | Endereço: ${a.clinicaEndereco||'Não informado'} | Telefone: ${a.clinicaTelefone||'Não informado'}. Data: ${formatarDataBR(a.data)} às ${a.horario}. Valor: R$ ${Number(a.reciboValor??a.valor??0).toFixed(2).replace('.',',')}. Pagamento: ${a.pagamentoStatus==='pago'?'Pago':a.pagamentoStatus==='dispensado'?'Dispensado':'Pendente'}.`;window.open('https://wa.me/55'+phone+'?text='+encodeURIComponent(msg),'_blank');}

async function cadastrarPacienteNovo(){
    const body={nome:$('novoPacienteNome').value.trim(),telefone:$('novoPacienteTelefone').value.trim(),cpf:normalizarCpf($('novoPacienteCpf').value),email:$('novoPacienteEmail').value.trim(),sus:$('novoPacienteSus').value.trim()};
    if(!body.nome){toast('Informe o nome do paciente.');return;}
    try{const d=await api('professional_create_patient',{method:'POST',body});toast(`Paciente cadastrado: ${d.patient.codigo}`);['novoPacienteNome','novoPacienteTelefone','novoPacienteCpf','novoPacienteEmail','novoPacienteSus'].forEach(id=>$(id).value='');await carregarPortalProfissional();}catch(e){toast(e.message);}
}
async function abrirHistoricoPaciente(id){
    const box=$('profPatientHistory');if(!box)return;
    try{
        const d=await api('professional_patient_history',{params:{patient_id:id}});const p=d.patient||{};
        box.classList.remove('hidden');
        box.innerHTML=`<div class="info-box"><strong>${escapeHTML(p.codigo||'PAC')} — ${escapeHTML(p.nome||'')}</strong><p>${escapeHTML(p.telefone||'')} • ${escapeHTML(p.email||'')}</p></div>`+
        `<h4>Todas as consultas deste paciente</h4>`+
        ((d.appointments||[]).filter(a=>a.consultaId).map(a=>`<article class="admin-appointment"><strong>${escapeHTML(formatarDataBR(a.data))} às ${escapeHTML(a.horario)}</strong><p>${escapeHTML(a.assunto||'Sem assunto')} • ${statusConsultaLabel(a.status)}</p><p>Status da consulta: ${statusConsultaLabel(a.status)}</p></article>`).join('')||'<div class="info-box">Nenhuma consulta registrada.</div>');
    }catch(e){toast(e.message);}
}
async function gerarRelatorioDiario(){const date=$('finRelatorioData')?.value;if(!date){toast('Escolha a data do relatório.');return;}try{const d=await api('professional_daily_report',{params:{data:date}});const rows=(d.payments||[]).map(p=>`<tr><td>${escapeHTML(p.data)}</td><td>${escapeHTML(p.paciente)}</td><td>${escapeHTML(p.formaPagamento)}</td><td>R$ ${Number(p.valor).toFixed(2).replace('.',',')}</td></tr>`).join('');const formas=Object.entries(d.porForma||{}).map(([k,v])=>`<li>${escapeHTML(k)}: R$ ${Number(v).toFixed(2).replace('.',',')}</li>`).join('');const w=window.open('','_blank');if(!w){toast('Permita pop-ups para gerar o relatório.');return;}w.document.write(`<html><head><title>Relatório financeiro ${date}</title><style>body{font-family:Arial;color:#16474b;padding:30px}table{width:100%;border-collapse:collapse;margin-top:20px}th,td{padding:9px;border-bottom:1px solid #ddd;text-align:left}h1{color:#087c7f}</style></head><body><h1>Relatório financeiro diário</h1><p>Data: ${formatarDataBR(date)} • Clínica: ${escapeHTML(professionalSession?.nome||'')}</p><h2>Total recebido: R$ ${Number(d.total||0).toFixed(2).replace('.',',')}</h2><ul>${formas||'<li>Nenhum recebimento</li>'}</ul><table><thead><tr><th>Horário</th><th>Paciente</th><th>Forma</th><th>Valor</th></tr></thead><tbody>${rows||'<tr><td colspan=4>Nenhum pagamento registrado.</td></tr>'}</tbody></table></body></html>`);w.document.close();w.focus();setTimeout(()=>w.print(),250);}catch(e){toast(e.message);}}
function enviarConfirmacaoWhatsApp(id){const a=dadosReciboConsulta(id);if(!a)return;const phone=String(a.telefone||'').replace(/\D/g,'');if(!phone){toast('Este paciente não possui celular cadastrado.');return;}const msg=`Olá, ${a.paciente}! Sua consulta na ${a.clinicaNome||professionalSession?.nome||'clínica'} está confirmada para ${formatarDataBR(a.data)} às ${a.horario}.`;window.open('https://wa.me/55'+phone+'?text='+encodeURIComponent(msg),'_blank');}
function abrirPagamento(consultaId,patientId){
    $('pagConsultaId').value=consultaId;$('pagPacienteId').value=patientId;$('pagValor').value='';
    $('pagForma').value='pix';atualizarCamposCartao();$('paymentModal').classList.remove('hidden');
}
function fecharPagamento(){$('paymentModal').classList.add('hidden');}
function atualizarCamposCartao(){
    const cartao=$('pagForma').value==='cartao';$('pagCartaoFields').classList.toggle('hidden',!cartao);
    const tipo=$('pagTipoCartao').value;const sel=$('pagParcelas');
    sel.innerHTML='';for(let i=1;i<=12;i++)sel.innerHTML+=`<option value="${i}" ${tipo==='debito'&&i===1?'selected':''}>${i}x</option>`;
    sel.disabled=tipo==='debito';
}
async function salvarPagamentoFormulario(){
    const forma=$('pagForma').value,tipo=forma==='cartao'?$('pagTipoCartao').value:'',parcelas=forma==='cartao'?Number($('pagParcelas').value):null;
    const body={consulta_id:$('pagConsultaId').value,patient_id:Number($('pagPacienteId').value),valor:$('pagValor').value.replace(',','.'),forma_pagamento:forma,tipo_cartao:tipo,parcelas};
    if(!body.valor||Number(body.valor)<=0){toast('Informe o valor recebido.');return;}
    try{await api('professional_register_payment',{method:'POST',body});fecharPagamento();toast('Pagamento registrado com segurança. O lançamento não pode ser alterado.');await carregarPortalProfissional();await carregarFinanceiroProfissional();}catch(e){toast(e.message);}
}
async function registrarPagamento(body){
    try{await api('professional_register_payment',{method:'POST',body});toast('Pagamento registrado com segurança. O lançamento não pode ser alterado.');carregarPortalProfissional();carregarFinanceiroProfissional();}catch(e){toast(e.message);}
}
async function carregarFinanceiroProfissional(){
    if($('finRelatorioData')&&!$('finRelatorioData').value)$('finRelatorioData').value=new Date().toISOString().slice(0,10);
    try{
        const d=await api('professional_finance',{params:{busca:$('finBusca')?.value.trim()||'',inicio:$('finInicio')?.value||'',fim:$('finFim')?.value||'',forma_pagamento:$('finForma')?.value||''}});
        $('finTotal').textContent=Number(d.total||0).toFixed(2).replace('.',',');
        renderFinanceAppointments(window.professionalAppointments||[]);$('financeList').innerHTML=(d.payments||[]).map(p=>`<article class="admin-appointment"><strong>${escapeHTML(p.codigo||'PAC')} — ${escapeHTML(p.paciente)}</strong><p>${escapeHTML(p.data)} • R$ ${Number(p.valor).toFixed(2).replace('.',',')} • ${escapeHTML(p.formaPagamento)}</p><p>${p.tipoCartao?escapeHTML(p.tipoCartao)+' • '+escapeHTML(p.parcelas||1)+'x':''}</p><small>Lançamento #${p.id} — somente consulta, sem edição.</small>${p.consultaId?`<div class="receipt-actions"><button class="btn secondary" onclick="gerarReciboConsulta('${escapeAttr(p.consultaId)}')">Imprimir comprovante</button><button class="btn primary" onclick="enviarReciboWhatsApp('${escapeAttr(p.consultaId)}')">Enviar comprovante pelo WhatsApp</button></div>`:''}</article>`).join('')||'<div class="info-box">Nenhum pagamento encontrado.</div>';
    }catch(e){toast(e.message);}
}
let relationshipPatients=[];
let mensagemPosVenda='Olá, {nome}! Aqui é da clínica {clinica}. Gostaríamos de saber como você está após sua consulta. Podemos ajudar em algo?';
async function carregarRelacionamentoProfissional(){try{const d=await api('professional_relationships');relationshipPatients=d.patients||[];const me=await api('professional_me');mensagemPosVenda=me.professional?.mensagemPosVenda||mensagemPosVenda;if($('relMensagemPadrao'))$('relMensagemPadrao').value=mensagemPosVenda;renderRelacionamentoProfissional(relationshipPatients);}catch(e){toast(e.message);}}
async function salvarMensagemPosVenda(){const msg=$('relMensagemPadrao')?.value.trim()||'';if(!msg){toast('Digite uma mensagem padrão.');return;}try{await api('professional_update_relationship_message',{method:'POST',body:{mensagem_pos_venda:msg}});mensagemPosVenda=msg;if($('relMensagemStatus'))$('relMensagemStatus').textContent='Mensagem salva com sucesso.';toast('Mensagem de pós-atendimento salva.');}catch(e){toast(e.message);}}
function filtrarRelacionamentoProfissional(){const q=($('relBusca')?.value||'').toLowerCase();renderRelacionamentoProfissional(relationshipPatients.filter(p=>(p.nome||'').toLowerCase().includes(q)||(p.cpf||'').includes(normalizarCpf(q))));}
function renderRelacionamentoProfissional(list){const box=$('relationshipList');if(!box)return;const total=list.length,contacted=list.filter(p=>p.ultimoContato).length;const sum=$('relacionamentoResumo');if(sum)sum.innerHTML=`<div><b>${total}</b><span>Pacientes na base</span></div><div><b>${contacted}</b><span>Já contatados</span></div><div><b>${total-contacted}</b><span>Sem contato pós-atendimento</span></div>`;box.innerHTML=list.length?list.map(p=>{const phone=String(p.telefone||'').replace(/\D/g,'');const msg=mensagemPosVenda.replaceAll('{nome}',p.nome||'paciente').replaceAll('{clinica}',professionalSession?.nome||'nossa clínica');const wa=phone?`https://wa.me/55${phone}?text=${encodeURIComponent(msg)}`:'#';return `<article class="relationship-card"><div><strong>${escapeHTML(p.nome)}</strong><p>CPF: ${escapeHTML(formatarCpf(p.cpf||''))} • ${escapeHTML(p.telefone||'Sem telefone')}</p><small>${p.ultimoContato?'Última mensagem: '+escapeHTML(p.ultimoContato):'Nenhuma mensagem registrada'} • ${Number(p.totalContatos||0)} contato(s)</small></div><a class="btn primary" href="${wa}" target="_blank" rel="noopener" onclick="registrarContatoWhatsApp(${Number(p.id)},${JSON.stringify(msg).replace(/"/g,'&quot;')})">Abrir WhatsApp</a></article>`;}).join(''):'<div class="info-box">Nenhum paciente encontrado.</div>';}
async function registrarContatoWhatsApp(patientId,message){try{await api('professional_log_relationship',{method:'POST',body:{patient_id:patientId,mensagem:message}});setTimeout(carregarRelacionamentoProfissional,500);}catch(e){toast(e.message);}}
function preencherSelectsProfissional(){['profAgendaPaciente'].forEach(id=>{const s=$(id);if(!s)return;s.innerHTML='<option value="">Selecione o paciente</option>'+professionalPatients.map(p=>`<option value="${p.id}">${escapeHTML(p.codigo||'PAC')} — ${escapeHTML(p.nome)}</option>`).join('');});}
async function vincularPacienteProfissional(){const sus=$('profPatientSus').value.trim();if(!sus){toast('Informe o Cartão SUS.');return;}try{await api('professional_link_patient',{method:'POST',body:{sus}});$('profPatientSus').value='';toast('Paciente vinculado.');carregarPortalProfissional();}catch(e){toast(e.message);}}
async function criarConsultaProfissional(){const body={patient_id:Number($('profAgendaPaciente').value),data_consulta:$('profAgendaData').value,horario:$('profAgendaHorario').value,assunto:$('profAgendaAssunto').value.trim()};if(!body.patient_id||!body.data_consulta||!body.horario){toast('Informe paciente, data e horário.');return;}try{await api('professional_create_appointment',{method:'POST',body});toast('Consulta confirmada e adicionada ao calendário.');$('profAgendaAssunto').value='';carregarPortalProfissional();}catch(e){toast(e.message);}}
async function carregarAgendaProfissional(){await carregarPortalProfissional();}
function atualizarLinkPublicoClinica(slug) {
    const publicLink = $("profPublicLink");
    const previewLink = $("profPublicLinkOpen");
    const copyButton = $("copyPublicClinicLinkButton");
    const publicSlug=String(slug??'').trim();
    if (!publicSlug) {
        if (publicLink) { publicLink.href = "#"; publicLink.textContent = "Link público indisponível"; delete publicLink.dataset.url; }
        if (previewLink) { previewLink.href = "#"; previewLink.setAttribute("aria-disabled", "true"); }
        if (copyButton) copyButton.disabled = true;
        return;
    }
    const url = new URL(appPath("/"), location.origin);
    url.searchParams.set("clinica", publicSlug);
    const href = url.toString();
    if (publicLink) { publicLink.href = href; publicLink.textContent = href; publicLink.dataset.url = href; }
    if (previewLink) { previewLink.href = href; previewLink.removeAttribute("aria-disabled"); }
    if (copyButton) copyButton.disabled = false;
}

async function copiarLinkPublicoClinica() {
    const url = $("profPublicLink")?.dataset.url;
    if (!url) { toast("O link público ainda não está disponível."); return; }
    try {
        await navigator.clipboard.writeText(url);
        toast("Link público copiado. Envie-o aos seus pacientes.");
    } catch (_error) {
        window.prompt("Copie e envie este link aos seus pacientes:", url);
    }
}

function definirPreviewLogoProfissional(src){
    const preview=$('profLogoPreview');if(!preview)return;
    preview.onerror=()=>{preview.onerror=null;preview.src=appPath('/img/logo-transparent.png');};
    preview.src=src||appPath('/img/logo-transparent.png');
}
function previewProfessionalLogo(input){
    const file=input?.files?.[0];if(!file){definirPreviewLogoProfissional(appPath('/img/logo-transparent.png'));return;}
    if(file.size>5*1024*1024){toast('A logo deve ter no máximo 5 MB.');input.value='';return;}
    if(!['image/png','image/jpeg','image/webp'].includes(file.type)){toast('Use uma imagem PNG, JPG ou WEBP.');input.value='';return;}
    const reader=new FileReader();reader.onload=()=>definirPreviewLogoProfissional(String(reader.result||''));reader.readAsDataURL(file);
}
async function carregarPerfilProfissional(){
    void carregarAgendaSemanal();
    try{
        const d=await api('professional_me'),p=d.professional||{};
        const map={nome:'profSetNome',cnpj:'profSetCnpj',especialidade:'profSetEspecialidade',registroProfissional:'profSetRegistro',telefone:'profSetTelefone',whatsapp:'profSetWhatsapp',modalidade:'profSetModalidade',horarioFuncionamento:'profSetHorario',valorConsulta:'profSetValor',endereco:'profSetEndereco',apresentacao:'profSetApresentacao',avisoPublico:'profSetAviso',mensagemPosVenda:'relMensagemPadrao',corPrimaria:'profSetCor',limiteDiario:'profSetLimiteDiario'};
        Object.entries(map).forEach(([k,id])=>{if($(id))$(id).value=p[k]??'';});
        $('profSetAutoConfirm').value=String(Number(p.confirmacaoAutomatica??1));
        $('profCancelHours').value=Number(p.cancelamentoAteHoras??24);
        $('profRescheduleHours').value=Number(p.remarcacaoAteHoras??24);
        professionalSession={...professionalSession,nome:p.nome,slug:p.slug};
        atualizarLinkPublicoClinica(p.slug);
        definirPreviewLogoProfissional(p.logoArquivo?appPath('/uploads/marca/'+encodeURIComponent(p.logoArquivo)):appPath('/img/logo-transparent.png'));
    }catch(e){
        if(professionalSession?.slug)atualizarLinkPublicoClinica(professionalSession.slug);
        else atualizarLinkPublicoClinica('');
        toast(e.message);
    }
}
const diasAgendaProfissional=['Segunda','Terça','Quarta','Quinta','Sexta','Sábado','Domingo'];
function agendaAtivo(value){return value===true||value===1||['1','t','true','yes','on'].includes(String(value??'').trim().toLowerCase());}
function normalizarAgendaSemanal(rows=[]){
    if(!Array.isArray(rows))return [];
    return rows.map(row=>{
        const item=row&&typeof row==='object'?row:{};
        return {...item,pausaInicio:item.pausaInicio??item.pausainicio??'',pausaFim:item.pausaFim??item.pausafim??''};
    });
}
function desenharAgendaSemanal(rows=[]){
    rows=normalizarAgendaSemanal(rows);
    const box=$('professionalScheduleEditor');
    if(!box)return;
    const previous=box.querySelector('[data-schedule-tab].is-selected');
    const firstOpen=rows.find(row=>agendaAtivo(row.ativo));
    const selected=previous?Number(previous.dataset.scheduleTab):(firstOpen?Number(firstOpen.dia):0);
    const diasCurtos=['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'];
    const diasLongos=['Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado','Domingo'];
    box.innerHTML=`<div class="schedule-editor">
        <div class="schedule-day-tabs" role="group" aria-label="Escolha o dia da semana">
            ${diasAgendaProfissional.map((day,i)=>{const r=rows.find(x=>Number(x.dia)===i)||{};const active=agendaAtivo(r.ativo);const start=String(r.inicio||'09:00').slice(0,5),end=String(r.fim||'17:00').slice(0,5);const hours=active?`${start}–${end}`:'Fechado';return `<button type="button" id="scheduleTab${i}" class="schedule-day-tab ${selected===i?'is-selected':''} ${active?'is-open':''}" data-schedule-tab="${i}" aria-controls="schedulePanel${i}" aria-pressed="${selected===i}" onclick="selecionarDiaExpediente(${i})"><span>${diasCurtos[i]}</span><i class="schedule-tab-dot" aria-hidden="true"></i><small class="schedule-tab-hours">${escapeHTML(hours)}</small></button>`;}).join('')}
        </div>
        <div class="schedule-panels">${diasAgendaProfissional.map((day,i)=>{
            const r=rows.find(x=>Number(x.dia)===i)||{};
            const active=agendaAtivo(r.ativo);
            const disabled=active?'':'disabled';
            const start=String(r.inicio||'09:00').slice(0,5),end=String(r.fim||'17:00').slice(0,5);
            const duration=Number(r.duracao||30),breakStart=String(r.pausaInicio||'').slice(0,5),breakEnd=String(r.pausaFim||'').slice(0,5);
            return `<article id="schedulePanel${i}" class="schedule-panel ${active?'':'is-closed'}" data-schedule-card="${i}" aria-labelledby="scheduleTab${i}" ${selected===i?'':'hidden'}>
                <header class="schedule-panel-head"><div><h4>${diasLongos[i]}</h4><span class="schedule-status" data-schedule-state="${i}">${active?'Atende neste dia':'Dia fechado'}</span></div><label class="schedule-toggle"><input type="checkbox" data-schedule-active="${i}" aria-label="Ativar expediente de ${diasLongos[i]}" ${active?'checked':''} onchange="alternarDiaExpediente(${i},this.checked)"><span>Atender neste dia</span></label></header>
                <div class="schedule-control-grid">
                    <div class="schedule-group"><strong>Expediente</strong><div class="schedule-pair"><label class="schedule-value-field">De<input type="time" data-schedule-start="${i}" data-schedule-field value="${escapeAttr(start)}" ${disabled}></label><label class="schedule-value-field">Até<input type="time" data-schedule-end="${i}" data-schedule-field value="${escapeAttr(end)}" ${disabled}></label></div></div>
                    <label class="schedule-group schedule-group-duration">Duração da consulta<input type="number" min="5" max="240" step="5" data-schedule-duration="${i}" data-schedule-field value="${duration}" ${disabled}><small>Minutos por horário</small></label>
                    <div class="schedule-group"><strong>Pausa <span>(opcional)</span></strong><div class="schedule-pair"><label class="schedule-value-field">Início<input type="time" data-schedule-break-start="${i}" data-schedule-field value="${escapeAttr(breakStart)}" ${disabled}></label><label class="schedule-value-field">Fim<input type="time" data-schedule-break-end="${i}" data-schedule-field value="${escapeAttr(breakEnd)}" ${disabled}></label></div></div>
                </div>
            </article>`;
        }).join('')}</div>
    </div>`;
    box.oninput=event=>{if(event.target?.matches?.('[data-schedule-field]'))atualizarResumoAgendaSemanal();};
    atualizarResumoAgendaSemanal();
}
function atualizarResumoAgendaSemanal(){
    const box=$("professionalScheduleEditor"),summary=$("professionalScheduleStatus");
    if(!box||!summary)return;
    const shortDays=['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'],active=[];
    box.querySelectorAll('[data-schedule-active]').forEach(input=>{
        const day=Number(input.dataset.scheduleActive),tab=box.querySelector(`[data-schedule-tab="${day}"]`),preview=tab?.querySelector('.schedule-tab-hours');
        const start=box.querySelector(`[data-schedule-start="${day}"]`)?.value||'',end=box.querySelector(`[data-schedule-end="${day}"]`)?.value||'';
        if(input.checked){const hours=start&&end?`${start}–${end}`:'Defina os horários';if(preview)preview.textContent=hours;active.push(`${shortDays[day]} ${hours}`);}
        else if(preview)preview.textContent='Fechado';
    });
    summary.textContent=active.length?`Dias ativos (${active.length}/7): ${active.join(' · ')}. Salve para atualizar os horários do link público.`:"Nenhum dia está ativo. Ative ao menos um dia para liberar horários no link público.";
    summary.classList.toggle("is-empty",active.length===0);
}
function selecionarDiaExpediente(day){
    const box=$('professionalScheduleEditor');if(!box)return;
    box.querySelectorAll('[data-schedule-tab]').forEach(tab=>{const selected=Number(tab.dataset.scheduleTab)===Number(day);tab.classList.toggle('is-selected',selected);tab.setAttribute('aria-pressed',String(selected));});
    box.querySelectorAll('[data-schedule-card]').forEach(panel=>{panel.hidden=Number(panel.dataset.scheduleCard)!==Number(day);});
}
function alternarDiaExpediente(day,active){
    const box=$('professionalScheduleEditor');const panel=box?.querySelector(`[data-schedule-card="${day}"]`);if(!panel)return;
    panel.classList.toggle('is-closed',!active);
    const status=panel.querySelector(`[data-schedule-state="${day}"]`);if(status)status.textContent=active?'Atende neste dia':'Dia fechado';
    panel.querySelectorAll('[data-schedule-field]').forEach(field=>{field.disabled=!active;});
    const tab=box.querySelector(`[data-schedule-tab="${day}"]`);if(tab)tab.classList.toggle('is-open',active);
    atualizarResumoAgendaSemanal();
}
async function carregarAgendaSemanal(){
    const box=$('professionalScheduleEditor'),status=$('professionalScheduleStatus');
    if(!box)return;
    let controller=null,timeoutId=null;
    if(status)status.textContent='Carregando expediente semanal…';
    try{
        if(typeof AbortController==='function'){
            controller=new AbortController();
            timeoutId=setTimeout(()=>controller.abort(),15000);
        }
        const d=await api('professional_schedule',controller?{signal:controller.signal}:{});
        if(!Array.isArray(d.schedule))throw new Error('O servidor retornou uma resposta inválida para o expediente.');
        desenharAgendaSemanal(d.schedule);
    }catch(e){
        const message=controller?.signal.aborted?'O carregamento do expediente demorou mais de 15 segundos. Tente novamente.':(e?.message||'Não foi possível carregar o expediente.');
        box.innerHTML='<div class="info-box schedule-load-error" role="alert"><strong>Não foi possível carregar o expediente.</strong><p>Verifique sua conexão e tente novamente.</p><button type="button" class="btn secondary" onclick="carregarAgendaSemanal()">Tentar novamente</button></div>';
        if(status)status.textContent=message;
        toast(message);
    }finally{
        if(timeoutId!==null)clearTimeout(timeoutId);
    }
}
function dadosAgendaSemanal(){
    const box=$('professionalScheduleEditor');
    if(!box)throw new Error('Abra “Minha marca” e aguarde a grade semanal carregar.');
    const read=(selector)=>{const el=box.querySelector(selector);if(!el)throw new Error('Não foi possível ler todos os campos da grade semanal.');return el;};
    return diasAgendaProfissional.map((_,dia)=>({
        dia,
        ativo:read(`[data-schedule-active="${dia}"]`).checked,
        inicio:read(`[data-schedule-start="${dia}"]`).value,
        fim:read(`[data-schedule-end="${dia}"]`).value,
        duracao:Number(read(`[data-schedule-duration="${dia}"]`).value||30),
        pausa_inicio:read(`[data-schedule-break-start="${dia}"]`).value,
        pausa_fim:read(`[data-schedule-break-end="${dia}"]`).value
    }));
}
function validarAgendaSemanal(rows){
    for(const row of rows){
        if(!row.ativo)continue;
        if(!row.inicio||!row.fim||row.inicio>=row.fim)throw new Error(`Revise o início e o fim de ${diasAgendaProfissional[row.dia]}.`);
        if(row.duracao<5||row.duracao>240)throw new Error(`A duração de ${diasAgendaProfissional[row.dia]} deve ficar entre 5 e 240 minutos.`);
        if(Boolean(row.pausa_inicio)!==Boolean(row.pausa_fim))throw new Error(`Preencha os dois horários da pausa de ${diasAgendaProfissional[row.dia]} ou deixe ambos vazios.`);
        if(row.pausa_inicio&&(row.pausa_inicio>=row.pausa_fim||row.pausa_inicio<row.inicio||row.pausa_fim>row.fim))throw new Error(`A pausa de ${diasAgendaProfissional[row.dia]} deve ficar dentro do expediente.`);
    }
}
async function salvarAgendaSemanal(){
    const button=$('saveProfessionalScheduleButton');if(button)button.disabled=true;
    try{
        const dias=dadosAgendaSemanal();
        validarAgendaSemanal(dias);
        const d=await api('professional_save_schedule',{method:'POST',body:{dias}});
        const savedSchedule=Array.isArray(d.schedule)?d.schedule:[];
        if(savedSchedule.length!==dias.length)throw new Error('O servidor não confirmou o salvamento dos sete dias. Tente novamente.');
        desenharAgendaSemanal(savedSchedule);
        const activeDays=savedSchedule.filter(row=>agendaAtivo(row.ativo)).length;
        toast(activeDays?'Expediente, duração e pausas salvos.': 'Expediente salvo, mas nenhum dia está ativo; o link público não mostrará horários.');
    }catch(e){toast(e.message);}
    finally{if(button)button.disabled=false;}
}
async function salvarPerfilProfissional(){
    const button=$('saveProfessionalProfileButton'),original=button?.textContent;
    const body={nome:$('profSetNome').value.trim(),cnpj:$('profSetCnpj').value.trim(),especialidade:$('profSetEspecialidade').value.trim(),registro_profissional:$('profSetRegistro').value.trim(),telefone:$('profSetTelefone').value.trim(),whatsapp:$('profSetWhatsapp').value.trim(),modalidade:$('profSetModalidade').value.trim(),horario_funcionamento:$('profSetHorario').value.trim(),valor_consulta:$('profSetValor').value,endereco:$('profSetEndereco').value.trim(),apresentacao:$('profSetApresentacao').value.trim(),aviso_publico:$('profSetAviso').value.trim(),mensagem_pos_venda:mensagemPosVenda,cor_primaria:$('profSetCor').value,limite_diario:Number($('profSetLimiteDiario')?.value||12),confirmacao_automatica:Number($('profSetAutoConfirm').value),cancelamento_ate_horas:Number($('profCancelHours').value||0),remarcacao_ate_horas:Number($('profRescheduleHours').value||0)};
    if(button){button.disabled=true;button.setAttribute('aria-busy','true');button.textContent='Salvando…';}
    try{
        const d=await api('professional_update_settings',{method:'POST',body});
        professionalSession={...professionalSession,...d.professional};
        atualizarLinkPublicoClinica(d.professional.slug||professionalSession?.slug);
        const file=$('profLogoFile')?.files?.[0];
        if(file){
            const form=new FormData();form.append('logo',file);
            try{const upload=await api('professional_upload_logo',{method:'POST',body:form});definirPreviewLogoProfissional(upload.url?appPath('/'+upload.url.replace(/^\/+/,'')):appPath('/img/logo-transparent.png'));$('profLogoFile').value='';}
            catch(uploadError){toast(`Perfil salvo. A logo não foi enviada: ${uploadError.message}`);return;}
        }
        toast('Minha marca salva. O expediente é salvo separadamente no bloco semanal.');
    }catch(e){toast(e.message);}
    finally{if(button){button.disabled=false;button.removeAttribute('aria-busy');button.textContent=original||'Salvar minha marca';}}
}
let planoPagamentoSelecionado=null;
let agendaMesAtual=new Date();
let agendaDiaAtual=null;
async function carregarPlanosProfissional(){
    try{
        const [d,sub]=await Promise.all([api('subscription_plans'),api('professional_subscription')]);
        $('profPlansList').innerHTML=(d.plans||[]).map(p=>`<div class="info-box plan-card"><h3>${escapeHTML(p.nome)}</h3><p class="plan-price">R$ ${Number(p.valorMensal).toFixed(2).replace('.',',')} <small>/mês</small></p><p>Até ${Number(p.limitePacientes)} pacientes</p><div class="payment-options"><button class="btn secondary" onclick="assinarPlanoProfissional(${Number(p.id)},'pix')">PIX</button><button class="btn secondary" onclick="assinarPlanoProfissional(${Number(p.id)},'cartao')">Cartão</button><button class="btn secondary" onclick="assinarPlanoProfissional(${Number(p.id)},'boleto')">Boleto</button></div></div>`).join('');
        const active=sub.subscription;
        const statusTexto=active?(active.status==='ativa'?`Ativa — ${active.plano} (${Number(active.limitePacientes||0)} pacientes)`:(active.status==='inadimplente'?'Pagamento não confirmado':'Pendente')): 'Gratuito — 5 pacientes';['profSubscriptionStatus','profSubscriptionStatusResumo'].forEach(id=>{if($(id))$(id).textContent=statusTexto;});
    }catch(e){toast(e.message);}
}
async function assinarPlanoProfissional(id,metodo='pix'){
    try{
        const d=await api('professional_start_subscription',{method:'POST',body:{plano_id:id,metodo}});
        ['profSubscriptionStatus','profSubscriptionStatusResumo'].forEach(id=>{if($(id))$(id).textContent='Pendente';});
        toast(d.message+` Forma: ${metodo.toUpperCase()}.`);
    }catch(e){toast(e.message);}
}
async function cancelarAssinaturaProfissional(){if(!confirm('Cancelar a assinatura atual?'))return;try{await api('professional_cancel_subscription',{method:'POST'});['profSubscriptionStatus','profSubscriptionStatusResumo'].forEach(id=>{if($(id))$(id).textContent='Cancelada';});toast('Assinatura cancelada.');}catch(e){toast(e.message);}}

/* ============================================================
   TECLA ENTER NO LOGIN
   ============================================================ */

document.addEventListener(
    "keydown",
    function (event) {

        if (
            event.key === "Enter" &&
            !$("loginModal").classList.contains("hidden")
        ) {

            realizarLogin();

        }

    }
);

/* ============================================================
   FIM
   ============================================================ */
