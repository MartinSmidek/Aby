<?php
# Aplikace Aby pro Nadační fond sester františkánek
# (c) 2022 Martin Šmídek <martin@smidek.eu>

  global // import 
    $ezer_root; 
  global // export
    $EZER, $ezer_server, $ezer_version;
  global // klíče
    $api_gmail_user, $api_gmail_pass;
  
  // vyzvednutí ostatních hodnot ze SESSION
  $ezer_server=  $_SESSION[$ezer_root]['ezer_server'];
  $ezer_version= $_SESSION[$ezer_root]['ezer'];
  $abs_root=     $_SESSION[$ezer_root]['abs_root'];
  $rel_root=     $_SESSION[$ezer_root]['rel_root'];
  chdir($abs_root);

//  // rozlišení ostré verze a serveru proglas/aby
//  $ezer_local= preg_match('/^\w+\.bean$/',$_SERVER["SERVER_NAME"])?1:0;
//  $aby= in_array($_SERVER["SERVER_NAME"],array("mail.telepace.cz","192.168.100.7","217.64.3.170"))?1:0;

  // inicializace objektu Ezer
  $EZER= (object)array(
      'version'=>'ezer'.$_SESSION[$ezer_root]['ezer'],
      'options'=>(object)array(
          'mail' => "martin@smidek.eu",
          'phone' => "603&nbsp;150&nbsp;565",
          'author' => "Martin",
      ),
      'activity'=>(object)array('skip'=>'MSM'));
  
  // databáze
  $deep_root= "../files/aby";
  require_once("$deep_root/aby.dbs.php");
  
  // archiv sql
  $path_backup= "$deep_root/sql";
  
  $tracked= ',clen,dar,projekt,ukol,dopis,role,_user,_cis,';
  
  // PHP moduly aplikace Ark
  $app_php= array(
//    "ck/ck.dop.php", ?
    "aby/aby.$.php",
    "aby/aby.klu.php",
    "aby/aby.klu.pre.php",
    "aby/aby.dop.php",
    "aby/aby.eko.php",
    "aby/aby_pdf.php",
    "aby/aby_tcpdf.php"
  );
  
  // PDF knihovny
  require_once('tcpdf/tcpdf.php');

  // stará verze json
  require_once("ezer$ezer_version/server/licensed/JSON_Ezer.php");

  // je to aplikace se startem v rootu
  chdir($_SESSION[$ezer_root]['abs_root']);
  require_once("{$EZER->version}/ezer_ajax.php");

  // specifické cesty
  global $ezer_path_root;

  $path_www= './';
?>
