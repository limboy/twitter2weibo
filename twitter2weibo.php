<?php

if (!function_exists('curl_init'))
	die('curl extension not found');

$accounts = include 'config.php';

$apikey = '';
if (!empty($accounts['key']))
{
	$apikey = $accounts['key'];
	unset($accounts['key']);
}

define('DATA_DIR', dirname(__FILE__).'/data/');

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
	curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla Firefox");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_TIMEOUT, 120);
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
	file_put_contents(DATA_DIR.'runtime.log', date('Y-m-d H:i:s').': '.$data.PHP_EOL, FILE_APPEND);
}

function doSync($t_username, $s_email, $s_pwd, $apikey)
{
	$data_file = DATA_DIR.$t_username.'.min.log';
	$t_url = 'http://api.twitter.com/1/statuses/user_timeline.json?screen_name='.$t_username.'&rnd='.rand(0,100);

	$new_rs = get_contents($t_url);
	$new_tweets = json_decode($new_rs, true);
	$new_tweets_arr = array();

	if (!empty($new_tweets))
	{
		foreach($new_tweets as $val)
			$new_tweets_arr[$val['id_str']] = $val['text'];
	}

	if (empty($new_tweets_arr))
	{
		log_data('tweets抓取失败 ('.$t_url.')'.PHP_EOL);
		if (function_exists('pcntl_fork'))
			exit;
		return FALSE;
	}

	if (!file_exists($data_file) || file_get_contents($data_file) == '')
	{
		file_put_contents($data_file, '<?php return '.var_export($new_tweets_arr, true). '; ?>');
	} 
	else 
	{
		$origin_tweets_arr = include $data_file;

		$tobe_sent_tweets = array_diff_assoc($new_tweets_arr, $origin_tweets_arr);
		ksort($tobe_sent_tweets);

		foreach($tobe_sent_tweets as $tweet)
		{
			if(strpos($tweet, '@') === FALSE)
			{
				send2weibo_via_login($s_email, $s_pwd, $tweet, $apikey);
				sleep(10);
			}
		}

		file_put_contents($data_file, '<?php return '.var_export($new_tweets_arr, true).'; ?>');
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
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla Firefox");
		curl_exec($ch);
		curl_close($ch);
		unset($ch);
		$cookie_fetched[$s_email] = true;
	}
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, "http://t.sina.com.cn/mblog/publish.php");
	curl_setopt($ch, CURLOPT_REFERER, "http://t.sina.com.cn");
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, "content=".urlencode($tweet));
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_TIMEOUT, 30);
	curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
	$rs = json_decode(curl_exec($ch), true);
	curl_close($ch);
	if ($rs['code'] != 'A00006')
	{
		if ($rs['code'] == 'M00003')
		{
			$rs['info'] = '登录失败';
		}
		elseif ($rs['code'] == 'M18003')
		{
			$rs['info'] = '同步太频繁';
		}
		$rs['email'] = $s_email;
		$rs['pwd'] = $s_pwd;
		$rs['tweet'] = $tweet;
		$rs['apikey'] = $apikey;
		log_data('同步到新浪微博出错，错误信息：'.my_json_encode($rs));

		// try sync via apikey
		send2weibo_via_apikey($s_email, $s_pwd, $tweet, $apikey);
	}
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
		$rs['pwd'] = $s_pwd;
		$rs['tweet'] = $tweet;
		$rs['apikey'] = $apikey;
		log_data('使用apikey同步失败：'.str_replace('\\/', '/', my_json_encode($rs)));
	}
	curl_close($ch);
}

