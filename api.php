<?php
declare(strict_types=1);
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/ai.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function reply(int $code, mixed $data=null): never {
    http_response_code($code);
    if ($code !== 204) echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}
function query(PDO $db, string $sql, array $params=[]): PDOStatement {
    $s=$db->prepare($sql);
    foreach ($params as $key=>$value) $s->bindValue(is_int($key)?$key+1:':'.$key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
    $s->execute(); return $s;
}
function body(): array {
    if (strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json') reply(415,['error'=>'JSON required']);
    $raw=file_get_contents('php://input',false,null,0,16385);
    if (strlen($raw)>16384) reply(413,['error'=>'Request too large']);
    try { $v=json_decode($raw,false,32,JSON_THROW_ON_ERROR); }
    catch (JsonException) { reply(400,['error'=>'Invalid JSON']); }
    if (!$v instanceof stdClass) reply(400,['error'=>'JSON object required']);
    return (array)$v;
}
function number(mixed $value,int $default,int $max): int {
    if ($value===null) return $default;
    if (!is_string($value)||!preg_match('/^[1-9][0-9]{0,9}$/D',$value)||(float)$value>$max) reply(422,['error'=>'Invalid pagination']);
    return (int)$value;
}
function taskBody(): array {
    $v=body();
    $allowed=['title','description','status','priority','due_date','owner','user_id'];
    if (array_diff(array_keys($v),$allowed)) reply(422,['error'=>'Unknown task field']);
    $t=$v['title']??null; $d=$v['description']??''; $s=$v['status']??'todo'; $p=$v['priority']??'medium'; $due=$v['due_date'] ?? null;
    if (!is_string($t)||!mb_check_encoding($t,'UTF-8')||!strlen(trim($t))||mb_strlen(trim($t))>200||!is_string($d)||!mb_check_encoding($d,'UTF-8')||mb_strlen($d)>5000||!in_array($s,['todo','in-progress','done'],true)||!in_array($p,['low','medium','high'],true)) reply(422,['error'=>'Invalid task fields']);
    if ($due !== null && (!is_string($due) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$due) || !checkdate((int)substr($due,5,2),(int)substr($due,8,2),(int)substr($due,0,4)))) reply(422,['error'=>'Invalid due date']);
    if ($due === '') $due = null;
    return ['title'=>trim($t),'description'=>$d,'status'=>$s,'priority'=>$p,'due_date'=>$due];
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    $secret=getenv('JWT_SECRET')?:'';
    if (strlen($secret)<32) throw new RuntimeException('JWT_SECRET requires at least 32 random bytes');
    $db=new PDO('mysql:host='.(getenv('DB_HOST')?:'db').';dbname='.(getenv('DB_NAME')?:'goqii').';charset=utf8mb4',getenv('DB_USER'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec("SET time_zone = '+00:00'");
    if (PHP_SAPI==='cli') {
        if (($argv[1]??'')!=='create-user'||!filter_var($argv[2]??'',FILTER_VALIDATE_EMAIL)||strlen($argv[2])>254||!in_array($argv[3]??'',['Admin','User'],true)) throw new RuntimeException('Usage: php api.php create-user EMAIL Admin|User < password-file');
        $password=rtrim(stream_get_contents(STDIN),"\r\n");
        if (strlen($password)<12||strlen($password)>72) throw new RuntimeException('Password must be 12-72 bytes');
        query($db,'INSERT INTO users(email,password_hash,role_id) SELECT ?,?,id FROM roles WHERE name=?',[strtolower($argv[2]),password_hash($password,PASSWORD_BCRYPT,['cost'=>12]),$argv[3]]);
        echo "User created\n"; exit;
    }
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH); $method=$_SERVER['REQUEST_METHOD'];
    if ($path==='/api/health'&&$method==='GET') reply(200,['ok'=>true]);
    if ($path==='/api/register'&&$method==='POST') {
        $v=body(); $email=$v['email']??''; $password=$v['password']??'';
        if (!is_string($email)||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254||!is_string($password)||strlen($password)<12||strlen($password)>72) reply(422,['error'=>'Invalid registration data']);
        $email=strtolower($email);
        $db->beginTransaction();
        $exists=query($db,'SELECT id FROM users WHERE email=:email FOR UPDATE',['email'=>$email])->fetch();
        if ($exists) { $db->commit(); reply(409,['error'=>'An account with this email already exists']); }
        $roleId=(int)query($db,'SELECT id FROM roles WHERE name=?',['User'])->fetchColumn();
        if (!$roleId) { $db->rollBack(); reply(500,['error'=>'User role missing']); }
        $inserted=query($db,'INSERT INTO users(email,password_hash,role_id) VALUES (:email,:password_hash,:role_id)',['email'=>$email,'password_hash'=>password_hash($password,PASSWORD_BCRYPT,['cost'=>12]),'role_id'=>$roleId]);
        $userId=(int)$db->lastInsertId();
        $u=query($db,'SELECT u.*,r.name AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?',[$userId])->fetch();
        $db->commit(); $now=time();
        $token=JWT::encode(['iss'=>'goqii-api','aud'=>'goqii-web','sub'=>(string)$u['id'],'iat'=>$now,'nbf'=>$now,'exp'=>$now+900,'ver'=>(int)$u['token_version']],$secret,'HS256');
        reply(201,['token'=>$token,'user'=>['id'=>(int)$u['id'],'email'=>$u['email'],'role'=>$u['role']]]);
    }
    if ($path==='/api/login'&&$method==='POST') {
        $v=body(); $email=$v['email']??''; $password=$v['password']??'';
        if (!is_string($email)||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254||!is_string($password)||strlen($password)>72) reply(422,['error'=>'Invalid credentials format']);
        $db->beginTransaction();
        $u=query($db,'SELECT u.*,r.name AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=:email FOR UPDATE',['email'=>strtolower($email)])->fetch();
        $valid=password_verify($password,$u['password_hash']??'$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        $locked=$u&&$u['locked_until']!==null&&strtotime($u['locked_until'].' UTC')>time();
        if (!$u||!$valid||$locked) {
            if ($u&&!$locked) {
                $attempts=$u['locked_until']!==null?1:min(5,(int)$u['failed_logins']+1);
                query($db,'UPDATE users SET failed_logins=?,locked_until=IF(? >= 5,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE),NULL) WHERE id=?',[$attempts,$attempts,(int)$u['id']]);
            }
            $db->commit(); reply(401,['error'=>'Invalid credentials or temporarily locked account']);
        }
        query($db,'UPDATE users SET failed_logins=0,locked_until=NULL WHERE id=?',[(int)$u['id']]);
        $db->commit(); $now=time();
        $token=JWT::encode(['iss'=>'goqii-api','aud'=>'goqii-web','sub'=>(string)$u['id'],'iat'=>$now,'nbf'=>$now,'exp'=>$now+900,'ver'=>(int)$u['token_version']],$secret,'HS256');
        reply(200,['token'=>$token,'user'=>['id'=>(int)$u['id'],'email'=>$u['email'],'role'=>$u['role']]]);
    }
    if (!preg_match('/^Bearer (\S+)$/D',$_SERVER['HTTP_AUTHORIZATION']??'',$m)) reply(401,['error'=>'Authentication required']);
    try {
        $claims=JWT::decode($m[1],new Key($secret,'HS256'));
        if (($claims->iss??'')!=='goqii-api'||($claims->aud??'')!=='goqii-web'||!is_string($claims->sub??null)||!preg_match('/^[1-9][0-9]{0,9}$/D',$claims->sub)||(float)$claims->sub>4294967295||!is_int($claims->exp??null)||!is_int($claims->ver??null)) throw new RuntimeException();
    } catch (Throwable) { reply(401,['error'=>'Invalid or expired token']); }
    $u=query($db,'SELECT u.id,u.token_version,r.name AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?',[(int)$claims->sub])->fetch();
    if (!$u||(int)$u['token_version']!==$claims->ver) reply(401,['error'=>'Session revoked']);
    if (!in_array($u['role'],['Admin','User'],true)) reply(403,['error'=>'Forbidden']);
    if ($path==='/api/suggest-category'&&$method==='POST') {
        $v=taskBody(); $key=getenv('GEMINI_API_KEY')?:''; $model=getenv('GEMINI_MODEL')?:'';
        if ($key===''||$model==='') reply(503,['error'=>'AI suggestions are not configured. Set GEMINI_API_KEY and GEMINI_MODEL on the server.']);
        try { reply(200,suggestCategory($v['title'],$v['description'],$key,$model)); }
        catch (Throwable $e) {
            error_log('Gemini suggestion failed: '.$e->getMessage());
            $message=$e->getMessage()==='Gemini HTTP 503'
                ?'Google Gemini is temporarily unavailable (HTTP 503). Please retry shortly.'
                :'AI suggestion failed. Check Gemini configuration, model access, quota, or network; see API logs for the provider status.';
            reply(502,['error'=>$message]);
        }
    }
    if ($path==='/api/logout'&&$method==='POST') {
        query($db,'UPDATE users SET token_version=token_version+1 WHERE id=?',[(int)$u['id']]); reply(204);
    }
    if (!preg_match('#^/api/tasks(?:/([1-9][0-9]{0,9}))?$#D',$path,$match)) reply(404,['error'=>'Not found']);
    $id=isset($match[1])?(int)$match[1]:null;
    if ($id!==null&&$id>4294967295) reply(404,['error'=>'Not found']);
    $where=[]; $params=[];
    if ($u['role']!=='Admin') { $where[]='user_id=:uid'; $params['uid']=(int)$u['id']; }
    if ($id!==null) { $where[]='id=:id'; $params['id']=$id; }
    if ($method==='GET'&&$id===null) {
        $page=number($_GET['page']??null,1,10000); $limit=number($_GET['limit']??null,10,100);
        if (isset($_GET['status'])) {
            if (!in_array($_GET['status'],['todo','in-progress','done'],true)) reply(422,['error'=>'Invalid status']);
            $where[]='status=:status'; $params['status']=$_GET['status'];
        }
        $filter=$where?' WHERE '.implode(' AND ',$where):'';
        $db->beginTransaction();
        $total=(int)query($db,'SELECT COUNT(*) FROM tasks'.$filter,$params)->fetchColumn();
        $items=query($db,'SELECT * FROM tasks'.$filter.' ORDER BY id DESC LIMIT :limit OFFSET :offset',$params+['limit'=>$limit,'offset'=>($page-1)*$limit])->fetchAll();
        $db->commit(); reply(200,['items'=>$items,'total'=>$total,'page'=>$page,'limit'=>$limit]);
    }
    if ($method==='POST'&&$id===null) {
        $v=taskBody();
        query($db,'INSERT INTO tasks(user_id,title,description,status,priority,due_date) VALUES (:uid,:title,:description,:status,:priority,:due_date)',['uid'=>(int)$u['id']]+$v);
        $id=(int)$db->lastInsertId(); header('Location: /api/tasks/'.$id);
        reply(201,query($db,'SELECT * FROM tasks WHERE id=?',[$id])->fetch());
    }
    if ($id!==null&&in_array($method,['GET','PUT','DELETE'],true)) {
        $v=$method==='PUT'?taskBody():null;
        $filter=' WHERE '.implode(' AND ',$where);
        $db->beginTransaction();
        $task=query($db,'SELECT * FROM tasks'.$filter.' FOR UPDATE',$params)->fetch();
        if (!$task) { $db->rollBack(); reply(404,['error'=>'Task not found']); }
        if ($method==='DELETE') query($db,'DELETE FROM tasks'.$filter,$params);
        if ($method==='PUT') {
            query($db,'UPDATE tasks SET title=:title,description=:description,status=:status,priority=:priority,due_date=:due_date'.$filter,$v+$params);
            $task=query($db,'SELECT * FROM tasks WHERE id=?',[$id])->fetch();
        }
        $db->commit(); reply($method==='DELETE'?204:200,$task);
    }
    header('Allow: '.($id===null?'GET, POST':'GET, PUT, DELETE')); reply(405,['error'=>'Method not allowed']);
} catch (Throwable $e) {
    if (isset($db)&&$db->inTransaction()) $db->rollBack();
    error_log((string)$e);
    if (PHP_SAPI==='cli') { fwrite(STDERR,"Operation failed; check server logs.\n"); exit(1); }
    reply(500,['error'=>'Internal server error']);
}
