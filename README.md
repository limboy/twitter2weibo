## 使用说明

* 打开config.php，在里面填入一组或多组信息(twitter_username/sina_email/sina_pwd)
* 在当前目录下新建一个data文件夹，并设置为可写入
* 设置cron为每3分钟执行一次脚本

	crontab -e
	*/3 * * * * php /path/to/twitter2weibo.php

可以先试运行一下看看是否正常

## 新加的特性

* 支持多用户(在config.php里配置)
* 多线程同步(只支持linux)。如果是windows主机，可以去掉pcntl_fork方法，直接调用sync方法
* 保存用户cookie，避免多次读取
* 用户删除某条/某些tweet后，不会出现异常同步
