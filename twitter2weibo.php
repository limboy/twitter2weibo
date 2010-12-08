<?php

$accounts = include 'config.php';

foreach ($accounts as $account)
{
	$pid = pcntl_fork();
	if(!$pid)
	{
		sync($account['t_username'], $account['s_email'], $account['s_pwd']);
	}
}

function sync($t_username, $s_email, $s_pwd)
{
	$data_file = 'data/'.$t_username.'.log';
	$t_url = 'http://api.twitter.com/1/statuses/user_timeline.json?screen_name='.$t_username;

	if (!file_exists($data_file) || file_get_contents($data_file) == '')
	{
		file_put_contents($data_file, file_get_contents($t_url));
	} 
	else 
	{
		$new_rs = file_get_contents($t_url);
		$new_tweets = json_decode($new_rs);
		$origin_tweets = json_decode(file_get_contents($data_file));

		$tobe_sent_tweets = array();
		foreach($new_tweets as $tweet)
		{
			if ($tweet->id != $origin_tweets[0]->id)
			{
				if (strpos($tweet->text, 'RT ') === FALSE && strpos($tweet->text, '@') === FALSE)
					$tobe_sent_tweets[] = array('id' => $tweet->id, 'text' => $tweet->text);
			} 
			else
			{
				break;
			}
		}

		if (!empty($tobe_sent_tweets))
		{
			foreach($origin_tweets as $tweet)
			{
				if ($tweet->id == $tobe_sent_tweets[0]['id'])
				{
					// 用户删除了某推
					return;
				}
			}
			foreach($tobe_sent_tweets as $weibo)
			{
				send2weibo($s_email, $s_pwd, $weibo['text']);
			}
		}

		file_put_contents($data_file, $new_rs);
	}
	exit;
}

function send2weibo($s_email, $s_pwd, $tweet) {
	$cookie = 'data/'.$s_email.'.cookie.txt';
	if (!file_exists($cookie))
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
