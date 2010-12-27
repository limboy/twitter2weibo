<?php
$accounts = include 'config.php';

define('DATA_DIR', dirname(__FILE__).'/data/');

foreach ($accounts as $account)
{
	sync($account['t_username'], $account['s_email'], $account['s_pwd']);
}

function sync($t_username, $s_email, $s_pwd)
{
	$pid = pcntl_fork();
	if(!$pid)
	{
		doSync($t_username, $s_email, $s_pwd);
	}
}

function doSync($t_username, $s_email, $s_pwd)
{
	$data_file = DATA_DIR.$t_username.'.log';
	$t_url = 'http://api.twitter.com/1/statuses/user_timeline.json?screen_name='.$t_username;

	if (!file_exists($data_file) || file_get_contents($data_file) == '')
	{
		file_put_contents($data_file, file_get_contents($t_url));
	} 
	else 
	{
		$new_rs = file_get_contents($t_url);
		$new_tweets = json_decode($new_rs, true);
		$origin_tweets = json_decode(file_get_contents($data_file), true);

		$new_tweets_arr = array();
		foreach($new_tweets as $val)
		{
			$new_tweets_arr[$val['id_str']] = $val['text'];
		}

		$origin_tweets_arr = array();
		foreach($origin_tweets as $val)
		{
			$origin_tweets_arr[$val['id_str']] = $val['text'];
		}

		$tobe_sent_tweets = array_diff_assoc($new_tweets_arr, $origin_tweets_arr);
		krsort($tobe_sent_tweets);

		foreach($tobe_sent_tweets as $tweet)
		{
			if(strpos($tweet, '@') === FALSE)
				send2weibo($s_email, $s_pwd, $tweet);
		}

		file_put_contents($data_file, $new_rs);
	}
	exit;
}

function send2weibo($s_email, $s_pwd, $tweet) {
	static $cookie_fetched = array();
	$cookie = DATA_DIR.$s_email.'.cookie.txt';
	if (empty($cookie_fetched[$s_email]))
	{
		$ch = curl_init("https://login.sina.com.cn/sso/login.php?username=$s_email&password=$s_pwd&returntype=TEXT");
		curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
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
	curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
	curl_exec($ch);
	curl_close($ch);
}



