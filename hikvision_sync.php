<?php
require __DIR__ . '/hikvision_sync.config.php';

define('TIMEZONE',  'America/Mexico_City');
define('LOG_FILE',  __DIR__ . '/hik_sync.log');
define('LAST_SYNC_FILE', __DIR__ . '/last_sync.json');
define('DEDUP_SECONDS', 180);

date_default_timezone_set(TIMEZONE);
$args = array_slice($argv ?? [], 1);
$mode = 'sync';
$targetDate = null;
foreach ($args as $i => $arg) {
    if ($arg === '--list') $mode = 'list';
    if ($arg === '--date' && isset($args[$i + 1])) $targetDate = $args[$i + 1];
}
function logMsg(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    file_put_contents(LOG_FILE, $line, FILE_APPEND);
}
function getDB(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    return $pdo;
}
function hikRequest(string $ip, string $user, string $pass, string $method, string $endpoint, ?array $body = null): ?array {
    $url = 'http://'.$ip.$endpoint;
    $ch  = curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPAUTH=>CURLAUTH_DIGEST,CURLOPT_USERPWD=>$user.':'.$pass,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
    if ($method==='POST'){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));}
    $response=curl_exec($ch);$httpCode=curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if ($error){logMsg("ERROR cURL $ip: $error");return null;}
    if ($httpCode!==200){logMsg("ERROR Hikvision $ip HTTP $httpCode");return null;}
    return json_decode($response,true);
}
function getHikEvents(string $ip, string $user, string $pass, string $startTime, string $endTime): array {
    $allEvents=[];
    foreach ([38,75,76] as $minorCode) {
        $position=0;
        do {
            $body=['AcsEventCond'=>['searchID'=>uniqid(),'searchResultPosition'=>$position,'maxResults'=>50,'major'=>5,'minor'=>$minorCode,'startTime'=>$startTime,'endTime'=>$endTime]];
            $resp=hikRequest($ip,$user,$pass,'POST','/ISAPI/AccessControl/AcsEvent?format=json',$body);
            if (!$resp||!isset($resp['AcsEvent']['InfoList'])) break;
            $events=$resp['AcsEvent']['InfoList'];
            $allEvents=array_merge($allEvents,$events);
            $total=$resp['AcsEvent']['totalMatches']??0;
            $position+=count($events);
        } while ($position<$total);
    }
    usort($allEvents, function($a,$b){return strcmp($a['time']??'',$b['time']??'');});
    return $allEvents;
}
function procesarDiaEmpleado(int $empNumber, string $date, array $timestamps): array {
    $db = getDB();
    sort($timestamps);
    $limpios = [];
    foreach ($timestamps as $ts) {
        if (empty($limpios) || ($ts - end($limpios)) >= DEDUP_SECONDS) {
            $limpios[] = $ts;
        }
    }
    $del = $db->prepare("DELETE FROM ohrm_attendance_record WHERE employee_id=? AND DATE(punch_in_user_time)=? AND (punch_in_note='Auto-sync Hikvision' OR punch_out_note='Auto-sync Hikvision')");
    $del->execute([$empNumber, $date]);
    $offset = '-06:00';
    $tz = 'America/Mexico_City';
    $insertados = 0;
    $i = 0;
    $n = count($limpios);
    while ($i < $n) {
        $in = $limpios[$i];
        $out = ($i + 1 < $n) ? $limpios[$i + 1] : null;
        $inStr = date('Y-m-d H:i:s', $in);
        if ($out !== null) {
            $outStr = date('Y-m-d H:i:s', $out);
            $stmt = $db->prepare("INSERT INTO ohrm_attendance_record (employee_id,punch_in_utc_time,punch_in_user_time,punch_in_time_offset,punch_in_timezone_name,punch_in_note,punch_out_utc_time,punch_out_user_time,punch_out_time_offset,punch_out_timezone_name,punch_out_note,state) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$empNumber,$inStr,$inStr,$offset,$tz,'Auto-sync Hikvision',$outStr,$outStr,$offset,$tz,'Auto-sync Hikvision','PUNCHED OUT']);
            $i += 2;
        } else {
            $stmt = $db->prepare("INSERT INTO ohrm_attendance_record (employee_id,punch_in_utc_time,punch_in_user_time,punch_in_time_offset,punch_in_timezone_name,punch_in_note,state) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$empNumber,$inStr,$inStr,$offset,$tz,'Auto-sync Hikvision','PUNCHED IN']);
            $i += 1;
        }
        $insertados++;
    }
    return ['marcas'=>$n, 'bloques'=>$insertados];
}
function syncAttendance(array $checadores, array $employeeMap, ?string $targetDate): void {
    if ($targetDate){
        $startTime=$targetDate.'T00:00:00-06:00';$endTime=$targetDate.'T23:59:59-06:00';
        $diasProcesar=[$targetDate];
    } else {
        $hoy=date('Y-m-d');
        $ayer=date('Y-m-d',strtotime('-1 day'));
        $startTime=$ayer.'T00:00:00-06:00';
        $endTime=$hoy.'T23:59:59-06:00';
        $diasProcesar=[$ayer,$hoy];
    }
    logMsg("=== INICIO SYNC === $startTime -> $endTime");
    $acc = [];
    $totalEventos = 0;
    $noMapeados = [];
    foreach ($checadores as $chk) {
        logMsg("--- Checador: {$chk['ip']} ---");
        $events=getHikEvents($chk['ip'],$chk['user'],$chk['pass'],$startTime,$endTime);
        logMsg("Total eventos: ".count($events));
        $totalEventos += count($events);
        foreach ($events as $event){
            $hikEmpNo=$event['employeeNoString']??$event['employeeNo']??null;
            if (!$hikEmpNo) continue;
            if (!isset($employeeMap[$hikEmpNo])) {
                if (!isset($noMapeados[$hikEmpNo])) {
                    $noMapeados[$hikEmpNo] = ['count'=>0, 'nombre'=>$event['name']??null, 'checadores'=>[]];
                }
                $noMapeados[$hikEmpNo]['count']++;
                if ($event['name']??null) $noMapeados[$hikEmpNo]['nombre']=$event['name'];
                if (!in_array($chk['ip'], $noMapeados[$hikEmpNo]['checadores'])) {
                    $noMapeados[$hikEmpNo]['checadores'][]=$chk['ip'];
                }
                continue;
            }
            $empNumber=(int)$employeeMap[$hikEmpNo];
            $ts=strtotime($event['time']??'');
            if (!$ts) continue;
            $fecha=date('Y-m-d',$ts);
            if (!in_array($fecha,$diasProcesar)) continue;
            $acc[$empNumber][$fecha][]=$ts;
        }
    }
    if (!empty($noMapeados)) {
        logMsg("*** ADVERTENCIA: ".count($noMapeados)." ID(s) de checador SIN empleado mapeado en OrangeHRM (checadas ignoradas) ***");
        foreach ($noMapeados as $idChecador=>$info) {
            $nombre = $info['nombre'] ?? '(nombre no reportado por el checador)';
            $checadoresStr = implode(', ', $info['checadores']);
            logMsg("    ID checador='$idChecador' nombre='$nombre' checadas_ignoradas={$info['count']} checador(es)=[$checadoresStr] -> agrega este ID en el campo 'Employee Id' del empleado en OrangeHRM, o en el arreglo \$EQUIV de este script.");
        }
    }
    $empleadosProcesados=0; $bloquesTotales=0;
    foreach ($acc as $empNumber=>$dias){
        foreach ($dias as $fecha=>$timestamps){
            $r=procesarDiaEmpleado($empNumber,$fecha,$timestamps);
            logMsg("  emp=$empNumber $fecha: {$r['marcas']} marcas -> {$r['bloques']} bloque(s)");
            $empleadosProcesados++;
            $bloquesTotales+=$r['bloques'];
        }
    }
    logMsg("=== FIN: $totalEventos eventos, $empleadosProcesados emp/dia, $bloquesTotales bloques, ".count($noMapeados)." id(s) sin mapear ===");
    file_put_contents(LAST_SYNC_FILE,json_encode(['last_sync'=>$endTime,'synced_at'=>date('Y-m-d H:i:s'),'sin_mapear'=>array_keys($noMapeados)]));
}
if ($mode==='list') logMsg("Usa import_employees.php para listar empleados");
else {
    $pdo=getDB();
    $stmt=$pdo->query('SELECT employee_id, emp_number FROM hs_hr_employee ORDER BY emp_number');
    $EMPLOYEE_MAP=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $EMPLOYEE_MAP[$r['employee_id']]=(string)$r['emp_number'];
    foreach($EQUIV as $idSur=>$empNum) $EMPLOYEE_MAP[$idSur]=$empNum;
    syncAttendance($CHECADORES,$EMPLOYEE_MAP,$targetDate);
}
