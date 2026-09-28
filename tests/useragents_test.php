<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/useragents_data.php';
require_once $root . '/pixl_server.php';
$checks = 0;
function agents_assert(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
agents_assert(ua_agents_score(0, 0) === null && ua_agents_score(1, 0) === null, 'zero or one observation has no score');
agents_assert(ua_agents_score(100, 9900) === 0.0, '100 identical agents score zero');
agents_assert(ua_agents_score(100, 0) === 100.0, '100 different agents score 100');
agents_assert(ua_agents_score(100, 90 * 89) === 19.1, '90 repeats and ten singletons score 19.1');
agents_assert(ua_agents_score(100, 2 * 50 * 49) === 50.5, 'balanced occurrences score above a dominant agent');
agents_assert(ua_agents_percent(11, 100) === 11.0 && ua_agents_percent(0, 0) === 0.0, 'unique ratio remains distinct from concentration score');
$fall = strtotime('2026-10-25T01:30:00Z');
agents_assert(ua_agents_range('1d', $fall)['since'] === strtotime('2026-10-24T22:00:00Z'), 'Berlin day handles fall DST');
agents_assert(ua_agents_range('7d', $fall)['since'] === $fall - 7 * 86400, 'seven days are a rolling interval');
agents_assert(ua_agents_range('all', $fall)['since'] === null, 'all has no lower date bound');
agents_assert(ua_agents_range('bad', $fall)['key'] === '24h', 'invalid range uses safe default');
agents_assert(str_contains(pixl_stats_safe_return_url('live.php?view=useragents&range=7d'), 'view=useragents'), 'login return preserves UserAgents view');
agents_assert(pixl_stats_safe_return_url('live.php?view=https://evil.test') === 'live.php', 'login view cannot become an external destination');
echo "PASS UserAgents pure: $checks checks\n";
$pureChecks = $checks;
$dsn = getenv('PIXL_USERAGENTS_TEST_DSN') ?: '';
if ($dsn === '') { echo "SKIP MySQL/HTTP: use an empty pixl_setup_test_* database via PIXL_USERAGENTS_TEST_DSN\n"; exit; }
$pdo = new PDO($dsn, getenv('PIXL_USERAGENTS_TEST_USER') ?: 'root', getenv('PIXL_USERAGENTS_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
agents_assert(str_starts_with($database, 'pixl_setup_test_') && $pdo->query('SHOW TABLES')->fetchAll() === [], 'isolated empty fixture database required');
foreach (pixl_unified_schema_definitions($root . '/pixl_schema.sql', 'pixl_events') as $sql) $pdo->exec($sql);
$now = time() - 3; $date = gmdate('Y-m-d H:i:s', $now - 100);
$sessionIds = [];
$insert = $pdo->prepare('INSERT INTO stat4_events (event_uuid,session_id,visitor_hash,occurred_at,event_type,user_agent,browser,os) VALUES (?,?,?,?,?,?,?,?)');
$add = static function (string $campaign, string $agent, string $browser = 'Safari', string $os = 'ios', string $source = 'alpha', ?int $stamp = null, string $type = 'pageview', bool $bot = false) use ($pdo, $insert, &$sessionIds, $date): void {
    $key = json_encode([$campaign,$source,$bot]);
    if (!isset($sessionIds[$key])) {
        $id = substr(hash('sha256', $key), 0, 32); $sessionIds[$key] = $id;
        $pdo->prepare('INSERT INTO stat4_visitors (visitor_hash,first_seen,last_seen,first_ip_hash,is_bot) VALUES (?,?,?,?,?)')->execute([$id,$date,$date,$id,(int)$bot]);
        $pdo->prepare('INSERT INTO stat4_sessions (session_id,visitor_hash,started_at,last_seen,utm_source,utm_campaign,is_bot) VALUES (?,?,?,?,?,?,?)')->execute([$id,$id,$date,$date,$source,$campaign,(int)$bot]);
    }
    $id = $sessionIds[$key];
    $insert->execute([bin2hex(random_bytes(16)),$id,$id,$stamp === null ? $date : gmdate('Y-m-d H:i:s',$stamp),$type,$agent,$browser,$os]);
};
for ($i=0; $i<100; $i++) {
    $add('Dominant', $i<90 ? 'Repeated Agent/1.0' : 'Rare Agent/' . $i, $i<20 ? ' Unknown ' : 'Safari', ($i<10 || ($i>=20 && $i<25)) ? '' : 'ios', $i<60 ? 'alpha' : 'beta');
    $add('Unique', 'Unique Agent/' . $i, 'Chrome', 'windows');
    $add('Repeated', 'Same Agent/1.0');
}
for ($i=0; $i<10; $i++) $add('Balanced', $i<5 ? 'Agent A' : 'Agent B');
$add('Single', 'Only Agent');
foreach (['', ' Unknown ', 'unbekannt'] as $ua) $add('Missing', $ua, 'Unknown', 'Unknown');
$add('', 'Direct Agent'); $add('Case', 'Agent'); $add('Case', 'agent'); $add('case', 'Agent');
$add('Literal', 'UA 100%_literal'); $add('Literal', 'UA normal');
$hostile = '<img src=x onerror="window.uaInjected=true">';
$add($hostile, '<script>window.uaInjected=true</script>' . str_repeat('x', 850), 'Unknown', 'Unknown');
$add('Boundary', 'On lower bound', 'Safari', 'ios', 'alpha', $now-86400);
$add('Old', 'Too old', 'Safari', 'ios', 'alpha', $now-86401);
$add('Future', 'At upper bound', 'Safari', 'ios', 'alpha', $now);
$add('Bot', 'Bot Agent', 'Unknown', 'Unknown', 'alpha', null, 'pageview', true);
$add('Non-pageviews', 'Heartbeat Agent', 'Unknown', 'Unknown', 'alpha', null, 'heartbeat');
$add('Non-pageviews', 'Click Agent', 'Unknown', 'Unknown', 'alpha', null, 'click');
$before = $pdo->query('SELECT * FROM stat4_events ORDER BY id')->fetchAll();
$overview = ua_agents_overview($pdo, $now);
$byName = []; foreach ($overview['campaigns'] as $row) $byName[$row['campaign']] = $row;
agents_assert($byName['Dominant']['occurrences'] === 100 && $byName['Dominant']['users'] === 2 && count($byName['Dominant']['sources']) === 2, 'campaign names merge across sources and users are distinct');
agents_assert($byName['Dominant']['distinct_useragents'] === 11 && $byName['Dominant']['unique_percent'] === 11.0 && $byName['Dominant']['score'] === 19.1, 'raw frequencies produce correct unique ratio and weighted score');
agents_assert($byName['Dominant']['dominant_percent'] === 90.0, 'dominant agent share is visible');
agents_assert($byName['Repeated']['score'] === 0.0 && $byName['Unique']['score'] === 100.0 && $byName['Balanced']['score'] === 55.6 && $byName['Single']['score'] === null, 'score extremes, balance and sample-size boundary');
agents_assert($byName['Dominant']['browser_unknown'] === 20 && $byName['Dominant']['os_unknown'] === 15 && $byName['Dominant']['both_unknown'] === 10 && $byName['Dominant']['either_unknown'] === 25, 'unknown browser, OS, intersection and union are counted separately');
agents_assert($byName['Dominant']['either_unknown_percent'] === 25.0, 'unknown percentages use all campaign pageviews');
agents_assert($byName['Missing']['missing_useragents'] === 3 && $byName['Missing']['distinct_useragents'] === 0 && $byName['Missing']['score'] === null, 'missing or placeholder UA values cannot improve diversity');
agents_assert($byName['Case']['distinct_useragents'] === 2 && isset($byName['case']), 'case-sensitive agent strings and campaign names remain distinct');
agents_assert(isset($byName['']) && isset($byName[$hostile]), 'unnamed and HTML-like campaign names remain data');
agents_assert(isset($byName['Boundary']) && !isset($byName['Old']) && !isset($byName['Future']) && !isset($byName['Bot']) && !isset($byName['Non-pageviews']), 'half-open interval, bots and non-pageviews');
agents_assert(isset(array_column(ua_agents_overview($pdo,$now,'all')['campaigns'],null,'campaign')['Old']), 'all includes old pageviews');
agents_assert($overview === ua_agents_overview($pdo,$now), 'same read snapshot is deterministic');
$details = ua_agents_details($pdo,$now,'24h','Dominant');
agents_assert($details['total_rows'] === 11 && $details['rows'][0]['user_agent'] === 'Repeated Agent/1.0' && $details['rows'][0]['occurrences'] === 90 && $details['rows'][0]['percent'] === 90.0, 'complete UserAgents are ranked by frequency');
agents_assert($details['rows'][0]['browsers'] === [['name'=>'Safari','occurrences'=>70],['name'=>'Unknown','occurrences'=>20]], 'per-agent browser frequencies aggregate across OS combinations');
agents_assert($details['rows'][0]['operating_systems'] === [['name'=>'ios','occurrences'=>75],['name'=>'Unknown','occurrences'=>15]], 'per-agent OS frequencies aggregate across browser combinations');
$unknown = ua_agents_details($pdo,$now,'24h','Dominant',1,'',true);
agents_assert($unknown['filtered_occurrences'] === 25 && $unknown['campaign_occurrences'] === 100 && $unknown['rows'][0]['percent'] === 25.0, 'unknown detail filter uses union and retains campaign denominator');
$first = ua_agents_details($pdo,$now,'24h','Unique'); $second = ua_agents_details($pdo,$now,'24h','Unique',2);
agents_assert(count($first['rows']) === 50 && count($second['rows']) === 50 && $second['pages'] === 2, 'large agent lists are completely paginated');
agents_assert(count(array_unique(array_merge(array_column($first['rows'],'user_agent'),array_column($second['rows'],'user_agent')))) === 100, 'stable pagination has no duplicate or lost agents');
agents_assert(ua_agents_details($pdo,$now,'24h','Unique',999)['page'] === 2, 'out-of-range pages clamp to the last page');
agents_assert(ua_agents_details($pdo,$now,'24h','Literal',1,'%_')['filtered_occurrences'] === 1, 'LIKE wildcard characters are searched literally');
agents_assert(ua_agents_details($pdo,$now,'24h',"' OR 1=1 --")['campaign_occurrences'] === 0, 'campaign query is parameterized');
agents_assert(ua_agents_details($pdo,$now,'24h','Missing')['total_rows'] === 1, 'missing UA placeholders form one labeled detail row');
agents_assert(ua_agents_details($pdo,$now,'24h','Unique',1,'no match')['total_rows'] === 0, 'empty detail search is valid');
agents_assert($before === $pdo->query('SELECT * FROM stat4_events ORDER BY id')->fetchAll() && !$pdo->inTransaction(), 'reports leave all event rows unchanged and close read transactions');
$empty = ua_agents_overview($pdo,1);
agents_assert($empty['campaigns'] === [] && $empty['totals']['occurrences'] === 0, 'empty timeframe returns zero totals');
$mysqlChecks = $checks - $pureChecks;
echo "PASS UserAgents MySQL: $mysqlChecks checks\n";

$fixture = sys_get_temp_dir() . '/stats3-useragents-http-' . bin2hex(random_bytes(6));
mkdir($fixture,0700); mkdir($fixture.'/sessions',0700);
foreach (['pixl_server.php','pixl_geoip.php','live.php','live_data.php','live.css','live.js','useragents_data.php','useragents_view.php','useragents.js','useragents.css'] as $file) copy($root.'/'.$file,$fixture.'/'.$file);
$db = ['database'=>$database,'user'=>getenv('PIXL_USERAGENTS_TEST_USER') ?: 'root','password'=>getenv('PIXL_USERAGENTS_TEST_PASSWORD') ?: '','charset'=>'utf8mb4'];
foreach (explode(';',substr($dsn,6)) as $part) { [$key,$value] = array_pad(explode('=',$part,2),2,''); if (in_array($key,['host','port'],true)) $db[$key]=$value; if ($key==='unix_socket') $db['socket']=$value; }
file_put_contents($fixture.'/pixl_config.php','<?php return '.var_export(['db'=>$db,'table'=>'pixl_events','stats_password'=>'agents-fixture','hash_salt'=>str_repeat('fixture-',8),'geoip'=>['enabled'=>false],'pushover'=>['enabled'=>false]],true).';');
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $address=stream_socket_get_name($socket,false); fclose($socket);
$server=proc_open([PHP_BINARY,'-d','opcache.enable=0','-d','session.save_path='.$fixture.'/sessions','-S',$address,'-t',$fixture],[0=>['pipe','r'],1=>['file',$fixture.'/server.log','a'],2=>['file',$fixture.'/server.log','a']],$pipes);
if (!is_resource($server)) throw new RuntimeException('HTTP fixture startup failed'); fclose($pipes[0]);
function agents_http(string $url,string $cookie='',?string $post=null): array {
    $ctx=stream_context_create(['http'=>['method'=>$post===null?'GET':'POST','header'=>"Cookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded",'content'=>$post??'','ignore_errors'=>true,'follow_location'=>0,'timeout'=>10]]);
    $body=file_get_contents($url,false,$ctx); preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$match);
    return ['status'=>(int)($match[1]??0),'body'=>$body,'headers'=>$http_response_header??[]];
}
try {
    for ($i=0;$i<80;$i++) { $c=@stream_socket_client('tcp://'.$address,$errno,$error,.1); if ($c) { fclose($c);break; } usleep(25000); }
    $base='http://'.$address;
    foreach (['useragents','useragent_details&campaign=Dominant'] as $kind) {
        $response=agents_http($base.'/live.php?data='.$kind);
        agents_assert($response['status']===401 && json_decode($response['body'],true)['ok']===false,'UserAgent API requires shared authentication');
    }
    $login=agents_http($base.'/live.php?view=useragents'); agents_assert(str_contains($login['body'],'stats_password'),'UserAgents HTML is protected');
    $login=agents_http($base.'/live.php?view=useragents','', 'stats_password=agents-fixture');
    $cookie=''; foreach ($login['headers'] as $h) if (preg_match('/^Set-Cookie: ([^;]+)/i',$h,$m)) $cookie=$m[1];
    agents_assert($login['status']===302 && $cookie!=='' && str_contains(implode('\n',$login['headers']),'view=useragents'),'login retains the selected area');
    $page=agents_http($base.'/live.php?view=useragents',$cookie);
    agents_assert($page['status']===200 && str_contains($page['body'],'UA · UserAgents') && str_contains($page['body'],'useragents.js') && !str_contains($page['body'],'src="live.js"'),'UserAgents loads its own report and leaves Live polling separate');
    $response=agents_http($base.'/live.php?data=useragents&range=24h',$cookie);
    agents_assert($response['status']===200 && count(json_decode($response['body'],true)['campaigns'])>0,'authenticated overview runs real queries');
    agents_assert(str_contains(strtolower(implode('\n',$response['headers'])),'cache-control: no-store, private'),'analytics JSON stays private and uncached');
    $detail=agents_http($base.'/live.php?data=useragent_details&range=24h&campaign=Dominant&at='.$now,$cookie);
    agents_assert(json_decode($detail['body'],true)['rows'][0]['occurrences']===90,'authenticated detail API returns full counts');
    foreach (['campaign[]=bad','campaign=Dominant&q[]=bad','q=test'] as $bad) agents_assert(agents_http($base.'/live.php?data=useragent_details&'.$bad,$cookie)['status']===400,'invalid detail inputs return 400');
    agents_assert(agents_http($base.'/useragents_view.php',$cookie)['status']===404,'view fragment cannot be opened as a standalone controller');
    if (getenv('PIXL_USERAGENTS_TEST_BROWSER')==='1') {
        $output=getenv('PIXL_USERAGENTS_ARTIFACTS') ?: $fixture;
        $process=proc_open(['node',$root.'/tests/useragents_browser_test.js'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$browserPipes,$root,array_merge(getenv(),['UA_TEST_BASE_URL'=>$base,'UA_TEST_COOKIE'=>$cookie,'UA_TEST_ARTIFACTS'=>$output]));
        fclose($browserPipes[0]); agents_assert(proc_close($process)===0,'UserAgents browser validation');
    }
    echo 'PASS UserAgents HTTP: '.($checks-$pureChecks-$mysqlChecks)." checks\n";
    echo "Fixture: $fixture\n";
} finally { proc_terminate($server); proc_close($server); }
