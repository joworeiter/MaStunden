<?php
declare(strict_types=1);
require_once __DIR__.'/schema.php';
function h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function fail(string $message): never { throw new RuntimeException($message); }
function cfg(): array { return $GLOBALS['config']; }
function db(): PDO { static $db; return $db ??= new PDO('mysql:host='.cfg()['host'].';port='.cfg()['port'].';dbname='.cfg()['database'].';charset=utf8mb4',cfg()['username'],cfg()['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]); }
function table(string $name): string { if(!array_key_exists($name,schema())) fail('Unbekannte Tabelle.'); return '`'.cfg()['prefix'].$name.'`'; }
function q(string $sql,array $args=[]): PDOStatement { $s=db()->prepare($sql); $s->execute($args); return $s; }
function rows(string $name,string $where='1',array $args=[],string $tail=''): array { return q('SELECT * FROM '.table($name).' WHERE '.$where.' '.$tail,$args)->fetchAll(); }
function one(string $name,string $where,array $args=[]): ?array { return rows($name,$where,$args,'LIMIT 1')[0]??null; }
function insert(string $name,array $data): int { if(in_array($name,['users','models'],true))unset($GLOBALS['schedule_cache']);$keys=array_keys($data); q('INSERT INTO '.table($name).' (`'.implode('`,`',$keys).'`) VALUES ('.implode(',',array_fill(0,count($keys),'?')).')',array_values($data)); return (int)db()->lastInsertId(); }
function update(string $name,int $id,array $data): void { if(in_array($name,['users','models'],true))unset($GLOBALS['schedule_cache']);q('UPDATE '.table($name).' SET '.implode(',',array_map(fn($k)=>'`'.$k.'`=?',array_keys($data))).' WHERE id=?',[...array_values($data),$id]); }
function now(): string { return gmdate('Y-m-d H:i:s'); }
function setting(string $key,string $default=''): string { if(!isset($GLOBALS['settings_cache']))$GLOBALS['settings_cache']=array_column(rows('settings'),'v','k');return (string)($GLOBALS['settings_cache'][$key]??$default); }
function set_setting(string $key,string $value): void { if(!isset($GLOBALS['settings_cache']))setting('__load__');q('INSERT INTO '.table('settings').' (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)',[$key,$value]);$GLOBALS['settings_cache'][$key]=$value; }
function tz(): DateTimeZone { return new DateTimeZone(setting('timezone','Europe/Berlin')); }
function today(): string { return (new DateTimeImmutable('now',tz()))->format('Y-m-d'); }
function date_ok(string $value): string { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value); if(!$d||$d->format('Y-m-d')!==$value) fail('Ungültiges Datum.'); return $value; }
function month_ok(string $value): string { if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$value)) fail('Ungültiger Monat.'); return $value; }
function user(): ?array { return isset($_SESSION['uid'])?one('users','id=? AND active=1',[(int)$_SESSION['uid']]):null; }
function admin(): bool { return (user()['role']??'')==='admin'; }
function require_admin(): void { if(!admin()) fail('Diese Funktion ist nur für Administratoren verfügbar.'); }
function owner(int $uid): void { if(!user()||(!admin()&&(int)user()['id']!==$uid)) fail('Kein Zugriff auf diesen Datensatz.'); }
function target(): int { $id=admin()?(int)($_GET['user']??$_POST['user_id']??user()['id']):(int)user()['id']; if(!one('users','id=?',[$id])) fail('Mitarbeiter nicht gefunden.'); owner($id); return $id; }
function csrf(): string { return $_SESSION['csrf']??=bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.h(csrf()).'">'; }
function csrf_check(): void { if(!hash_equals(csrf(),(string)($_POST['csrf']??''))) fail('Die Sitzung ist abgelaufen. Bitte Seite neu laden.'); }
function go(string $url='index.php'): never { header('Location: '.$url, true,303); exit; }
function flash(string $s): void { $_SESSION['flash']=$s; }
function clean_audit(?array $data): ?array { if($data===null)return null; foreach(['password','storage_name','recovery_hash'] as $k) unset($data[$k]); return $data; }
function audit(string $action,string $entity,?int $id,?array $before=null,?array $after=null,string $reason=''): void { insert('audit',['actor_id'=>user()['id']??null,'action'=>$action,'entity'=>$entity,'entity_id'=>$id,'before_json'=>$before?json_encode(clean_audit($before),JSON_UNESCAPED_UNICODE):null,'after_json'=>$after?json_encode(clean_audit($after),JSON_UNESCAPED_UNICODE):null,'reason'=>$reason,'created_at'=>now()]); }
function notify(int $uid,string $text): void { insert('notifications',['user_id'=>$uid,'message'=>$text,'created_at'=>now()]); if(function_exists('mail_queue'))mail_queue($uid,$text); }
function notify_admins(string $text): void { foreach(rows('users',"role='admin' AND active=1") as $u) notify((int)$u['id'],$text); }
function post(string $k,string $default=''): string { return trim((string)($_POST[$k]??$default)); }
function bounded(string $v,int $max): string { if(mb_strlen($v)>$max) fail('Die Eingabe ist zu lang (maximal '.$max.' Zeichen).'); return $v; }
function password_rule(string $p): void { if(strlen($p)<12||strlen($p)>72)fail('Das Passwort muss zwischen 12 und 72 Zeichen lang sein.'); }
function hours(int $minutes): string { return ($minutes<0?'−':'').sprintf('%d:%02d',intdiv(abs($minutes),60),abs($minutes)%60); }
function local_time(?string $utc,string $format='d.m.Y H:i'): string { return $utc?(new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(tz())->format($format):'läuft'; }
function entry_months(string $start,?string $end): array { $a=(new DateTimeImmutable($start,new DateTimeZone('UTC')))->setTimezone(tz()); $b=(new DateTimeImmutable($end??now(),new DateTimeZone('UTC')))->setTimezone(tz()); $out=[]; for($d=$a->modify('first day of this month')->setTime(0,0);$d<=$b;$d=$d->modify('+1 month'))$out[]=$d->format('Y-m'); return array_unique($out); }
function unlocked(int $uid,string $month): void { $c=one('closures','user_id=? AND month=?',[$uid,$month]); if($c&&in_array($c['status'],['submitted','approved'],true))fail('Dieser Monat ist eingereicht oder freigegeben. Bitte zuerst mit Begründung wieder öffnen.'); }
function days(string $start,string $end): array { $out=[]; for($d=new DateTimeImmutable($start);$d<=new DateTimeImmutable($end);$d=$d->modify('+1 day')){ $out[]=$d->format('Y-m-d'); if(count($out)>370) fail('Zeitraum auf maximal ein Jahr begrenzen.'); } return $out; }
function no_maintenance(): void { if(is_file(cfg()['storage'].'/maintenance.json'))fail('Die Anwendung wird gerade gesichert oder wiederhergestellt. Bitte später erneut versuchen.'); }
function storage_path(string $name): string { if(!preg_match('/^[a-zA-Z0-9_.-]+$/D',$name))fail('Ungültiger Dateiname.');return cfg()['storage'].'/'.$name; }
function require_reason(): string { $r=bounded(post('reason'),1000);if($r==='')fail('Bitte eine Begründung angeben.');return $r; }
function badge(string $s): string { $labels=['cancelled'=>'Zurückgezogen / storniert','pending'=>'Offen','approved'=>'Freigegeben','rejected'=>'Abgelehnt','open'=>'Offen','submitted'=>'Eingereicht','returned'=>'Zurückgegeben','processing'=>'In Prüfung','done'=>'Bearbeitet','sick'=>'Krankheit','vacation'=>'Urlaub','comp'=>'Freizeitausgleich','other'=>'Sonstige Abwesenheit'];return '<span class="badge '.h($s).'">'.h($labels[$s]??$s).'</span>'; }
function limits_bytes(string $s): int { $n=(int)$s; return match(strtolower(substr($s,-1))){'g'=>$n*1073741824,'m'=>$n*1048576,'k'=>$n*1024,default=>$n}; }
