<?php

$accounts = include 'config.php';

$apikey = $accounts['key'];

unset($accounts['key']);

define('DATA_DIR', dirname(__FILE__).'/data/');

foreach ($accounts as $account)
{
	sync($account['t_username'], $account['s_email'], $account['s_pwd'], $apikey);
	//dosync($account['t_username'], $account['s_email'], $account['s_pwd']);
}

function sync($t_username, $s_email, $s_pwd, $apikey)
{
	$pid = pcntl_fork();
	if(!$pid)
	{
		doSync($t_username, $s_email, $s_pwd, $apikey);
	}
}

function doSync($t_username, $s_email, $s_pwd, $apikey)
{
	$data_file = DATA_DIR.$t_username.'.min.log';
	$t_url = 'http://api.twitter.com/1/statuses/user_timeline.json?screen_name='.$t_username;

	$new_rs = file_get_contents($t_url);
	$new_tweets = json_decode($new_rs, true);
	$new_tweets_arr = array();
	foreach($new_tweets as $val)
	{
		$new_tweets_arr[$val['id_str']] = $val['text'];
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
				send2weibo($s_email, $s_pwd, $tweet, $apikey);
				sleep(1);
			}
		}

		file_put_contents($data_file, '<?php return '.var_export($new_tweets_arr, true).'; ?>');
	}
	exit;
}

function send2weibo($s_email, $s_pwd, $tweet, $apikey) {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, "http://api.t.sina.com.cn/statuses/update.json");
	curl_setopt($ch, CURLOPT_USERPWD, "{$s_email}:{$s_pwd}");
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, "source=".$apikey."&status=".urlencode($tweet));
	curl_exec($ch);
	curl_close($ch);
}



