<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/config.php';
$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
$isProfessionalAction = str_starts_with($action, 'professional_');
$professionalSessionLifetime = max(SESSION_ABSOLUTE_TIMEOUT, (int)ini_get('session.gc_maxlifetime'));
configure_secure_session($isProfessionalAction ? $professionalSessionLifetime : 0);
ob_start();
try {
    $pdo = db();
    configure_database_session_handler($pdo);
    if (!session_start()) throw new RuntimeException('Não foi possível iniciar a sessão segura.');
    enforce_session_lifetime();
    validate_professional_session($pdo);
    if ($isProfessionalAction) refresh_professional_session_cookie();
    // As ações da clínica usam autenticação profissional; UBS e demais POSTs mantêm CSRF.
    $requiresCsrf = $_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'asaas_webhook' && !str_starts_with((string)$action, 'professional_');
    if ($requiresCsrf) require_csrf();
    switch ($action) {
        case 'csrf_token': json_response(['success'=>true,'csrf_token'=>$_SESSION['_csrf_token'] ?? rotate_csrf_token()]); break;
        case 'professional_verify_email': professional_verify_email($pdo, trim((string)($_GET['token'] ?? ''))); break;
        case 'professional_resend_verification': professional_resend_verification($pdo, body_json()); break;
        case 'professional_request_password_reset': professional_request_password_reset($pdo, body_json()); break;
        case 'professional_reset_password': professional_reset_password($pdo, body_json()); break;
        case 'professional_schedule': professional_schedule($pdo); break;
        case 'professional_save_schedule': professional_save_schedule($pdo, body_json()); break;
        case 'public_clinic_slots': public_clinic_slots($pdo, trim((string)($_GET['slug'] ?? '')), trim((string)($_GET['data'] ?? ''))); break;
        case 'public_clinic_manage': public_clinic_manage($pdo, body_json()); break;

        case 'professional_register': professional_register($pdo, body_json()); break;
        case 'public_clinic': public_clinic($pdo, trim((string)($_GET['slug'] ?? ''))); break;
        case 'public_clinic_book': public_clinic_book($pdo, body_json()); break;
        case 'public_clinic_join_waitlist': public_clinic_join_waitlist($pdo, body_json()); break;
        case 'professional_login': professional_login($pdo, body_json()); break;
        case 'professional_logout': professional_logout($pdo);
        case 'professional_me': professional_me($pdo); break;
        case 'professional_update_settings': professional_update_settings($pdo, body_json()); break;
        case 'professional_update_relationship_message': professional_update_relationship_message($pdo, body_json()); break;
        case 'professional_upload_logo': professional_upload_logo($pdo); break;
        case 'professional_link_patient': professional_link_patient($pdo, body_json()); break;
        case 'professional_patients': professional_patients($pdo); break;
        case 'professional_create_patient': professional_create_patient($pdo, body_json()); break;
        case 'professional_update_patient': professional_update_patient($pdo, body_json()); break;
        case 'professional_patient_history': professional_patient_history($pdo); break;
        case 'professional_records': professional_records($pdo); break;
        case 'professional_create_record': professional_create_record($pdo, body_json()); break;
        case 'professional_finance': professional_finance($pdo); break;
        case 'professional_daily_report': professional_daily_report($pdo); break;
        case 'asaas_webhook': asaas_webhook($pdo); break;
        case 'professional_register_payment': professional_register_payment($pdo, body_json()); break;
        case 'professional_receipt': professional_receipt($pdo); break;
        case 'professional_relationships': professional_relationships($pdo); break;
        case 'professional_log_relationship': professional_log_relationship($pdo, body_json()); break;
        case 'professional_create_appointment': professional_create_appointment($pdo, body_json()); break;
        case 'professional_appointments': professional_appointments($pdo); break;
        case 'professional_waitlist': professional_waitlist($pdo); break;
        case 'professional_cancel_waitlist': professional_cancel_waitlist($pdo, body_json()); break;
        case 'professional_update_appointment': professional_update_appointment($pdo, body_json()); break;
        case 'subscription_plans': subscription_plans($pdo); break;
        case 'professional_subscription': professional_subscription($pdo); break;
        case 'admin_professional_payments': admin_professional_payments($pdo); break;
        case 'admin_update_professional_payment': admin_update_professional_payment($pdo, body_json()); break;
        case 'professional_start_subscription': professional_start_subscription($pdo, body_json()); break;
        case 'professional_cancel_subscription': professional_cancel_subscription($pdo); break;
        case 'get_app_config':
            get_app_config($pdo);
            break;

        case 'update_app_config':
            update_app_config($pdo, body_json());
            break;

        case 'get_ubs':
            json_response([
                'success' => true,
                'ubs' => all_ubs($pdo, false)
            ]);
            break;

        case 'save_patient':
            save_patient($pdo, body_json());
            break;

        case 'update_patient_profile':
            update_patient_profile($pdo, body_json());
            break;

        case 'create_ubs':
            create_ubs($pdo, body_json());
            break;

        case 'support_message':
            support_message($pdo, body_json());
            break;

        case 'get_support':
            get_support($pdo, trim((string)($_GET['sus'] ?? '')));
            break;

        case 'admin_support_messages':
            admin_support_messages($pdo);
            break;

        case 'admin_update_support':
            admin_update_support($pdo, body_json());
            break;

        case 'support_inbox':
            support_inbox($pdo);
            break;

        case 'support_forward_developer':
            support_forward_developer($pdo, body_json());
            break;

        case 'support_update_clinic':
            support_update_clinic($pdo, body_json());
            break;

        case 'admin_secretaria_dashboard':
            secretaria_dashboard($pdo);
            break;

        case 'admin_save_health_indicator':
            save_health_indicator($pdo, body_json());
            break;

        case 'admin_delete_health_indicator':
            delete_health_indicator($pdo, body_json());
            break;

        case 'admin_toggle_ubs':
            toggle_ubs_active($pdo, body_json());
            break;
        case 'admin_list_ubs':
            list_admin_ubs($pdo);
            break;

        case 'admin_secretaria_accounts':
            list_secretaria_accounts($pdo);
            break;

        case 'admin_create_secretaria':
            create_secretaria_account($pdo, body_json());
            break;

        case 'admin_delete_secretaria':
            delete_secretaria_account($pdo, body_json());
            break;

        case 'admin_change_password':
            admin_change_password($pdo, body_json());
        case 'admin_logout':
            admin_logout();

        case 'admin_audit':
            admin_audit($pdo);
            break;

        case 'get_patient_appointments':
            get_patient_appointments(
                $pdo,
                trim((string)($_GET['sus'] ?? ''))
            );
            break;

        case 'get_patient_notifications':
            get_patient_notifications($pdo, trim((string)($_GET['sus'] ?? '')));
            break;

        case 'join_waitlist':
            join_waitlist($pdo, body_json());
            break;

        case 'get_occupied_slots':
            get_occupied_slots(
                $pdo,
                trim((string)($_GET['ubs_id'] ?? '')),
                trim((string)($_GET['especialidade'] ?? '')),
                trim((string)($_GET['data'] ?? ''))
            );
            break;

        case 'book_appointment':
            book_appointment($pdo, body_json());
            break;

        case 'cancel_appointment':
            cancel_appointment($pdo, body_json());
            break;

        case 'set_reminder':
            set_reminder($pdo, body_json());
            break;

        case 'get_patient_exams':
            get_patient_exams($pdo, trim((string)($_GET['sus'] ?? '')));
            break;

        case 'admin_update_appointment_status':
            admin_update_appointment_status($pdo, body_json());
            break;

        case 'admin_save_exam':
            admin_save_exam($pdo, $_POST ?: body_json());
            break;

        case 'download_exam':
            download_exam($pdo);
            break;

        case 'admin_exams':
            admin_exams($pdo);
            break;

        case 'login':
            login_admin($pdo, body_json());
            break;

        case 'admin_ubs_data':
            $ubsId = trim((string)($_GET['ubs_id'] ?? ''));
            authorize_ubs($ubsId);

            json_response([
                'success' => true,
                'ubs' => get_ubs($pdo, $ubsId, true)
            ]);
            break;

        case 'update_ubs':
            update_ubs($pdo, body_json());
            break;
        case 'update_ubs_campaigns':
            update_ubs_campaigns($pdo, body_json());
            break;

        case 'save_employee':
            save_employee($pdo, body_json());
            break;

        case 'delete_employee':
            delete_employee($pdo, body_json());
            break;

        case 'admin_waitlist': admin_waitlist($pdo); break;
        case 'admin_add_waitlist_to_queue': admin_add_waitlist_to_queue($pdo, body_json()); break;
        case 'admin_notify_waitlist': admin_notify_waitlist($pdo, body_json()); break;
        case 'admin_appointments':
            admin_appointments($pdo);
            break;

        case 'logout':
            $_SESSION = [];
            session_destroy();

            json_response([
                'success' => true
            ]);
            break;

        default:
            json_response([
                'success' => false,
                'message' => 'Ação não encontrada.'
            ], 404);
    }

} catch (PDOException $e) {

    error_log('Falha PDO; SQLSTATE/código ' . (string)$e->getCode());

    json_response([
        'success' => false,
        'message' => 'Não foi possível acessar a base de dados. Tente novamente mais tarde.',
        'code' => 'DATABASE_UNAVAILABLE'
    ], 500);

} catch (Throwable $e) {

    error_log($e->getMessage());

    json_response([
        'success' => false,
        'message' => 'Erro interno do sistema.'
    ], 500);
}

function all_ubs(PDO $pdo, bool $admin): array
{
    $sql = 'SELECT id, nome, endereco, cidade, estado, telefone, horario, usuario, limite_diario, ativa FROM ubs';
    if (!$admin) $sql .= ' WHERE ativa = 1';
    $stmt = $pdo->query($sql . ' ORDER BY nome');

    $result = [];

    foreach ($stmt->fetchAll() as $row) {
        $result[] = get_ubs_from_row($pdo, $row, $admin);
    }

    return $result;
}

function ubs_da_regiao(array $unidades, ?array $region): array
{
    if ($region === null) return $unidades;
    return array_values(array_filter($unidades, static fn(array $ubs): bool => ubs_pertence_regiao($ubs, $region)));
}

function get_ubs(PDO $pdo, string $id, bool $admin = false): array
{
    $sql = 'SELECT id, nome, endereco, cidade, estado, telefone, horario, usuario, limite_diario, ativa FROM ubs WHERE id = ?';
    if (!$admin) $sql .= ' AND ativa = 1';
    $stmt = $pdo->prepare($sql);

    $stmt->execute([$id]);

    $row = $stmt->fetch();

    if (!$row) {
        json_response([
            'success' => false,
            'message' => 'UBS não encontrada.'
        ], 404);
    }

    return get_ubs_from_row($pdo, $row, $admin);
}

function get_ubs_from_row(PDO $pdo, array $row, bool $admin): array
{
    $id = $row['id'];

    $specialties = $pdo->prepare(
        'SELECT nome FROM ubs_especialidades WHERE ubs_id = ? ORDER BY id'
    );
    $specialties->execute([$id]);

    $services = $pdo->prepare(
        'SELECT nome FROM ubs_servicos WHERE ubs_id = ? ORDER BY id'
    );
    $services->execute([$id]);

    $campaigns = $pdo->prepare(
        'SELECT nome FROM ubs_campanhas WHERE ubs_id = ? ORDER BY id'
    );
    $campaigns->execute([$id]);

    $documents = $pdo->prepare(
        'SELECT nome FROM ubs_documentos WHERE ubs_id = ? ORDER BY id'
    );
    $documents->execute([$id]);

    $employees = $pdo->prepare(
        'SELECT id, nome, cargo FROM funcionarios WHERE ubs_id = ? ORDER BY nome'
    );
    $employees->execute([$id]);

    $result = [
        'id' => $row['id'],
        'nome' => $row['nome'],
        'endereco' => $row['endereco'],
        'cidade' => $row['cidade'] ?? '',
        'estado' => $row['estado'] ?? '',
        'telefone' => $row['telefone'],
        'horario' => $row['horario'],
        'ativa' => database_bool($row['ativa'] ?? true),
        'limiteDiario' => (int)($row['limite_diario'] ?? 12),
        'especialidades' => array_column($specialties->fetchAll(), 'nome'),
        'servicos' => array_column($services->fetchAll(), 'nome'),
        'campanhas' => array_column($campaigns->fetchAll(), 'nome'),
        'documentos' => array_column($documents->fetchAll(), 'nome'),
        'funcionarios' => $employees->fetchAll()
    ];

    if ($admin) {
        $result['usuario'] = $row['usuario'];
        $result['senha'] = '';
    }

    return $result;
}

function professional_register(PDO $pdo, array $data): never {
    $nome=required_string($data,'nome'); $email=strtolower(required_string($data,'email')); $senha=(string)($data['senha']??'');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($senha)<8) json_response(['success'=>false,'message'=>'Informe um e-mail válido e uma senha com pelo menos 8 caracteres.'],422);
    $key=auth_attempt_key('cadastro',$email); if(auth_is_limited($pdo,$key)) json_response(['success'=>false,'message'=>'Muitas tentativas. Aguarde 15 minutos.'],429); auth_record_failure($pdo,$key);
    try {
        $slug=slug_publico($pdo,$nome);
        $q=$pdo->prepare("INSERT INTO profissionais (nome,email,slug,senha_hash,especialidade,registro_profissional,telefone,status) VALUES (?,?,?,?,?,?,?,'ativo')");
        $q->execute([$nome,$email,$slug,password_hash($senha,PASSWORD_DEFAULT),trim((string)($data['especialidade']??''))?:null,trim((string)($data['registro_profissional']??''))?:null,trim((string)($data['telefone']??''))?:null]);
        $id=(int)$pdo->lastInsertId();
        $q=$pdo->prepare('SELECT id,nome,email,slug,auth_version FROM profissionais WHERE id=?'); $q->execute([$id]); $p=$q->fetch();
        auth_clear_attempts($pdo,$key); session_regenerate_id(true); rotate_csrf_token();
        $_SESSION['_auth_created_at']=time(); $_SESSION['_last_activity']=time();
        $_SESSION['professional']=['id'=>(int)$p['id'],'email'=>$p['email'],'nome'=>$p['nome'],'slug'=>$p['slug'],'auth_version'=>(int)$p['auth_version']];
        audit_professional_event($pdo,$id,'cadastro_profissional','profissionais',(string)$id,['email_hash'=>hash('sha256',$email)]);
        json_response(['success'=>true,'message'=>'Conta profissional criada. Você já pode começar.','csrf_token'=>$_SESSION['_csrf_token'],'professional'=>['id'=>(int)$p['id'],'nome'=>$p['nome'],'email'=>$p['email'],'slug'=>$p['slug']]]);
    } catch(PDOException $e){ if(database_is_unique_violation($e)) json_response(['success'=>false,'message'=>'Este e-mail já está cadastrado. Entre ou use “Esqueci minha senha”.'],409); json_response(['success'=>false,'message'=>'Não foi possível criar a conta profissional. Verifique os dados e tente novamente.'],500); }
}
function slug_publico(PDO $pdo,string $nome): string { $s=iconv('UTF-8','ASCII//TRANSLIT',$nome);$s=preg_replace('/[^a-z0-9]+/','-',strtolower((string)$s));$s=trim($s,'-')?:'profissional';$base=$s;$i=2;$q=$pdo->prepare('SELECT 1 FROM profissionais WHERE slug=? LIMIT 1');while(true){$q->execute([$s]);if(!$q->fetchColumn())return $s;$s=$base.'-'.$i++;} }
function ensure_professional_public_slug(PDO $pdo,array $professional): array {
    $existing=trim((string)($professional['slug']??''));
    if($existing!==''){$professional['slug']=$existing;return $professional;}
    $id=(int)($professional['id']??0);
    if($id<1)return $professional;
    $generated=slug_publico($pdo,'profissional-'.$id);
    $update=$pdo->prepare("UPDATE profissionais SET slug=? WHERE id=? AND (slug IS NULL OR TRIM(slug)='')");
    $update->execute([$generated,$id]);
    if($update->rowCount()>0){$professional['slug']=$generated;return $professional;}
    $lookup=$pdo->prepare('SELECT slug FROM profissionais WHERE id=?');$lookup->execute([$id]);
    $current=trim((string)$lookup->fetchColumn());
    if($current!=='')$professional['slug']=$current;
    return $professional;
}
function public_clinic(PDO $pdo,string $slug): never {
    if($slug==='')json_response(['success'=>false,'message'=>'Clínica não informada.'],422);
    $q=$pdo->prepare("SELECT id,nome,slug,especialidade,registro_profissional AS registroProfissional,telefone,whatsapp,endereco,modalidade,valor_consulta AS valorConsulta,limite_diario AS limiteDiario,apresentacao,logo_arquivo AS logoArquivo,horario_funcionamento AS horarioFuncionamento,mensagem_pos_venda AS mensagemPosVenda,aviso_publico AS avisoPublico,cor_primaria AS corPrimaria,cor_secundaria AS corSecundaria,confirmacao_automatica AS confirmacaoAutomatica,cancelamento_ate_horas AS cancelamentoAteHoras,remarcacao_ate_horas AS remarcacaoAteHoras FROM profissionais WHERE slug=? AND status='ativo'");
    $q->execute([$slug]);$p=$q->fetch();if(!$p)json_response(['success'=>false,'message'=>'Clínica não encontrada.'],404);
    json_response(['success'=>true,'clinic'=>$p]);
}

function normalize_cpf($value): string { return preg_replace('/\D+/', '', (string)$value); }
function valid_cpf(string $cpf): bool { if(strlen($cpf)!==11 || preg_match('/^(\d)\1{10}$/',$cpf)) return false; for($t=9;$t<11;$t++){ $sum=0; for($i=0;$i<$t;$i++) $sum += (int)$cpf[$i]*(($t+1)-$i); $digit=(($sum*10)%11)%10; if((int)$cpf[$t]!==$digit)return false; } return true; }
function public_clinic_book(PDO $pdo,array $data): never {
    $slug=required_string($data,'slug'); $nome=required_string($data,'nome');
    $cpf=normalize_cpf($data['cpf']??''); $email=strtolower(trim((string)($data['email']??'')));
    $telefone=required_string($data,'telefone'); $date=required_string($data,'data_consulta');
    $time=trim((string)($data['horario']??''));
    if(!valid_cpf($cpf)||!filter_var($email,FILTER_VALIDATE_EMAIL)||!valid_date($date)||!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$time)) json_response(['success'=>false,'message'=>'Informe CPF, e-mail, data e horário válidos.'],422);
    if(strlen($nome)<3||strlen($nome)>150||strlen($telefone)>30) json_response(['success'=>false,'message'=>'Confira o nome e o celular informados.'],422);
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare("SELECT id,nome,valor_consulta,limite_diario,confirmacao_automatica,cancelamento_ate_horas,remarcacao_ate_horas FROM profissionais WHERE slug=? AND status='ativo' FOR UPDATE");
        $q->execute([$slug]); $pro=$q->fetch(); if(!$pro){$pdo->rollBack();json_response(['success'=>false,'message'=>'Clínica não encontrada.'],404);}
        $slots=professional_slots($pdo,(int)$pro['id'],$date);
        $slot=null; foreach($slots as $candidate){if($candidate['horario']===$time){$slot=$candidate;break;}}
        if(!$slot){$pdo->rollBack();json_response(['success'=>false,'message'=>'Esse horário acabou de ficar indisponível. Escolha outro horário livre.','code'=>'SLOT_UNAVAILABLE'],409);}
        $q=$pdo->prepare('SELECT p.id,p.sus FROM pacientes p INNER JOIN profissional_pacientes pp ON pp.paciente_id=p.id AND pp.profissional_id=? WHERE p.cpf=? ORDER BY p.id DESC LIMIT 1');
        $q->execute([$pro['id'],$cpf]); $patient=$q->fetch();
        if(!$patient){
            enforce_professional_patient_limit($pdo,(int)$pro['id']);
            $synthetic='CLI-'.$pro['id'].'-'.bin2hex(random_bytes(8));
            $q=$pdo->prepare('INSERT INTO pacientes (codigo,nome,telefone,email,cpf,sus,ubs_id) VALUES (?,?,?,?,?,?,NULL)');
            $q->execute([generate_patient_code(),$nome,$telefone,$email,$cpf,$synthetic]); $patientId=(int)$pdo->lastInsertId();
            $patientSus=$synthetic;
        } else {
            $patientId=(int)$patient['id']; $patientSus=(string)$patient['sus'];
            $q=$pdo->prepare('UPDATE pacientes SET nome=?,telefone=?,email=? WHERE id=?'); $q->execute([$nome,$telefone,$email,$patientId]);
        }
        $q=$pdo->prepare('INSERT IGNORE INTO profissional_pacientes (profissional_id,paciente_id,consentimento_em) VALUES (?,?,NOW())');$q->execute([$pro['id'],$patientId]);
        $status=database_bool($pro['confirmacao_automatica']??false)?'confirmada':'solicitada';
        $manageToken=bin2hex(random_bytes(32)); $id='PUB'.date('YmdHis').bin2hex(random_bytes(3));
        $q=$pdo->prepare('INSERT INTO consultas_profissionais (id,profissional_id,paciente_id,data_consulta,horario,duracao_minutos,assunto,valor,status,confirmada_em,manage_token_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$id,$pro['id'],$patientId,$date,$time,(int)$slot['duracao'],trim((string)($data['assunto']??''))?:null,$pro['valor_consulta'],$status,$status==='confirmada'?date('Y-m-d H:i:s'):null,hash('sha256',$manageToken)]);
        $pdo->commit();
        $emailSent=false;try { $manageUrl=app_public_url().'?clinica='.rawurlencode($slug).'&gerenciar='.rawurlencode($manageToken);send_appointment_email($email,$nome,$pro['nome'],$date,$time,$status,$id,$manageUrl);$emailSent=true; } catch(Throwable $mailError) { error_log('Falha ao enviar confirmação da consulta '.$id.': '.$mailError->getMessage()); }
        try { criar_notificacoes_profissionais($pdo,(int)$pro['id'],$patientId,$id,$date,$time,$nome,$pro['nome']); } catch(Throwable $notificationError) { error_log('Falha ao criar notificacoes da consulta '.$id.': '.$notificationError->getMessage()); }
        audit_professional_event($pdo,(int)$pro['id'],'agendamento_publico_criado','consultas_profissionais',$id,['status'=>$status]);
        json_response(['success'=>true,'message'=>$status==='confirmada'?'Consulta confirmada.':'Solicitação recebida; aguarde a confirmação da clínica.','appointment_id'=>$id,'manage_token'=>$manageToken,'confirmation'=>['id'=>$id,'clinic'=>$pro['nome'],'date'=>$date,'time'=>$time,'value'=>$pro['valor_consulta'],'status'=>$status,'duration'=>(int)$slot['duracao'],'email_sent'=>$emailSent]]);
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        error_log('Erro ao agendar consulta pública: '.$e->getMessage());
        json_response(['success'=>false,'message'=>'Não foi possível concluir o agendamento. Atualize os horários e tente novamente.'],500);
    }
}
function public_clinic_join_waitlist(PDO $pdo,array $data): never {
    $slug=required_string($data,'slug'); $nome=required_string($data,'nome'); $cpf=normalize_cpf($data['cpf']??''); $email=trim((string)($data['email']??'')); $telefone=required_string($data,'telefone'); $date=required_string($data,'data_consulta');
    if(!valid_cpf($cpf)||!filter_var($email,FILTER_VALIDATE_EMAIL)||!valid_date($date)||$date<date('Y-m-d')) json_response(['success'=>false,'message'=>'Informe seus dados e uma data futura válida.'],422);
    $q=$pdo->prepare("SELECT id,nome,limite_diario,confirmacao_automatica FROM profissionais WHERE slug=? AND status='ativo'"); $q->execute([$slug]); $pro=$q->fetch(); if(!$pro) json_response(['success'=>false,'message'=>'Clínica não encontrada.'],404);
    if(professional_slots($pdo,(int)$pro['id'],$date)!==[]) json_response(['success'=>false,'message'=>'Já existe uma vaga disponível para esta data. Volte e faça o agendamento normalmente.'],409);
    $q=$pdo->prepare('SELECT p.id,p.sus FROM pacientes p INNER JOIN profissional_pacientes pp ON pp.paciente_id=p.id AND pp.profissional_id=? WHERE p.cpf=? LIMIT 1'); $q->execute([$pro['id'],$cpf]); $pat=$q->fetch();
    if(!$pat){ enforce_professional_patient_limit($pdo,(int)$pro['id']); $synthetic='CLI-'.$pro['id'].'-'.bin2hex(random_bytes(8)); $q=$pdo->prepare('INSERT INTO pacientes (nome,telefone,email,cpf,sus,ubs_id) VALUES (?,?,?,?,?,NULL)'); $q->execute([$nome,$telefone,$email,$cpf,$synthetic]); $patientId=(int)$pdo->lastInsertId(); }
    else { $patientId=(int)$pat['id']; enforce_professional_patient_limit($pdo,(int)$pro['id'],$patientId); $q=$pdo->prepare('UPDATE pacientes SET nome=?,telefone=?,email=? WHERE id=?'); $q->execute([$nome,$telefone,$email,$patientId]); }
    $q=$pdo->prepare('INSERT IGNORE INTO profissional_pacientes (profissional_id,paciente_id,consentimento_em) VALUES (?,?,NOW())'); $q->execute([$pro['id'],$patientId]);
    $q=$pdo->prepare('INSERT IGNORE INTO lista_espera_profissionais (paciente_id,profissional_id,data_consulta) VALUES (?,?,?)'); $q->execute([$patientId,$pro['id'],$date]);
    if($q->rowCount()===0) json_response(['success'=>true,'message'=>'Você já está na lista de espera desta data.']);
    json_response(['success'=>true,'message'=>database_bool($pro['confirmacao_automatica']??false)?'Você entrou na lista de espera. Se houver cancelamento, a vaga será agendada automaticamente e você receberá um aviso.':'Você entrou na lista de espera. Se houver cancelamento, a clínica receberá sua solicitação para confirmar a vaga.','patient_sus'=>null]);
}

function professional_login(PDO $pdo, array $data): never {
    $email=strtolower(required_string($data,'email')); $senha=(string)($data['senha']??''); $key=auth_attempt_key('profissional_login',$email);
    if(auth_is_limited($pdo,$key)) json_response(['success'=>false,'message'=>'Muitas tentativas de acesso. Aguarde 15 minutos e tente novamente.'],429);
    $q=$pdo->prepare('SELECT id,nome,email,slug,senha_hash,status,email_verificado_em,auth_version FROM profissionais WHERE email=?');$q->execute([$email]);$p=$q->fetch();
    if(!$p||!password_verify($senha,$p['senha_hash'])) { auth_record_failure($pdo,$key); json_response(['success'=>false,'message'=>'E-mail ou senha inválidos.'],401); }
    // Cadastros antigos aguardavam verificação por e-mail; ao autenticar corretamente,
    // são ativados como no fluxo anterior. Contas suspensas continuam bloqueadas.
    if($p['status']==='pendente') { $pdo->prepare("UPDATE profissionais SET status='ativo' WHERE id=? AND status='pendente'")->execute([$p['id']]); $p['status']='ativo'; }
    if($p['status']!=='ativo') json_response(['success'=>false,'message'=>'Esta conta está suspensa. Entre em contato com o administrador.'],403);
    $p=ensure_professional_public_slug($pdo,$p);
    auth_clear_attempts($pdo,$key); session_regenerate_id(true); rotate_csrf_token();
    $_SESSION['_auth_created_at']=time(); $_SESSION['_last_activity']=time();
    $_SESSION['professional']=['id'=>(int)$p['id'],'email'=>$p['email'],'nome'=>$p['nome'],'slug'=>$p['slug']??'','auth_version'=>(int)$p['auth_version']];
    audit_professional_event($pdo,(int)$p['id'],'login_profissional','profissionais',(string)$p['id']);
    json_response(['success'=>true,'csrf_token'=>$_SESSION['_csrf_token'],'professional'=>['id'=>(int)$p['id'],'nome'=>$p['nome'],'email'=>$p['email'],'slug'=>$p['slug']??'']]);
}
function professional_me(PDO $pdo): never {
    $s=require_professional();$q=$pdo->prepare('SELECT id,nome,email,slug,cnpj,especialidade,registro_profissional AS registroProfissional,telefone,whatsapp,endereco,modalidade,valor_consulta AS valorConsulta,limite_diario AS limiteDiario,apresentacao,logo_arquivo AS logoArquivo,horario_funcionamento AS horarioFuncionamento,mensagem_pos_venda AS mensagemPosVenda,aviso_publico AS avisoPublico,cor_primaria AS corPrimaria,cor_secundaria AS corSecundaria,confirmacao_automatica AS confirmacaoAutomatica,cancelamento_ate_horas AS cancelamentoAteHoras,remarcacao_ate_horas AS remarcacaoAteHoras,status FROM profissionais WHERE id=?');
    $q->execute([$s['id']]);$professional=$q->fetch();
    if(!is_array($professional))json_response(['success'=>false,'message'=>'Perfil profissional não encontrado.'],404);
    $professional=ensure_professional_public_slug($pdo,$professional);
    json_response(['success'=>true,'professional'=>$professional]);
}

function professional_update_settings(PDO $pdo,array $data): never {
    $s=require_professional(); $nome=required_string($data,'nome');
    if(!array_key_exists('logo_arquivo',$data)){ $keep=$pdo->prepare('SELECT logo_arquivo FROM profissionais WHERE id=?'); $keep->execute([$s['id']]); $data['logo_arquivo']=$keep->fetchColumn(); }
    $limite=max(1,(int)($data['limite_diario']??12));
    $fields=['cnpj','especialidade','registro_profissional','telefone','whatsapp','endereco','modalidade','apresentacao','logo_arquivo','horario_funcionamento','aviso_publico','mensagem_pos_venda','cor_primaria','cor_secundaria']; $v=[]; foreach($fields as $f)$v[]=trim((string)($data[$f]??''))?:null;
    $confirmacao=isset($data['confirmacao_automatica'])?filter_var($data['confirmacao_automatica'],FILTER_VALIDATE_BOOLEAN):true;
    $cancelar=max(0,min(720,(int)($data['cancelamento_ate_horas']??24)));
    $remarcar=max(0,min(720,(int)($data['remarcacao_ate_horas']??24)));
    $q=$pdo->prepare('UPDATE profissionais SET nome=?,cnpj=?,especialidade=?,registro_profissional=?,telefone=?,whatsapp=?,endereco=?,modalidade=?,apresentacao=?,logo_arquivo=?,horario_funcionamento=?,aviso_publico=?,mensagem_pos_venda=?,cor_primaria=COALESCE(?,cor_primaria),cor_secundaria=COALESCE(?,cor_secundaria),valor_consulta=?,limite_diario=?,confirmacao_automatica=?,cancelamento_ate_horas=?,remarcacao_ate_horas=? WHERE id=?');
    $q->execute([$nome,$v[0],$v[1],$v[2],$v[3],$v[4],$v[5],$v[6],$v[7],$v[8],$v[9],$v[10],$v[11],$v[12],$v[13],$data['valor_consulta']??null,$limite,$confirmacao,$cancelar,$remarcar,$s['id']]); professional_me($pdo);
}
function professional_update_relationship_message(PDO $pdo,array $data): never {
    $s=require_professional(); $message=trim((string)($data['mensagem_pos_venda']??''));
    if($message==='') json_response(['success'=>false,'message'=>'Informe uma mensagem de pós-atendimento.'],422);
    if(strlen($message)>2000) json_response(['success'=>false,'message'=>'A mensagem deve ter no máximo 2.000 caracteres.'],422);
    $q=$pdo->prepare('UPDATE profissionais SET mensagem_pos_venda=? WHERE id=?'); $q->execute([$message,$s['id']]);
    json_response(['success'=>true,'message'=>'Mensagem de pós-atendimento salva.']);
}
function professional_patient_limit(PDO $pdo,int $professionalId): array {
    $q=$pdo->prepare("SELECT p.limite_pacientes,a.status FROM assinaturas_profissionais a INNER JOIN planos_assinatura p ON p.id=a.plano_id WHERE a.profissional_id=? AND a.status='ativa' AND (a.fim IS NULL OR a.fim>=CURDATE()) ORDER BY a.id DESC LIMIT 1");
    $q->execute([$professionalId]);$sub=$q->fetch();
    return $sub ? ['limite'=>(int)$sub['limite_pacientes'],'assinado'=>true] : ['limite'=>5,'assinado'=>false];
}
function enforce_professional_patient_limit(PDO $pdo,int $professionalId,int $patientId=0): void {
    if($patientId>0){$q=$pdo->prepare('SELECT 1 FROM profissional_pacientes WHERE profissional_id=? AND paciente_id=?');$q->execute([$professionalId,$patientId]);if($q->fetchColumn())return;}
    $q=$pdo->prepare('SELECT COUNT(*) FROM profissional_pacientes WHERE profissional_id=?');$q->execute([$professionalId]);$count=(int)$q->fetchColumn();
    $limit=professional_patient_limit($pdo,$professionalId);
    if($count >= $limit['limite']) {
        $msg=$limit['assinado'] ? 'O limite de pacientes do seu plano foi atingido.' : 'Você já possui 5 pacientes gratuitos. Escolha e pague um plano para cadastrar novos pacientes.';
        json_response(['success'=>false,'message'=>$msg,'limitePacientes'=>$limit['limite'],'assinaturaNecessaria'=>!$limit['assinado']],409);
    }
}
function professional_link_patient(PDO $pdo,array $data): never {
    require_professional();
    json_response(['success'=>false,'message'=>'Esse tipo de vínculo não está disponível. Cadastre o paciente na clínica ou use o link público de agendamento.'],410);
}


function generate_patient_code(): string {
    return 'PAC-'.date('ym').'-'.strtoupper(bin2hex(random_bytes(3)));
}
function professional_create_patient(PDO $pdo,array $data): never {
    $s=require_professional();$nome=required_string($data,'nome');$telefone=trim((string)($data['telefone']??''));$email=strtolower(trim((string)($data['email']??'')));$cpf=normalize_cpf($data['cpf']??'');
    if($cpf!==''&&!valid_cpf($cpf))json_response(['success'=>false,'message'=>'CPF inválido.'],422);
    if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))json_response(['success'=>false,'message'=>'E-mail inválido.'],422);
    if($cpf!==''){$q=$pdo->prepare('SELECT 1 FROM profissional_pacientes pp INNER JOIN pacientes p ON p.id=pp.paciente_id WHERE pp.profissional_id=? AND p.cpf=? LIMIT 1');$q->execute([$s['id'],$cpf]);if($q->fetchColumn())json_response(['success'=>false,'message'=>'Este paciente já está cadastrado nesta clínica.'],409);}
    enforce_professional_patient_limit($pdo,$s['id']);$sus='CLI-'.$s['id'].'-'.strtoupper(bin2hex(random_bytes(8)));$codigo=generate_patient_code();
    $q=$pdo->prepare('INSERT INTO pacientes (codigo,nome,telefone,email,cpf,sus,ubs_id) VALUES (?,?,?,?,?,?,NULL)');$q->execute([$codigo,$nome,$telefone?:null,$email?:null,$cpf?:null,$sus]);$pid=(int)$pdo->lastInsertId();
    $q=$pdo->prepare('INSERT INTO profissional_pacientes (profissional_id,paciente_id) VALUES (?,?)');$q->execute([$s['id'],$pid]);
    json_response(['success'=>true,'patient'=>['id'=>$pid,'codigo'=>$codigo,'nome'=>$nome,'telefone'=>$telefone,'email'=>$email,'cpf'=>$cpf]]);
}
function professional_patient_history(PDO $pdo): never {
    $s=require_professional();$pid=(int)($_GET['patient_id']??0);if(!$pid)json_response(['success'=>false,'message'=>'Paciente não informado.'],422);
    assert_prof_patient($pdo,$s['id'],$pid);
    $q=$pdo->prepare("SELECT p.id,p.codigo,p.nome,p.telefone,p.email,p.cpf,p.sus,p.endereco,p.data_nascimento AS nascimento,
        c.id AS consultaId,c.data_consulta AS data,c.horario,c.assunto,c.valor,c.forma_pagamento AS formaPagamento,c.tipo_cartao AS tipoCartao,c.parcelas,c.recibo_valor AS reciboValor,c.pagamento_status AS pagamentoStatus,c.pago_em AS pagoEm,c.status
        FROM pacientes p LEFT JOIN consultas_profissionais c ON c.paciente_id=p.id AND c.profissional_id=?
        WHERE p.id=? ORDER BY c.data_consulta DESC,c.horario DESC");
    $q->execute([$s['id'],$pid]);$rows=$q->fetchAll();audit_professional_event($pdo,(int)$s['id'],'historico_paciente_consultado','pacientes',(string)$pid,['consultas'=>count($rows)]);
    json_response(['success'=>true,'patient'=>$rows[0]??null,'appointments'=>$rows]);
}
function professional_finance(PDO $pdo): never {
    $s=require_professional();
    $inicio=trim((string)($_GET['inicio']??''));$fim=trim((string)($_GET['fim']??''));$forma=trim((string)($_GET['forma_pagamento']??''));$busca=trim((string)($_GET['busca']??''));
    $sql="SELECT f.id,f.recebido_em AS data,f.valor,f.forma_pagamento AS formaPagamento,f.tipo_cartao AS tipoCartao,f.parcelas,
        f.consulta_id AS consultaId,p.codigo,p.nome AS paciente
        FROM pagamentos_pacientes f INNER JOIN pacientes p ON p.id=f.paciente_id WHERE f.profissional_id=?";
    $params=[$s['id']];
    if($inicio!==''){ $sql.=" AND DATE(f.recebido_em)>=?";$params[]=$inicio; }
    if($fim!==''){ $sql.=" AND DATE(f.recebido_em)<=?";$params[]=$fim; }
    if($forma!==''){ $sql.=" AND f.forma_pagamento=?";$params[]=$forma; }
    if($busca!==''){ $sql.=" AND (p.nome LIKE ? OR p.codigo LIKE ? OR f.consulta_id LIKE ?)";$like="%$busca%";array_push($params,$like,$like,$like); }
    $sql.=" ORDER BY f.recebido_em DESC,f.id DESC";
    $q=$pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll();
    $total=array_sum(array_map(fn($r)=>(float)$r['valor'],$rows));
    json_response(['success'=>true,'payments'=>$rows,'total'=>$total]);
}
function professional_register_payment(PDO $pdo,array $data): never {
    $s=require_professional();$pid=(int)($data['patient_id']??0);$consulta=trim((string)($data['consulta_id']??''));$valor=(float)($data['valor']??0);
    $forma=trim((string)($data['forma_pagamento']??''));$tipo=trim((string)($data['tipo_cartao']??''));$parcelas=(int)($data['parcelas']??1);
    if(!$pid||$valor<=0||!in_array($forma,['pix','dinheiro','cartao','boleto'],true))json_response(['success'=>false,'message'=>'Informe paciente, valor e forma de pagamento válida.'],422);
    assert_prof_patient($pdo,$s['id'],$pid);
    if($forma==='cartao'&&!in_array($tipo,['credito','debito'],true))json_response(['success'=>false,'message'=>'Selecione crédito ou débito.'],422);
    if($forma==='cartao' && $tipo==='debito') $parcelas=1;
    if($forma!=='cartao'){ $tipo=null;$parcelas=null; } if($consulta!==''){ $q=$pdo->prepare("SELECT id FROM pagamentos_pacientes WHERE profissional_id=? AND consulta_id=? LIMIT 1");$q->execute([$s['id'],$consulta]);if($q->fetch())json_response(['success'=>false,'message'=>'Esta consulta já possui um lançamento financeiro. Ele não pode ser alterado nem duplicado.'],409); }
    if($forma==='cartao' && ($parcelas<1||$parcelas>24))json_response(['success'=>false,'message'=>'Quantidade de parcelas inválida.'],422);
    $q=$pdo->prepare('INSERT INTO pagamentos_pacientes (profissional_id,paciente_id,consulta_id,valor,forma_pagamento,tipo_cartao,parcelas) VALUES (?,?,?,?,?,?,?)');
    $q->execute([$s['id'],$pid,$consulta?:null,$valor,$forma,$tipo,$parcelas?:null]);
    if($consulta!==''){
        $q=$pdo->prepare("UPDATE consultas_profissionais SET recibo_valor=?,forma_pagamento=?,tipo_cartao=?,parcelas=?,pagamento_status='pago',pago_em=NOW(),valor=? WHERE id=? AND profissional_id=? AND pagamento_status<>'pago'");
        $q->execute([$valor,$forma,$tipo,$parcelas?:null,$valor,$consulta,$s['id']]);
    }
    json_response(['success'=>true,'payment_id'=>(int)$pdo->lastInsertId(),'message'=>'Pagamento registrado. Este lançamento não pode ser editado.']);
}
function professional_receipt(PDO $pdo): never {
    $s=require_professional();$consulta=required_string(['id'=>$_GET['id']??''],'id');
    $q=$pdo->prepare("SELECT c.*,p.codigo,p.nome AS paciente,p.telefone AS pacienteTelefone,pr.nome AS clinicaNome,pr.cnpj,pr.endereco AS clinicaEndereco,pr.telefone AS clinicaTelefone
        FROM consultas_profissionais c INNER JOIN pacientes p ON p.id=c.paciente_id INNER JOIN profissionais pr ON pr.id=c.profissional_id
        WHERE c.id=? AND c.profissional_id=? LIMIT 1");$q->execute([$consulta,$s['id']]);$r=$q->fetch();
    if(!$r)json_response(['success'=>false,'message'=>'Consulta não encontrada.'],404);
    json_response(['success'=>true,'receipt'=>$r]);
}
function professional_patients(PDO $pdo): never { $s=require_professional();$q=$pdo->prepare('SELECT p.id,p.codigo,p.nome,p.telefone,p.email,p.cpf,p.sus,p.endereco,p.data_nascimento AS nascimento,p.condicoes_saude AS condicoesSaude,p.alergias,p.medicamentos,p.informacoes_adicionais AS informacoesAdicionais,pp.consentimento_em AS consentimentoEm FROM profissional_pacientes pp INNER JOIN pacientes p ON p.id=pp.paciente_id WHERE pp.profissional_id=? ORDER BY p.nome');$q->execute([$s['id']]);$patients=$q->fetchAll();audit_professional_event($pdo,(int)$s['id'],'lista_pacientes_consultada','profissional_pacientes',(string)$s['id'],['quantidade'=>count($patients)]);json_response(['success'=>true,'patients'=>$patients]);}
function assert_prof_patient(PDO $pdo,int $professionalId,int $patientId): void { $q=$pdo->prepare('SELECT 1 FROM profissional_pacientes WHERE profissional_id=? AND paciente_id=?');$q->execute([$professionalId,$patientId]);if(!$q->fetchColumn())json_response(['success'=>false,'message'=>'Paciente não está vinculado a este profissional.'],403);}
function professional_create_record(PDO $pdo,array $data): never { $s=require_professional();$patient=(int)($data['patient_id']??0);$content=required_string($data,'conteudo');assert_prof_patient($pdo,$s['id'],$patient);$q=$pdo->prepare('INSERT INTO prontuarios_profissionais (profissional_id,paciente_id,consulta_id,tipo,conteudo) VALUES (?,?,?,?,?)');$q->execute([$s['id'],$patient,trim((string)($data['consulta_id']??''))?:null,trim((string)($data['tipo']??'evolucao')),$content]);$recordId=(int)$pdo->lastInsertId();audit_professional_event($pdo,(int)$s['id'],'prontuario_criado','prontuarios_profissionais',(string)$recordId,['patient_id'=>$patient]);json_response(['success'=>true,'record_id'=>$recordId]);}
function professional_update_patient(PDO $pdo,array $data): never { $s=require_professional();$patient=(int)($data['patient_id']??0);if(!$patient)json_response(['success'=>false,'message'=>'Paciente não informado.'],422);assert_prof_patient($pdo,$s['id'],$patient);$nome=required_string($data,'nome');$telefone=trim((string)($data['telefone']??''));$email=trim((string)($data['email']??''));$nascimento=trim((string)($data['data_nascimento']??''));$endereco=trim((string)($data['endereco']??''));$condicoes=trim((string)($data['condicoes_saude']??''));$alergias=trim((string)($data['alergias']??''));$medicamentos=trim((string)($data['medicamentos']??''));$adicionais=trim((string)($data['informacoes_adicionais']??''));if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))json_response(['success'=>false,'message'=>'E-mail do paciente inválido.'],422);if($nascimento!==''&&!valid_date($nascimento))json_response(['success'=>false,'message'=>'Data de nascimento inválida.'],422);$q=$pdo->prepare('UPDATE pacientes SET nome=?,telefone=?,email=?,data_nascimento=?,endereco=?,condicoes_saude=?,alergias=?,medicamentos=?,informacoes_adicionais=? WHERE id=?');$q->execute([$nome,$telefone?:null,$email?:null,$nascimento?:null,$endereco?:null,$condicoes?:null,$alergias?:null,$medicamentos?:null,$adicionais?:null,$patient]);audit_professional_event($pdo,(int)$s['id'],'cadastro_paciente_atualizado','pacientes',(string)$patient);json_response(['success'=>true,'message'=>'Cadastro do paciente atualizado.']);}
function professional_upload_logo(PDO $pdo): never { $s=require_professional();if(empty($_FILES['logo'])||$_FILES['logo']['error']!==UPLOAD_ERR_OK)json_response(['success'=>false,'message'=>'Selecione uma imagem válida.'],422);$file=$_FILES['logo'];if($file['size']>5*1024*1024)json_response(['success'=>false,'message'=>'A logo deve ter no máximo 5 MB.'],422);$mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$allowed=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];if(!isset($allowed[$mime]))json_response(['success'=>false,'message'=>'Use PNG, JPG ou WEBP.'],422);$dir=__DIR__.'/uploads/marca';if(!is_dir($dir))mkdir($dir,0750,true);$name='prof-'.$s['id'].'-'.bin2hex(random_bytes(8)).'.'.$allowed[$mime];if(!move_uploaded_file($file['tmp_name'],$dir.'/'.$name))json_response(['success'=>false,'message'=>'Não foi possível salvar a logo.'],500);$q=$pdo->prepare('UPDATE profissionais SET logo_arquivo=? WHERE id=?');$q->execute([$name,$s['id']]);json_response(['success'=>true,'arquivo'=>$name,'url'=>'uploads/marca/'.$name]);}

function professional_records(PDO $pdo): never { $s=require_professional();$patient=(int)($_GET['patient_id']??0);if(!$patient)json_response(['success'=>false,'message'=>'Paciente não informado.'],422);assert_prof_patient($pdo,$s['id'],$patient);$q=$pdo->prepare('SELECT id,consulta_id AS consultaId,tipo,conteudo,criado_em AS criadoEm FROM prontuarios_profissionais WHERE profissional_id=? AND paciente_id=? ORDER BY criado_em DESC');$q->execute([$s['id'],$patient]);$records=$q->fetchAll();audit_professional_event($pdo,(int)$s['id'],'prontuario_consultado','prontuarios_profissionais',(string)$patient,['quantidade'=>count($records)]);json_response(['success'=>true,'records'=>$records]);}
function professional_relationships(PDO $pdo): never {
    $s=require_professional();
    $q=$pdo->prepare("SELECT p.id,p.nome,p.cpf,p.telefone,p.email,MAX(r.enviado_em) AS ultimoContato,COUNT(r.id) AS totalContatos,MAX(c.data_consulta) AS ultimaConsulta FROM profissional_pacientes pp INNER JOIN pacientes p ON p.id=pp.paciente_id LEFT JOIN relacionamento_contatos r ON r.profissional_id=pp.profissional_id AND r.paciente_id=p.id LEFT JOIN consultas_profissionais c ON c.profissional_id=pp.profissional_id AND c.paciente_id=p.id WHERE pp.profissional_id=? GROUP BY p.id,p.nome,p.cpf,p.telefone,p.email ORDER BY p.nome");
    $q->execute([$s['id']]); json_response(['success'=>true,'patients'=>$q->fetchAll()]);
}
function professional_log_relationship(PDO $pdo,array $data): never {
    $s=require_professional(); $pid=(int)($data['patient_id']??0); $message=trim((string)($data['mensagem']??''));
    if(!$pid || $message==='') json_response(['success'=>false,'message'=>'Paciente e mensagem são obrigatórios.'],422); assert_prof_patient($pdo,$s['id'],$pid);
    $q=$pdo->prepare('INSERT INTO relacionamento_contatos (profissional_id,paciente_id,mensagem) VALUES (?,?,?)'); $q->execute([$s['id'],$pid,$message]);
    json_response(['success'=>true,'enviadoEm'=>date('Y-m-d H:i:s')]);
}
function professional_create_appointment(PDO $pdo,array $data): never {
    $s=require_professional();$patient=(int)($data['patient_id']??0);$date=required_string($data,'data_consulta');$time=trim((string)($data['horario']??''));
    if(!valid_date($date)||!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$time))json_response(['success'=>false,'message'=>'Data ou horário inválido.'],422);
    assert_prof_patient($pdo,$s['id'],$patient);$pdo->beginTransaction();
    try {
        $lock=$pdo->prepare('SELECT id FROM profissionais WHERE id=? FOR UPDATE');$lock->execute([$s['id']]);
        $slots=professional_slots($pdo,(int)$s['id'],$date);$slot=null;foreach($slots as $candidate){if($candidate['horario']===$time){$slot=$candidate;break;}}
        if(!$slot){$pdo->rollBack();json_response(['success'=>false,'message'=>'O horário não está disponível na agenda configurada.'],409);}
        $id='P'.date('YmdHis').bin2hex(random_bytes(3));
        $q=$pdo->prepare("INSERT INTO consultas_profissionais (id,profissional_id,paciente_id,data_consulta,horario,duracao_minutos,assunto,valor,status,confirmada_em) VALUES (?,?,?,?,?,?,?,?,'confirmada',NOW())");
        $q->execute([$id,$s['id'],$patient,$date,$time,(int)$slot['duracao'],trim((string)($data['assunto']??''))?:null,$data['valor']??null]);$pdo->commit();
        criar_notificacoes_profissionais($pdo,(int)$s['id'],$patient,$id,$date,$time,'','');
        audit_professional_event($pdo,(int)$s['id'],'agendamento_criado','consultas_profissionais',$id,['patient_id'=>$patient]);
        json_response(['success'=>true,'appointment_id'=>$id,'status'=>'confirmada']);
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function criar_notificacoes_profissionais(PDO $pdo,int $profissionalId,int $pacienteId,string $consultaId,string $date,string $time,string $nome='',string $clinica=''): void {
    $q=$pdo->prepare('SELECT nome,telefone,email FROM pacientes WHERE id=?');$q->execute([$pacienteId]);$p=$q->fetch()?:[];
    $nome=$nome?:($p['nome']??'Paciente');$clinica=$clinica?:'sua clínica';
    $when=DateTime::createFromFormat('Y-m-d H:i:s',$date.' '.$time.':00') ?: new DateTime($date.' '.$time);
    $confirm="Olá, {$nome}! Sua consulta na {$clinica} foi confirmada para {$when->format('d/m/Y')} às {$when->format('H:i')}.";
    $reminder=(clone $when)->modify('-1 day'); $rem="Lembrete: {$nome}, sua consulta na {$clinica} será amanhã, {$when->format('d/m/Y')} às {$when->format('H:i')}.";
    try {
        $q=$pdo->prepare('INSERT IGNORE INTO notificacoes_profissionais (profissional_id,paciente_id,consulta_id,tipo,mensagem,agendada_para) VALUES (?,?,?,?,?,?)');
        $q->execute([$profissionalId,$pacienteId,$consultaId,'confirmacao',$confirm,(new DateTime())->format('Y-m-d H:i:s')]);
        $q->execute([$profissionalId,$pacienteId,$consultaId,'lembrete',$rem,$reminder->format('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        error_log('Notificacao profissional nao gravada: '.$e->getMessage());
    }
}
function professional_daily_report(PDO $pdo): never {
    $s=require_professional();$date=trim((string)($_GET['data']??date('Y-m-d')));if(!valid_date($date))json_response(['success'=>false,'message'=>'Data do relatório inválida.'],422);
    $q=$pdo->prepare('SELECT f.id,f.recebido_em AS data,f.valor,f.forma_pagamento AS formaPagamento,f.tipo_cartao AS tipoCartao,f.parcelas,f.consulta_id AS consultaId,p.codigo,p.nome AS paciente FROM pagamentos_pacientes f INNER JOIN pacientes p ON p.id=f.paciente_id WHERE f.profissional_id=? AND DATE(f.recebido_em)=? ORDER BY f.recebido_em');$q->execute([$s['id'],$date]);$rows=$q->fetchAll();$total=array_sum(array_map(fn($r)=>(float)$r['valor'],$rows));$formas=[];foreach($rows as $r){$formas[$r['formaPagamento']]=($formas[$r['formaPagamento']]??0)+(float)$r['valor'];}json_response(['success'=>true,'data'=>$date,'payments'=>$rows,'total'=>$total,'porForma'=>$formas]);
}
function professional_appointments(PDO $pdo): never { $s=require_professional();$q=$pdo->prepare('SELECT c.id,c.data_consulta AS data,c.horario,c.assunto,c.valor,c.forma_pagamento AS formaPagamento,c.pagamento_status AS pagamentoStatus,c.pago_em AS pagoEm,c.status,c.cancelamento_motivo AS cancelamentoMotivo,p.id AS patientId,p.nome AS paciente,p.sus,p.telefone,p.email,pr.nome AS clinicaNome,pr.cnpj AS clinicaCnpj,pr.endereco AS clinicaEndereco,pr.telefone AS clinicaTelefone FROM consultas_profissionais c INNER JOIN pacientes p ON p.id=c.paciente_id INNER JOIN profissionais pr ON pr.id=c.profissional_id WHERE c.profissional_id=? ORDER BY c.data_consulta,c.horario');$q->execute([$s['id']]);json_response(['success'=>true,'appointments'=>$q->fetchAll()]);}
function promote_professional_waitlist(PDO $pdo,int $professionalId,string $date,string $time): ?array {
    $q=$pdo->prepare('SELECT nome,limite_diario,confirmacao_automatica,slug,valor_consulta FROM profissionais WHERE id=?');$q->execute([$professionalId]);$pro=$q->fetch();if(!$pro)return null;
    $pdo->beginTransaction();try{
        $lock=$pdo->prepare('SELECT id FROM profissionais WHERE id=? FOR UPDATE');$lock->execute([$professionalId]);
        $slots=professional_slots($pdo,$professionalId,$date);$free=null;foreach($slots as $slot){if($slot['horario']===substr($time,0,5)){$free=$slot;break;}}if(!$free){$pdo->rollBack();return null;}
        $q=$pdo->prepare("SELECT COUNT(*) FROM consultas_profissionais WHERE profissional_id=? AND data_consulta=? AND status NOT IN ('cancelada','faltou')");$q->execute([$professionalId,$date]);$count=(int)$q->fetchColumn();if($count>=max(1,(int)$pro['limite_diario'])){$pdo->rollBack();return null;}
        $q=$pdo->prepare('SELECT l.id,l.paciente_id,p.nome,p.email FROM lista_espera_profissionais l INNER JOIN pacientes p ON p.id=l.paciente_id WHERE l.profissional_id=? AND l.data_consulta=? AND l.status="pendente" ORDER BY l.id LIMIT 1 FOR UPDATE');$q->execute([$professionalId,$date]);$row=$q->fetch();if(!$row){$pdo->rollBack();return null;}
        $exists=$pdo->prepare("SELECT id FROM consultas_profissionais WHERE paciente_id=? AND profissional_id=? AND data_consulta=? AND status NOT IN ('cancelada','faltou') LIMIT 1");$exists->execute([$row['paciente_id'],$professionalId,$date]);if($exists->fetch()){ $pdo->prepare('UPDATE lista_espera_profissionais SET status="cancelado" WHERE id=?')->execute([$row['id']]);$pdo->commit();return promote_professional_waitlist($pdo,$professionalId,$date,$time); }
        $status=database_bool($pro['confirmacao_automatica']??false)?'confirmada':'solicitada';$manageToken=bin2hex(random_bytes(32));$id='PWL'.date('YmdHis').bin2hex(random_bytes(3));$ins=$pdo->prepare("INSERT INTO consultas_profissionais (id,profissional_id,paciente_id,data_consulta,horario,duracao_minutos,assunto,valor,status,confirmada_em,manage_token_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?)");$ins->execute([$id,$professionalId,$row['paciente_id'],$date,$time,(int)$free['duracao'],'Vaga oferecida pela lista de espera',$pro['valor_consulta'],$status,$status==='confirmada'?date('Y-m-d H:i:s'):null,hash('sha256',$manageToken)]);
        $pdo->prepare('UPDATE lista_espera_profissionais SET status="agendado",consulta_id=? WHERE id=?')->execute([$id,$row['id']]);
        $msg="Olá, {$row['nome']}! Uma vaga ficou disponível na {$pro['nome']}. ".($status==='confirmada'?'Sua consulta está confirmada.':'Sua solicitação foi encaminhada para confirmação da clínica.')." Data: ".date('d/m/Y',strtotime($date))." às ".substr($time,0,5).". Protocolo: {$id}.";
        $pdo->prepare('INSERT IGNORE INTO notificacoes_profissionais (profissional_id,paciente_id,consulta_id,tipo,canal,mensagem,agendada_para,status) VALUES (?,? ,?,"lista_espera_agendada","whatsapp",?,NOW(),"pendente")')->execute([$professionalId,$row['paciente_id'],$id,$msg]);
        $pdo->commit();if(!empty($row['email'])){try{$url=app_public_url().'?clinica='.rawurlencode($pro['slug']).'&gerenciar='.rawurlencode($manageToken);send_appointment_email($row['email'],$row['nome'],$pro['nome'],$date,substr($time,0,5),$status,$id,$url);}catch(Throwable $mailError){error_log('Falha ao enviar convite da lista de espera '.$id.': '.$mailError->getMessage());}}return ['id'=>$id,'patientId'=>(int)$row['paciente_id'],'nome'=>$row['nome'],'status'=>$status];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function professional_waitlist(PDO $pdo): never {
    $s=require_professional();$q=$pdo->prepare('SELECT l.id,l.data_consulta AS data,l.criado_em AS criadoEm,p.codigo,p.nome,p.telefone FROM lista_espera_profissionais l INNER JOIN pacientes p ON p.id=l.paciente_id WHERE l.profissional_id=? AND l.status="pendente" ORDER BY l.data_consulta,l.id');$q->execute([$s['id']]);json_response(['success'=>true,'waitlist'=>$q->fetchAll()]);
}
function professional_cancel_waitlist(PDO $pdo,array $data): never {
    $s=require_professional();$id=(int)($data['id']??0);if($id<1)json_response(['success'=>false,'message'=>'Item da lista de espera não informado.'],422);
    $q=$pdo->prepare("UPDATE lista_espera_profissionais SET status='cancelado' WHERE id=? AND profissional_id=? AND status='pendente'");$q->execute([$id,$s['id']]);
    if($q->rowCount()===0)json_response(['success'=>false,'message'=>'Este item não está mais pendente ou não pertence à sua clínica.'],409);
    audit_professional_event($pdo,(int)$s['id'],'lista_espera_cancelada','lista_espera_profissionais',(string)$id);json_response(['success'=>true,'message'=>'Paciente removido da lista de espera.']);
}
function professional_update_appointment(PDO $pdo,array $data): never {
    $s=require_professional();$id=required_string($data,'id');$pdo->beginTransaction();
    try {
        $lock=$pdo->prepare('SELECT id FROM profissionais WHERE id=? FOR UPDATE');$lock->execute([$s['id']]);
        $q=$pdo->prepare('SELECT id,data_consulta,horario,status,pagamento_status,duracao_minutos,forma_pagamento FROM consultas_profissionais WHERE id=? AND profissional_id=? FOR UPDATE');$q->execute([$id,$s['id']]);$old=$q->fetch();
        if(!$old){$pdo->rollBack();json_response(['success'=>false,'message'=>'Consulta não encontrada.'],404);}
        $status=trim((string)($data['status']??$old['status']));$allowed=['solicitada','agendada','confirmada','atendida','cancelada','faltou'];if(!in_array($status,$allowed,true))json_response(['success'=>false,'message'=>'Status inválido.'],422);
        $date=trim((string)($data['data_consulta']??$old['data_consulta']));$time=trim((string)($data['horario']??substr((string)$old['horario'],0,5)));if(!valid_date($date)||!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$time))json_response(['success'=>false,'message'=>'Data ou horário inválido.'],422);
        $changed=($date!==$old['data_consulta']||$time!==substr((string)$old['horario'],0,5));$reviving=in_array($old['status'],['cancelada','faltou'],true)&&!in_array($status,['cancelada','faltou'],true);$duration=(int)$old['duracao_minutos'];
        if(($changed||$reviving)&&!in_array($status,['cancelada','faltou'],true)){$slots=professional_slots($pdo,(int)$s['id'],$date,$id);$selected=null;foreach($slots as $slot){if($slot['horario']===$time){$selected=$slot;break;}}if(!$selected)json_response(['success'=>false,'message'=>'O novo horário não está livre na agenda.'],409);$duration=(int)$selected['duracao'];}
        $cancel=trim((string)($data['cancelamento_motivo']??''));$paymentStatus=(string)($data['pagamento_status']??$old['pagamento_status']);if(!in_array($paymentStatus,['pendente','pago','dispensado'],true))$paymentStatus=$old['pagamento_status'];$method=trim((string)($data['forma_pagamento']??$old['forma_pagamento']??''));
        $q=$pdo->prepare("UPDATE consultas_profissionais SET data_consulta=?,horario=?,duracao_minutos=?,status=?,cancelamento_motivo=?,forma_pagamento=?,pagamento_status=?,confirmada_em=CASE WHEN ?='confirmada' THEN COALESCE(confirmada_em,NOW()) ELSE confirmada_em END,atendida_em=CASE WHEN ?='atendida' THEN COALESCE(atendida_em,NOW()) ELSE atendida_em END WHERE id=? AND profissional_id=?");
        $q->execute([$date,$time,$duration,$status,$cancel,$method?:null,$paymentStatus,$status,$status,$id,$s['id']]);$pdo->commit();
        audit_professional_event($pdo,(int)$s['id'],'consulta_atualizada','consultas_profissionais',$id,['status'=>$status,'remarcada'=>$changed]);
        if($old['status']!==$status||$changed){$q=$pdo->prepare('SELECT p.nome,p.email,pr.nome AS clinica,c.data_consulta,c.horario,c.status FROM consultas_profissionais c INNER JOIN pacientes p ON p.id=c.paciente_id INNER JOIN profissionais pr ON pr.id=c.profissional_id WHERE c.id=? AND c.profissional_id=?');$q->execute([$id,$s['id']]);$a=$q->fetch();if($a&&!empty($a['email'])){try{send_appointment_email($a['email'],$a['nome'],$a['clinica'],$a['data_consulta'],substr((string)$a['horario'],0,5),$a['status'],$id,'');}catch(Throwable $mailError){error_log('Falha ao avisar alteração de consulta '.$id.': '.$mailError->getMessage());}}}
        if($status==='cancelada'&&$old['status']!=='cancelada')promote_professional_waitlist($pdo,(int)$s['id'],$date,$time);
        json_response(['success'=>true,'message'=>'Consulta atualizada. Dados financeiros salvos permanecem imutáveis.']);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function subscription_plans(PDO $pdo): never { $q=$pdo->query('SELECT id,codigo,nome,valor_mensal AS valorMensal,limite_pacientes AS limitePacientes FROM planos_assinatura WHERE ativo=1 ORDER BY valor_mensal');json_response(['success'=>true,'plans'=>$q->fetchAll()]);}
function professional_subscription(PDO $pdo): never { $s=require_professional();$q=$pdo->prepare('SELECT a.id,a.status,a.inicio,a.fim,p.nome AS plano,p.valor_mensal AS valorMensal,p.limite_pacientes AS limitePacientes FROM assinaturas_profissionais a INNER JOIN planos_assinatura p ON p.id=a.plano_id WHERE a.profissional_id=? ORDER BY a.id DESC LIMIT 1');$q->execute([$s['id']]);json_response(['success'=>true,'subscription'=>$q->fetch()?:null]);}
function admin_professional_payments(PDO $pdo): never { $s=require_admin();if($s['tipo']!=='desenvolvedor')json_response(['success'=>false,'message'=>'Somente o desenvolvedor pode administrar pagamentos.'],403);$q=$pdo->query('SELECT pg.id,pg.profissional_id,pr.nome AS profissional,pg.valor,pg.status,pg.metodo,pg.criado_em AS criadoEm,a.id AS assinaturaId,pl.nome AS plano FROM pagamentos_profissionais pg INNER JOIN profissionais pr ON pr.id=pg.profissional_id LEFT JOIN assinaturas_profissionais a ON a.id=pg.assinatura_id LEFT JOIN planos_assinatura pl ON pl.id=a.plano_id ORDER BY pg.id DESC');json_response(['success'=>true,'payments'=>$q->fetchAll()]);}
function admin_update_professional_payment(PDO $pdo,array $data): never { $s=require_admin();if($s['tipo']!=='desenvolvedor')json_response(['success'=>false,'message'=>'Somente o desenvolvedor pode aprovar pagamentos.'],403);$id=(int)($data['id']??0);$status=required_string($data,'status');if(!in_array($status,['aprovado','recusado','estornado','manual'],true))json_response(['success'=>false,'message'=>'Status de pagamento inválido.'],422);$pdo->beginTransaction();$q=$pdo->prepare('SELECT profissional_id,assinatura_id FROM pagamentos_profissionais WHERE id=? FOR UPDATE');$q->execute([$id]);$pay=$q->fetch();if(!$pay){$pdo->rollBack();json_response(['success'=>false,'message'=>'Pagamento não encontrado.'],404);}$q=$pdo->prepare('UPDATE pagamentos_profissionais SET status=?,pago_em=IF(? IN (\'aprovado\',\'manual\'),NOW(),NULL) WHERE id=?');$q->execute([$status,$status,$id]);if($pay['assinatura_id']){$subStatus=$status==='aprovado'||$status==='manual'?'ativa':($status==='estornado'?'cancelada':'inadimplente');$q=$pdo->prepare('UPDATE assinaturas_profissionais SET status=?,inicio=IF(?=\'ativa\' AND inicio IS NULL,CURDATE(),inicio),fim=IF(?=\'ativa\',DATE_ADD(CURDATE(),INTERVAL 1 MONTH),fim) WHERE id=?');$q->execute([$subStatus,$subStatus,$subStatus,$pay['assinatura_id']]);}$pdo->commit();audit_event($pdo,'pagamento_profissional_atualizado','pagamentos_profissionais',(string)$id,['status'=>$status]);json_response(['success'=>true,'status'=>$status]);}

function asaas_request(string $method,string $path,array $payload=[]): array {
    $key=getenv('ASAAS_API_KEY') ?: ''; if($key==='') throw new RuntimeException('Integração Asaas não configurada. Defina ASAAS_API_KEY no servidor.');
    $base=(getenv('ASAAS_ENV')==='production')?'https://api.asaas.com/api/v3':'https://api-sandbox.asaas.com/api/v3';
    $ch=curl_init($base.$path); $headers=['Content-Type: application/json','access_token: '.$key];
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>20]);
    if($payload!==[]) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_UNESCAPED_UNICODE));
    $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    if($raw===false||$err!=='') throw new RuntimeException('Não foi possível comunicar com o Asaas.');
    $data=json_decode($raw,true)?:[]; if($http<200||$http>=300) throw new RuntimeException((string)($data['errors'][0]['description']??'O Asaas recusou a cobrança.')); return $data;
}
function professional_start_subscription(PDO $pdo,array $data): never {
    $s=require_professional();$plan=(int)($data['plano_id']??0);$metodo=trim((string)($data['metodo']??''));
    if(!in_array($metodo,['pix','cartao','boleto'],true))json_response(['success'=>false,'message'=>'Escolha PIX, cartão ou boleto.'],422);
    $q=$pdo->prepare('SELECT id,valor_mensal,nome FROM planos_assinatura WHERE id=? AND ativo=1');$q->execute([$plan]);$p=$q->fetch();if(!$p)json_response(['success'=>false,'message'=>'Plano não encontrado.'],404);
    $pdo->beginTransaction();$q=$pdo->prepare('INSERT INTO assinaturas_profissionais (profissional_id,plano_id,status,gateway) VALUES (?,?,\'ativa\',\'demonstracao\')');$q->execute([$s['id'],$plan]);$sub=(int)$pdo->lastInsertId();
    $q=$pdo->prepare('UPDATE assinaturas_profissionais SET inicio=CURDATE(),fim=DATE_ADD(CURDATE(),INTERVAL 1 MONTH) WHERE id=?');$q->execute([$sub]);
    $q=$pdo->prepare('INSERT INTO pagamentos_profissionais (profissional_id,assinatura_id,valor,status,metodo,gateway,pago_em) VALUES (?,?,?,\'manual\',?,\'demonstracao\',NOW())');$q->execute([$s['id'],$sub,$p['valor_mensal'],$metodo]);$pdo->commit();
    json_response(['success'=>true,'message'=>'Demonstração ativada. Nenhuma cobrança real foi criada.','subscription_id'=>$sub,'status'=>'ativa','metodo'=>$metodo]);
}

function aplicar_status_pagamento_asaas(PDO $pdo,string $paymentId,string $status): void {
    $map=['PAYMENT_RECEIVED'=>'aprovado','PAYMENT_CONFIRMED'=>'aprovado','PAYMENT_RECEIVED_IN_CASH'=>'aprovado','PAYMENT_OVERDUE'=>'recusado','PAYMENT_REFUNDED'=>'estornado','PAYMENT_CHARGEBACK_REQUESTED'=>'estornado','PAYMENT_CHARGEBACK_DISPUTE'=>'estornado','RECEIVED'=>'aprovado','CONFIRMED'=>'aprovado','RECEIVED_IN_CASH'=>'aprovado','OVERDUE'=>'recusado','REFUNDED'=>'estornado'];$local=$map[$status]??null;if($local===null)return;
    $q=$pdo->prepare('SELECT id,assinatura_id FROM pagamentos_profissionais WHERE referencia_externa=? LIMIT 1');$q->execute([$paymentId]);$pay=$q->fetch();if(!$pay)return;
    $pdo->beginTransaction();$q=$pdo->prepare('UPDATE pagamentos_profissionais SET status=?,pago_em=IF(?=\'aprovado\',NOW(),NULL) WHERE id=?');$q->execute([$local,$local,$pay['id']]);
    if($pay['assinatura_id']){$sub=$local==='aprovado'?'ativa':($local==='estornado'?'cancelada':'inadimplente');$q=$pdo->prepare('UPDATE assinaturas_profissionais SET status=?,inicio=IF(?=\'ativa\' AND inicio IS NULL,CURDATE(),inicio),fim=IF(?=\'ativa\',DATE_ADD(CURDATE(),INTERVAL 1 MONTH),fim) WHERE id=?');$q->execute([$sub,$sub,$sub,$pay['assinatura_id']]);}$pdo->commit();
}
function asaas_webhook(PDO $pdo): never {
    $expected=getenv('ASAAS_WEBHOOK_TOKEN')?:'';$received=$_SERVER['HTTP_ASAAS_ACCESS_TOKEN']??'';if($expected===''||!hash_equals($expected,$received))json_response(['success'=>false,'message'=>'Webhook não autorizado.'],401);
    $payload=json_decode(file_get_contents('php://input'),true)?:[];$payment=$payload['payment']??[];$id=trim((string)($payment['id']??''));if($id!=='')aplicar_status_pagamento_asaas($pdo,$id,(string)($payload['event']??''));json_response(['success'=>true]);
}

function professional_cancel_subscription(PDO $pdo): never { $s=require_professional();$q=$pdo->prepare('UPDATE assinaturas_profissionais SET status=\'cancelada\',fim=CURDATE() WHERE profissional_id=? AND status IN (\'pendente\',\'ativa\')');$q->execute([$s['id']]);json_response(['success'=>true,'message'=>'Assinatura cancelada.']);}

function get_app_config(PDO $pdo): never { $row=$pdo->query('SELECT id,modo,nome_exibicao AS nomeExibicao,especialidade,registro_profissional AS registroProfissional,telefone,whatsapp,endereco,modalidade,valor_consulta AS valorConsulta,apresentacao,logo_arquivo AS logoArquivo,cor_primaria AS corPrimaria,cor_secundaria AS corSecundaria FROM configuracao_app WHERE id=1')->fetch(); json_response(['success'=>true,'config'=>$row ?: ['modo'=>'ubs','nomeExibicao'=>'Acessa+ Saúde']]); }
function update_app_config(PDO $pdo, array $data): never { $session=require_admin();if($session['tipo']!=='desenvolvedor')json_response(['success'=>false,'message'=>'Somente o desenvolvedor pode alterar o modo do sistema.'],403);$modo=required_string($data,'modo');if(!in_array($modo,['ubs','profissional'],true))json_response(['success'=>false,'message'=>'Modo inválido.'],422);$nome=required_string($data,'nome_exibicao');$campos=['especialidade','registro_profissional','telefone','whatsapp','endereco','modalidade','apresentacao','logo_arquivo','cor_primaria','cor_secundaria'];$vals=[];foreach($campos as $campo)$vals[]=trim((string)($data[$campo]??''))?:null;$valor=$data['valor_consulta']??null;$q=$pdo->prepare('UPDATE configuracao_app SET modo=?,nome_exibicao=?,especialidade=?,registro_profissional=?,telefone=?,whatsapp=?,endereco=?,modalidade=?,valor_consulta=?,apresentacao=?,logo_arquivo=?,cor_primaria=COALESCE(?,cor_primaria),cor_secundaria=COALESCE(?,cor_secundaria) WHERE id=1');$q->execute([$modo,$nome,$vals[0],$vals[1],$vals[2],$vals[3],$vals[4],$vals[5],$valor!==''?$valor:null,$vals[6],$vals[7],$vals[8],$vals[9]]);audit_event($pdo,'configuracao_modo_atualizada','configuracao_app','1',['modo'=>$modo]);get_app_config($pdo);}

function save_patient(PDO $pdo, array $data): never
{
    $nome=required_string($data,'nome'); $telefone=required_string($data,'telefone'); $sus=required_string($data,'sus'); $ubsId=required_string($data,'ubs_id');
    if (strlen($nome) < 3 || strlen($nome) > 150) json_response(['success'=>false,'message'=>'Informe um nome completo válido.'],422);
    if (!preg_match('/^[0-9()+\s-]{8,30}$/', $telefone)) json_response(['success'=>false,'message'=>'Informe um celular válido.'],422);
    if (strlen($sus) > 20) json_response(['success'=>false,'message'=>'Cartão SUS inválido.'],422);
    if (strlen(preg_replace('/\D+/','',$sus))<8) json_response(['success'=>false,'message'=>'Cartão SUS inválido.'],422);
    $q=$pdo->prepare('SELECT id FROM ubs WHERE id=?'); $q->execute([$ubsId]); if(!$q->fetch()) json_response(['success'=>false,'message'=>'UBS de referência não encontrada.'],422);
    $codigo='PAC-'.date('ym').'-'.strtoupper(substr(hash('sha256',$sus),0,6)); $q=$pdo->prepare('INSERT INTO pacientes (codigo,nome,telefone,sus,ubs_id) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE nome=VALUES(nome),telefone=VALUES(telefone),ubs_id=COALESCE(VALUES(ubs_id),ubs_id)'); $q->execute([$codigo,$nome,$telefone,$sus,$ubsId?:null]);
    $q=$pdo->prepare('SELECT id,codigo,nome,telefone,sus,ubs_id AS ubsId,endereco,data_nascimento AS dataNascimento,condicoes_saude AS condicoesSaude,alergias,medicamentos,informacoes_adicionais AS informacoesAdicionais FROM pacientes WHERE sus=?'); $q->execute([$sus]); json_response(['success'=>true,'patient'=>$q->fetch()]);
}

function update_patient_profile(PDO $pdo, array $data): never
{
    $sus = required_string($data, 'sus');
    $nome = required_string($data, 'nome');
    $telefone = required_string($data, 'telefone');
    $dataNascimento = trim((string)($data['data_nascimento'] ?? ''));
    $endereco = trim((string)($data['endereco'] ?? ''));
    $condicoes = trim((string)($data['condicoes_saude'] ?? ''));
    $alergias = trim((string)($data['alergias'] ?? ''));
    $medicamentos = trim((string)($data['medicamentos'] ?? ''));
    $informacoes = trim((string)($data['informacoes_adicionais'] ?? ''));

    foreach (['nome'=>$nome,'telefone'=>$telefone,'endereco'=>$endereco,'condicoes_saude'=>$condicoes,'alergias'=>$alergias,'medicamentos'=>$medicamentos,'informacoes_adicionais'=>$informacoes] as $campo=>$valor) {
        if (strlen($valor) > 2000) json_response(['success'=>false,'message'=>'O campo '.$campo.' excede o limite permitido.'],422);
    }
    if (strlen($nome) < 3 || !preg_match('/^[0-9()+\s-]{8,30}$/', $telefone)) json_response(['success'=>false,'message'=>'Nome ou celular inválido.'],422);

    if ($dataNascimento !== '' && !valid_date($dataNascimento)) {
        json_response(['success' => false, 'message' => 'Data de nascimento inválida.'], 422);
    }
    if ($dataNascimento !== '' && new DateTime($dataNascimento) > new DateTime('today')) json_response(['success'=>false,'message'=>'A data de nascimento não pode ser futura.'],422);
    $stmt = $pdo->prepare(
        'UPDATE pacientes
         SET nome = ?, telefone = ?, endereco = ?, data_nascimento = ?,
             condicoes_saude = ?, alergias = ?, medicamentos = ?, informacoes_adicionais = ?
         WHERE sus = ?'
    );
    $stmt->execute([$nome, $telefone, $endereco ?: null, $dataNascimento ?: null, $condicoes ?: null, $alergias ?: null, $medicamentos ?: null, $informacoes ?: null, $sus]);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare('SELECT id FROM pacientes WHERE sus = ?');
        $check->execute([$sus]);
        if (!$check->fetch()) {
            json_response(['success' => false, 'message' => 'Paciente não encontrado.'], 404);
        }
    }
    $q=$pdo->prepare('SELECT id,nome,telefone,sus,ubs_id AS ubsId,endereco,data_nascimento AS dataNascimento,condicoes_saude AS condicoesSaude,alergias,medicamentos,informacoes_adicionais AS informacoesAdicionais FROM pacientes WHERE sus=?');
    $q->execute([$sus]);
    json_response(['success' => true, 'patient' => $q->fetch()]);
}

function patient_id_by_sus(PDO $pdo, string $sus): ?int
{
    $stmt = $pdo->prepare(
        'SELECT id FROM pacientes WHERE sus = ?'
    );

    $stmt->execute([$sus]);

    $id = $stmt->fetchColumn();

    return $id === false ? null : (int)$id;
}

function get_patient_appointments(PDO $pdo, string $sus): never
{
    if ($sus === '') {
        json_response([
            'success' => false,
            'message' => 'Cartão SUS não informado.'
        ], 422);
    }

    $stmt = $pdo->prepare(
        'SELECT
            c.id,
            c.ubs_id AS ubsId,
            u.nome AS ubsNome,
            p.nome,
            p.telefone,
            p.sus,
            c.especialidade,
            c.assunto,
            DATE_FORMAT(c.data_consulta, "%Y-%m-%d") AS data,
            CASE WHEN c.horario = \'00:00:00\' THEN NULL ELSE TIME_FORMAT(c.horario, \'%H:%i\') END AS horario,
            c.fila,
            c.status,
            c.cancelamento_motivo AS cancelamentoMotivo,
            c.lembrete,
            c.notificado,
            c.criada_em AS criadaEm
         FROM consultas c
         INNER JOIN pacientes p ON p.id = c.paciente_id
         INNER JOIN ubs u ON u.id = c.ubs_id
         WHERE p.sus = ?
         ORDER BY c.data_consulta, c.horario'
    );

    $stmt->execute([$sus]);

    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['lembrete'] = database_bool($row['lembrete']);
        $row['notificado'] = database_bool($row['notificado']);
    }

    json_response([
        'success' => true,
        'appointments' => $rows
    ]);
}

function join_waitlist(PDO $pdo, array $data): never
{
    $ubsId=required_string($data,'ubs_id'); $sus=required_string($data,'sus'); $especialidade=required_string($data,'especialidade'); $date=required_string($data,'data');
    if (!valid_date($date)) json_response(['success'=>false,'message'=>'Data inválida.'],422);
    $patientId=patient_id_by_sus($pdo,$sus); if($patientId===null) json_response(['success'=>false,'message'=>'Paciente não cadastrado.'],422);
    $q=$pdo->prepare('SELECT id FROM ubs WHERE id=?');$q->execute([$ubsId]);if(!$q->fetch())json_response(['success'=>false,'message'=>'UBS não encontrada.'],404);
    $q=$pdo->prepare('INSERT IGNORE INTO lista_espera (paciente_id,ubs_id,especialidade,data_consulta) VALUES (?,?,?,?)');$q->execute([$patientId,$ubsId,$especialidade,$date]);
    json_response(['success'=>true,'message'=>'Você entrou na lista de espera desta data.']);
}

function promote_ubs_waitlist(PDO $pdo,string $ubsId,string $especialidade,string $date): ?array {
    if($date<date('Y-m-d'))return null;
    $limQ=$pdo->prepare('SELECT limite_diario,nome FROM ubs WHERE id=?');$limQ->execute([$ubsId]);$unit=$limQ->fetch();if(!$unit)return null;$limite=max(1,(int)$unit['limite_diario']);
    $pdo->beginTransaction(); try {
        $count=$pdo->prepare('SELECT COUNT(*) FROM consultas WHERE ubs_id=? AND especialidade=? AND data_consulta=? AND status="agendado"');$count->execute([$ubsId,$especialidade,$date]);$ocupadas=(int)$count->fetchColumn();if($ocupadas>=$limite){$pdo->rollBack();return null;}
        $w=$pdo->prepare('SELECT l.id,l.paciente_id,p.nome FROM lista_espera l INNER JOIN pacientes p ON p.id=l.paciente_id WHERE l.ubs_id=? AND l.especialidade=? AND l.data_consulta=? AND l.status="pendente" ORDER BY l.id LIMIT 1 FOR UPDATE');$w->execute([$ubsId,$especialidade,$date]);$row=$w->fetch();if(!$row){$pdo->rollBack();return null;}
        $exists=$pdo->prepare('SELECT id FROM consultas WHERE paciente_id=? AND ubs_id=? AND especialidade=? AND data_consulta=? AND status="agendado" LIMIT 1');$exists->execute([$row['paciente_id'],$ubsId,$especialidade,$date]);if($exists->fetch()){ $pdo->prepare('UPDATE lista_espera SET status="cancelado" WHERE id=?')->execute([$row['id']]);$pdo->commit();return promote_ubs_waitlist($pdo,$ubsId,$especialidade,$date); }
        $id='CONS-'.date('YmdHis').'-'.strtoupper(bin2hex(random_bytes(3)));$fila=$ocupadas+1;
        $ins=$pdo->prepare('INSERT INTO consultas (id,ubs_id,paciente_id,especialidade,assunto,data_consulta,horario,fila,status) VALUES (?,?,?,?,?,? ,"00:00:00",?,"agendado")');$ins->execute([$id,$ubsId,$row['paciente_id'],$especialidade,'Agendamento automático pela lista de espera',$date,$fila]);
        $pdo->prepare('UPDATE lista_espera SET status="agendado",consulta_id=? WHERE id=?')->execute([$id,$row['id']]);
        $msg="Olá, {$row['nome']}! Uma vaga ficou disponível na {$unit['nome']}. Sua consulta de {$especialidade} foi agendada automaticamente para ".date('d/m/Y',strtotime($date))." e está confirmada. Posição na fila: {$fila}. Protocolo: {$id}.";
        $pdo->prepare('INSERT INTO notificacoes (paciente_id,consulta_id,canal,tipo,mensagem,agendada_para,status) VALUES (?,? ,"push","lista_espera_agendada",?,NOW(),"pendente")')->execute([$row['paciente_id'],$id,$msg]);
        $pdo->commit(); audit_event($pdo,'lista_espera_vaga_repassada','consultas',$id,['ubs_id'=>$ubsId,'lista_espera_id'=>(int)$row['id']]); return ['id'=>$id,'patientId'=>(int)$row['paciente_id'],'nome'=>$row['nome']];
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function get_occupied_slots(PDO $pdo,string $ubsId,string $especialidade,string $date): never
{
 if($ubsId===''||$especialidade===''||!valid_date($date)) json_response(['success'=>false,'message'=>'Dados de agenda inválidos.'],422);
 $q=$pdo->prepare('SELECT limite_diario FROM ubs WHERE id=?');$q->execute([$ubsId]);$limite=max(1,(int)$q->fetchColumn()); $q=$pdo->prepare('SELECT COUNT(*) FROM consultas WHERE ubs_id=? AND especialidade=? AND data_consulta=? AND status="agendado"'); $q->execute([$ubsId,$especialidade,$date]); $ocupadas=(int)$q->fetchColumn();
 json_response(['success'=>true,'ocupadas'=>$ocupadas,'vagas'=>max(0,$limite-$ocupadas),'proximaFila'=>$ocupadas+1,'limite'=>$limite]);
}

function book_appointment(PDO $pdo,array $data): never
{
 $ubsId=required_string($data,'ubs_id'); $sus=required_string($data,'sus'); $especialidade=required_string($data,'especialidade'); $date=required_string($data,'data'); $assunto=trim((string)($data['assunto'] ?? ''));
 if(!valid_date($date)) json_response(['success'=>false,'message'=>'Data inválida.'],422); if(!is_business_day($date)) json_response(['success'=>false,'message'=>'A UBS não realiza agendamentos aos finais de semana ou nos feriados fixos.'],422); if(new DateTime($date)<new DateTime('today')) json_response(['success'=>false,'message'=>'Não é possível agendar uma data que já passou.'],422);
 $ubs=get_ubs($pdo,$ubsId,false); $limQ=$pdo->prepare('SELECT limite_diario FROM ubs WHERE id=?');$limQ->execute([$ubsId]);$limite=max(1,(int)$limQ->fetchColumn()); $patientId=patient_id_by_sus($pdo,$sus); if($patientId===null) json_response(['success'=>false,'message'=>'Paciente não cadastrado.'],422); if(!in_array($especialidade,$ubs['especialidades'],true)) json_response(['success'=>false,'message'=>'A especialidade selecionada não está disponível nesta UBS.'],422);
 $pdo->beginTransaction(); try { $lock=$pdo->prepare('SELECT id FROM ubs WHERE id=? FOR UPDATE'); $lock->execute([$ubsId]); $q=$pdo->prepare('SELECT COUNT(*) FROM consultas WHERE ubs_id=? AND especialidade=? AND data_consulta=? AND status="agendado"'); $q->execute([$ubsId,$especialidade,$date]); $ocupadas=(int)$q->fetchColumn(); if($ocupadas>=$limite){$pdo->rollBack();json_response(['success'=>false,'listaEspera'=>true,'message'=>"O limite diário de {$limite} atendimentos desta especialidade já foi atingido. Deseja entrar na lista de espera?",'ubs_id'=>$ubsId,'especialidade'=>$especialidade,'data'=>$date],409);} $q=$pdo->prepare('SELECT id FROM consultas WHERE paciente_id=? AND ubs_id=? AND especialidade=? AND data_consulta=? AND status="agendado" FOR UPDATE'); $q->execute([$patientId,$ubsId,$especialidade,$date]); if($q->fetch()){$pdo->rollBack();json_response(['success'=>false,'message'=>'Você já possui uma consulta para esta especialidade nesta data.'],409);} $id='CONS-'.date('YmdHis').'-'.strtoupper(bin2hex(random_bytes(3))); $fila=$ocupadas+1; $q=$pdo->prepare('INSERT INTO consultas (id,ubs_id,paciente_id,especialidade,assunto,data_consulta,horario,fila,status) VALUES (?,?,?,?,?,?,"00:00:00",?,"agendado")'); $q->execute([$id,$ubsId,$patientId,$especialidade,$assunto ?: null,$date,$fila]); enqueue_reminder($pdo,$patientId,$id,$date); $pdo->commit(); json_response(['success'=>true,'appointment'=>appointment_by_id($pdo,$id)]); } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function appointment_by_id(PDO $pdo, string $id): array
{
    $stmt = $pdo->prepare(
        'SELECT
            c.id,
            c.ubs_id AS ubsId,
            u.nome AS ubsNome,
            p.nome,
            p.telefone,
            p.sus,
            c.especialidade,
            c.assunto,
            DATE_FORMAT(c.data_consulta, "%Y-%m-%d") AS data,
            CASE WHEN c.horario = \'00:00:00\' THEN NULL ELSE TIME_FORMAT(c.horario, \'%H:%i\') END AS horario,
            c.fila,
            c.status,
            c.cancelamento_motivo AS cancelamentoMotivo,
            c.lembrete,
            c.notificado,
            c.criada_em AS criadaEm
         FROM consultas c
         INNER JOIN pacientes p ON p.id = c.paciente_id
         INNER JOIN ubs u ON u.id = c.ubs_id
         WHERE c.id = ?'
    );

    $stmt->execute([$id]);

    $row = $stmt->fetch();

    if (!$row) {
        json_response([
            'success' => false,
            'message' => 'Consulta não encontrada.'
        ], 404);
    }

    $row['lembrete'] = database_bool($row['lembrete']);
    $row['notificado'] = database_bool($row['notificado']);

    return $row;
}

function cancel_appointment(PDO $pdo, array $data): never
{
    $id = required_string($data, 'id');
    $motivo = trim((string)($data['motivo'] ?? ''));
    if (strlen($motivo) > 255) json_response(['success'=>false,'message'=>'O motivo deve ter no máximo 255 caracteres.'],422);
    $session = admin_session();

    $stmt = $pdo->prepare(
        'SELECT c.id, c.ubs_id, c.especialidade, c.data_consulta, p.sus, c.status
         FROM consultas c
         INNER JOIN pacientes p ON p.id = c.paciente_id
         WHERE c.id = ?'
    );

    $stmt->execute([$id]);

    $appointment = $stmt->fetch();

    if (!$appointment) {
        json_response([
            'success' => false,
            'message' => 'Consulta não encontrada.'
        ], 404);
    }

    if (!$session) {

        $sus = trim((string)($data['sus'] ?? ''));

        if (
            $sus === '' ||
            !hash_equals(
                (string)$appointment['sus'],
                $sus
            )
        ) {
            json_response([
                'success' => false,
                'message' => 'Você não tem permissão para cancelar esta consulta.'
            ], 403);
        }

    } else {

        if (
            !in_array($session['tipo'], ['desenvolvedor','secretaria'], true) &&
            $session['ubs_id'] !== $appointment['ubs_id']
        ) {
            json_response([
                'success' => false,
                'message' => 'Você não tem permissão para cancelar esta consulta.'
            ], 403);
        }
        if ($session['tipo'] === 'secretaria') {
            assert_ubs_in_admin_region($pdo, $session, (string)$appointment['ubs_id']);
        }
    }

    if ($appointment['status'] === 'cancelado') {
        json_response([
            'success' => true,
            'message' => 'Consulta já estava cancelada.'
        ]);
    }

    $stmt = $pdo->prepare('UPDATE consultas SET status = "cancelado", cancelamento_motivo = ? WHERE id = ?');
    $stmt->execute([$motivo ?: null, $id]);
    audit_event($pdo, 'consulta_cancelada', 'consultas', $id, ['motivo'=>$motivo]);
    $pdo->prepare('UPDATE notificacoes SET status = "erro" WHERE consulta_id = ? AND status = "pendente"')->execute([$id]);

    // A vaga liberada vai para o primeiro da lista de espera; a UBS é avisada na aba "Lista de espera".
    $promovido = $appointment['status'] === 'agendado'
        ? promote_ubs_waitlist($pdo, (string)$appointment['ubs_id'], (string)$appointment['especialidade'], (string)$appointment['data_consulta'])
        : null;

    $response = ['success' => true, 'vagaRepassada' => $promovido !== null];
    if ($session && $promovido !== null) $response['listaEspera'] = ['nome' => $promovido['nome'], 'consultaId' => $promovido['id']];
    json_response($response);
}

function set_reminder(PDO $pdo, array $data): never
{
    $id = required_string($data, 'id');
    $sus = required_string($data, 'sus');

    $stmt = $pdo->prepare(
        'UPDATE consultas
         SET lembrete = TRUE,
             notificado = FALSE
         WHERE id = ?
           AND status = "agendado"
           AND EXISTS (SELECT 1 FROM pacientes p WHERE p.id = consultas.paciente_id AND p.sus = ?)'
    );

    $stmt->execute([
        $id,
        $sus
    ]);

    if ($stmt->rowCount() === 0) {
        json_response([
            'success' => false,
            'message' => 'Não foi possível ativar o lembrete.'
        ], 403);
    }

    json_response([
        'success' => true
    ]);
}


function get_patient_exams(PDO $pdo, string $sus): never
{
    if ($sus === '') {
        json_response(['success' => false, 'message' => 'Cartão SUS não informado.'], 422);
    }

    $stmt = $pdo->prepare(
        'SELECT e.id, e.nome_exame AS nome, DATE_FORMAT(e.data_exame, "%Y-%m-%d") AS data,
                e.resultado, e.observacoes, e.anexo_nome AS anexoNome,
                (e.anexo_arquivo IS NOT NULL) AS anexo, u.nome AS ubsNome
         FROM exames_resultados e
         INNER JOIN pacientes p ON p.id = e.paciente_id
         INNER JOIN ubs u ON u.id = e.ubs_id
         WHERE p.sus = ?
         ORDER BY e.data_exame DESC, e.id DESC'
    );
    $stmt->execute([$sus]);

    json_response(['success' => true, 'exams' => $stmt->fetchAll()]);
}

function admin_update_appointment_status(PDO $pdo, array $data): never
{
    $id = required_string($data, 'id');
    $status = required_string($data, 'status');

    if (!in_array($status, ['agendado', 'atendido', 'faltou', 'cancelado'], true)) {
        json_response(['success' => false, 'message' => 'Status inválido.'], 422);
    }

    $stmt = $pdo->prepare('SELECT ubs_id FROM consultas WHERE id = ?');
    $stmt->execute([$id]);
    $appointment = $stmt->fetch();
    if (!$appointment) {
        json_response(['success' => false, 'message' => 'Consulta não encontrada.'], 404);
    }

    authorize_ubs($appointment['ubs_id']);

    $stmt = $pdo->prepare('SELECT ubs_id,especialidade,data_consulta,status FROM consultas WHERE id=?');$stmt->execute([$id]);$before=$stmt->fetch();
    $stmt = $pdo->prepare('UPDATE consultas SET status = ? WHERE id = ?');
    $stmt->execute([$status, $id]);
    $promovido = ($status==='cancelado' && $before && $before['status']==='agendado') ? promote_ubs_waitlist($pdo,(string)$before['ubs_id'],(string)$before['especialidade'],(string)$before['data_consulta']) : null;
    audit_event($pdo, 'consulta_status_atualizado', 'consultas', $id, ['status'=>$status]);

    json_response(['success' => true, 'vagaRepassada' => $promovido !== null, 'listaEspera' => $promovido ? ['nome'=>$promovido['nome'],'consultaId'=>$promovido['id']] : null]);
}

function admin_save_exam(PDO $pdo, array $data): never
{
    $ubsId = required_string($data, 'ubs_id');
    authorize_ubs($ubsId);

    $sus = required_string($data, 'sus');
    $nome = required_string($data, 'nome');
    $dataExame = required_string($data, 'data_exame');
    $resultado = required_string($data, 'resultado');
    $observacoes = trim((string)($data['observacoes'] ?? ''));

    if (!valid_date($dataExame)) {
        json_response(['success' => false, 'message' => 'Data do exame inválida.'], 422);
    }

    $patientId = patient_id_by_sus($pdo, $sus);
    if ($patientId === null) {
        json_response(['success' => false, 'message' => 'Paciente não encontrado. Verifique o Cartão SUS.'], 404);
    }

    $owner = $pdo->prepare('SELECT ubs_id FROM pacientes WHERE id = ?');
    $owner->execute([$patientId]);
    $patientUBS = $owner->fetchColumn();
    if ($patientUBS !== null && $patientUBS !== $ubsId) {
        json_response(['success' => false, 'message' => 'Este paciente está vinculado a outra UBS.'], 403);
    }

    $uploadDir = __DIR__ . '/uploads/exames';
    $anexoNome = null;
    $anexoMime = null;
    $anexoArquivo = null;
    $arquivoMovido = false;
    $upload = $_FILES['anexo'] ?? null;

    if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            json_response(['success' => false, 'message' => 'Não foi possível receber o arquivo anexado.'], 422);
        }
        if (($upload['size'] ?? 0) > 10 * 1024 * 1024) {
            json_response(['success' => false, 'message' => 'O anexo deve ter no máximo 10 MB.'], 422);
        }
        if (!is_uploaded_file($upload['tmp_name'] ?? '')) {
            json_response(['success' => false, 'message' => 'Arquivo anexado inválido.'], 422);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $anexoMime = $finfo->file($upload['tmp_name']) ?: '';
        $extensoes = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png'
        ];
        if (!isset($extensoes[$anexoMime])) {
            json_response(['success' => false, 'message' => 'Formato não permitido. Envie apenas PDF, JPG ou PNG.'], 422);
        }
        $anexoNome = trim((string)($upload['name'] ?? 'resultado.' . $extensoes[$anexoMime]));
        $anexoNome = preg_replace('/[^A-Za-z0-9._ -]/u', '', $anexoNome) ?: 'resultado.' . $extensoes[$anexoMime];
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Não foi possível preparar a pasta de anexos.');
        }
        $anexoArquivo = 'exam_' . bin2hex(random_bytes(16)) . '.' . $extensoes[$anexoMime];
        if (!move_uploaded_file($upload['tmp_name'], $uploadDir . '/' . $anexoArquivo)) {
            throw new RuntimeException('Não foi possível salvar o anexo.');
        }
        $arquivoMovido = true;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO exames_resultados
            (paciente_id, ubs_id, nome_exame, data_exame, resultado, observacoes, anexo_nome, anexo_mime, anexo_arquivo, enviado_por)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    try {
        $session = admin_session();
        $stmt->execute([$patientId, $ubsId, $nome, $dataExame, $resultado, $observacoes ?: null, $anexoNome, $anexoMime, $anexoArquivo, $session['id'] ?? null]);
    } catch (Throwable $e) {
        if ($arquivoMovido && $anexoArquivo) {
            @unlink($uploadDir . '/' . $anexoArquivo);
        }
        throw $e;
    }

    $examId = (int)$pdo->lastInsertId();
    audit_event($pdo, 'exame_cadastrado', 'exames_resultados', (string)$examId, ['ubs_id'=>$ubsId]);
    json_response(['success' => true, 'exam_id' => $examId]);
}

function download_exam(PDO $pdo): never
{
    $id = required_string($_GET, 'id');
    $stmt = $pdo->prepare(
        'SELECT e.anexo_nome, e.anexo_mime, e.anexo_arquivo, e.ubs_id, p.sus
         FROM exames_resultados e
         INNER JOIN pacientes p ON p.id = e.paciente_id
         WHERE e.id = ?'
    );
    $stmt->execute([(int)$id]);
    $exam = $stmt->fetch();
    if (!$exam || !$exam['anexo_arquivo']) {
        json_response(['success' => false, 'message' => 'Anexo não encontrado.'], 404);
    }

    $session = admin_session();
    if (!$session) {
        $sus = trim((string)($_GET['sus'] ?? ''));
        if ($sus === '' || !hash_equals((string)$exam['sus'], $sus)) {
            json_response(['success' => false, 'message' => 'Você não tem permissão para abrir este anexo.'], 403);
        }
    } elseif (!in_array($session['tipo'], ['desenvolvedor','secretaria'], true) && $session['ubs_id'] !== $exam['ubs_id']) {
        json_response(['success' => false, 'message' => 'Você não tem permissão para abrir este anexo.'], 403);
    } elseif ($session['tipo'] === 'secretaria') {
        assert_ubs_in_admin_region($pdo, $session, (string)$exam['ubs_id']);
    }

    $path = __DIR__ . '/uploads/exames/' . basename((string)$exam['anexo_arquivo']);
    if (!is_file($path)) {
        json_response(['success' => false, 'message' => 'Arquivo do anexo não encontrado no servidor.'], 404);
    }

    header('Content-Type: ' . $exam['anexo_mime']);
    header('Content-Disposition: inline; filename="' . addcslashes((string)$exam['anexo_nome'], "\\\"") . '"');
    header('Content-Length: ' . (string)filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function enqueue_reminder(PDO $pdo,int $patientId,string $appointmentId,string $date): void { $q=$pdo->prepare('INSERT INTO notificacoes (paciente_id,consulta_id,canal,tipo,mensagem,agendada_para) VALUES (?,? ,"sms","lembrete_consulta",?,?)'); $q->execute([$patientId,$appointmentId,'Lembrete: consulta em '.date('d/m/Y',strtotime($date)),date('Y-m-d H:i:s',strtotime($date.' 08:00:00 -1 day'))]); }
function support_message(PDO $pdo,array $data): never {
    $nome=required_string($data,'nome');$telefone=required_string($data,'telefone');$mensagem=required_string($data,'mensagem');
    if(strlen($mensagem)>3000)json_response(['success'=>false,'message'=>'A mensagem deve ter no máximo 3000 caracteres.'],422);
    $patientId=!empty($data['sus'])?patient_id_by_sus($pdo,trim((string)$data['sus'])):null;
    $destinoTipo='desenvolvedor'; $destinoId=null;
    if($patientId){
        $q=$pdo->prepare('SELECT ubs_id FROM pacientes WHERE id=?'); $q->execute([$patientId]); $ubsId=$q->fetchColumn();
        if($ubsId){ $destinoTipo='ubs'; $destinoId=(string)$ubsId; }
        $q=$pdo->prepare('SELECT profissional_id FROM profissional_pacientes WHERE paciente_id=? ORDER BY criado_em DESC LIMIT 1'); $q->execute([$patientId]); $profId=$q->fetchColumn();
        if($profId){ $destinoTipo='clinica'; $destinoId=(int)$profId; }
    }
    $clinicSlug = trim((string)($data['clinic_slug'] ?? ''));
    if ($clinicSlug !== '') {
        $clinic = $pdo->prepare("SELECT id FROM profissionais WHERE slug = ? AND status = 'ativo' LIMIT 1");
        $clinic->execute([$clinicSlug]);
        $professionalId = $clinic->fetchColumn();
        if (!$professionalId) json_response(['success'=>false,'message'=>'Clínica não encontrada para receber o chamado.'],404);
        $destinoTipo = 'clinica';
        $destinoId = (int)$professionalId;
    } elseif (!empty($data['ubs_id'])) {
        $ubsId = trim((string)$data['ubs_id']);
        $unit = $pdo->prepare('SELECT id FROM ubs WHERE id = ? AND ativa = 1');
        $unit->execute([$ubsId]);
        if (!$unit->fetchColumn()) json_response(['success'=>false,'message'=>'UBS selecionada não está disponível.'],404);
        $destinoTipo = 'ubs';
        $destinoId = $ubsId;
    }
    $protocolo='SUP-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
    $token=bin2hex(random_bytes(32));
    $q=$pdo->prepare('INSERT INTO suporte_mensagens (protocolo,acesso_token,paciente_id,nome,telefone,mensagem,destino_tipo,destino_id) VALUES (?,?,?,?,?,?,?,?)');
    $q->execute([$protocolo,$token,$patientId,$nome,$telefone,$mensagem,$destinoTipo,$destinoId]);
    json_response(['success'=>true,'protocolo'=>$protocolo,'acesso_token'=>$token,'message'=>'Solicitação enviada. Guarde o protocolo e a chave de acesso para consultar a resposta.']);
}
function get_support(PDO $pdo, string $sus): never {
    $protocolo=trim((string)($_GET['protocolo']??''));$token=trim((string)($_GET['token']??''));
    if($protocolo!==''&&$token!==''){
        $q=$pdo->prepare('SELECT protocolo,mensagem,COALESCE(resposta_clinica,resposta) AS resposta,status,destino_tipo AS destinoTipo,criado_em AS criadoEm,atualizado_em AS atualizadoEm FROM suporte_mensagens WHERE protocolo=? AND acesso_token=? LIMIT 1');
        $q->execute([$protocolo,$token]);$rows=$q->fetchAll();json_response(['success'=>true,'messages'=>$rows]);
    }
    // Sem a chave secreta, não retorna protocolos nem respostas.
    json_response(['success'=>true,'messages'=>[]]);
}
function admin_support_messages(PDO $pdo): never
{
    $session = require_admin();
    if ($session['tipo'] !== 'desenvolvedor') {
        json_response(['success' => false, 'message' => 'Somente o desenvolvedor pode consultar as mensagens de suporte.'], 403);
    }
    $status = trim((string)($_GET['status'] ?? ''));
    $sql = 'SELECT id, protocolo, nome, telefone, mensagem, resposta, COALESCE(resposta_clinica,resposta) AS respostaClinica, resposta_desenvolvedor AS respostaTecnica, status, destino_tipo AS destinoTipo, encaminhado_desenvolvedor AS encaminhadoDesenvolvedor, encaminhamento_motivo AS encaminhamentoMotivo, criado_em AS criadoEm, atualizado_em AS atualizadoEm FROM suporte_mensagens WHERE (destino_tipo = "desenvolvedor" OR encaminhado_desenvolvedor = 1)';
    $params = [];
    if (in_array($status, ['aberto','em_atendimento','resolvido'], true)) { $sql .= ' AND status = ?'; $params[] = $status; }
    $sql .= ' ORDER BY criado_em DESC, id DESC';
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    json_response(['success' => true, 'messages' => $stmt->fetchAll()]);
}

function admin_update_support(PDO $pdo, array $data): never
{
    $session=require_admin();
    if($session['tipo']!=='desenvolvedor')json_response(['success'=>false,'message'=>'Somente o desenvolvedor pode atualizar mensagens de suporte.'],403);
    $id=(int)($data['id']??0);$status=trim((string)($data['status']??''));$resposta=trim((string)($data['resposta']??''));
    if(!$id||!in_array($status,['aberto','em_atendimento','resolvido'],true))json_response(['success'=>false,'message'=>'Chamado ou status inválido.'],422);
    $q=$pdo->prepare('SELECT destino_tipo,encaminhado_desenvolvedor FROM suporte_mensagens WHERE id=?');$q->execute([$id]);$ticket=$q->fetch();
    if(!$ticket)json_response(['success'=>false,'message'=>'Chamado não encontrado.'],404);
    if($ticket['destino_tipo']==='clinica'&&database_bool($ticket['encaminhado_desenvolvedor'])){
        if($resposta==='')json_response(['success'=>false,'message'=>'Escreva a orientação técnica para a clínica.'],422);
        $pdo->prepare('UPDATE suporte_mensagens SET resposta_desenvolvedor=?,status="em_atendimento" WHERE id=?')->execute([$resposta,$id]);
        audit_event($pdo,'orientacao_tecnica_enviada_clinica','suporte_mensagens',(string)$id);
        json_response(['success'=>true,'message'=>'Orientação técnica enviada à clínica. Ela continua responsável pela resposta ao paciente e pela resolução.']);
    }
    $pdo->prepare('UPDATE suporte_mensagens SET status=?,resposta=? WHERE id=?')->execute([$status,$resposta?:null,$id]);
    audit_event($pdo,'suporte_atualizado','suporte_mensagens',(string)$id,['status'=>$status]);json_response(['success'=>true]);
}


function support_inbox(PDO $pdo): never {
    $session = $_SESSION['admin'] ?? null;
    $tipo = $session['tipo'] ?? '';
    $ubsId = $session['ubs_id'] ?? null;
    if (!in_array($tipo, ['ubs','desenvolvedor'], true)) {
        // Profissionais/clínicas usam a sessão própria.
        $prof = $_SESSION['professional'] ?? null;
        if (!$prof) json_response(['success'=>false,'message'=>'Acesso não autorizado.'],403);
        $q=$pdo->prepare('SELECT s.id,s.protocolo,s.nome,s.telefone,s.mensagem,COALESCE(s.resposta_clinica,s.resposta) AS resposta,s.resposta_desenvolvedor AS respostaTecnica,s.status,s.destino_tipo AS destinoTipo,s.encaminhado_desenvolvedor AS encaminhadoDesenvolvedor,s.encaminhamento_motivo AS encaminhamentoMotivo,s.criado_em AS criadoEm,s.atualizado_em AS atualizadoEm FROM suporte_mensagens s WHERE s.destino_tipo="clinica" AND s.destino_id=? ORDER BY s.id DESC');
        $q->execute([(int)$prof['id']]);
        json_response(['success'=>true,'messages'=>$q->fetchAll()]);
    }
    if ($tipo === 'desenvolvedor') {
        admin_support_messages($pdo);
    }
    $status=trim((string)($_GET['status']??''));
    $sql='SELECT id,protocolo,nome,telefone,mensagem,resposta,status,destino_tipo AS destinoTipo,encaminhado_desenvolvedor AS encaminhadoDesenvolvedor,criado_em AS criadoEm,atualizado_em AS atualizadoEm FROM suporte_mensagens WHERE destino_tipo="ubs" AND destino_id=?';
    $params=[$ubsId];
    if(in_array($status,['aberto','em_atendimento','resolvido'],true)){ $sql.=' AND status=?'; $params[]=$status; }
    $sql.=' ORDER BY id DESC';
    $q=$pdo->prepare($sql); $q->execute($params);
    json_response(['success'=>true,'messages'=>$q->fetchAll()]);
}

function support_update_clinic(PDO $pdo, array $data): never
{
    $professional=$_SESSION['professional']??null;
    if(!$professional)json_response(['success'=>false,'message'=>'Acesso exclusivo à clínica autenticada.'],403);
    $id=(int)($data['id']??0);$status=trim((string)($data['status']??''));$reply=trim((string)($data['resposta']??''));
    if(!$id||!in_array($status,['em_atendimento','resolvido'],true))json_response(['success'=>false,'message'=>'Chamado ou status inválido.'],422);
    if(strlen($reply)>5000)json_response(['success'=>false,'message'=>'A resposta deve ter até 5.000 caracteres.'],422);
    if($status==='resolvido'&&$reply==='')json_response(['success'=>false,'message'=>'Escreva a resposta ao paciente antes de resolver o chamado.'],422);
    $q=$pdo->prepare('SELECT status FROM suporte_mensagens WHERE id=? AND destino_tipo="clinica" AND destino_id=?');$q->execute([$id,(int)$professional['id']]);
    if($q->fetchColumn()===false)json_response(['success'=>false,'message'=>'Chamado não encontrado para esta clínica.'],404);
    $q=$pdo->prepare('UPDATE suporte_mensagens SET status=?,resposta_clinica=?,resposta=?,atualizado_em=NOW() WHERE id=? AND destino_tipo="clinica" AND destino_id=?');
    $q->execute([$status,$reply?:null,$reply?:null,$id,(int)$professional['id']]);
    audit_professional_event($pdo,(int)$professional['id'],'suporte_clinica_'.($status==='resolvido'?'resolvido':'respondido'),'suporte_mensagens',(string)$id,['status'=>$status]);
    json_response(['success'=>true,'message'=>$status==='resolvido'?'Resposta registrada e chamado resolvido pela clínica.':'Resposta registrada pela clínica.']);
}

function support_forward_developer(PDO $pdo, array $data): never {
    $id=(int)($data['id']??0);
    if(!$id) json_response(['success'=>false,'message'=>'Chamado inválido.'],422);
    $prof=$_SESSION['professional']??null;
    $session=$_SESSION['admin']??null;
    if(!$prof && !$session) json_response(['success'=>false,'message'=>'Acesso não autorizado.'],403);
    $q=$pdo->prepare('SELECT id,destino_tipo,destino_id,status,COALESCE(resposta_clinica,resposta) AS resposta FROM suporte_mensagens WHERE id=?');
    $q->execute([$id]); $ticket=$q->fetch();
    if(!$ticket) json_response(['success'=>false,'message'=>'Chamado não encontrado.'],404);
    $motivo=trim((string)($data['motivo']??''));
    if(strlen($motivo)<10) json_response(['success'=>false,'message'=>'Descreva a falha técnica com pelo menos 10 caracteres.'],422);
    if($prof && ($ticket['destino_tipo']!=='clinica'||(int)$ticket['destino_id']!==(int)$prof['id'])) json_response(['success'=>false,'message'=>'Chamado não pertence a esta clínica.'],403);
    if($prof && ($ticket['status']!=='em_atendimento'||trim((string)($ticket['resposta']??''))==='')) json_response(['success'=>false,'message'=>'Primeiro faça a triagem e registre uma resposta da clínica; só então encaminhe se confirmar falha técnica.'],409);
    if($session && !in_array($session['tipo'],['ubs','desenvolvedor','secretaria'],true)) json_response(['success'=>false,'message'=>'Perfil sem permissão para encaminhar chamados.'],403);
    if($session && $session['tipo']==='ubs' && ($ticket['destino_tipo']!=='ubs'||$ticket['destino_id']!==$session['ubs_id'])) json_response(['success'=>false,'message'=>'Chamado não pertence a esta UBS.'],403);
    $pdo->prepare('UPDATE suporte_mensagens SET encaminhado_desenvolvedor=1,encaminhamento_motivo=?,status="em_atendimento" WHERE id=?')->execute([$motivo,$id]);
    if($prof) audit_professional_event($pdo,(int)$prof['id'],'suporte_encaminhado_desenvolvedor','suporte_mensagens',(string)$id,['motivo'=>$motivo]);
    else audit_event($pdo,'suporte_encaminhado_desenvolvedor','suporte_mensagens',(string)$id,['motivo'=>$motivo]);
    json_response(['success'=>true,'message'=>'Chamado técnico encaminhado ao desenvolvedor.']);
}

function require_secretaria_manager(PDO $pdo): array
{
    $session = require_admin();
    if (!in_array($session['tipo'], ['secretaria','desenvolvedor'], true)) json_response(['success'=>false,'message'=>'Apenas a Secretaria de Saúde pode administrar os indicadores globais.'],403);
    return $session;
}
function secretaria_dashboard(PDO $pdo): never
{
    $session=require_admin();if($session['tipo']!=='secretaria')json_response(['success'=>false,'message'=>'Acesso reservado ao administrador da Secretaria de Saúde.'],403);
    $region=admin_region($pdo,$session);$units=ubs_da_regiao(all_ubs($pdo,true),$region);$unitIds=array_flip(array_map('strval',array_column($units,'id')));$inRegion=static fn(array $row): bool=>isset($unitIds[(string)($row['ubsId'] ?? $row['ubsid'] ?? '')]);
    $today=date('Y-m-d');$q=$pdo->prepare('SELECT u.id AS ubsId,u.nome AS unidade,COUNT(c.id) AS consultas FROM ubs u LEFT JOIN consultas c ON c.ubs_id=u.id AND c.data_consulta=? AND c.status="agendado" WHERE u.ativa=1 GROUP BY u.id,u.nome ORDER BY u.nome');$q->execute([$today]);$appointments=array_values(array_filter($q->fetchAll(),$inRegion));
    $q=$pdo->prepare('SELECT i.id,i.ubs_id AS ubsId,u.nome AS unidade,i.tipo,i.nome,i.meta,i.realizado,DATE_FORMAT(i.periodo_inicio,"%Y-%m-%d") AS periodoInicio,DATE_FORMAT(i.periodo_fim,"%Y-%m-%d") AS periodoFim FROM indicadores_saude i INNER JOIN ubs u ON u.id=i.ubs_id WHERE u.ativa=1 AND (i.periodo_fim IS NULL OR i.periodo_fim>=?) ORDER BY FIELD(i.tipo,"campanha","vacina","citologia"),u.nome,i.nome');$q->execute([$today]);
    json_response(['success'=>true,'data'=>['data'=>$today,'consultasHoje'=>$appointments,'indicadores'=>array_values(array_filter($q->fetchAll(),$inRegion)),'ubs'=>$units,'regiao'=>$region]]);
}
function save_health_indicator(PDO $pdo,array $data): never
{
    $session=require_secretaria_manager($pdo);$id=(int)($data['id']??0);$ubsId=required_string($data,'ubs_id');$type=trim((string)($data['tipo']??''));$name=required_string($data,'nome');$goal=(int)($data['meta']??0);$done=(int)($data['realizado']??0);$start=trim((string)($data['periodo_inicio']??''));$end=trim((string)($data['periodo_fim']??''));
    if(!in_array($type,['campanha','vacina','citologia'],true)||strlen($name)>180||$goal<1||$done<0)json_response(['success'=>false,'message'=>'Revise o tipo, nome, meta e quantidade realizada.'],422);
    foreach(['periodo_inicio'=>$start,'periodo_fim'=>$end] as $field=>$value)if($value!==''&&!valid_date($value))json_response(['success'=>false,'message'=>'Data inválida em '.$field.'.'],422);
    if($start!==''&&$end!==''&&$end<$start)json_response(['success'=>false,'message'=>'O fim do período deve ser igual ou posterior ao início.'],422);
    $q=$pdo->prepare('SELECT id FROM ubs WHERE id=? AND ativa=1');$q->execute([$ubsId]);if(!$q->fetchColumn())json_response(['success'=>false,'message'=>'Selecione uma UBS ativa.'],422);
    assert_ubs_in_admin_region($pdo,$session,$ubsId);
    if($id){$q=$pdo->prepare('SELECT ubs_id FROM indicadores_saude WHERE id=?');$q->execute([$id]);$currentUbs=$q->fetchColumn();if($currentUbs===false)json_response(['success'=>false,'message'=>'Indicador não encontrado.'],404);assert_ubs_in_admin_region($pdo,$session,(string)$currentUbs);$q=$pdo->prepare('UPDATE indicadores_saude SET ubs_id=?,tipo=?,nome=?,meta=?,realizado=?,periodo_inicio=?,periodo_fim=? WHERE id=?');$q->execute([$ubsId,$type,$name,$goal,$done,$start?:null,$end?:null,$id]);$action='indicador_saude_atualizado';}
    else{$q=$pdo->prepare('INSERT INTO indicadores_saude (ubs_id,tipo,nome,meta,realizado,periodo_inicio,periodo_fim,criado_por) VALUES (?,?,?,?,?,?,?,?)');$q->execute([$ubsId,$type,$name,$goal,$done,$start?:null,$end?:null,$session['id']??null]);$id=(int)$pdo->lastInsertId();$action='indicador_saude_criado';}
    audit_event($pdo,$action,'indicadores_saude',(string)$id,['ubs_id'=>$ubsId,'tipo'=>$type]);json_response(['success'=>true,'id'=>$id,'message'=>'Indicador de saúde salvo.']);
}
function delete_health_indicator(PDO $pdo,array $data): never
{
    $session=require_secretaria_manager($pdo);$id=(int)($data['id']??0);if($id<1)json_response(['success'=>false,'message'=>'Indicador inválido.'],422);$q=$pdo->prepare('SELECT ubs_id FROM indicadores_saude WHERE id=?');$q->execute([$id]);$currentUbs=$q->fetchColumn();if($currentUbs===false)json_response(['success'=>false,'message'=>'Indicador não encontrado.'],404);assert_ubs_in_admin_region($pdo,$session,(string)$currentUbs);$q=$pdo->prepare('DELETE FROM indicadores_saude WHERE id=?');$q->execute([$id]);if($q->rowCount()<1)json_response(['success'=>false,'message'=>'Indicador não encontrado.'],404);audit_event($pdo,'indicador_saude_removido','indicadores_saude',(string)$id);json_response(['success'=>true,'message'=>'Indicador removido.']);
}
function toggle_ubs_active(PDO $pdo,array $data): never
{
    $session=require_admin();if(!in_array($session['tipo'],['secretaria','desenvolvedor'],true))json_response(['success'=>false,'message'=>'Apenas a Secretaria de Saúde ou o desenvolvedor pode gerenciar unidades.'],403);$id=required_string($data,'ubs_id');$active=filter_var($data['ativa']??null,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);if($active===null)json_response(['success'=>false,'message'=>'Estado da UBS inválido.'],422);assert_ubs_in_admin_region($pdo,$session,$id);$q=$pdo->prepare('SELECT nome FROM ubs WHERE id=?');$q->execute([$id]);$name=$q->fetchColumn();if(!$name)json_response(['success'=>false,'message'=>'UBS não encontrada.'],404);$pdo->prepare('UPDATE ubs SET ativa=? WHERE id=?')->execute([$active,$id]);audit_event($pdo,$active?'ubs_reativada':'ubs_arquivada','ubs',$id,['nome'=>$name]);json_response(['success'=>true,'message'=>$active?'UBS reativada.':'UBS desativada e arquivada; os dados históricos foram preservados.','ubs'=>get_ubs($pdo,$id,true)]);
}
function list_admin_ubs(PDO $pdo): never
{
    $session=require_admin();if(!in_array($session['tipo'],['desenvolvedor','secretaria'],true))json_response(['success'=>false,'message'=>'Acesso reservado à gestão global.'],403);
    $region=admin_region($pdo,$session);
    json_response(['success'=>true,'ubs'=>ubs_da_regiao(all_ubs($pdo,true),$region),'regiao'=>$region]);
}
function list_secretaria_accounts(PDO $pdo): never
{
    $session=require_admin();if($session['tipo']!=='desenvolvedor')json_response(['success'=>false,'message'=>'Somente o desenvolvedor pode administrar as contas da Secretaria.'],403);$q=$pdo->query("SELECT id,usuario,cidade,estado,created_at AS criadoEm FROM administradores WHERE tipo='secretaria' ORDER BY usuario");json_response(['success'=>true,'accounts'=>$q->fetchAll()]);
}
function create_secretaria_account(PDO $pdo,array $data): never
{
    $session=require_admin();if($session['tipo']!=='desenvolvedor')json_response(['success'=>false,'message'=>'Somente o desenvolvedor pode provisionar o administrador da Secretaria.'],403);$user=required_string($data,'usuario');$password=(string)($data['senha']??'');$location=localizacao_canonica($pdo,validar_localizacao_ubs($data));
    if(!preg_match('/^[A-Za-z0-9._@-]{3,80}$/',$user))json_response(['success'=>false,'message'=>'Use um usuário de 3 a 80 caracteres: letras, números, ponto, hífen, sublinhado ou @.'],422);if(strlen($password)<12||strlen($password)>200)json_response(['success'=>false,'message'=>'A senha precisa ter entre 12 e 200 caracteres.'],422);
    try{$q=$pdo->prepare("INSERT INTO administradores (usuario,senha_hash,tipo,ubs_id,cidade,estado) VALUES (?,?,'secretaria',NULL,?,?)");$q->execute([$user,password_hash($password,PASSWORD_DEFAULT),$location['cidade'],$location['estado']]);$id=(int)$pdo->lastInsertId();}catch(PDOException $e){if(database_is_unique_violation($e))json_response(['success'=>false,'message'=>'Esse usuário já está cadastrado.'],409);throw $e;}
    audit_event($pdo,'administrador_secretaria_criado','administradores',(string)$id,['usuario'=>$user,'cidade'=>$location['cidade'],'estado'=>$location['estado']]);json_response(['success'=>true,'message'=>'Conta da Secretaria criada com cidade e UF. As novas UBS herdarão essa localização.','account'=>['id'=>$id,'usuario'=>$user,'cidade'=>$location['cidade'],'estado'=>$location['estado']]]);
}
function delete_secretaria_account(PDO $pdo,array $data): never
{
    $session=require_admin();if($session['tipo']!=='desenvolvedor')json_response(['success'=>false,'message'=>'Somente o desenvolvedor pode remover contas da Secretaria.'],403);$id=(int)($data['id']??0);if($id<1||$id===(int)($session['id']??0))json_response(['success'=>false,'message'=>'Conta inválida.'],422);$q=$pdo->prepare("DELETE FROM administradores WHERE id=? AND tipo='secretaria'");$q->execute([$id]);if($q->rowCount()<1)json_response(['success'=>false,'message'=>'Conta da Secretaria não encontrada.'],404);audit_event($pdo,'administrador_secretaria_removido','administradores',(string)$id);json_response(['success'=>true,'message'=>'Conta da Secretaria removida.']);
}
function admin_change_password(PDO $pdo, array $data): never
{
    $session = require_admin();
    if (($session['tipo'] ?? '') !== 'desenvolvedor') {
        json_response(['success'=>false,'message'=>'Somente o desenvolvedor pode alterar esta senha.'],403);
    }
    $currentPassword = (string)($data['senha_atual'] ?? '');
    $newPassword = (string)($data['nova_senha'] ?? '');
    if ($currentPassword === '') json_response(['success'=>false,'message'=>'Informe a senha atual.'],422);
    if (strlen($newPassword) < 12 || strlen($newPassword) > 200) {
        json_response(['success'=>false,'message'=>'A nova senha precisa ter entre 12 e 200 caracteres.'],422);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT senha_hash FROM administradores WHERE id=? AND tipo='desenvolvedor' FOR UPDATE");
        $stmt->execute([(int)($session['id'] ?? 0)]);
        $currentHash = $stmt->fetchColumn();
        if (!is_string($currentHash) || !password_verify($currentPassword, $currentHash)) {
            $pdo->rollBack();
            json_response(['success'=>false,'message'=>'A senha atual está incorreta.'],403);
        }
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE administradores SET senha_hash=? WHERE id=? AND tipo='desenvolvedor'");
        $stmt->execute([$newHash,(int)$session['id']]);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            json_response(['success'=>false,'message'=>'Não foi possível localizar a conta de desenvolvedor.'],404);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    session_regenerate_id(true);
    rotate_csrf_token();
    audit_event($pdo,'senha_desenvolvedor_alterada','administradores',(string)$session['id']);
    json_response(['success'=>true,'message'=>'Senha do desenvolvedor alterada com sucesso.','csrf_token'=>$_SESSION['_csrf_token']]);
}

function admin_logout(): never
{
    unset($_SESSION['admin']);$_SESSION['_auth_created_at']=time();$_SESSION['_last_activity']=time();session_regenerate_id(true);rotate_csrf_token();json_response(['success'=>true,'csrf_token'=>$_SESSION['_csrf_token']]);
}
function admin_audit(PDO $pdo): never
{
    $session = require_admin();
    if ($session['tipo'] !== 'desenvolvedor') json_response(['success'=>false,'message'=>'Somente o desenvolvedor pode consultar a auditoria.'],403);
    $limit = min(200, max(10, (int)($_GET['limit'] ?? 100)));
    $stmt = $pdo->query('SELECT id, tipo_usuario AS tipoUsuario, acao, entidade, entidade_id AS entidadeId, detalhes, ip, criado_em AS criadoEm FROM auditoria ORDER BY id DESC LIMIT '.$limit);
    json_response(['success'=>true,'events'=>$stmt->fetchAll()]);
}

function get_patient_notifications(PDO $pdo, string $sus): never
{
    $id = patient_id_by_sus($pdo, $sus);
    if ($id === null) json_response(['success'=>true,'notifications'=>[]]);
    $stmt = $pdo->prepare('SELECT id, tipo, mensagem, status, agendada_para AS agendadaPara FROM notificacoes WHERE paciente_id = ? ORDER BY agendada_para DESC LIMIT 30');
    $stmt->execute([$id]);
    json_response(['success'=>true,'notifications'=>$stmt->fetchAll()]);
}
function validar_localizacao_ubs(array $data): array
{
    $cidade = trim((string)($data['cidade'] ?? ''));
    $estado = strtoupper(trim((string)($data['estado'] ?? '')));
    $ufs = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
    if (preg_match('/^.{2,120}$/uD', $cidade) !== 1 || !in_array($estado, $ufs, true)) {
        json_response(['success'=>false,'message'=>'Informe uma cidade e uma sigla de UF válida.'],422);
    }
    return ['cidade'=>normalizar_nome_cidade($cidade),'estado'=>$estado];
}

// Reaproveita a grafia já cadastrada da cidade (ex.: "serra" vira "Serra") para não duplicar opções no login.
function localizacao_canonica(PDO $pdo, array $location): array
{
    $q = $pdo->prepare("SELECT cidade FROM ubs WHERE estado = ? AND cidade <> '' UNION SELECT cidade FROM administradores WHERE tipo = 'secretaria' AND estado = ? AND cidade <> ''");
    $q->execute([$location['estado'], $location['estado']]);
    $key = chave_cidade($location['cidade']);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $existing) {
        if (chave_cidade((string)$existing) === $key) return ['cidade'=>normalizar_nome_cidade((string)$existing),'estado'=>$location['estado']];
    }
    return $location;
}

// Desenvolvedor define livremente; Secretaria com município fica restrita a ele; demais perfis não mudam uma localização já definida.
function localizacao_para_ubs(PDO $pdo, array $session, string $ubsId, array $data): array
{
    if ($session['tipo'] !== 'desenvolvedor') {
        $region = admin_region($pdo, $session);
        if ($region !== null) return $region;
        $q = $pdo->prepare('SELECT cidade, estado FROM ubs WHERE id = ?');
        $q->execute([$ubsId]);
        $current = admin_region_from_row($q->fetch(PDO::FETCH_ASSOC) ?: null);
        if ($current !== null) return $current;
    }
    return localizacao_canonica($pdo, validar_localizacao_ubs($data));
}

function create_ubs(PDO $pdo,array $data): never
{
    $session = require_admin();
    if (!in_array($session['tipo'], ['desenvolvedor','secretaria'], true)) json_response(['success'=>false,'message'=>'Apenas a Secretaria de Saúde ou o desenvolvedor pode cadastrar novas UBS.'],403);
    $id = required_string($data, 'id');
    $nome = required_string($data, 'nome');
    $endereco = required_string($data, 'endereco');
    $secretariaNeedsLocationSave = false;
    if ($session['tipo'] === 'secretaria') {
        $qSecretaria = $pdo->prepare("SELECT cidade, estado FROM administradores WHERE id=? AND tipo='secretaria'");
        $qSecretaria->execute([(int)($session['id'] ?? 0)]);
        $secretariaLocation = $qSecretaria->fetch(PDO::FETCH_ASSOC);
        if (!$secretariaLocation) json_response(['success'=>false,'message'=>'Conta da Secretaria não encontrada.'],403);
        if (trim((string)$secretariaLocation['cidade']) !== '' && trim((string)$secretariaLocation['estado']) !== '') {
            $location = validar_localizacao_ubs($secretariaLocation);
        } else {
            $location = localizacao_canonica($pdo, validar_localizacao_ubs($data));
            $secretariaNeedsLocationSave = true;
        }
    } else {
        $location = localizacao_canonica($pdo, validar_localizacao_ubs($data));
    }
    $telefone = required_string($data, 'telefone');
    $horario = required_string($data, 'horario');
    $usuario = required_string($data, 'usuario');
    $senha = required_string($data, 'senha');
    $esp = clean_list($data['especialidades'] ?? []);
    if (!$esp) json_response(['success'=>false,'message'=>'Cadastre pelo menos uma especialidade.'],422);
    if (!preg_match('/^[A-Za-z0-9_-]{2,20}$/', $id)) json_response(['success'=>false,'message'=>'Identificador inválido.'],422);
    $q = $pdo->prepare('SELECT id FROM ubs WHERE id=? OR usuario=? UNION SELECT ubs_id FROM administradores WHERE usuario=?');
    $q->execute([$id,$usuario,$usuario]);
    if ($q->fetch()) json_response(['success'=>false,'message'=>'Identificador ou usuário já utilizado.'],409);
    $pdo->beginTransaction();
    try {
        if ($secretariaNeedsLocationSave) {
            $pdo->prepare("UPDATE administradores SET cidade=?,estado=? WHERE id=? AND tipo='secretaria'")->execute([$location['cidade'],$location['estado'],(int)($session['id'] ?? 0)]);
        }
        $pdo->prepare('INSERT INTO ubs(id,nome,endereco,cidade,estado,telefone,horario,usuario,limite_diario) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$id,$nome,$endereco,$location['cidade'],$location['estado'],$telefone,$horario,$usuario,12]);
        replace_list($pdo,'ubs_especialidades',$id,$esp);
        $pdo->prepare("INSERT INTO administradores(usuario,senha_hash,tipo,ubs_id) VALUES(?,?,'ubs',?)")->execute([$usuario,password_hash($senha,PASSWORD_DEFAULT),$id]);
        $pdo->commit();
        audit_event($pdo, 'ubs_criada', 'ubs', $id, ['nome'=>$nome,'cidade'=>$location['cidade'],'estado'=>$location['estado']]);
        json_response(['success'=>true,'ubs'=>get_ubs($pdo,$id,true)]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
function admin_exams(PDO $pdo): never
{
    $ubsId = trim((string)($_GET['ubs_id'] ?? ''));
    authorize_ubs($ubsId);

    $stmt = $pdo->prepare(
        'SELECT e.id, e.nome_exame AS nome, DATE_FORMAT(e.data_exame, "%Y-%m-%d") AS data,
                e.resultado, e.observacoes, e.anexo_nome AS anexoNome,
                (e.anexo_arquivo IS NOT NULL) AS anexo, p.nome AS paciente, p.sus
         FROM exames_resultados e
         INNER JOIN pacientes p ON p.id = e.paciente_id
         WHERE e.ubs_id = ?
         ORDER BY e.data_exame DESC, e.id DESC'
    );
    $stmt->execute([$ubsId]);

    json_response(['success' => true, 'exams' => $stmt->fetchAll()]);
}

function login_admin(PDO $pdo, array $data): never
{
    $usuario = required_string($data, 'usuario');
    $senha = (string)($data['senha'] ?? '');
    $attemptKey = auth_attempt_key('admin_login', $usuario);
    if (auth_is_limited($pdo, $attemptKey)) json_response(['success'=>false,'message'=>'Muitas tentativas de acesso. Aguarde 15 minutos.'],429);

    if ($senha === '') {
        json_response([
            'success' => false,
            'message' => 'Informe a senha.'
        ], 422);
    }

    $stmt = $pdo->prepare(
        'SELECT a.id, a.usuario, a.senha_hash, a.tipo, a.ubs_id, a.cidade, a.estado, u.ativa AS ubs_ativa
         FROM administradores a
         LEFT JOIN ubs u ON u.id = a.ubs_id
         WHERE a.usuario = ?'
    );

    $stmt->execute([$usuario]);

    $admin = $stmt->fetch();

    if (
        !$admin ||
        !password_verify($senha, $admin['senha_hash']) ||
        ($admin['tipo'] === 'ubs' && !database_bool($admin['ubs_ativa'] ?? false))
    ) {
        auth_record_failure($pdo,$attemptKey);
        json_response([
            'success' => false,
            'message' => 'Usuário ou senha inválidos.'
        ], 401);
    }

    auth_clear_attempts($pdo,$attemptKey);
    session_regenerate_id(true);
    rotate_csrf_token();
    $_SESSION['_auth_created_at']=time(); $_SESSION['_last_activity']=time();

    $_SESSION['admin'] = [
        'id' => (int)$admin['id'],
        'tipo' => $admin['tipo'],
        'ubs_id' => $admin['ubs_id'],
        'cidade' => $admin['cidade'] ?? '',
        'estado' => trim((string)($admin['estado'] ?? ''))
    ];

    audit_event($pdo, 'login_admin', 'administradores', (string)$admin['id'], ['usuario' => $usuario]);

    json_response([
        'success' => true,
        'csrf_token' => $_SESSION['_csrf_token'],
        'session' => [
            'id' => (int)$admin['id'],
            'tipo' => $admin['tipo'],
            'ubsId' => $admin['ubs_id'],
            'cidade' => $admin['cidade'] ?? '',
            'estado' => trim((string)($admin['estado'] ?? ''))
        ]
    ]);
}

function audit_event(PDO $pdo, string $acao, ?string $entidade = null, ?string $entidadeId = null, array $detalhes = []): void
{
    try {
        $session = admin_session();
        $stmt = $pdo->prepare('INSERT INTO auditoria (admin_id,tipo_usuario,acao,entidade,entidade_id,detalhes,ip) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$session['id'] ?? null, $session['tipo'] ?? null, $acao, $entidade, $entidadeId, $detalhes ? json_encode($detalhes, JSON_UNESCAPED_UNICODE) : null, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $ignored) {
        error_log('Falha ao gravar auditoria: '.$ignored->getMessage());
    }
}

function update_ubs_campaigns(PDO $pdo, array $data): never
{
    $ubsId = required_string($data, 'ubs_id');
    authorize_ubs($ubsId);
    $campaigns = clean_list($data['campanhas'] ?? []);

    $pdo->beginTransaction();
    try {
        replace_list($pdo, 'ubs_campanhas', $ubsId, $campaigns);
        $pdo->commit();
        json_response([
            'success' => true,
            'message' => 'Campanhas e eventos atualizados.',
            'ubs' => get_ubs($pdo, $ubsId, true)
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function update_ubs(PDO $pdo, array $data): never
{
    $ubsId = required_string($data, 'ubs_id');

    $session = authorize_ubs($ubsId);

    $nome = required_string($data, 'nome');
    $endereco = required_string($data, 'endereco');
    $location = localizacao_para_ubs($pdo, $session, $ubsId, $data);
    $telefone = required_string($data, 'telefone');
    $horario = required_string($data, 'horario');
    $usuario = required_string($data, 'usuario');

    $especialidades = clean_list($data['especialidades'] ?? []);
    $servicos = clean_list($data['servicos'] ?? []);
    $campanhas = clean_list($data['campanhas'] ?? []);
    $documentos = clean_list($data['documentos'] ?? []);
    $novaSenha = trim((string)($data['senha'] ?? ''));

    if (!$especialidades) {
        json_response([
            'success' => false,
            'message' => 'Cadastre pelo menos uma especialidade.'
        ], 422);
    }

    $stmt = $pdo->prepare(
        'SELECT id
         FROM ubs
         WHERE usuario = ?
           AND id <> ?'
    );

    $stmt->execute([
        $usuario,
        $ubsId
    ]);

    if ($stmt->fetch()) {
        json_response([
            'success' => false,
            'message' => 'Esse usuário já pertence a outra UBS.'
        ], 409);
    }

    $stmt = $pdo->prepare(
        'SELECT id
         FROM administradores
         WHERE usuario = ?
           AND (ubs_id IS NULL OR ubs_id <> ?)'
    );

    $stmt->execute([
        $usuario,
        $ubsId
    ]);

    if ($stmt->fetch()) {
        json_response([
            'success' => false,
            'message' => 'Esse usuário já está sendo utilizado por outro administrador.'
        ], 409);
    }

    $pdo->beginTransaction();

    try {

        $stmt = $pdo->prepare(
            'UPDATE ubs
             SET nome = ?,
                 endereco = ?,
                 cidade = ?,
                 estado = ?,
                 telefone = ?,
                 horario = ?,
                 usuario = ?,
                 limite_diario = ?
             WHERE id = ?'
        );

        $stmt->execute([
            $nome,
            $endereco,
            $location['cidade'],
            $location['estado'],
            $telefone,
            $horario,
            $usuario,
            max(1,(int)($data['limite_diario']??12)),
            $ubsId
        ]);

        replace_list(
            $pdo,
            'ubs_especialidades',
            $ubsId,
            $especialidades
        );

        replace_list(
            $pdo,
            'ubs_servicos',
            $ubsId,
            $servicos
        );

        replace_list(
            $pdo,
            'ubs_campanhas',
            $ubsId,
            $campanhas
        );

        replace_list(
            $pdo,
            'ubs_documentos',
            $ubsId,
            $documentos
        );

        $stmt = $pdo->prepare(
            'UPDATE administradores
             SET usuario = ?
             WHERE ubs_id = ?'
        );

        $stmt->execute([
            $usuario,
            $ubsId
        ]);

        if ($novaSenha !== '') {

            $stmt = $pdo->prepare(
                'UPDATE administradores
                 SET senha_hash = ?
                 WHERE ubs_id = ?'
            );

            $stmt->execute([
                password_hash(
                    $novaSenha,
                    PASSWORD_DEFAULT
                ),
                $ubsId
            ]);

        }

        $pdo->commit();

        json_response([
            'success' => true,
            'ubs' => get_ubs(
                $pdo,
                $ubsId,
                true
            )
        ]);

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function replace_list(
    PDO $pdo,
    string $table,
    string $ubsId,
    array $items
): void
{
    $allowed = [
        'ubs_especialidades',
        'ubs_servicos',
        'ubs_campanhas',
        'ubs_documentos'
    ];

    if (!in_array($table, $allowed, true)) {
        throw new RuntimeException(
            'Tabela de lista inválida.'
        );
    }

    $pdo->prepare(
        "DELETE FROM {$table} WHERE ubs_id = ?"
    )->execute([$ubsId]);

    $stmt = $pdo->prepare(
        "INSERT INTO {$table} (ubs_id, nome) VALUES (?, ?)"
    );

    foreach ($items as $item) {
        $stmt->execute([
            $ubsId,
            $item
        ]);
    }
}

function require_employee_manager(): array
{
    $session = require_admin();
    if (!in_array($session['tipo'], ['desenvolvedor', 'secretaria'], true)) {
        json_response(['success' => false, 'message' => 'Somente a Secretaria de Saúde ou o desenvolvedor pode gerenciar funcionários.'], 403);
    }
    return $session;
}

function save_employee(PDO $pdo, array $data): never
{
    require_employee_manager();
    $ubsId = required_string($data, 'ubs_id');
    authorize_ubs($ubsId);

    $nome = required_string($data, 'nome');
    $cargo = required_string($data, 'cargo');
    $id = trim((string)($data['id'] ?? ''));

    if ($id !== '') {

        $stmt = $pdo->prepare(
            'UPDATE funcionarios
             SET nome = ?, cargo = ?
             WHERE id = ? AND ubs_id = ?'
        );

        $stmt->execute([
            $nome,
            $cargo,
            $id,
            $ubsId
        ]);

        if ($stmt->rowCount() === 0) {

            $check = $pdo->prepare(
                'SELECT id
                 FROM funcionarios
                 WHERE id = ?'
            );

            $check->execute([$id]);

            if ($check->fetch()) {
                json_response([
                    'success' => false,
                    'message' => 'Funcionário não pertence a esta UBS.'
                ], 403);
            }

            json_response([
                'success' => false,
                'message' => 'Funcionário não encontrado.'
            ], 404);
        }

    } else {

        $id =
            'func-' .
            bin2hex(
                random_bytes(6)
            );

        $stmt = $pdo->prepare(
            'INSERT INTO funcionarios
                (id, ubs_id, nome, cargo)
             VALUES (?, ?, ?, ?)'
        );

        $stmt->execute([
            $id,
            $ubsId,
            $nome,
            $cargo
        ]);

    }

    $stmt = $pdo->prepare(
        'SELECT id, nome, cargo
         FROM funcionarios
         WHERE ubs_id = ?
         ORDER BY nome'
    );

    $stmt->execute([$ubsId]);

    json_response([
        'success' => true,
        'funcionarios' => $stmt->fetchAll()
    ]);
}

function delete_employee(PDO $pdo, array $data): never
{
    require_employee_manager();
    $id = required_string($data, 'id');

    $stmt = $pdo->prepare(
        'SELECT ubs_id
         FROM funcionarios
         WHERE id = ?'
    );

    $stmt->execute([$id]);

    $employee = $stmt->fetch();

    if (!$employee) {
        json_response([
            'success' => false,
            'message' => 'Funcionário não encontrado.'
        ], 404);
    }

    authorize_ubs($employee['ubs_id']);

    $stmt = $pdo->prepare(
        'DELETE FROM funcionarios
         WHERE id = ?'
    );

    $stmt->execute([$id]);

    $stmt = $pdo->prepare(
        'SELECT id, nome, cargo
         FROM funcionarios
         WHERE ubs_id = ?
         ORDER BY nome'
    );

    $stmt->execute([
        $employee['ubs_id']
    ]);

    json_response([
        'success' => true,
        'funcionarios' => $stmt->fetchAll()
    ]);
}

function admin_waitlist(PDO $pdo): never {
    $ubsId=trim((string)($_GET['ubs_id']??'')); $especialidade=trim((string)($_GET['especialidade']??'')); $date=trim((string)($_GET['data']??'')); authorize_ubs($ubsId);
    $sql='SELECT l.id,l.data_consulta AS data,l.especialidade,l.criado_em AS criadoEm,p.codigo,p.nome,p.telefone FROM lista_espera l INNER JOIN pacientes p ON p.id=l.paciente_id WHERE l.ubs_id=? AND l.status="pendente"';$params=[$ubsId];
    if($especialidade!==''){$sql.=' AND l.especialidade=?';$params[]=$especialidade;} if($date!==''){$sql.=' AND l.data_consulta=?';$params[]=$date;}$sql.=' ORDER BY l.data_consulta,l.especialidade,l.id';$q=$pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll();
    // Vagas livres por especialidade/data: indicam à UBS quando vale encaixar alguém da lista.
    $q=$pdo->prepare('SELECT limite_diario FROM ubs WHERE id=?');$q->execute([$ubsId]);$limite=max(1,(int)$q->fetchColumn());
    $count=$pdo->prepare("SELECT COUNT(*) FROM consultas WHERE ubs_id=? AND especialidade=? AND data_consulta=? AND status='agendado'");$vagas=[];
    foreach($rows as &$row){$key=$row['especialidade'].'|'.$row['data'];if(!isset($vagas[$key])){$count->execute([$ubsId,$row['especialidade'],$row['data']]);$vagas[$key]=max(0,$limite-(int)$count->fetchColumn());}$row['vagasLivres']=$vagas[$key];}
    unset($row);
    $q=$pdo->prepare("SELECT l.id,l.consulta_id,l.data_consulta,l.especialidade,c.fila,p.codigo,p.nome,p.telefone FROM lista_espera l INNER JOIN consultas c ON c.id=l.consulta_id INNER JOIN pacientes p ON p.id=l.paciente_id WHERE l.ubs_id=? AND l.status='agendado' AND l.aviso_enviado_em IS NULL AND c.status='agendado' AND l.data_consulta>=? ORDER BY l.data_consulta,l.id");
    $q->execute([$ubsId,date('Y-m-d')]);
    $avisos=array_map(static fn(array $a): array=>['id'=>(int)$a['id'],'consultaId'=>$a['consulta_id'],'data'=>$a['data_consulta'],'especialidade'=>$a['especialidade'],'fila'=>(int)$a['fila'],'codigo'=>$a['codigo'],'nome'=>$a['nome'],'telefone'=>$a['telefone']],$q->fetchAll());
    json_response(['success'=>true,'waitlist'=>$rows,'avisosPendentes'=>$avisos]);
}
function whatsapp_url(?string $telefone, string $mensagem): ?string {
    $phone=preg_replace('/\D+/','',(string)$telefone);if($phone==='')return null;
    $waPhone=str_starts_with($phone,'55')&&strlen($phone)>=12?$phone:'55'.$phone;
    return 'https://wa.me/'.$waPhone.'?text='.rawurlencode($mensagem);
}
function admin_notify_waitlist(PDO $pdo,array $data): never {
    $ubsId=required_string($data,'ubs_id');$waitlistId=(int)($data['waitlist_id']??0);if($waitlistId<1)json_response(['success'=>false,'message'=>'Paciente da lista de espera não informado.'],422);authorize_ubs($ubsId);
    $q=$pdo->prepare("SELECT l.id,l.consulta_id,l.especialidade,l.data_consulta,c.fila,p.nome,p.telefone,u.nome AS ubs_nome FROM lista_espera l INNER JOIN consultas c ON c.id=l.consulta_id INNER JOIN pacientes p ON p.id=l.paciente_id INNER JOIN ubs u ON u.id=l.ubs_id WHERE l.id=? AND l.ubs_id=? AND l.status='agendado' AND c.status='agendado'");
    $q->execute([$waitlistId,$ubsId]);$row=$q->fetch();
    if(!$row)json_response(['success'=>false,'message'=>'Este agendamento da lista de espera não está mais ativo. Atualize a lista.'],409);
    $msg="Olá, {$row['nome']}! Aqui é a {$row['ubs_nome']}. Uma vaga foi liberada e você saiu da lista de espera: sua consulta de {$row['especialidade']} está confirmada para ".date('d/m/Y',strtotime((string)$row['data_consulta'])).". Posição na fila: {$row['fila']}. Protocolo: {$row['consulta_id']}. Se não puder comparecer, cancele pelo Acessa+ Saúde para liberar a vaga.";
    $pdo->prepare("INSERT INTO notificacoes (paciente_id,consulta_id,canal,tipo,mensagem,agendada_para,enviada_em,status) SELECT paciente_id,consulta_id,'whatsapp','lista_espera_aviso_ubs',?,NOW(),NOW(),'enviada' FROM lista_espera WHERE id=?")->execute([$msg,$waitlistId]);
    $pdo->prepare('UPDATE lista_espera SET aviso_enviado_em=NOW() WHERE id=?')->execute([$waitlistId]);
    audit_event($pdo,'lista_espera_aviso_enviado','lista_espera',(string)$waitlistId,['ubs_id'=>$ubsId,'consulta_id'=>$row['consulta_id']]);
    $url=whatsapp_url($row['telefone'],$msg);
    json_response(['success'=>true,'whatsapp_url'=>$url,'message'=>$url?'Aviso registrado. Envie a mensagem no WhatsApp aberto.':'Aviso registrado, mas o paciente não tem telefone cadastrado.']);
}
function admin_add_waitlist_to_queue(PDO $pdo,array $data): never {
    $ubsId=required_string($data,'ubs_id');$waitlistId=(int)($data['waitlist_id']??0);if($waitlistId<1)json_response(['success'=>false,'message'=>'Paciente da lista de espera não informado.'],422);authorize_ubs($ubsId);
    $pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT l.id,l.paciente_id,l.especialidade,l.data_consulta,p.nome,p.telefone FROM lista_espera l INNER JOIN pacientes p ON p.id=l.paciente_id WHERE l.id=? AND l.ubs_id=? AND l.status="pendente" FOR UPDATE');$q->execute([$waitlistId,$ubsId]);$row=$q->fetch();if(!$row){$pdo->rollBack();json_response(['success'=>false,'message'=>'Este paciente não está mais pendente na lista de espera. Atualize a lista e tente novamente.'],409);}
        $q=$pdo->prepare('SELECT id FROM consultas WHERE paciente_id=? AND ubs_id=? AND especialidade=? AND data_consulta=? AND status="agendado" LIMIT 1');$q->execute([$row['paciente_id'],$ubsId,$row['especialidade'],$row['data_consulta']]);if($q->fetch()){ $pdo->rollBack();json_response(['success'=>false,'message'=>'Este paciente já possui uma consulta agendada para esta UBS, especialidade e data.'],409); }
        $lock=$pdo->prepare('SELECT nome FROM ubs WHERE id=? FOR UPDATE');$lock->execute([$ubsId]);$unit=$lock->fetch();if(!$unit){$pdo->rollBack();json_response(['success'=>false,'message'=>'UBS não encontrada.'],404);}
        $next=$pdo->prepare('SELECT COALESCE(MAX(fila),0)+1 FROM consultas WHERE ubs_id=? AND especialidade=? AND data_consulta=?');$next->execute([$ubsId,$row['especialidade'],$row['data_consulta']]);$fila=(int)$next->fetchColumn();
        $id='CONS-'.date('YmdHis').'-'.strtoupper(bin2hex(random_bytes(3)));$ins=$pdo->prepare('INSERT INTO consultas (id,ubs_id,paciente_id,especialidade,assunto,data_consulta,horario,fila,status) VALUES (?,?,?,?,?,? ,"00:00:00",?,"agendado")');$ins->execute([$id,$ubsId,$row['paciente_id'],$row['especialidade'],'Encaixe administrativo da lista de espera',$row['data_consulta'],$fila]);
        $pdo->prepare('UPDATE lista_espera SET status="agendado",consulta_id=?,aviso_enviado_em=NOW() WHERE id=? AND status="pendente"')->execute([$id,$waitlistId]);
        $msg="Olá, {$row['nome']}! Você foi encaixado(a) na fila da {$unit['nome']} para {$row['especialidade']} em ".date('d/m/Y',strtotime($row['data_consulta'])).". Sua posição é {$fila}. Protocolo: {$id}.";$pdo->prepare('INSERT INTO notificacoes (paciente_id,consulta_id,canal,tipo,mensagem,agendada_para,status) VALUES (?,? ,"push","lista_espera_encaixada",?,NOW(),"pendente")')->execute([$row['paciente_id'],$id,$msg]);
        $whatsappUrl=whatsapp_url($row['telefone']??null,$msg);
        $pdo->commit();audit_event($pdo,'paciente_encaixado_lista_espera','consultas',$id,['ubs_id'=>$ubsId,'lista_espera_id'=>$waitlistId,'fila'=>$fila]);json_response(['success'=>true,'appointment_id'=>$id,'fila'=>$fila,'whatsapp_url'=>$whatsappUrl,'message'=>'Paciente adicionado à fila principal e notificado no sistema.']);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function admin_appointments(PDO $pdo): never
{
    $ubsId =
        trim(
            (string)(
                $_GET['ubs_id'] ?? ''
            )
        );

    authorize_ubs($ubsId);

    $date =
        trim(
            (string)(
                $_GET['data'] ?? ''
            )
        );

    $specialty =
        trim(
            (string)(
                $_GET['especialidade'] ?? ''
            )
        );

    $sql =
        'SELECT
            c.id,
            c.ubs_id AS ubsId,
            u.nome AS ubsNome,
            p.nome,
            p.telefone,
            p.sus,
            c.especialidade,
            c.assunto,
            DATE_FORMAT(c.data_consulta, "%Y-%m-%d") AS data,
            CASE WHEN c.horario = \'00:00:00\' THEN NULL ELSE TIME_FORMAT(c.horario, \'%H:%i\') END AS horario,
            c.fila,
            c.status,
            c.cancelamento_motivo AS cancelamentoMotivo,
            c.lembrete,
            c.notificado,
            c.criada_em AS criadaEm
         FROM consultas c
         INNER JOIN pacientes p ON p.id = c.paciente_id
         INNER JOIN ubs u ON u.id = c.ubs_id
         WHERE c.ubs_id = ?';

    $params = [$ubsId];

    if ($date !== '') {
        $sql .= ' AND c.data_consulta = ?';
        $params[] = $date;
    }

    if ($specialty !== '') {
        $sql .= ' AND c.especialidade = ?';
        $params[] = $specialty;
    }

    $sql .=
        ' ORDER BY c.data_consulta, c.horario';

    $stmt = $pdo->prepare($sql);

    $stmt->execute($params);

    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['lembrete'] = database_bool($row['lembrete']);
        $row['notificado'] = database_bool($row['notificado']);
    }

    json_response([
        'success' => true,
        'appointments' => $rows
    ]);
}


// ------ Agenda particular, autenticação reforçada e gestão do agendamento público ------
function auth_attempt_key(string $scope,string $identity): string {
    $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
    return hash('sha256',$scope.'|'.strtolower(trim($identity)).'|'.$ip);
}
function auth_is_limited(PDO $pdo,string $key): bool {
    $q=$pdo->prepare('SELECT (bloqueado_ate IS NOT NULL AND bloqueado_ate>NOW()) FROM tentativas_autenticacao WHERE chave=?');$q->execute([$key]);return database_bool($q->fetchColumn());
}
function auth_record_failure(PDO $pdo,string $key): void {
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('INSERT IGNORE INTO tentativas_autenticacao (chave,tentativas,inicio_janela) VALUES (?,0,NOW())');$q->execute([$key]);
        $q=$pdo->prepare('SELECT tentativas,inicio_janela FROM tentativas_autenticacao WHERE chave=? FOR UPDATE');$q->execute([$key]);$row=$q->fetch();
        $chk=$pdo->prepare('SELECT (inicio_janela<DATE_SUB(NOW(),INTERVAL 15 MINUTE)) FROM tentativas_autenticacao WHERE chave=?');$chk->execute([$key]);$isExpired=database_bool($chk->fetchColumn());
        $attempts=$isExpired?1:((int)$row['tentativas']+1);
        $q=$pdo->prepare('UPDATE tentativas_autenticacao SET tentativas=?,inicio_janela=IF(?,NOW(),inicio_janela),bloqueado_ate=IF(? >= 5,DATE_ADD(NOW(),INTERVAL 15 MINUTE),NULL) WHERE chave=?');$q->execute([$attempts,$isExpired,$attempts,$key]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('Falha no limitador de acesso: '.$e->getMessage());}
}
function auth_clear_attempts(PDO $pdo,string $key): void {$q=$pdo->prepare('DELETE FROM tentativas_autenticacao WHERE chave=?');$q->execute([$key]);}
function validate_professional_session(PDO $pdo): void {
    $s=professional_session();if(!$s)return;
    $q=$pdo->prepare('SELECT auth_version,status FROM profissionais WHERE id=?');$q->execute([(int)$s['id']]);$row=$q->fetch();
    if(!professional_session_matches_account($s,is_array($row)?$row:null)){
        $_SESSION['professional']=null;session_regenerate_id(true);rotate_csrf_token();
    }
}
function professional_logout(PDO $pdo): never {
    $s=professional_session();if($s)audit_professional_event($pdo,(int)$s['id'],'logout_profissional','profissionais',(string)$s['id']);
    $_SESSION['professional']=null;$_SESSION['_auth_created_at']=null;$_SESSION['_last_activity']=null;session_regenerate_id(true);rotate_csrf_token();json_response(['success'=>true,'csrf_token'=>$_SESSION['_csrf_token']]);
}
function professional_revoke_sessions(PDO $pdo): never {
    $s=require_professional();$q=$pdo->prepare('UPDATE profissionais SET auth_version=auth_version+1 WHERE id=?');$q->execute([$s['id']]);
    audit_professional_event($pdo,(int)$s['id'],'sessoes_revogadas','profissionais',(string)$s['id']);
    $_SESSION['professional']=null;$_SESSION['_auth_created_at']=null;$_SESSION['_last_activity']=null;session_regenerate_id(true);rotate_csrf_token();
    json_response(['success'=>true,'message'=>'Todas as sessões foram encerradas. Entre novamente.','csrf_token'=>$_SESSION['_csrf_token']]);
}
function audit_professional_event(PDO $pdo,int $professionalId,string $action,?string $entity=null,?string $entityId=null,array $details=[]): void {
    try{$q=$pdo->prepare('INSERT INTO auditoria (admin_id,profissional_id,tipo_usuario,acao,entidade,entidade_id,detalhes,ip) VALUES (NULL,?,?,?,?,?,?,?)');$q->execute([$professionalId,'profissional',$action,$entity,$entityId,$details?json_encode($details,JSON_UNESCAPED_UNICODE):null,$_SERVER['REMOTE_ADDR']??null]);}
    catch(Throwable $e){error_log('Falha ao registrar auditoria profissional: '.$e->getMessage());}
}
function professional_schedule(PDO $pdo): never {
    $s=require_professional();
    $q=$pdo->prepare('SELECT dia_semana AS dia,inicio,fim,duracao_minutos AS duracao,pausa_inicio AS pausaInicio,pausa_fim AS pausaFim,ativo FROM agendas_profissionais WHERE profissional_id=? ORDER BY dia_semana,inicio');
    $q->execute([$s['id']]);
    json_response(['success'=>true,'schedule'=>$q->fetchAll()]);
}
function professional_save_schedule(PDO $pdo,array $data): never {
    $s=require_professional(); $days=$data['dias']??null;
    if(!is_array($days)||count($days)>7) json_response(['success'=>false,'message'=>'Informe a disponibilidade semanal.'],422);
    $normalized=[]; $seenDays=[];
    foreach($days as $row){
        if(!is_array($row)) json_response(['success'=>false,'message'=>'Revise os dados da semana.'],422);
        $day=(int)($row['dia']??-1); $active=(int)database_bool($row['ativo']??false);
        if($day<0||$day>6) json_response(['success'=>false,'message'=>'Dia da semana inválido.'],422);
        if(isset($seenDays[$day])) json_response(['success'=>false,'message'=>'Cada dia da semana deve aparecer uma única vez.'],422);
        $seenDays[$day]=true;
        $start=trim((string)($row['inicio']??'')); $end=trim((string)($row['fim']??''));
        $duration=(int)($row['duracao']??30); $breakStart=trim((string)($row['pausa_inicio']??'')); $breakEnd=trim((string)($row['pausa_fim']??''));
        $validTime=static fn(string $time): bool => (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$time);
        if(!$active){
            // Mantém horários editáveis nos dias fechados sem permitir valores inválidos no banco.
            if(!$validTime($start)||!$validTime($end)||strtotime($start)>=strtotime($end)){ $start='09:00'; $end='17:00'; }
            if($duration<5||$duration>240) $duration=30;
            if(($breakStart==='')!==($breakEnd==='')||($breakStart!==''&&(!$validTime($breakStart)||!$validTime($breakEnd)||strtotime($breakStart)>=strtotime($breakEnd)||strtotime($breakStart)<strtotime($start)||strtotime($breakEnd)>strtotime($end)))){ $breakStart=''; $breakEnd=''; }
            $normalized[]=['dia'=>$day,'inicio'=>$start,'fim'=>$end,'duracao'=>$duration,'pausa_inicio'=>$breakStart?:null,'pausa_fim'=>$breakEnd?:null,'ativo'=>0];
            continue;
        }
        if(!$validTime($start)||!$validTime($end)||strtotime($start)>=strtotime($end)||$duration<5||$duration>240) json_response(['success'=>false,'message'=>'Revise o início, fim e duração do expediente.'],422);
        if(($breakStart==='')!==($breakEnd==='')) json_response(['success'=>false,'message'=>'Informe início e fim da pausa, ou deixe os dois vazios.'],422);
        if($breakStart!==''&&(!$validTime($breakStart)||!$validTime($breakEnd)||strtotime($breakStart)>=strtotime($breakEnd)||strtotime($breakStart)<strtotime($start)||strtotime($breakEnd)>strtotime($end))) json_response(['success'=>false,'message'=>'A pausa precisa estar dentro do expediente.'],422);
        $normalized[]=['dia'=>$day,'inicio'=>$start,'fim'=>$end,'duracao'=>$duration,'pausa_inicio'=>$breakStart?:null,'pausa_fim'=>$breakEnd?:null,'ativo'=>1];
    }
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT id FROM profissionais WHERE id=? FOR UPDATE'); $q->execute([$s['id']]);
        $pdo->prepare('DELETE FROM agendas_profissionais WHERE profissional_id=?')->execute([$s['id']]);
        $q=$pdo->prepare('INSERT INTO agendas_profissionais (profissional_id,dia_semana,inicio,fim,duracao_minutos,pausa_inicio,pausa_fim,ativo) VALUES (?,?,?,?,?,?,?,?)');
        foreach($normalized as $r) $q->execute([$s['id'],$r['dia'],$r['inicio'],$r['fim'],$r['duracao'],$r['pausa_inicio'],$r['pausa_fim'],(bool)$r['ativo']]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $activeDays=count(array_filter($normalized,static fn(array $r): bool => $r['ativo']===1));
    audit_professional_event($pdo,(int)$s['id'],'agenda_semanal_atualizada','agendas_profissionais',(string)$s['id'],['dias_ativos'=>$activeDays]);
    professional_schedule($pdo);
}
function professional_slots(PDO $pdo,int $professionalId,string $date,?string $excludeAppointmentId=null): array {
    if(!valid_date($date)||$date<date('Y-m-d'))return [];
    $weekday=(int)(new DateTimeImmutable($date))->format('N')-1;
    $q=$pdo->prepare('SELECT inicio,fim,duracao_minutos,pausa_inicio,pausa_fim FROM agendas_profissionais WHERE profissional_id=? AND dia_semana=? AND ativo=1 ORDER BY inicio');$q->execute([$professionalId,$weekday]);$shifts=$q->fetchAll();if(!$shifts)return [];
    $q=$pdo->prepare("SELECT limite_diario FROM profissionais WHERE id=? AND status='ativo'");$q->execute([$professionalId]);$limit=(int)$q->fetchColumn();if($limit<1)return [];
    $sql="SELECT id,horario,duracao_minutos FROM consultas_profissionais WHERE profissional_id=? AND data_consulta=? AND status NOT IN ('cancelada','faltou')";$params=[$professionalId,$date];
    if($excludeAppointmentId!==null){$sql.=' AND id<>?';$params[]=$excludeAppointmentId;}
    $q=$pdo->prepare($sql);$q->execute($params);$booked=$q->fetchAll();
    $countQ=$pdo->prepare("SELECT COUNT(*) FROM consultas_profissionais WHERE profissional_id=? AND data_consulta=? AND status NOT IN ('cancelada','faltou')".($excludeAppointmentId!==null?' AND id<>?':''));$countQ->execute($excludeAppointmentId!==null?[$professionalId,$date,$excludeAppointmentId]:[$professionalId,$date]);if((int)$countQ->fetchColumn()>=$limit)return [];
    $occupied=[];foreach($booked as $b){$start=(int)substr((string)$b['horario'],0,2)*60+(int)substr((string)$b['horario'],3,2);$occupied[]=[$start,$start+max(5,(int)$b['duracao_minutos'])];}
    $slots=[];$now=time();
    foreach($shifts as $shift){$start=(int)substr((string)$shift['inicio'],0,2)*60+(int)substr((string)$shift['inicio'],3,2);$end=(int)substr((string)$shift['fim'],0,2)*60+(int)substr((string)$shift['fim'],3,2);$duration=max(5,(int)$shift['duracao_minutos']);
        $pauseStart=$shift['pausa_inicio']?((int)substr((string)$shift['pausa_inicio'],0,2)*60+(int)substr((string)$shift['pausa_inicio'],3,2)):null;$pauseEnd=$shift['pausa_fim']?((int)substr((string)$shift['pausa_fim'],0,2)*60+(int)substr((string)$shift['pausa_fim'],3,2)):null;
        for($m=$start;$m+$duration<=$end;$m+=$duration){$finish=$m+$duration;if($pauseStart!==null&&$m<$pauseEnd&&$finish>$pauseStart)continue;if($date===date('Y-m-d')&&strtotime($date.' '.sprintf('%02d:%02d',$m/60,$m%60).':00')<=$now)continue;$busy=false;foreach($occupied as [$a,$b])if($m<$b&&$finish>$a){$busy=true;break;}if(!$busy)$slots[]=['horario'=>sprintf('%02d:%02d',intdiv($m,60),$m%60),'duracao'=>$duration];}
    }
    return $slots;
}
function public_clinic_slots(PDO $pdo,string $slug,string $date): never {
    if(!valid_date($date)||$date<date('Y-m-d'))json_response(['success'=>false,'message'=>'Escolha uma data futura válida.'],422);
    $q=$pdo->prepare("SELECT id,confirmacao_automatica AS confirmacaoAutomatica,cancelamento_ate_horas AS cancelamentoAteHoras,remarcacao_ate_horas AS remarcacaoAteHoras FROM profissionais WHERE slug=? AND status='ativo'");$q->execute([$slug]);$p=$q->fetch();if(!$p)json_response(['success'=>false,'message'=>'Clínica não encontrada.'],404);
    json_response(['success'=>true,'slots'=>professional_slots($pdo,(int)$p['id'],$date),'confirmacaoAutomatica'=>database_bool($p['confirmacaoAutomatica']??false)]);
}
function email_delivery_configured(): bool {return (bool)(getenv('RESEND_API_KEY')&&getenv('MAIL_FROM')&&getenv('APP_URL'));}
function app_public_url(): string {
    $url=rtrim(trim((string)(getenv('APP_URL')?:'')),'/');if($url===''||!filter_var($url,FILTER_VALIDATE_URL))throw new RuntimeException('Configure APP_URL com a URL pública HTTPS do site.');
    if(getenv('VERCEL')==='1'&&!str_starts_with(strtolower($url),'https://'))throw new RuntimeException('APP_URL precisa usar HTTPS em produção.');return $url.'/';
}
function send_email_message(string $to,string $subject,string $html,string $text): void {
    $apiKey=(string)(getenv('RESEND_API_KEY')?:'');$from=trim((string)(getenv('MAIL_FROM')?:''));if($apiKey===''||$from===''||!filter_var($to,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Serviço de e-mail não configurado.');
    if(!function_exists('curl_init'))throw new RuntimeException('Ative cURL para enviar e-mails transacionais.');
    $payload=json_encode(['from'=>$from,'to'=>[$to],'subject'=>$subject,'html'=>$html,'text'=>$text],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $ch=curl_init('https://api.resend.com/emails');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiKey,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>$payload,CURLOPT_TIMEOUT=>12]);$response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);if($response===false||$error!==''||$status<200||$status>=300)throw new RuntimeException('Falha ao enviar e-mail transacional.');
}
function send_professional_token(PDO $pdo,int $professionalId,string $type,string $email,string $name): bool {
    $token=bin2hex(random_bytes(32));$hours=$type==='verificar_email'?24:1;$q=$pdo->prepare('UPDATE tokens_profissionais SET consumido_em=NOW() WHERE profissional_id=? AND tipo=? AND consumido_em IS NULL');$q->execute([$professionalId,$type]);$q=$pdo->prepare('INSERT INTO tokens_profissionais (profissional_id,tipo,token_hash,expira_em) VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL ? HOUR))');$q->execute([$professionalId,$type,hash('sha256',$token),$hours]);
    $param=$type==='verificar_email'?'verificar_email':'redefinir_senha';$url=app_public_url().'?'.$param.'='.rawurlencode($token);$safe=htmlspecialchars($name,ENT_QUOTES,'UTF-8');$label=$type==='verificar_email'?'Confirmar e-mail':'Redefinir senha';$subject=$type==='verificar_email'?'Confirme seu e-mail — Acessa+ Saúde':'Redefinição de senha — Acessa+ Saúde';
    $html='<p>Olá, '.$safe.'.</p><p>Para '.$label.' da sua conta Acessa+ Saúde, use o link abaixo. Ele expira em '.$hours.' hora(s) e pode ser usado uma única vez.</p><p><a href="'.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'">'.$label.'</a></p><p>Se você não solicitou esta ação, ignore esta mensagem.</p>';
    send_email_message($email,$subject,$html,"Olá, {$name}. {$label}: {$url} (expira em {$hours} hora(s)).");return true;
}
function send_appointment_email(string $email,string $name,string $clinic,string $date,string $time,string $status,string $id,string $manageUrl): void {
    $safeName=htmlspecialchars($name,ENT_QUOTES,'UTF-8');$safeClinic=htmlspecialchars($clinic,ENT_QUOTES,'UTF-8');$when=(new DateTimeImmutable($date.' '.$time))->format('d/m/Y \\à\\s H:i');$state=$status==='confirmada'?'confirmada':($status==='cancelada'?'cancelada':($status==='solicitada'?'recebida e aguardando confirmação':'atualizada'));
    $link=$manageUrl!==''?'<p><a href="'.htmlspecialchars($manageUrl,ENT_QUOTES,'UTF-8').'">Gerenciar, remarcar ou cancelar</a></p>':'';$html='<p>Olá, '.$safeName.'.</p><p>Sua consulta na <strong>'.$safeClinic.'</strong> foi '.$state.' para '.$when.'.</p><p>Protocolo: '.htmlspecialchars($id,ENT_QUOTES,'UTF-8').'</p>'.$link;
    send_email_message($email,'Atualização da consulta — '.$clinic,$html,"Olá, {$name}. Sua consulta na {$clinic} foi {$state} para {$when}. Protocolo: {$id}. ".($manageUrl!==''?'Gerenciar: '.$manageUrl:''));
}
function professional_token_row(PDO $pdo,string $token,string $type): ?array {
    if(!preg_match('/^[a-f0-9]{64}$/',$token))return null;$q=$pdo->prepare('SELECT t.id AS token_id,t.profissional_id,t.expira_em,p.email,p.nome FROM tokens_profissionais t INNER JOIN profissionais p ON p.id=t.profissional_id WHERE t.token_hash=? AND t.tipo=? AND t.consumido_em IS NULL AND t.expira_em>NOW() LIMIT 1');$q->execute([hash('sha256',$token),$type]);return $q->fetch()?:null;
}
function professional_verify_email(PDO $pdo,string $token): never {
    $pdo->beginTransaction();try{$row=professional_token_row($pdo,$token,'verificar_email');if(!$row){$pdo->rollBack();json_response(['success'=>false,'message'=>'Link de verificação inválido ou expirado. Solicite um novo.'],410);}
        $lock=$pdo->prepare("SELECT id FROM tokens_profissionais WHERE id=? AND tipo='verificar_email' AND consumido_em IS NULL AND expira_em>NOW() FOR UPDATE");$lock->execute([$row['token_id']]);if(!$lock->fetchColumn()){$pdo->rollBack();json_response(['success'=>false,'message'=>'Este link já foi usado ou expirou. Solicite um novo.'],410);}
        $pdo->prepare('UPDATE tokens_profissionais SET consumido_em=NOW() WHERE id=?')->execute([$row['token_id']]);$pdo->prepare("UPDATE profissionais SET email_verificado_em=NOW(),status='ativo' WHERE id=?")->execute([$row['profissional_id']]);$pdo->commit();audit_professional_event($pdo,(int)$row['profissional_id'],'email_verificado','profissionais',(string)$row['profissional_id']);json_response(['success'=>true,'message'=>'E-mail confirmado. Agora você pode entrar.']);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function professional_resend_verification(PDO $pdo,array $data): never {
    $email=strtolower(trim((string)($data['email']??'')));$key=auth_attempt_key('reenviar_verificacao',$email);if(auth_is_limited($pdo,$key))json_response(['success'=>false,'message'=>'Muitas solicitações. Aguarde 15 minutos.'],429);
    $q=$pdo->prepare("SELECT id,email,nome FROM profissionais WHERE email=? AND status='pendente' AND email_verificado_em IS NULL");$q->execute([$email]);$p=$q->fetch();
    if($p){try{send_professional_token($pdo,(int)$p['id'],'verificar_email',$p['email'],$p['nome']);}catch(Throwable $e){error_log('Falha ao reenviar verificação: '.$e->getMessage());}}
    auth_record_failure($pdo,$key);json_response(['success'=>true,'message'=>'Se houver uma conta pendente para esse e-mail, enviaremos um novo link.']);
}
function professional_request_password_reset(PDO $pdo,array $data): never {
    $email=strtolower(trim((string)($data['email']??'')));if(!filter_var($email,FILTER_VALIDATE_EMAIL))json_response(['success'=>false,'message'=>'Informe um e-mail profissional válido.'],422);if(!email_delivery_configured())json_response(['success'=>false,'message'=>'A recuperação de senha está temporariamente indisponível porque o envio de e-mails ainda não foi configurado no servidor.'],503);$key=auth_attempt_key('redefinir_senha',$email);if(auth_is_limited($pdo,$key))json_response(['success'=>false,'message'=>'Muitas solicitações. Aguarde 15 minutos.'],429);
    $q=$pdo->prepare("SELECT id,email,nome FROM profissionais WHERE email=? AND status IN ('ativo','pendente')");$q->execute([$email]);$p=$q->fetch();
    if($p){try{send_professional_token($pdo,(int)$p['id'],'redefinir_senha',$p['email'],$p['nome']);}catch(Throwable $e){error_log('Falha no pedido de redefinição: '.$e->getMessage());}}
    auth_record_failure($pdo,$key);json_response(['success'=>true,'message'=>'Se o e-mail estiver cadastrado, enviaremos instruções para redefinir a senha.']);
}
function professional_reset_password(PDO $pdo,array $data): never {
    $token=trim((string)($data['token']??''));$password=(string)($data['senha']??'');if(strlen($password)<8)json_response(['success'=>false,'message'=>'A nova senha precisa ter pelo menos 8 caracteres.'],422);
    $pdo->beginTransaction();try{$row=professional_token_row($pdo,$token,'redefinir_senha');if(!$row){$pdo->rollBack();json_response(['success'=>false,'message'=>'Link de redefinição inválido ou expirado. Solicite outro.'],410);}
        $lock=$pdo->prepare("SELECT id FROM tokens_profissionais WHERE id=? AND tipo='redefinir_senha' AND consumido_em IS NULL AND expira_em>NOW() FOR UPDATE");$lock->execute([$row['token_id']]);if(!$lock->fetchColumn()){$pdo->rollBack();json_response(['success'=>false,'message'=>'Este link já foi usado ou expirou. Solicite outro.'],410);}
        $pdo->prepare('UPDATE profissionais SET senha_hash=?,auth_version=auth_version+1 WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$row['profissional_id']]);$pdo->prepare("UPDATE tokens_profissionais SET consumido_em=NOW() WHERE profissional_id=? AND tipo='redefinir_senha' AND consumido_em IS NULL")->execute([$row['profissional_id']]);$pdo->commit();audit_professional_event($pdo,(int)$row['profissional_id'],'senha_redefinida','profissionais',(string)$row['profissional_id']);json_response(['success'=>true,'message'=>'Senha alterada. Entre novamente; as sessões anteriores foram revogadas.']);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function appointment_policy_allows(string $date,string $time,int $hours): bool {
    $appointment=strtotime($date.' '.$time);return $appointment!==false&&$appointment-time()>=max(0,$hours)*3600;
}
function public_clinic_manage(PDO $pdo,array $data): never {
    $token=trim((string)($data['token']??''));$operation=trim((string)($data['operation']??'view'));if(!preg_match('/^[a-f0-9]{64}$/',$token))json_response(['success'=>false,'message'=>'Link inválido ou expirado.'],403);
    $hash=hash('sha256',$token);$pdo->beginTransaction();
    try{$q=$pdo->prepare('SELECT c.id,c.profissional_id,c.data_consulta,c.horario,c.duracao_minutos,c.status,c.assunto,p.nome AS clinica,p.slug,p.cancelamento_ate_horas,p.remarcacao_ate_horas FROM consultas_profissionais c INNER JOIN profissionais p ON p.id=c.profissional_id WHERE c.manage_token_hash=? LIMIT 1');$q->execute([$hash]);$a=$q->fetch();if(!$a){$pdo->rollBack();json_response(['success'=>false,'message'=>'Agendamento não encontrado ou link inválido.'],404);}
        $lock=$pdo->prepare('SELECT id FROM profissionais WHERE id=? FOR UPDATE');$lock->execute([$a['profissional_id']]);
        $q=$pdo->prepare('SELECT c.id,c.profissional_id,c.data_consulta,c.horario,c.duracao_minutos,c.status,c.assunto,p.nome AS clinica,p.slug,p.cancelamento_ate_horas,p.remarcacao_ate_horas FROM consultas_profissionais c INNER JOIN profissionais p ON p.id=c.profissional_id WHERE c.manage_token_hash=? LIMIT 1 FOR UPDATE');$q->execute([$hash]);$a=$q->fetch();if(!$a){$pdo->rollBack();json_response(['success'=>false,'message'=>'Agendamento não encontrado.'],404);}
        if($operation==='view'){$pdo->commit();json_response(['success'=>true,'appointment'=>['id'=>$a['id'],'clinica'=>$a['clinica'],'slug'=>$a['slug'],'data'=>$a['data_consulta'],'horario'=>substr((string)$a['horario'],0,5),'status'=>$a['status'],'assunto'=>$a['assunto'],'cancelamentoAteHoras'=>(int)$a['cancelamento_ate_horas'],'remarcacaoAteHoras'=>(int)$a['remarcacao_ate_horas']]]);}
        if(in_array($a['status'],['cancelada','atendida','faltou'],true)){$pdo->rollBack();json_response(['success'=>false,'message'=>'Este agendamento não pode mais ser alterado.'],409);}
        if($operation==='cancel'){
            if(!appointment_policy_allows($a['data_consulta'],substr((string)$a['horario'],0,5),(int)$a['cancelamento_ate_horas'])){$pdo->rollBack();json_response(['success'=>false,'message'=>'O prazo para cancelamento on-line expirou. Entre em contato com a clínica.'],409);}
            $pdo->prepare("UPDATE consultas_profissionais SET status='cancelada',cancelamento_motivo='Cancelamento solicitado pelo paciente' WHERE id=?")->execute([$a['id']]);$pdo->commit();audit_professional_event($pdo,(int)$a['profissional_id'],'consulta_cancelada_pelo_paciente','consultas_profissionais',(string)$a['id']);try{promote_professional_waitlist($pdo,(int)$a['profissional_id'],$a['data_consulta'],substr((string)$a['horario'],0,5));}catch(Throwable $waitError){error_log('Falha ao processar lista de espera após cancelamento: '.$waitError->getMessage());}
            $q=$pdo->prepare('SELECT p.nome,p.email FROM consultas_profissionais c INNER JOIN pacientes p ON p.id=c.paciente_id WHERE c.id=?');$q->execute([$a['id']]);$patient=$q->fetch();if($patient&&!empty($patient['email'])){try{send_appointment_email($patient['email'],$patient['nome'],$a['clinica'],$a['data_consulta'],substr((string)$a['horario'],0,5),'cancelada',(string)$a['id'],'');}catch(Throwable $e){error_log('Falha ao enviar cancelamento: '.$e->getMessage());}}
            json_response(['success'=>true,'message'=>'Consulta cancelada.']);
        }
        if($operation==='reschedule'){
            if(!appointment_policy_allows($a['data_consulta'],substr((string)$a['horario'],0,5),(int)$a['remarcacao_ate_horas'])){$pdo->rollBack();json_response(['success'=>false,'message'=>'O prazo para remarcação on-line expirou. Entre em contato com a clínica.'],409);}
            $date=trim((string)($data['data_consulta']??''));$time=trim((string)($data['horario']??''));if(!valid_date($date)||!preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/',$time)){$pdo->rollBack();json_response(['success'=>false,'message'=>'Escolha uma data e horário válidos.'],422);}
            $slots=professional_slots($pdo,(int)$a['profissional_id'],$date,(string)$a['id']);$selected=null;foreach($slots as $slot){if($slot['horario']===$time){$selected=$slot;break;}}if(!$selected){$pdo->rollBack();json_response(['success'=>false,'message'=>'Esse horário não está mais disponível.'],409);}
            $pdo->prepare('UPDATE consultas_profissionais SET data_consulta=?,horario=?,duracao_minutos=? WHERE id=?')->execute([$date,$time,$selected['duracao'],$a['id']]);$pdo->commit();audit_professional_event($pdo,(int)$a['profissional_id'],'consulta_remarcada_pelo_paciente','consultas_profissionais',(string)$a['id'],['data'=>$date,'horario'=>$time]);
            $q=$pdo->prepare('SELECT p.nome,p.email FROM consultas_profissionais c INNER JOIN pacientes p ON p.id=c.paciente_id WHERE c.id=?');$q->execute([$a['id']]);$patient=$q->fetch();if($patient&&!empty($patient['email'])){try{send_appointment_email($patient['email'],$patient['nome'],$a['clinica'],$date,$time,$a['status'],(string)$a['id'],'');}catch(Throwable $e){error_log('Falha ao enviar remarcação: '.$e->getMessage());}}
            json_response(['success'=>true,'message'=>'Consulta remarcada.','data'=>$date,'horario'=>$time]);
        }
        $pdo->rollBack();json_response(['success'=>false,'message'=>'Operação inválida.'],422);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
