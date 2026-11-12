<?php
declare(strict_types=1);
require_once __DIR__.'/user_delete.php';
function admin_action(string $a,int $uid): void {
 require_admin();switch($a){
 case 'qr_settings':case 'qr_rotate':case 'qr_offsite':qr_admin_action($a);break;
 case 'own_time':own_time_action();break;
 case 'user_delete': delete_employee((int)post('id'));break;
 case 'user_save':
  $id=(int)post('id','0');$old=$id?one('users','id=?',[$id]):null;if($id&&!$old)fail('Benutzer nicht gefunden.');$email=strtolower(post('email'));if(!filter_var($email,FILTER_VALIDATE_EMAIL))fail('Ungültige E-Mail-Adresse.');$role=post('role');if(!in_array($role,['admin','employee'],true))fail('Ungültige Rolle.');$active=isset($_POST['active'])?1:0;
  if($old&&$old['role']==='admin'&&(!$active||$role!=='admin')&&count(rows('users',"role='admin' AND active=1"))<=1)fail('Der letzte aktive Administrator darf nicht deaktiviert werden.');if($old&&(int)$old['id']===(int)user()['id']&&(!$active||$role!=='admin'))fail('Das eigene Administratorkonto darf hier nicht herabgestuft werden.');
  $start=date_ok(post('employment_start'));$end=post('employment_end')!==''?date_ok(post('employment_end')):null;if($end&&$end<$start)fail('Austritt liegt vor Eintritt.');if($start<'1970-01-01')fail('Beschäftigungsbeginn frühestens 1970.');
  if($old&&($old['employment_start']!==$start||$old['employment_end']!==$end)){if(one('closures','user_id=? AND status IN (?,?)',[$id,'approved','submitted']))fail('Beschäftigungsdaten erst nach Wiederöffnung der abgeschlossenen Monate ändern.');}
  $vac=(float)post('vacation_days','0');if($vac<0||$vac>366)fail('Ungültiger Urlaubsanspruch.');if($old&&(float)$old['vacation_days']!==$vac)fail('Bestehenden Urlaubsanspruch über eine jahresbezogene Korrekturbuchung ändern.');
  $data=['email'=>$email,'name'=>bounded(post('name'),190),'personnel'=>bounded(post('personnel'),80),'role'=>$role,'active'=>$active,'employment_start'=>$start,'employment_end'=>$end,'vacation_days'=>$vac];if($data['name']==='')fail('Name fehlt.');
  if(!$old||post('password')!==''){password_rule(post('password'));$data['password']=password_hash(post('password'),PASSWORD_DEFAULT);$data['must_change']=1;}
  if($old)update('users',$id,$data);else{$data['created_at']=now();$id=insert('users',$data);}audit('save','users',$id,$old,$data,$old?require_reason():'Erstanlage');break;
 case 'model_save':
  $from=date_ok(post('valid_from'));$person=one('users','id=?',[$uid]);if($from<$person['employment_start'])fail('Arbeitszeitmodell liegt vor Eintritt.');if(one('closures',"user_id=? AND month>=? AND status IN ('submitted','approved')",[$uid,substr($from,0,7)]))fail('Betroffene Monatsabschlüsse zuerst wieder öffnen.');$w=(float)str_replace(',','.',(string)($_POST['weekly']??''));if($w<0||$w>168)fail('Ungültige Wochenstunden.');$total=(int)round($w*60);$minutes=[];foreach(range(0,6) as $i)$minutes[]=intdiv($total,7)+($i<$total%7?1:0);$old=one('models','user_id=? AND valid_from=?',[$uid,$from]);$d=['user_id'=>$uid,'valid_from'=>$from,'minutes'=>json_encode($minutes)];if($old){$id=(int)$old['id'];update('models',$id,$d);}else $id=insert('models',$d);audit('save','models',$id,$old,$d,require_reason());break;
 case 'adjustment':
  $day=date_ok(post('day'));unlocked($uid,substr($day,0,7));$d=['user_id'=>$uid,'day'=>$day,'minutes'=>(int)round((float)post('hours','0')*60),'vacation'=>(float)post('vacation','0'),'reason'=>require_reason()];$id=insert('adjustments',$d);audit('create','adjustments',$id,null,$d,$d['reason']);break;
 case 'holiday_save':
  $day=date_ok(post('day'));if(one('closures',"month=? AND status IN ('submitted','approved')",[substr($day,0,7)]))fail('Betroffene Monatsabschlüsse zuerst wieder öffnen.');$old=one('holidays','day=?',[$day]);$d=['day'=>$day,'name'=>bounded(post('name'),190)];if($old){$id=(int)$old['id'];update('holidays',$id,$d);}else $id=insert('holidays',$d);audit('save','holidays',$id,$old,$d,require_reason());break;
 case 'holiday_delete':
  $old=one('holidays','id=?',[(int)post('id')]);if(!$old)fail('Feiertag nicht gefunden.');if(one('closures',"month=? AND status IN ('submitted','approved')",[substr($old['day'],0,7)]))fail('Betroffene Monatsabschlüsse zuerst wieder öffnen.');q('DELETE FROM '.table('holidays').' WHERE id=?',[$old['id']]);audit('delete','holidays',(int)$old['id'],$old,null,require_reason());break;
 case 'settings':
  $allowed=['company','address','contact','email','phone','website','imprint','privacy','color','location','state','timezone','upload_mb','max_daily','pause_short','pause_long','rest_hours','retention_days','other_absence_label','other_absence_paid'];$before=[];$after=[];
  foreach($allowed as $k){$v=bounded(post($k),2000);if(in_array($k,['website','imprint','privacy'],true)&&$v!==''&&!preg_match('~^https://[^\s]+$~D',$v))fail('Links müssen vollständige HTTPS-Adressen sein.');if($k==='color'&&!preg_match('/^#[a-fA-F0-9]{6}$/D',$v))fail('Ungültige Farbe.');if($k==='timezone'){if(!in_array($v,DateTimeZone::listIdentifiers(),true))fail('Ungültige Zeitzone.');if($v!==setting('timezone','Europe/Berlin')&&one('entries','1'))fail('Zeitzone kann nach Beginn der Zeiterfassung nicht geändert werden.');}if(in_array($k,['upload_mb','max_daily','pause_short','pause_long','rest_hours','retention_days'],true)&&(!ctype_digit($v)||(int)$v>10000))fail('Ungültiger Zahlenwert.');if($k==='upload_mb'&&((int)$v<1||(int)$v>50))fail('Uploadlimit: 1 bis 50 MB.');if(in_array($k,['other_absence_label','other_absence_paid'],true)&&$v!==setting($k,$k==='other_absence_paid'?'1':'Sonstige Abwesenheit')&&one('absences',"type='other'"))fail('Bereits verwendete Abwesenheitsregeln können nicht rückwirkend geändert werden.');$before[$k]=setting($k);$after[$k]=$v;set_setting($k,$v);}
  foreach(['company_logo'] as $field)if(isset($_FILES[$field])&&$_FILES[$field]['error']!==UPLOAD_ERR_NO_FILE){$f=upload_file($field,['image/png'=>'png','image/jpeg'=>'jpg']);$size=getimagesize($f['tmp_name']);if($size[0]>3000||$size[1]>3000)fail('Logo maximal 3000 × 3000 Pixel.');$image=imagecreatefromstring(file_get_contents($f['tmp_name']));if(!$image)fail('Logo ungültig.');$name=bin2hex(random_bytes(24)).'.png';imagepng($image,storage_path($name));imagedestroy($image);set_setting($field,$name);}
  audit('settings','settings',null,$before,$after);break;
 case 'retention_cleanup':
  $r=require_reason();$days=(int)setting('retention_days','0');if($days<=0)fail('Zuerst eine Aufbewahrungsfrist für Dokumente konfigurieren.');$cut=gmdate('Y-m-d H:i:s',time()-$days*86400);$docs=rows('documents',"created_at<? AND status IN ('done','rejected')",[$cut]);foreach($docs as $d){q('DELETE FROM '.table('documents').' WHERE id=?',[$d['id']]);$GLOBALS['delete_after_commit'][]=storage_path($d['storage_name']);audit('retention_delete','documents',(int)$d['id'],['id'=>$d['id'],'user_id'=>$d['user_id']],null,$r);}flash(count($docs).' abgeschlossene Dokumente gelöscht.');break;
 default:fail('Unbekannte Aktion.');
 }
}
