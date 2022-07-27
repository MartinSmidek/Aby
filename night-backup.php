<?php
  // wget http://192.168.1.250/www/policka/night-backup.php?secret=74d6dh68495526th458791vt32558798td865
  $dnes= date("ymd");
  echo "before:".(file_exists("test.txt") ? "ok" : "ko");
  file_put_contents("test.txt", "dnes je $dnes"); 
  echo "<hr>after:".(file_exists("test.txt") ? "ok" : "ko");
/*
  goto end;
  // uložení dumpu databází  do složek Ywd  ... 2019/01/po ... 20xx/52/ne
  // odhad místa/rok = 365*30MB = 11G ... koncem roku se to může vždy promazat
  $secret= $_GET['secret'];
  $server= $_SERVER['SERVER_NAME'];
  // úschovu lze spustit pouze z lokálního prostředí a s heslem
  if ( $secret!="74d6dh68495526th458791vt32558798td865" || $server!="192.168.1.250" ) die('?');
  // cesta
  $dnes= date("ymd");
  $path= "backup/".date("Y/W/").array('ne','po','ut','st','ct','pa','so')[date('w')];

  // ---------------------------------------------------- databáze pod MariaDB 5
  $secret= "--opt -u root --password=fP9!7Gfnqr";
  $dbs= array('ezer_ch');
  // případné vytvoření složky
  if ( !file_exists($path) ) {
  	mkdir($path,0777,true);
  }
  // cyklus ukládání
  foreach ($dbs as $db) {
  	$cmd= "/volume1/@appstore/MariaDB/usr/bin/mysqldump $secret $db | gzip > ./$path/$dnes.$db.sql.gz";
    $out= shell_exec($cmd);
    //copy("/volume1/web/$path/$dnes.$db.sql.gz","/volume1/web/backup/actual/$db.sql.gz");
  }
end:
*/
?>