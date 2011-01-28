<?php

date_default_timezone_set('Asia/Shanghai');
error_reporting(E_ERROR);

if (!extension_loaded('curl'))
	die('curl extension not found');

if (!is_readable('config.php') || !touch('config.php') || !chmod('config.php', 0600))
    die("can't read or update config file");

$accounts = include 'config.php';

$apikey = '';
if (!empty($accounts['key']))
{
	$apikey = $accounts['key'];
	unset($accounts['key']);
}

define('DATA_DIR', dirname(__FILE__).'/data/');
define("RUNTIME_LOG_FILE", DATA_DIR.'runtime.log');
define("TIME_OUT", 60);
define("WEIBO_SEND_INTERVAL", 20);
define('USER_AGENT', "Mozilla/5.0 (compatible; MSIE 8.0; Windows NT 5.2; Trident/4.0)");

foreach ($accounts as $account)
{
	if (function_exists('pcntl_fork'))
		sync($account['t_username'], $account['s_email'], $account['s_pwd'], $apikey);
	else
		doSync($account['t_username'], $account['s_email'], $account['s_pwd'], $apikey);
}

function sync($t_username, $s_email, $s_pwd, $apikey)
{
	$pid = pcntl_fork();
	if(!$pid)
	{
		doSync($t_username, $s_email, $s_pwd, $apikey);
	}
}

function get_contents($url)
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_USERAGENT, USER_AGENT);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_TIMEOUT, TIME_OUT);
	$data = curl_exec($ch);
	curl_close($ch);
	return $data;
}

function my_json_encode($code)
{
	$code = json_encode(urlencode_arr($code));
	return urldecode($code);
}

function urlencode_arr($data)
{
	if(is_array($data))
	{
		foreach($data as $key=>$val)
		{
			$data[$key] = urlencode_arr($val);
		}
		return $data;
	}
	else
	{
		return urlencode($data);
	}
}

function log_data($data)
{
    file_put_contents(RUNTIME_LOG_FILE,
        sprintf('%s: %s'.PHP_EOL, date('r'), $data), FILE_APPEND);
}


function doSync($t_username, $s_email, $s_pwd, $apikey)
{
	$data_file = DATA_DIR.$t_username.'.data';

    // Anonymous calls are based on the IP of the host and are permitted 150 requests per hour. 
    //      @see http://dev.twitter.com/pages/rate-limiting
    $t_url = sprintf('http://api.twitter.com/1/statuses/user_timeline.json?screen_name=%s&t=%s', 
                        $t_username, time());
        
	$new_rs = get_contents($t_url);
	$new_tweets = json_decode($new_rs, true);
	$new_tweets_arr = array();

	if (!empty($new_tweets))
	{
		foreach($new_tweets as $val)
			$new_tweets_arr[$val['id_str']] = $val['text'];
	}

    // 因为有150的查询限制，所以可能会出现查询出错的情况
    //     @see https://github.com/feelinglucky/twitter2weibo/commit/86512602f2054d585ed872356078152c3afb58b2#twitter2weibo.php-P56
    if ($new_tweets['error'] || empty($new_tweets_arr))
	{
		log_data('[ERROR] fetch tweets failed from ('.$t_url.')');
		if (function_exists('pcntl_fork'))
			exit;
		return FALSE;
	}

    log_data("fetch ${t_username}'s tweets is finished.");

	if (!file_exists($data_file) || !filesize($data_file)) {
        log_data("[NOTICE] ${t_username}'s data file not exists, nothing tobe done.");
		file_put_contents($data_file, serialize($new_tweets_arr));
	}
	else 
	{
		$origin_tweets_arr = unserialize(file_get_contents($data_file));

		$tobe_sent_tweets = array_diff_assoc($new_tweets_arr, $origin_tweets_arr);
		ksort($tobe_sent_tweets);

        // 为了避免错误阻塞，立即写入到数据文件中
		file_put_contents($data_file, serialize($new_tweets_arr));

        if (sizeof($tobe_sent_tweets)) {
            log_data("received ${t_username}'s new ". sizeof($tobe_sent_tweets) ." tweets, sync them.");

            foreach($tobe_sent_tweets as $key => $tweet) {
                // 剔除 Twitter 的回复贴，避免给新浪用户造成困扰
                if (!preg_match("/^@\w+\s+/i", $tweet)) {
                    if (send2weibo_via_login($s_email,  $s_pwd, $tweet, $apikey)
                     || send2weibo_via_apikey($s_email, $s_pwd, $tweet, $apikey)) {
                        log_data("sync tweets to sina which key is '".$key."' finished");
                    } else {
                        // 请求失败的返回信息已经写在函数中
                    }

                    sleep(WEIBO_SEND_INTERVAL);
                }
            }
        } else {
            log_data("no new tweets received, do nothing.");
        }
	}

	if (function_exists('pcntl_fork'))
		exit;
}

function send2weibo_via_login($s_email, $s_pwd, $tweet, $apikey) {
	static $cookie_fetched = array();
	$cookie = DATA_DIR.$s_email.'.cookie.txt';
	if (empty($cookie_fetched[$s_email]))
	{
		$login_data = array(
			'service' => 'miniblog',
			'client' => 'ssologin.js(v1.3.9)',
			'entry' => 'miniblog',
			'encoding' => 'utf-8',
			'gateway' => 1,
			'savestate' => 7,
			'useticket' => 0,
			'username' => $s_email,
			'password' => $s_pwd,
			'url' => 'http://t.sina.com.cn/ajaxlogin.php?framelogin=1&callback=parent.sinaSSOController.feedBackUrlCallBack&returntype=META',
		);
		$ch = curl_init("http://login.sina.com.cn/sso/login.php?client=ssologin.js(v1.3.9)");
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($login_data));
		curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, TIME_OUT);
		curl_setopt($ch, CURLOPT_USERAGENT, USER_AGENT);
		curl_exec($ch);
		curl_close($ch);
        chmod($cookie, 0600); // 只有自己可以读取！
		unset($ch);
		$cookie_fetched[$s_email] = true;
	}
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, "http://t.sina.com.cn/mblog/publish.php");
	curl_setopt($ch, CURLOPT_REFERER, "http://t.sina.com.cn");
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, "content=".urlencode($tweet));
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_TIMEOUT, TIME_OUT);
	curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
	$rs = json_decode(curl_exec($ch), true);
	curl_close($ch);

	if ($rs['code'] != 'A00006') {
		if ($rs['code'] == 'M00003')
		{
			$rs['info'] = '登录失败';
		}
		elseif ($rs['code'] == 'M18003')
		{
			$rs['info'] = '同步太频繁';
		}
		$rs['email'] = $s_email;
		//$rs['pwd'] = $s_pwd;
		$rs['tweet'] = $tweet;
		$rs['apikey'] = $apikey;

		log_data('[ERROR] 同步到新浪微博出错，错误信息：'.my_json_encode($rs));

        return false;
		// try sync via apikey
		//send2weibo_via_apikey($s_email, $s_pwd, $tweet, $apikey);
	}

    return true;
}

function send2weibo_via_apikey($s_email, $s_pwd, $tweet, $apikey) {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, "http://api.t.sina.com.cn/statuses/update.json");
	curl_setopt($ch, CURLOPT_USERPWD, "{$s_email}:{$s_pwd}");
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, "source=".$apikey."&status=".urlencode($tweet));
	$rs = json_decode(curl_exec($ch), true);
	if (!empty($rs['error'])) {
		$rs['email'] = $s_email;
		//$rs['pwd'] = $s_pwd;
		$rs['tweet'] = $tweet;
		$rs['apikey'] = $apikey;
		log_data('[ERROR] 使用 API 同步新浪微博失败：'.str_replace('\\/', '/', my_json_encode($rs)));
        return false;
	}

	curl_close($ch);
    return true;
}
