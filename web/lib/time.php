<?php
declare(strict_types=1);
/* Explicit UTC offset removes ambiguity in the repeated autumn hour. */
function parse_local(string $value,string $offset=''): string {
 if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$value))fail('Datum und Uhrzeit vollständig angeben.');
 $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,tz());
 if(!$d||$d->format('Y-m-d\TH:i')!==$value)fail('Diese lokale Uhrzeit existiert nicht (Zeitumstellung oder ungültiges Datum).');
 $trans=tz()->getTransitions($d->getTimestamp()-86400,$d->getTimestamp()+86400);$candidates=[];
 foreach(array_unique(array_column($trans?:[],'offset')) as $o){$t=(new DateTimeImmutable($value,new DateTimeZone('UTC')))->getTimestamp()-$o;$p=(new DateTimeImmutable('@'.$t))->setTimezone(tz());if($p->format('Y-m-d\TH:i')===$value)$candidates[$p->format('P')]=$p;}
 if(count($candidates)>1&&$offset==='')fail('Doppelte Uhrzeit bei Zeitumstellung: UTC-Abstand auswählen.');
 if($offset!==''){if(!isset($candidates[$offset]))fail('UTC-Abstand passt nicht zur Uhrzeit.');$d=$candidates[$offset];}
 return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
function validate_interval(int $uid,string $start,string $end,array $breaks,int $exclude=0): void {
 $a=strtotime($start.' UTC');$b=strtotime($end.' UTC');if($b<=$a||$b-$a>48*3600)fail('Arbeitsende muss nach dem Beginn liegen; maximal 48 Stunden pro Block.');
 if($b>time()+60)fail('Arbeitszeiten dürfen nicht in der Zukunft liegen.');
 $u=one('users','id=?',[$uid]);$sd=local_time($start,'Y-m-d');$ed=local_time($end,'Y-m-d');if($sd<$u['employment_start']||($u['employment_end']&&$ed>$u['employment_end']))fail('Zeit liegt außerhalb der Beschäftigung.');
 $last=$a;foreach($breaks as $p){$ps=strtotime($p[0].' UTC');$pe=strtotime($p[1].' UTC');if($ps<$a||$pe>$b||$pe<=$ps||$ps<$last)fail('Pausen müssen sortiert, überschneidungsfrei und innerhalb des Arbeitsblocks liegen.');$last=$pe;}
 if(one('entries','user_id=? AND id<>? AND start_at<? AND (end_at IS NULL OR end_at>?)',[$uid,$exclude,$end,$start]))fail('Der Arbeitsblock überschneidet sich mit einem vorhandenen Eintrag.');
 foreach(entry_months($start,$end) as $m)unlocked($uid,$m);
 foreach(rows('absences',"user_id=? AND status='approved' AND start_date<=? AND end_date>=? AND fraction=1",[$uid,$ed,$sd]) as $abs)fail('Der Zeitraum enthält eine genehmigte ganztägige Abwesenheit.');
}
function work_minutes(array $entry,int $from,int $to): int {
 $a=max($from,strtotime($entry['start_at'].' UTC'));$b=min($to,strtotime(($entry['end_at']??now()).' UTC'));if($b<=$a)return 0;$seconds=$b-$a;
 foreach(json_decode($entry['breaks_json'],true) as $p){$ps=max($a,strtotime($p[0].' UTC'));$pe=min($b,strtotime(($p[1]??now()).' UTC'));$seconds-=max(0,$pe-$ps);}
 return (int)floor(max(0,$seconds)/60);
}
function scheduled(int $uid,string $day): int {
 if(!isset($GLOBALS['schedule_cache'][$uid]))$GLOBALS['schedule_cache'][$uid]=['user'=>one('users','id=?',[$uid]),'models'=>rows('models','user_id=?',[$uid],'ORDER BY valid_from DESC')];$cache=$GLOBALS['schedule_cache'][$uid];$u=$cache['user'];if($day<$u['employment_start']||($u['employment_end']&&$day>$u['employment_end']))return 0;
 $model=null;foreach($cache['models'] as $candidate)if($candidate['valid_from']<=$day){$model=$candidate;break;}
 return $model?(int)(json_decode($model['minutes'],true)[(int)(new DateTimeImmutable($day))->format('N')-1]??0):0;
}
function month_report(int $uid,string $month,bool $snapshot=true): array {
 month_ok($month);$closure=one('closures','user_id=? AND month=?',[$uid,$month]);if($snapshot&&($closure['status']??'')==='approved'&&$closure['snapshot'])return json_decode($closure['snapshot'],true);
 $first=$month.'-01';$last=(new DateTimeImmutable($first))->format('Y-m-t');$from=new DateTimeImmutable($first,tz());$to=$from->modify('+1 month');
 $entries=rows('entries','user_id=? AND start_at<? AND (end_at IS NULL OR end_at>?)',[$uid,$to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),$from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')],'ORDER BY start_at');
 $abs=rows('absences',"user_id=? AND status='approved' AND start_date<=? AND end_date>=?",[$uid,$last,$first]);$holiday=array_column(rows('holidays','day BETWEEN ? AND ?',[$first,$last]),'name','day');$days=[];$sum=['work'=>0,'target'=>0,'credit'=>0,'balance'=>0,'break'=>0];
 foreach(days($first,$last) as $day){$d=new DateTimeImmutable($day,tz());$next=$d->modify('+1 day');$r=['day'=>$day,'target'=>scheduled($uid,$day),'work'=>0,'break'=>0,'credit'=>0,'label'=>$holiday[$day]??'','blocks'=>[],'warnings'=>[]];
  if(isset($holiday[$day]))$r['credit']=$r['target'];
  foreach($abs as $a)if($a['start_date']<=$day&&$a['end_date']>=$day){$r['label'].=($r['label']?' · ':'').['vacation'=>'Urlaub','sick'=>'Krankheit','comp'=>'Freizeitausgleich','other'=>setting('other_absence_label','Sonstige Abwesenheit')][$a['type']];if($a['type']!=='comp'&&($a['type']!=='other'||setting('other_absence_paid','1')==='1')&&!isset($holiday[$day]))$r['credit']+=(int)($a['amount_minutes']??0)>0?(int)$a['amount_minutes']:(int)round($r['target']*(float)$a['fraction']);}
  foreach($entries as $e){$ea=strtotime($e['start_at'].' UTC');$eb=strtotime(($e['end_at']??now()).' UTC');if($ea<$next->getTimestamp()&&$eb>$d->getTimestamp()){$m=work_minutes($e,$d->getTimestamp(),$next->getTimestamp());$gross=(int)floor((min($eb,$next->getTimestamp())-max($ea,$d->getTimestamp()))/60);$r['work']+=$m;$r['break']+=$gross-$m;$r['blocks'][]=(new DateTimeImmutable('@'.max($ea,$d->getTimestamp())))->setTimezone(tz())->format('H:i').'–'.($eb>=$next->getTimestamp()?'24:00':($e['end_at']?local_time($e['end_at'],'H:i'):'läuft')).qr_block_suffix($e['note']??null);if(!$e['end_at'])$r['warnings'][]='Laufender Eintrag';}}
  $r['credit']=min($r['credit'],$r['target']);$r['balance']=$r['work']+$r['credit']-$r['target'];
  if($day<today()&&$r['target']>0&&$r['work']+$r['credit']===0&&$r['label']==='')$r['warnings'][]='Eintrag fehlt';
  if($r['work']>(int)setting('max_daily','600'))$r['warnings'][]='Lange Arbeitszeit';
  $required=$r['work']>540?(int)setting('pause_long','45'):($r['work']>360?(int)setting('pause_short','30'):0);if($r['break']<$required)$r['warnings'][]='Pause prüfen';
  foreach($sum as $k=>$_)$sum[$k]+=$r[$k];$days[]=$r;
 }
 $previous=null;foreach($entries as $e){if($previous&&local_time($previous,'Y-m-d')!==local_time($e['start_at'],'Y-m-d')&&strtotime($e['start_at'].' UTC')-strtotime($previous.' UTC')<(int)setting('rest_hours','11')*3600){foreach($days as &$r)if($r['day']===local_time($e['start_at'],'Y-m-d'))$r['warnings'][]='Ruhezeit prüfen';unset($r);}if($e['end_at'])$previous=$e['end_at'];}
 $adjust=(int)(q('SELECT COALESCE(SUM(minutes),0) FROM '.table('adjustments').' WHERE user_id=? AND day BETWEEN ? AND ?',[$uid,$first,$last])->fetchColumn());$sum['adjustment']=$adjust;$sum['balance']+=$adjust;
 return ['entries'=>$entries,'days'=>$days,'sum'=>$sum,'month'=>$month,'user'=>clean_audit(one('users','id=?',[$uid])),'generated'=>now()];
}
function balance(int $uid,string $until): int {
 $u=one('users','id=?',[$uid]);$result=0;$d=new DateTimeImmutable(substr($u['employment_start'],0,7).'-01');$limit=new DateTimeImmutable($until);
 if($d->diff($limit)->y>50)fail('Beschäftigungsbeginn liegt zu weit zurück.');
 while($d<=$limit){$r=month_report($uid,$d->format('Y-m'));foreach($r['days'] as $day)if($day['day']<=$until)$result+=$day['balance'];$d=$d->modify('+1 month');}
 return $result+(int)q('SELECT COALESCE(SUM(minutes),0) FROM '.table('adjustments').' WHERE user_id=? AND day<=?',[$uid,$until])->fetchColumn();
}
function vacation(int $uid,int $year): array {
 $u=one('users','id=?',[$uid]);$used=0.0;$pending=0.0;
 foreach(rows('absences',"user_id=? AND type='vacation' AND status IN ('approved','pending') AND start_date<=? AND end_date>=?",[$uid,"$year-12-31","$year-01-01"]) as $a)foreach(days(max($a['start_date'],"$year-01-01"),min($a['end_date'],"$year-12-31")) as $d)if(scheduled($uid,$d)>0&&!one('holidays','day=?',[$d])){if($a['status']==='approved')$used+=(int)($a['amount_minutes']??0)>0?(int)$a['amount_minutes']/scheduled($uid,$d):(float)$a['fraction'];else $pending+=(int)($a['amount_minutes']??0)>0?(int)$a['amount_minutes']/scheduled($uid,$d):(float)$a['fraction'];}
 $adjust=(float)q('SELECT COALESCE(SUM(vacation),0) FROM '.table('adjustments').' WHERE user_id=? AND day BETWEEN ? AND ?',[$uid,"$year-01-01","$year-12-31"])->fetchColumn();
 return ['entitlement'=>(float)$u['vacation_days']+$adjust,'used'=>round($used,2),'pending'=>round($pending,2),'remaining'=>round((float)$u['vacation_days']+$adjust-$used,2)];
}
