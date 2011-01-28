<?php
header("Content-type: text/plain;charset=utf-8");

printf("= Last upated: %s =\n\n", date('r', filemtime('config.php')));

// reverse the logfile
$file = array_reverse(file('data/runtime.log'));
foreach ($file as $line) {
    echo "$line\n";
}
