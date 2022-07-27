<?php
# Aplikace Polička
# 2021 Martin Smidek <martin@smidek.eu>
#
/** =======================================================================================> TABLES */
# ------------------------------------------------------------------------------------- aby truncate
# inicializace db
function aby_truncate() { trace();
  query("TRUNCATE TABLE dar");
  query("TRUNCATE TABLE clen");
  query("TRUNCATE TABLE ukol");
  query("TRUNCATE TABLE role");
  query("TRUNCATE TABLE vypis");
  return "tabulky clen, role, dar, ukol, vypis jsou vymazány";
}
# --------------------------------------------------------------------------------------- aby import
# primární import dat
function aby_import($par) { trace();
  global $ezer_path_root;
  $csv= "$ezer_path_root/doc/import/{$par->file}.csv";
  $TEST= isset($par->test);
  $data= array();
  $msg= aby_csv2array($csv,$data,$par->max?:999999,'UTF-8');
//  display($msg);                                              
  debug($data,$csv);
  // zrušíme staré záznamy
  // definice polí
  $flds= array( 
      // Donio: Email	Jméno	Částka	Stav	Typ platby	Datum příspěvku	Jméno přispěvatele	Vzkaz
      // kontakty darci_2021.csv: Jméno, x, Email, Adresa, Poznámka, Posláno ...
      // clen
      'Email'   => "C,email",
      'Jméno'   => "C,*,jp",
      // dar
      'Částka'            => "D,castka,dn",
      'zpusob'            => "D,-",
      'Datum příspěvku'   => "D,castka_kdy,d",
      'Jméno přispěvatele'=> "D,-",
      'Vzkaz'             => "D,pozn",
  );
  // rozdělíme na clen a dar
  $n_clen= $n_dar= 0;
  foreach ($data as $row) {
    $poznamka= ''; // složená z poznamka s prefixem barva a poznamka2
//                                                    debug($row);
    // najdi kontakt: fyzické podle jmeno+prijmeni (osoba=1), právnické podle firma (osoba=0)
    // nebo vlož nvý kontakt
    $osoba= $row['osoba'];
    $jmeno= $row['jmeno'];
    $prijmeni= $row['prijmeni'];
    $firma= trim($row['firma']);
    if (!$prijmeni && !$firma) continue;
    $firma_info= trim($row['firma_info']);
    $idc= 0;
    if ($row['soukr']=='soukr') {
      // u lékařů ap. proveď firma=titul+firma a info= celé jméno
      $firma= "{$row['titul']} $firma";
      $firma_info.= " {$row['firma']}";
    }
    if ($row['zdroj']=='firmy2') {
      $poznamka= $row['barva'];
      $firma_info= trim("{$row['titul']} {$row['jmeno']} {$row['prijmeni']} {$row['titul_za']}");
    }
    if ($row['zdroj']=='dobr' || $row['zdroj']=='kruh') {
      $p2= trim($row['poznamka2']);
      $p3= trim($row['poznamka3']);
      $poznamka.= ($p2 && $p3) ? "POVOLÁNÍ $p2, POMOC: $p3" 
          : ($p2 ? "POVOLÁNÍ $p2" : ($p3 ? "POMOC $p3" : ''));
    }
    if ($row['zdroj']=='darci') {
      $idc= select('id_clen','clen', $osoba||!$firma
          ? "prijmeni='$prijmeni' AND jmeno='$jmeno'"
  //        : "firma='$firma' AND prijmeni='$prijmeni' AND jmeno='$jmeno'"
          : "firma='$firma' /*AND firma_info='$firma_info'*/ "
          );
    }
    if (!$idc) {
      if ($osoba||!$firma) {
        $JM= trim(utf2ascii($jmeno,' .'));
        $PR= trim(utf2ascii($prijmeni,' .'));
        $qry= "INSERT INTO clen (osoba,firma,jmeno,prijmeni,ascii_jmeno,ascii_prijmeni) 
          VALUE ($osoba,'$firma','$jmeno','$prijmeni','$JM','$PR')";
      }
      else {
        $FI= trim(utf2ascii($firma_info,' .'));
        $qry= "INSERT INTO clen (osoba,firma,firma_info,ascii_firma_info) 
          VALUE ($osoba,'$firma','$firma_info','$FI')";
      }
      query($qry);
      $idc= pdo_insert_id();
      $n_clen++;
    }
    // atributy
    $c= $d= array();
    $c['email']= $c['poznamka']= '';
    $d['zpusob']= 0;
    foreach ($flds as $fld=>$desc) {
      if (substr($fld,0,1)=='-') continue;
      list($tab,$cnv)= explode(',',$desc);
      $val= $row[$fld];
      switch($cnv) {
        case 'adr2': 
          $m= null;
          if (!$val) {
            break;
          }
          elseif (preg_match("~^\*~",$val)) {
            display("UPRAVIT:$val");
          }
          elseif (preg_match("~^(.*),([\s\d]+)(.*)$~",$val,$m)) {
            $c['ulice 2']= $m[1];
            $c['psc 2']= str_replace(' ','',$m[2]);
            $c['obec 2']= $m[3];
          }
          break;
        case 'adr': 
          $m= null;
          if (!$val) {
            break;
          }
          elseif (preg_match("~^\*~",$val)) {
            display("UPRAVIT:$val");
          }
          elseif (preg_match("~^(.*),([\s\d]+)(.*)$~",$val,$m)) {
            $c['ulice']= $m[1];
            $c['psc']= str_replace(' ','',$m[2]);
            $c['obec']= $m[3]=='P'?'Polička':$m[3];
          }
          else {
            $c['ulice']= $val;
          }
          break;
        case 'rc': 
          $m= null;
          $val= str_replace('*','',$val);
          if (preg_match("~^\d\d\d\d$~",$val)) {
            $c['narozeni_rok']= $val;
            display("$prijmeni: narozeni_rok:$val");
          }
          elseif (preg_match("~^\d+\.\d+\.\d+$~",$val)) {
            $c['narozeni']= sql_date($val,1);
            display("$prijmeni: narozeni:$val");
          }
          elseif (preg_match("~^t:\s*(\d+)$~",$val,$m)) {
            $c['telefony']= $m[1];
            display("$prijmeni: telefon:$val");
          }
          break;
        case 'ic': 
          $c[$fld]= str_replace(' ','',$val);
          break;
        case 'dn': 
          $d[$fld]= str_replace(' ','',$val);
          break;
        case 'd': 
          if (preg_match("~^\*?\d+\.\d+\.\d+$~",$val)) {
            if ($tab=='C') $c[$fld]= sql_date($val,1); 
            elseif ($tab=='D') $d[$fld]= sql_date($val,1); 
          }
          break;
        case 'dv': 
          if (preg_match("~věcný~",$val)) {
            $d['zpusob']= 4; 
          }
          elseif (preg_match("~^\d+\.\d+\.\d+$~",$val)) {
            $d[$fld]= sql_date($val,1); 
          }
          break;
        case 'z': 
          $d['zpusob']= $val=='na účet' ? 2 : ($val=='v hotovosti' ? 1 : 0); 
          break;
        case 'ep': 
          if (strchr($val,'@')) $c['email']= $val; elseif ($tab=='D') $c['poznamka']= $val; 
          break;
        case 'p': 
          $c['poznamka']= ($poznamka ? "$poznamka, " : '').$val; 
          break;
        default: 
          if ($tab=='C') $c[$fld]= $val; else $d[$fld]= $val; 
          break;
      }
    }
    // přidání atributů do clen
    $attr= array();
    foreach ($c as $fld=>$val) {
      if ($fld=='rodcis') {
        $attr[]= "$fld=IF($fld='0000-00-00',$val'";
      }
      elseif ($val)
        $attr[]= "$fld='$val'";
    }
    if (count($attr))
      query("UPDATE clen SET ".implode(',',$attr)." WHERE id_clen=$idc");
    // vygenerování oslovení pro fyzické osoby
    if ($osoba==1)
      osl_update($idc);
    // vytvoření dar
    $attr= array();
    $d['id_clen']= $idc;
    foreach ($d as $fld=>$val) {
      $attr[]= "$fld='$val'";
    }
    if (isset($d['castka']) && $d['castka']) {
      query("INSERT INTO dar SET typ=9,".implode(',',$attr));
      $n_dar++;
    }
  }
  return "Bylo vloženo $n_clen lidí a $n_dar darů";
}
# ------------------------------------------------------------------------------------ aby csv2array
# načtení CSV-souboru do asociativního pole, při chybě navrací chybovou zprávu
# obsahuje speciální kód pro soubory kódované UTF-16LE
function aby_csv2array($fpath,&$data,$max=0,$encoding='UTF-8') { trace();
  $msg= '';
//  display($fpath); fopen("C:/Ezer/beans/aby/doc/import/kontakty darci_2021.csv",'r');
  $f= $encoding=='UTF-16LE' ? fopen_utf8($fpath, "r") : @fopen($fpath, "r");
  if ( !$f ) { $msg.= "soubor $fpath nelze otevřít"; goto end; }
  // načteme hlavičku
  $s= fgets($f, 5000);
  $bom= pack('H*','EFBBBF');
  $s= preg_replace("/^$bom/", '', $s);
  if ($encoding!='UTF-8' && $encoding!='UTF-16LE') {
    if ($encoding=='CP1250')
      $s= win2utf($s,1);
    else 
      $s= mb_convert_encoding($s, "UTF-8", $encoding);
  }
  // diskuse oddělovače
  $del= strstr($s,';') ? ';' : (strstr($s,',') ? ',' : '');
  if ( !$del ) { $msg.= "v souboru $fpath jsou nestandardní oddělovače"; goto end; }
  $head= str_getcsv($s,$del);
  $n= 0;
  while (($s= fgets($f, 5000)) !== false) {
    if ($encoding!='UTF-8' && $encoding!='UTF-16LE') {
      if ($encoding=='CP1250')
        $s= win2utf($s,1);
      else 
        $s= mb_convert_encoding($s, "UTF-8", $encoding);
    }
//    display("$n:$s");
    $d= str_getcsv($s,$del);
    foreach ($d as $i=>$val) {
      $data[$n][$head[$i]]= $val;
    }
    $n++;
    if ($max && $n>=$max) break;
  }
end:
  return $msg;
}
# http://www.practicalweb.co.uk/blog/2008/05/18/reading-a-unicode-excel-file-in-php/
function fopen_utf8($filename){ trace();
  $encoding= '';
  $handle= @fopen($filename, 'r');
  $bom= fread($handle, 2);
  rewind($handle);
  if($bom === chr(0xff).chr(0xfe)  || $bom === chr(0xfe).chr(0xff)){
    // UTF16 Byte Order Mark present
    $encoding= 'UTF-16';
  } 
  else {
    $file_sample= fread($handle, 1000) + 'e'; //read first 1000 bytes
    // + e is a workaround for mb_string bug
    rewind($handle);
    $encoding= mb_detect_encoding($file_sample , 'UTF-8, UTF-7, ASCII, EUC-JP,SJIS, eucJP-win, SJIS-win, JIS, ISO-2022-JP');
  }
  if ($encoding){
    stream_filter_append($handle, 'convert.iconv.'.$encoding.'/UTF-8');
  }
  return $handle;
} 
/** ===========================================================================================> GIT */
# ----------------------------------------------------------------------------------------- git make
# provede git par.cmd>.git.log a zobrazí jej
# fetch pro lokální tj. vývojový server nepovolujeme
function git_make($par) {
  global $abs_root;
  $bean= preg_match('/bean/',$_SERVER['SERVER_NAME'])?1:0;
                                                    display("bean=$bean");
  $cmd= $par->cmd;
  $folder= $par->folder;
  $lines= '';
  $msg= "";
  // proveď operaci
  switch ($par->op) {
  case 'cmd':
    if ( $cmd=='fetch' && $bean) {
      $msg= "na vývojových serverech (*.bean) příkaz fetch není povolen ";
      break;
    }
    $state= 0;
    // zruš starý obsah .git.log
    $f= @fopen("$abs_root/docs/.git.log", "r+");
    if ($f !== false) {
        ftruncate($f, 0);
        fclose($f);
    }
    if ( $folder=='ezer') chdir("../_ezer3.1");
    elseif ( $folder=='skins') { chdir($abs_root); chdir("../_skins"); }
    $exec= "git $cmd>$abs_root/docs/.git.log";
    exec($exec,$lines,$state);
                            display("$state::$exec");
    // po fetch ještě nastav shodu s github
    if ( $cmd=='fetch') {
      $msg.= "$state:$exec\n";
      $cmd= "reset --hard origin/".($folder=='ezer'?'ezer3.1':'master');
      $exec= "git $cmd>$abs_root/docs/.git.log";
      exec($exec,$lines,$state);
                            display("$state::$exec");
    }
    if ( $folder=='ezer' || $folder=='skins') chdir($abs_root);
    $msg.= "$state:$exec\n";
  case 'show':
    $msg.= file_get_contents("$abs_root/docs/.git.log");
    break;
  }
  $msg= nl2br(htmlentities($msg));
  $msg= "<i>Synology: musí být spuštěný Git Server (po aktualizaci se vypíná)</i><hr>$msg";
  return $msg;
}
/** ================================================================================================ NASTAVENÍ */
# -------------------------------------------------------------------------------------------------- sys_errata
function sys_errata() {
  $html= '';
  // 1
  $n= $k= 0;
  $sro_as= 's\\.[ ]*r\\.[ ]*o\\.|a\\.[ ]*s\\.|spol\\.[ ]*s[ ]*r\\.o.';
  $qry= "SELECT id_clen, osoba, firma, jmeno, prijmeni FROM clen
         WHERE prijmeni REGEXP '$sro_as'";
  $res= pdo_qry($qry);
  while ( $res && ($u= pdo_fetch_object($res)) ) {
    $prijmeni= pdo_real_escape_string($u->prijmeni);
    $firma= pdo_real_escape_string($u->firma);
    $qryu= "UPDATE clen SET osoba=0,firma='$prijmeni',prijmeni='$firma'
            WHERE id_clen={$u->id_clen}";
    $resu= pdo_qry($qryu);
    $k+= pdo_affected_rows();
    $n++;
  }
  $html.= "<br>$k změn typu firma=prijmeni, prijmeni=firma  ($n řádků)";
  // 2
  $n= $k= 0;
  $qry= "SELECT id_clen, osoba, firma, jmeno, prijmeni FROM clen
         WHERE jmeno REGEXP '$sro_as'";
  $res= pdo_qry($qry);
  while ( $res && ($u= pdo_fetch_object($res)) ) {
    $x= pdo_real_escape_string(str_replace('  ',' ',$u->prijmeni.' '.$u->jmeno));
    $firma= pdo_real_escape_string($u->firma);
    $qryu= "UPDATE clen SET osoba=0,firma='$x',prijmeni='$firma',jmeno=''
            WHERE id_clen={$u->id_clen}";
    $resu= pdo_qry($qryu);
    $k+= pdo_affected_rows();
    $n++;
  }
  $html.= "<br>$k změn typu firma=prijmeni+jmeno, prijmeni=firma  ($n řádků)";
  return $html;
}
# -------------------------------------------------------------------------------------------------- ezer_get_temp_dir
# (nepoužitá) funkce definující pro balík PDF pracovní složku
function ezer_get_temp_dir() {
  global $ezer_path_root;
  return "$ezer_path_root/tmp";
}
# -------------------------------------------------------------------------------------------------- sys_backup_make
/*
# BACKUP: uloží obrazy databází do příslušných složek
# parametry
#   listing  - přehled existujících záloh
#   kaskada  - uložení dnešní zálohy, (je-li pondělí přesun poslední pondělní do jeho týdne)
#              -- days:  dny v týdnu
#              -- weeks: pondělky týdnů roku
#   special  - uložení okamžité zálohy do složky special
#   kontrola - kontrola existence dnešní zálohy
function sys_backup_make($par) {  trace();
  global $path_backup, $ezer_root;
  $html= '';
  $sign= date("Ymd_Hi");
  switch ($par->typ) {
  case 'listing':
  case 'restore':
    $html= "<h2>Zálohy v $path_backup/</h2>";
    // denní zálohy
    $html.= "<h3>Denní zálohy</h3><dl>";
    foreach (glob("$path_backup/days/*",GLOB_ONLYDIR) as $dir_d) {
      $files= glob("$dir_d/*");
      $html.= "<dt>".substr($dir_d,1+strlen($path_backup))."/</dt>";
      foreach($files as $file) {
        $ref= $par->typ=='restore'
          ? "<a target='back' href='zaloha.php?root=$ezer_root&restore="
              .substr($file,1+strlen($path_backup))."'>".substr($file,1+strlen($dir_d))."</a>"
          : substr($file,1+strlen($dir_d));
        $html.= "<dd>$ref</dd>";
      }
    }
    $html.= "</dl>";
    // týdenní zálohy
    $html.= "<h3>týdenní zálohy</h3><dl>";
    foreach (glob("$path_backup/weeks/*",GLOB_ONLYDIR) as $dir_d) {
      $files= glob("$dir_d/*");
      $html.= "<dt>".substr($dir_d,1+strlen($path_backup))."/</dt>";
      foreach($files as $file) {
        $ref= $par->typ=='restore'
          ? "<a target='back' href='zaloha.php?root=$ezer_root&restore="
              .substr($file,1+strlen($path_backup))."'>".substr($file,1+strlen($dir_d))."</a>"
          : substr($file,1+strlen($dir_d));
        $html.= "<dd>$ref</dd>";
      }
    }
    $html.= "</dl>";
    // speciální zálohy
    $html.= "<h3>speciální zálohy</h3><dl>";
    $dir_d= "$path_backup/special";
    $files= glob("$dir_d/*");
    $html.= "<dt>".substr($dir_d,1+strlen($path_backup))."/</dt>";
    foreach($files as $file) {
      $ref= $par->typ=='restore'
        ? "<a target='back' href='zaloha.php?root=$ezer_root&restore="
            .substr($file,1+strlen($path_backup))."'>".substr($file,1+strlen($dir_d))."</a>"
        : substr($file,1+strlen($dir_d));
      $html.= "<dd>$ref</dd>";
    }
    $html.= "</dl>";
    break;
//   case 'listing':
//     $html= "<h2>Zálohy v $path_backup/</h2>";
//     // denní zálohy
//     $html.= "<h3>Denní zálohy</h3><dl>";
//     foreach (glob("$path_backup/days/*",GLOB_ONLYDIR) as $dir_d) {
//       $files= glob("$dir_d/*");
//       $html.= "<dt>".substr($dir_d,1+strlen($path_backup))."/</dt>";
//       foreach($files as $file) {
//         $html.= "<dd>".substr($file,1+strlen($dir_d))."</dd>";
//       }
//     }
//     $html.= "</dl>";
//     // týdenní zálohy
//     $html.= "<h3>týdenní zálohy</h3><dl>";
//     foreach (glob("$path_backup/weeks/*",GLOB_ONLYDIR) as $dir_d) {
//       $files= glob("$dir_d/*");
//       $html.= "<dt>".substr($dir_d,1+strlen($path_backup))."/</dt>";
//       foreach($files as $file) {
//         $html.= "<dd>".substr($file,1+strlen($dir_d))."</dd>";
//       }
//     }
//     $html.= "</dl>";
//     break;
  case 'special':
    $path= "$path_backup/special";
    sys_backup_into($path,$sign);
    $html.= "<br>vytvořena záloha do 'special'";
    break;
  case 'kontrola':
    $d= date('N');                                              // dnešní den v týdnu (pondělí=1)
    sys_backup_test("$path_backup/days/$d",date("Ymd_*"),&$backs,$ok);
                                        display("$ok:$backs");
    $html.= $backs && $ok ? "Dnešní zálohy byly vytvořeny v pořádku"
      : ($backs ? "Některé":"Žádné") . " dnešní zálohy nebyly vytvořeny";
    $html.= $backs ? "<br/><br/>Databáze jsou uloženy takto: <dl>$backs</dl>" : "";
    break;
  case 'kaskada':
    $d= date('N');                                              // dnešní den v týdnu (pondělí=1)
    // kontrola existence záloh - aby nedošlo k přepsání
    sys_backup_test("$path_backup/days/$d",date("Ymd_*"),&$backs,$ok);
    if ( $ok ) {
      $html.= "<br>dnešní zálohy již v 'days/$d' existují";
    }
    else {
      // kontrola existence denní složky
      if ( !file_exists("$path_backup/days/$d") ) mkdir("$path_backup/days/$d");
      // pondělní přesun
      if ( $d==1 ) {
        // zjisti minulý týden
        $prev= mktime(0, 0, 0, date("m")  , date("d")-7, date("Y"));
        $w= date("W",$prev) + 1;                                 // minulý týden od počátku roku
        // kontrola existence týdenní složky
        if ( !file_exists("$path_backup/weeks/$w") ) mkdir("$path_backup/weeks/$w");
        // zkopíruj předchozí pondělí do jeho týdne
        sys_backup_delete("$path_backup/weeks/$w");
        $html.= sys_backup_move("$path_backup/days/$d","$path_backup/weeks/$w");
        $html.= "<br>přesunuty poslední pondělní zálohy do 'weeks/$w'";
      }
      // nahraď den novou zálohou
//       sys_backup_delete("$path_backup/days/$d");
      $dbs= sys_backup_into("$path_backup/days/$d",$sign);
      $html.= "<br>vytvořena záloha pro $dbs do 'days/$d'";
    }
    break;
  }
  return $html;
}
# -------------------------------------------------------------------------- sys_backup_test
# BACKUP: test vytvoření zálohy databází do dané složky
function sys_backup_test($into,$sign,&$backs,&$ok) {   trace();
  global $ezer_db, $ezer_pdo_path;
  $backs= '';
  $ok= true;
  foreach ( $ezer_db as $db_id=>$db_desc ) {
    list($n,$host,$user,$pasw,$lang,$db_name)= $db_desc;
    if ( !isset($ezer_db[$db_name]) ) {
      $name= $db_name ? $db_name : $db_id;
      $files= glob("$into/{$name}_$sign.sql");
      $je= count($files)>0;
      $backs.= "<dt>databáze $name</dt><dd>";
      $backs.= $je ? implode(' ',$files) : "!!! chybí";
      $ok&= $je;
      $backs.= "</dd>";
                                        debug($files,"$je");
    }
  }
}
# -------------------------------------------------------------------------- sys_backup_into
# BACKUP: vytvoření zálohy databází do dané složky
function sys_backup_into($into,$sign) {   trace();
  global $ezer_db, $ezer_pdo_path;
  $dbs= '';
  foreach ( $ezer_db as $db_id=>$db_desc ) {
    list($n,$host,$user,$pasw,$lang,$db_name)= $db_desc;
    if ( !isset($ezer_db[$db_name]) ) {
      $name= $db_name ? $db_name : $db_id;
                                                debug($db_desc,$db_id);
      $cmd= "$ezer_pdo_path/mysqldump --opt -h $host ";
      $cmd.= "-u $user --password=$pasw $name ";
      $cmd.= "> $into/{$name}_$sign.sql";
                                                display($cmd);
      $status= system($cmd);
      $dbs.= " $name/$status";
    }
  }
  return $dbs;
}
# -------------------------------------------------------------------------- sys_backup_move
# BACKUP: přesuň jednu složku do druhé
function sys_backup_move($srcDir,$destDir) {   trace();
  $err= '';
  $html= '';
  if ( file_exists($destDir) && is_dir($destDir) && is_writable($destDir) ) {
    if ($handle= opendir($srcDir)) {
      while (false !== ($file= readdir($handle))) {
        if (is_file("$srcDir/$file")) {
          $ok= @rename("$srcDir/$file","$destDir/$file");
          if ( !$ok ) {
            $html.= "<br> -- nebylo možné přesunout $file do $destDir - zkusíme aspoň kopírovat";
            try {
              $ok= @copy("$srcDir/$file","$destDir/$file");
            }
            catch (Exception $e) {
              $ok= false;
              $msg= $e->getMessage();
            }
            if ( !$ok ) {
              $html.= "<br> -- nebylo možné ani zkopírovat $file do $destDir ($msg)";
            }
          }
        }
      }
      closedir($handle);
    }
    else $err= "in sys_backup_move: $srcDir/src";
  }
  else $err= "in sys_backup_move: $destDir/dst";
  if ( $err ) fce_error($err);
  return $html;
}
# -------------------------------------------------------------------------- sys_backup_delete
# BACKUP: vymazání obsahu složky
function sys_backup_delete($dir) { trace();
  $ok= true;
  if ($handle= opendir($dir)) {
    while (false !== ($file= readdir($handle))) {
      if (is_file("$dir/$file")) {
        $ok&= @unlink("$dir/$file");
      }
    }
    closedir($handle);
  }
  if ( !$ok ) fce_error("sys_backup_delete: $dir");
}
*/
//# -------------------------------------------------------------------------------------------------- sys_vs_excel
//# ASK
//# export variabilních symbolů do Excelu
//function sys_vs_excel() {
//  $result= (object)array('_err'=>'','_html'=>'');
//  $xvs= array();
//  $map_str= map_cis('stredisko','hodnota');
//  $qryc= "SELECT * FROM _cis WHERE druh='varsym' ORDER BY ikona,data";
//  $resc= pdo_qry($qryc);
//  while ( $resc && $c= pdo_fetch_object($resc) ) {
//    $hodnota= strtr($c->hodnota,array("\n"=>' ',"|"=>'/',"::"=>": "));
//    $xvs[$c->ikona][]= (object)array('dar'=>$c->zkratka?'dar':'platba','vs'=>$c->data,'nazev'=>$hodnota);
//  }
////                                                         debug($xs);
//  // export tabulky
//  global $ezer_root;
//  $title= "Variabilní symboly ke dni ".date("j. n. Y");
//  $file= "vs";
//  $xls= "open $file|sheet varsym;;P;page\n";
//  $xls.= "|columns B=6,C=7,D=90";
//  $r= 2;
//  $xls.= "\n|B$r $title ::size=12|B$r:D$r bold merge center";
//  $r++;
//  $xls.= "\n|B$r (vygenerované z aplikace Ezer, karta Nastavení/Střediska a účty)|B$r:D$r italic merge center";
//  $r++;
//  foreach ($xvs as $s=>$xv) {
//    $r+= 2;
//    $xls.= "\n|B$r {$map_str[$s]}::bcolor=ff8f2c2c color=ffffffff|B$r:D$r bold merge center border=t";
//    foreach ($xv as $x) {
//      $r++;
//      $xls.= "\n|B$r {$x->dar}::border=t center middle|C$r {$x->vs}::border=t center middle
//                |D$r {$x->nazev}::border=t wrap";
//    }
//  }
//  $xls.= "\n|close";
//  if ( $file ) {
////                                                      display($xls);
//    $result->_err= Excel2007($xls,1);
//    if ( !$result->_err ) {
//      $result->_html= "<a target='dopis' href='docs/$file.xlsx'>soubor ke stažení</a>";
//    }
//  }
//  return $result;
//}
//# -------------------------------------------------------------------------------------------------- sys_regenerate_titul
//# Obnoví položku CLENI.title textem ('','Vážený pan','Vážená paní') podle CLENI.rod u fyzických osob
//# resp. ji vymaže u právnických osob.
//# Tato změna se dotkne pouze kontaktů, jejichž titul je ('','Vážený pan','Vážená paní')
//function sys_regenerate_titul ($cond=1,$update=false) {     trace();
//  $n= 0;
//  $qry= "SELECT id_clen,rod,osoba,titul,prijmeni FROM clen
//         WHERE LEFT(deleted,1)!='D' AND neposilat=0
//           AND titul IN ('','Vážený pan','Vážená paní') AND $cond
//         ORDER BY osoba,rod";
//  $res= pdo_qry($qry);
//  while ( $res && ($o= pdo_fetch_object($res)) ) {
//    $osloveni= $o->osoba==1
//      ? ($o->rod==1 ? "Vážený pan" : ($o->rod==2 ? "Vážená paní" : ''))
//      : '';
//    if ( $o->titul!=$osloveni ) {
//      $osoba= $o->osoba==1 ? 'f' : 'p';
//      $rod= $o->rod==1 ? 'm' : ($o->rod==2 ? 'ž' : '-');
//      $id= "<b><a href='ezer://klu.cle.show_clen/{$o->id_clen}'>{$o->id_clen}</a></b>";
//      $txt.= "<br>$osoba$rod $id : {$o->titul} / $osloveni - {$o->prijmeni}";
//      if ( $update ) {
//        $qry2= "UPDATE clen SET titul='$osloveni' WHERE id_clen={$o->id_clen}";
//        $res2= pdo_qry($qry2);
//      }
//      $n++;
//    }
//  }
//  $txt= $update
//   ? "Bylo obnoveno $n automatických obsahů položky titul".$txt
//   : "Budou změněny těchto $n automatických obsahů položky titul
//      (první 2 písmena označují osobu a rod)".$txt;
//  return $txt;
//}
//# -------------------------------------------------------------------------------------------------- sys_copy_osloveni
//# naplní tabulku CLENI údaji z tabulky OSLOVENI
//function sys_copy_osloveni ($limit=20000) {     trace();
//  $n= 0;
//  $qry= "SELECT c.id_clen as clen,_rod,_osloveni,_prijmeni5p
//         FROM clen AS c
//         LEFT JOIN osloveni AS o ON o.id_clen=c.id_clen
//         WHERE vyjimka=0 AND osloveni=0 AND o._osloveni!=0 AND o._anomalie='' LIMIT $limit ;";
//  $res= pdo_qry($qry);
//  while ( $res && ($o= pdo_fetch_object($res)) ) {
//    $prijmeni5p= pdo_real_escape_string($o->_prijmeni5p);
//    $qry2= "UPDATE clen SET
//            osloveni={$o->_osloveni},rod={$o->_rod},prijmeni5p='$prijmeni5p'
//            WHERE id_clen={$o->clen}";
//    $res2= pdo_qry($qry2);
//    $n++;
//  }
//  $txt= "Bylo vloženo $n oslovení (včetně 5. pádu příjmení, rodu a případné anomálie)";
//  return $txt;
//}
//# -------------------------------------------------------------------------------------------------- sys_trunc_osloveni
//# zruší v CLENI oslovení, pokud není označeno jako výjimka
//function sys_trunc_osloveni () {     trace();
//  $n= 0;
//  $qry= "SELECT c.id_clen as clen FROM clen AS c WHERE vyjimka=0";
//  $res= pdo_qry($qry);
//  while ( $res && ($o= pdo_fetch_object($res)) ) {
//    $qry2= "UPDATE clen SET osloveni=0,rod=0,prijmeni5p='' WHERE id_clen={$o->clen}";
//    $res2= pdo_qry($qry2);
//    $n++;
//  }
//  $txt= "Bylo zrušeno $n oslovení (včetně 5. pádu příjmení a rodu),
//         oslovení označená jako 'corr' byla zachována";
//  return $txt;
//}
///** ================================================================================================== KLUB & KASA */
# -------------------------------------------------------------------------------------------------- psc
// doplnění mezery do PSČ
function psc ($psc,$user2sql=0) {
  if ( $user2sql )                            // převeď uživatelskou podobu na sql tvar
    $text= str_replace(' ','',$psc);
  else {                                      // převeď sql tvar na uživatelskou podobu (default)
    $psc= str_replace(' ','',$psc);
    $text= substr($psc,0,3).' '.substr($psc,3);
  }
  return $text;
}
//# -------------------------------------------------------------------------------------------------- p_pdenik_dar
//# vložení daru do pokladního deníku z Klub/Cleni
//# org: 1=NF, 2=RP; typ: 1=V, 2=P
// function p_pdenik_dar($org,$datum,$castka,$clen,$darce) {
// //                                                           display("p_pdenik_dar($org,$datum,$castka,$clen,$darce)");
//   $ok= false;
//   // nalezení nového čísla dokladu (v každé pokladně se zvlášť číslují příjmy a výdaje)
//   $org_abbr= $org==1 ? 'N' : ($org==2 ? 'R' : '?');
//   $typ=2;
//   $year= substr($datum,0,4);
//   $qry= "SELECT max(cislo) as c FROM pdenik WHERE org=$org AND typ=$typ AND year(datum)=$year";
//   $res= pdo_qry($qry);
//   if ( $res && $row= pdo_fetch_assoc($res) ) {
//     $cislo= 1+$row['c'];
//   }
//   // nalezeni člena
//   $qry= "SELECT * FROM clen WHERE id_clen=$clen";
//   $res= pdo_qry($qry,1);
//   $c= pdo_fetch_object($res);
//   if ( $cislo && $c ) {
//     // vytvoření dokladu
//     $komu= $darce ? $darce : "{$c->prijmeni} {$c->jmeno}";
//     $komu.= ", {$c->obec}, {$c->ulice} ($clen)";
//     $s= "komu='".pdo_real_escape_string($komu)."',castka=$castka,datum='$datum',ucel='dar'";
//     $s.= ",priloh=0,vytisten=0,kat='+d'";
//     $ident= $org_abbr.($typ==1?'V':'P').substr($year,2,2).'_'.str_pad($cislo,5,'0',STR_PAD_LEFT);
//     $qry= "INSERT INTO pdenik SET $s,org=$org,typ=$typ,cislo=$cislo,ident='$ident'";
// //                                                         display($qry);
//     $res= pdo_qry($qry);
//     if ( $res && pdo_affected_rows()==1 ) $ok= true;
//   }
//   $html= $ok ? "byl vložen doklad $ident" : "vložení selhalo";
//   return $html;
// }
/** ================================================================================================ OSLOVENÍ */
# -------------------------------------------------------------------------------------------------- osl_insert
# ASK
# vygeneruje rod,osloveni,prijmeni5p do tabulky CLEN
function osl_update($id_clen) {
  $qry= "SELECT id_clen,osoba,c.jmeno,prijmeni,titul,rod,n.sex,anomalie,osloveni,prijmeni5p,vyjimka
         FROM clen AS c LEFT JOIN _jmena AS n ON c.jmeno=n.jmeno
         WHERE id_clen=$id_clen AND vyjimka!=801 ";
  $res= pdo_qry($qry);
  if ( $res && ($x= pdo_fetch_object($res)) ) {
    osl_kontakt($rod,$typ,$ano,$x->osoba,$x->titul,$x->jmeno,$x->prijmeni,$x->sex);
    $prijmeni5p= osl_prijmeni5p($x->titul,$x->prijmeni,$rod,$ano);
    $prijmeni5p= pdo_real_escape_string($prijmeni5p);
    $osloveni= osl_osloveni($rod,$typ);
    $r= $rod=='m' ? 1 : ( $rod=='f' ? 2 : 0);
    $qr1= "UPDATE clen SET rod=$r,osloveni='$osloveni',prijmeni5p='$prijmeni5p'
           WHERE id_clen=$id_clen ";
    $re1= pdo_qry($qr1);
  }
  return 1;
}
# -------------------------------------------------------------------------------------------------- osl_insert
# ASK
# vygeneruje rod,osloveni,prijmeni5p do tabulky CLEN
function osl_insert($osoba,$titul,$jmeno,$prijmeni) {
  $result= (object)array();
  $qry= "SELECT jmeno,sex FROM _jmena WHERE jmeno='$jmeno' ORDER BY cetnost DESC LIMIT 1";
  $res= pdo_qry($qry);
  $sex= 0;
  if ( $res && pdo_num_rows($res) ) {
    $s= pdo_fetch_object($res);
    $sex= $s->sex;
  }
  osl_kontakt($rod,$typ,$ano,$osoba,$titul,$jmeno,$prijmeni,$sex);
  $result->prijmeni5p= $prijmeni ? osl_prijmeni5p($titul,$prijmeni,$rod,$ano) : '';
  $result->osloveni= osl_osloveni($rod,$typ);
  $result->rod= $rod=='m' ? 1 : ( $rod=='f' ? 2 : 0);
//                                         debug($result,"osl_insert($osoba,$titul,$jmeno,$prijmeni)");
  return $result;
}
# -------------------------------------------------------------------------------------------------- osl_kontakt_new
# ASK
# vygeneruje rod,osloveni,prijmeni5p do tabulky OSLOVENI
function osl_kontakt_new ($op,$ids='',$limit=25000) { trace();
  $msg= '';
  switch ( $op ) {
  case 'start':                                 // smazání verze
    $qry= "TRUNCATE osloveni";
    $res= pdo_qry($qry);
    $msg= "všechna nová oslovení byla vymazána";
    break;
  case 'cont':                                  // opakovaný výpočet po smazání
    $qry= "SELECT max(id_clen) as konec FROM osloveni ";
    $res= pdo_qry($qry);
    $konec= ($res && ($o= pdo_fetch_object($res)) && $o->konec) ? $o->konec : 0;
//                                                 display("konec=$konec");
    $qry= "SELECT id_clen,osoba,c.jmeno,prijmeni,titul,rod,n.sex,anomalie,osloveni,prijmeni5p,vyjimka
           FROM clen AS c LEFT JOIN _jmena AS n ON c.jmeno=n.jmeno
           WHERE id_clen>$konec AND vyjimka!=801 /*AND psc!='' AND psc!=0*/ and left(c.deleted,1)!='D'
           and umrti=0 AND neposilat=0
           GROUP BY id_clen
           ORDER BY id_clen LIMIT $limit";
    $res= pdo_qry($qry);
    $n= 0;
    while ( $res && ($x= pdo_fetch_object($res)) ) {
      $n++;
      osl_kontakt($rod,$typ,$ano,$x->osoba,$x->titul,$x->jmeno,$x->prijmeni,$x->sex);
      $prijmeni5p= $x->prijmeni ? osl_prijmeni5p($x->titul,$x->prijmeni,$rod,$ano) : '';
//                                                 display("{$x->prijmeni} -> $prijmeni5p");
      $prijmeni5p= pdo_real_escape_string($prijmeni5p);
      $osloveni= osl_osloveni($rod,$typ);
      $r= $rod=='m' ? 1 : ( $rod=='f' ? 2 : 0);
      $qry1= "INSERT INTO osloveni (id_clen,_rod,_osloveni,_prijmeni5p,_anomalie) VALUE ";
      $qry1.= "($x->id_clen,$r,'$osloveni','$prijmeni5p','$ano')";
      $res1= pdo_qry($qry1);
    }
    $msg= "bylo vygenerováno $n oslovení";
    break;
  case 'replace':                               // náhrada vybraných hodnot ve CLEN
    $n= 0;
    $qry= "SELECT * FROM osloveni WHERE FIND_IN_SET(id_clen,'$ids')";
    $res= pdo_qry($qry);
    while ( $res && ($o= pdo_fetch_object($res)) ) {
      $n++;
      $prijmeni5p= pdo_real_escape_string($o->_prijmeni5p);
      $qr1= "UPDATE clen SET rod={$o->_rod},osloveni='{$o->_osloveni}',prijmeni5p='$prijmeni5p'
             WHERE id_clen={$o->id_clen} ";
      $re1= pdo_qry($qr1);
    }
    $msg= "bylo opraveno $n oslovení";
    break;
  case 'problem':                               // označení jako problematické oslovení
    $n= 0;
    $qry= "UPDATE clen SET vyjimka=802 WHERE FIND_IN_SET(id_clen,'$ids')";
    $res= pdo_qry($qry);
    $msg= pdo_affected_rows()." oslovení bylo označeno jako problematické";
    break;
  case 'update':                                // přepočet vybraných v OSLOVENI (po změně algoritmu)
    $n= 0;
    $qry= "SELECT id_clen,osoba,titul,c.jmeno,prijmeni,n.sex
           FROM clen AS c LEFT JOIN _jmena AS n ON c.jmeno=n.jmeno WHERE FIND_IN_SET(id_clen,'$ids')";
    $res= pdo_qry($qry);
    while ( $res && ($x= pdo_fetch_object($res)) ) {
      $n++;
      osl_kontakt($rod,$typ,$ano,$x->osoba,$x->titul,$x->jmeno,$x->prijmeni,$x->sex);
      $prijmeni5p= osl_prijmeni5p($x->titul,$x->prijmeni,$rod,$ano);
      $prijmeni5p= pdo_real_escape_string($prijmeni5p);
      $osloveni= osl_osloveni($rod,$typ);
      $r= $rod=='m' ? 1 : ( $rod=='f' ? 2 : 0);
      $qry1= "REPLACE osloveni (id_clen,_rod,_osloveni,_prijmeni5p,_anomalie) VALUE ";
      $qry1.= "($x->id_clen,$r,'$osloveni','$prijmeni5p','$ano')";
      $res1= pdo_qry($qry1);
    }
    $msg= "byl opraven návrh $n oslovení";
    break;
  case 'ova':                                   // oprava vybraných koncovek -ova na -ová
    $qry= "UPDATE clen SET prijmeni=concat(left(trim(prijmeni),CHAR_LENGTH(trim(prijmeni))-1),'á')
           WHERE right(trim(prijmeni),3)='ova' AND FIND_IN_SET(id_clen,'$ids')";
    $res= pdo_qry($qry);
    $msg= "bylo opraveno ".pdo_affected_rows()." ova na ová, ";
    $msg.= osl_kontakt_new ('update',$ids);
    break;
  case 'rodina':                                // přepis Rodina ze jmena do titulu
    $qry= "UPDATE clen SET titul='Rodina',jmeno='' WHERE jmeno='Rodina'";
    $res= pdo_qry($qry);
    $msg= "bylo opraveno ".pdo_affected_rows()." kontaktů";
    break;
  }
  return $msg;
}
# -------------------------------------------------------------------------------------------------- osl_oslovení
// generování oslovení
function osl_osloveni ($rod,$typ) {
  $oslo= 0;
  switch ( $typ ) {
  case 'p':  $oslo= 3; break;
  case 's':  $oslo= 4; break;
  case 'ss': $oslo= 5; break;
  case 'l':  $oslo= $rod=='f' ? 2 : ( $rod=='m' ? 1 : 0 ); break;
  case 'll': $oslo= 6; break;
  }
  return $oslo;
}
# -------------------------------------------------------------------------------------------------- osl_prijmeni5p
// generování 5. pádu z $prijmeni,$rod
function osl_prijmeni5p ($titul,$prijmeni,$rod,&$ano) {  
  $y= '';
  // odříznutí přílepků za jménem (po mezeře nebo čárce)
  $p= trim($prijmeni);
  $ic= strpos($p,',');
  $is= strpos($p,' ');
  if ( $ic || $is ) {
    $i= min($ic?$ic:9999,$is?$is:9999);
    $p= substr($p,0,$i);
  }
  // vlastní algoritmus
  $len= mb_strlen($p,'UTF-8');
  $p1= mb_substr($p,0,-1,'UTF-8'); $p_1= mb_substr($p,-1,1,'UTF-8');
  $p2= mb_substr($p,0,-2,'UTF-8'); $p_2= mb_substr($p,-2,2,'UTF-8');
  $p3= mb_substr($p,0,-3,'UTF-8'); $p_3= mb_substr($p,-3,3,'UTF-8');
  // specifické případy
  if ( trim($titul)=='Rodina' && $p_3=='ova' ) $y= $p3.'ovi';
  else {
    // obecné případy
    switch ( $rod ) {
    case 'm':
      if ( mb_strpos(' eěíýoůú',$p_1,0,'UTF-8') ) $y= $p;
      // změny
      else if ( mb_strpos(' a',$p_1,0,'UTF-8') ) $y= $p1.'o';
      else if ( mb_strpos(' ek',$p_2,0,'UTF-8') ) $y= $p2.'ku';
      else if ( mb_strpos(' el',$p_2,0,'UTF-8') ) $y= $p2.'le';
      else if ( mb_strpos(' ec',$p_2,0,'UTF-8') ) $y= $p2.'če';
      // přidání
      else if ( mb_strpos(' bdflmnprtv',$p_1,0,'UTF-8') ) $y= $p.'e';
      else if ( mb_strpos(' ghjk',$p_1,0,'UTF-8') ) $y= $p.'u';
      else if ( mb_strpos(' cčsšřzž',$p_1,0,'UTF-8') ) $y= $p.'i';
      else if ( $p_1=='ň' ) $y= $p1.'ni';
      break;
    case 'f':
      if ( $p_3=='ova' || $p_1=='á' || $p_1=='ů' || $p_1=='í' ) $y= $p;
      break;
    case 'mf':
      if ( $p_3=='ovi' ) $y= $p;
      break;
    }
  }
  if ( $y) $ano= '';
//                                                 display("osl_prijmeni5p($p,$rod)=$y ($len:$p1,$p_1,$p2,$p_2,$p3,$p_3:$p)");
  return $y;
}
# -------------------------------------------------------------------------------------------------- osl_kontakt
# rozeznání kategorie člena - kvůli oslovení (vstupem db hodnoty $osoba,$titul,$jmeno,$prijmeni,$sex)
# rod: ?|m|f|mm|ff|mf
# typ: l|ll|s|ss|p
# ano: [o] [f] [r] [a]
#   // o - chybí právnická/fyzická => ručně
    // f - právnická osoba má křestní jméno => fyzická osoba
    // r - rod křestního jména a tvaru příjmení se liší => ručně
    // a - ženské křestní jméno a koncovka -ova => -ová

function osl_kontakt (&$rod,&$typ,&$ano,$osoba,$titul,$jmeno,$prijmeni,$sex) {
  $osoba= $osoba==1 ? 'f' : ( $osoba==0 ? 'p' : '?');
  $sex= $sex==1 ? 'm' : ( $sex==2 ? 'f' : '?');
  $rod= $typ= '?';
  if ( !strcasecmp(mb_substr($titul,0,2,'UTF-8'),'P.') || stristr($titul,'Mons.')
    || $prijmeni=='FU' && strstr($jmeno,"P.") ) {
    $rod= 'm'; $typ= 'p';
  }
  else if ( !strcasecmp(mb_substr($titul,0,2,'UTF-8'),'s.')
       || !strcasecmp(mb_substr($jmeno,0,2,'UTF-8'),'s.')  ) {
    $rod= 'f'; $typ= 's';
  }
  else if ( stristr($titul,"rodina") || stristr($titul,"manželé")
    || mb_substr($prijmeni,-3,3,'UTF-8')=='ovi' || strstr($jmeno,' a ') ) {
    $rod= 'mf'; $typ= 'll';
  }
  else if ( stristr($prijmeni,"Sestry") ) {
    $rod= 'ff'; $typ= 'ss';
  }
  else if ( $osoba=='f' && $prijmeni ) {
    $typ= 'l';
    $p_1= mb_substr($prijmeni,-1,1,'UTF-8');
    if ( $sex!='?' ) {
      $rod= $sex; $typ= 'l';
    }
    if ( mb_strstr(' áůí',$p_1,false,'UTF-8') ) {
      $rod= 'f';
    }
    else {
      $rod= 'm';
    }
  }
  // anomálie adres
  $ano= '';
  // o - chybí právnická/fyzická => ručně
  if ( $osoba=='?' ) $ano.= 'o';
  // f - právnická osoba má křestní jméno => fyzická osoba
  if ( $osoba=='p' && $sex!='?' ) $ano.= 'f';
  // r - rod křestního jména a tvaru příjmení se liší => ručně
  if ( $prijmeni && $osoba=='f' && strstr(' mf',$rod) && $rod!=$sex
    && ($sex!='f' || mb_substr($prijmeni,-3,3,'UTF-8')!='ova') ) {
    $ano.= 'r'; if ( $sex!='?' ) $rod= $sex;
  }
  // a - ženské křestní jméno a koncovka -ova => -ová
  if ( $sex=='f' && mb_substr($prijmeni,-3,3,'UTF-8')=='ova' ) {
    $ano.= 'a';
  }
//                                                 display("osl_kontakt ($rod,$typ,$ano,$osoba,$titul,$jmeno,$prijmeni,$sex)");
}
# -------------------------------------------------------------------------------------------------- osl_gen_oprava
// opravy anomálií a informace o jejich počtu
function osl_gen_oprava ($typ) {
  global $row, $suma;
  switch ( $typ ) {
  case '?':             // zjistí počet anomálií
    $qry= "SELECT anomalie,count(*) as c FROM clen WHERE length(anomalie)>0  GROUP BY anomalie";
    $res= pdo_qry($qry);
    while ( $res && ($row= pdo_fetch_assoc($res)) ) {
      $txt.= "{$row['anomalie']}: {$row['c']}x<br>";
    }
    break;
  case 'f':             // kontakty označené jak anomálie 'f' jsou upraveny na fyzické osoby
    $qry= "UPDATE clen SET osoba=100 WHERE LOCATE('f',anomalie)>0 ";
    $res= pdo_qry($qry);
    $num= pdo_affected_rows();
    $txt= "$num kontaktů bylo změněno jako kontakty na fyzické osoby";
    break;
  case 'a':             // kontakty označené jak anomálie 'a' změní koncovku -ova na -ová
    $qry= "UPDATE clen SET prijmeni=concat(LEFT(prijmeni,CHAR_LENGTH(prijmeni)-1),'á')
           WHERE LOCATE('a',anomalie)>0 ";
    $res= pdo_qry($qry);
    $num= pdo_affected_rows();
    $txt= "$num kontaktů bylo změněno: substituce -ova na -ová u žen";
    break;
  }
  return $txt;
}
//
///** ************************************************************************************************ MAPY */
//# ------------------------------------------------------------------------------------------------- okresy_create
//# vytvoří strukturu okresů, s text==abbr
//# <okresy> = array ( abbr => <okres>, ... )
//# <okres> = array ( 'rgb' => 'r,g,b', 'text' => text_v_mapě, 'title' => pod_myší,
//#                  'href' => click,     'xy' => 'x,y' )
//function okresy_create ($cond=1) { trace();
//  // vytvoření okresů
//  $okresy= array();
//  $res= pdo_qry("SELECT abbr,x,y,nazokr FROM okresy WHERE $cond ");
//  while ( $res && $row= pdo_fetch_assoc($res) ) {
//    $abbr= $row['abbr'];
//    $okresy[$abbr]= array ('text'=> $abbr, 'title'=> $row['nazokr']
//      , 'xy' => "{$row['x']},{$row['y']}" );
//  }
//  return $okresy;
//}
//# -------------------------------------------------------------------------------------------------- okresy_show
//# zobrazí mapku okresů se všemi údaji
//# popis struktur <okresy> viz okresy_create a <mapa> viz mapa_html
//#   $img_atr udává atribut v <img ...>
//#   $bgcolor='r,g,b' se uplatní při redukci obrázku jako pozadí
//# method=session|get -- způsob dopravení dat
//function okresy_show ($okresy,$scale=1,$img_atr='',$bgcolor='0,255,255',$method='session') { trace();
//  // transformace okresů na mapu
//  $map= '';
//  $mapa='okresy2';
//  $_SESSION['mapy']['id']= isset($_SESSION['mapy']['id']) ? $_SESSION['mapy']['id']+1 : 1;
//  $id= "okr_{$_SESSION['mapy']['id']}";
//  $xy= array();
//  foreach ( $okresy as $abbr => $desc ) {
//    $xy[$desc['xy']]= array ('rgb'=>$desc['rgb'],'text'=>$desc['text']);
//    if ( $title= $desc['title'] or $desc['href'] ) {
//      list($x,$y)= explode(',',$desc['xy']);
//      if ( $scale!= 1 ) { // uprav pokud je žádáno měřítko
//        $x= round($scale * $x);
//        $y= round($scale * $y);
//      }
//      $href= '';
//      if ( $desc['href'] ) {
//        $href= " href='{$desc['href']}'";
//        if ( !$title ) $title=' ';
//      }
//      $map.= "\n  <area $href shape='circle' title='$title' coords='$x,$y,16'>";
//    }
//  }
//  // zobrazení mapky
//  $text= '';
//  switch ($method) {
//  case 'session':
//    $_SESSION['mapy'][$mapa]['template']= "img/$mapa.png";
//    $_SESSION['mapy'][$mapa]['xy']= $xy;
//    if ( $bgcolor ) $_SESSION['mapy'][$mapa]['bgcolor']= $bgcolor;
//    $_SESSION['mapy'][$mapa]['scale']= $scale;
//    $text.= "\n<map name='$id'>$map\n</map>";
//    $text.= "<img $img_atr src='ch/map/map_png.php?rand=".mt_rand()."&mapa=$mapa' border=0 usemap='#$id'/>";
////                                                 debug($_SESSION['mapy'][$mapa],'mapa');
//    break;
//  case 'get':
//    $_mapa= "img/$mapa.png";
//    if ( $bgcolor ) $_bg= $bgcolor;
//    $_scal= $scale;
//    $_parm= ''; $del= '';
//    foreach($xy as $coord=>$rgbt) {
//      $_parm.= "$del$coord,{$rgbt['rgb']},{$rgbt['text']}";
//      $del= ';';
//    }
//    $text.= "\n<map name='$id'>$map\n</map>";
//    $_url= "mapa=$_mapa&bg=$_bg&scal=$_scale&parm=$_parm&rand=".mt_rand();
//    $text.= "<img $img_atr src='ch/map/map_png2.php?$_url' border=0 usemap='#$id'/>";
////                                                 display($_url);
//    break;
//  }
//  return $text;
//}
