<?php
# Aplikace Aby pro Nadační fond sester františkánek
# (c) 2022 Martin Smidek <martin@smidek.eu>

/** =======================================================================================> ŠABLONY */
# ------------------------------------------------------------------------------------- dop sab_mail
# přečtení běžného dopisu daného typu
function dop_sab_mail($typ) { trace();
  $d= null;
  try {
    $d= pdo_object("SELECT id_dopis,obsah FROM dopis WHERE typ='$typ' AND id_davka=0 ");
  }
  catch (Exception $e) { display($e); fce_error("dop_sab_mail: mail '$typ' nebyl nalezen"); }
  return $d;
}
# ------------------------------------------------------------------------------------- dop sab_text
# přečtení běžného dopisu daného typu
function dop_sab_text($dopis) { trace();
  $d= null;
  try {
    $qry= "SELECT id_dopis,obsah FROM dopis WHERE vzor='$dopis' ";
    $res= pdo_qry($qry,1,null,1);
    $d= pdo_fetch_object($res);
  }
  catch (Exception $e) { display($e); fce_error("dop_sab_text: průběžný dopis '$dopis' nebyl nalezen"); }
  return $d;
}
# ------------------------------------------------------------------------------------- dop sab_cast
# přečtení části šablony
function dop_sab_cast($druh,$cast) { trace();
  $d= null;
  try {
    $qry= "SELECT id_dopis_cast,obsah FROM dopis_cast WHERE druh='$druh' AND name='$cast' ";
    $res= pdo_qry($qry,1,null,1);
    $d= pdo_fetch_object($res);
  }
  catch (Exception $e) { display($e); fce_error("dop_sab_cast: část '$cast' sablony nebyla nalezena"); }
  return $d;
}
# ----------------------------------------------------------------------------------- dop sab_nahled
# ukázka šablony
function dop_sab_nahled($druh) { trace();
  global $ezer_path_docs;
  $html= '';
  $fname= "sablona.pdf";
  $f_abs= "$ezer_path_docs/$fname";
  $f_rel= "docs/$fname";
  $html= tc_sablona($f_abs,'',$druh);                 // jen části bez označení v dopis_cast.pro
  $date= @filemtime($f_abs);
  $href= "<a target='dopis' href='$f_rel'>$fname</a>";
  $html.= "Byl vygenerován PDF soubor: $href (verze ze ".date('d.m.Y H:i',$date).")";
  $html.= "<br><br>Jako jméno vyřizujícícho pracovníka je vždy použito jméno přihlášeného uživatele,"
    ." ve tvaru uvedeném v osobním nastavení. Pro změnu osobního nastavení požádejte prosím administrátora webu.";
  return $html;
}

/** =========================================================================================> MAILY */
# ------------------------------------------------------------------------ mail oprav_mail_potvrzeni
# pro mail.state=5 se před clen.email dají ## a zruší se datum potvrzení u darů z davka.rok
function mail_oprav_mail_potvrzeni($davka) {
  global $ezer_path_docs;
//                                          debug($davka,"mail_oprav_mail_potvrzeni");
  $dnes= date('Y-m-d');
  $rok= 0+date('Y');
  $rok= $davka->par_drok==1 ? $rok : ($davka->par_drok==2 ? $rok-1 : 0);
  $mailem= "$ezer_path_docs/mailem_$rok";
  $postou= "$ezer_path_docs/mailem_$rok/postou";
  if ( !is_dir($postou) ) mkdir($postou);
  $n= 0;
  $ms= pdo_qry("SELECT id_clen FROM mail WHERE state=5 ");
  while ( $ms && (list($idc)= pdo_fetch_row($ms)) ) {
    query("UPDATE clen SET email=CONCAT('##',email) 
      WHERE id_clen=$idc AND NOT SUBSTR(email,1,2)='##'");
    query("UPDATE dar SET potvrz_kdy='$dnes' WHERE deleted='' AND id_clen=$idc 
      AND YEAR(castka_kdy)=$rok");
    // přesuň potvrzení do podsložky
    $potvrzeni= "{$idc}_potvrzeni_$rok.pdf";
    if (file_exists("$mailem/$potvrzeni"))
      rename("$mailem/$potvrzeni","$postou/$potvrzeni");
    else 
      $err.= " $potvrzeni";
    // konec cyklu
    $n++;
//    break;
  }
  return "Bylo zneplatněno $n chybných mailů, neposlaná potvrzení přesunuta k odeslání 
    do složky $postou, datum potvrzení příslušných darů změněno na $dnes "
      . ($err ? "<hr>nebyla ale nalezena potvrzení: $err" : "");
}
# ------------------------------------------------------------------------------ mail delivery_error
# detekce chybných mailů
function mail_delivery_error($davka) {
  global $ezer_path_docs;
  $res= (object)array('ok'=>0,'match'=>null,'cleni'=>null,'maily'=>null,'emls'=>'');
  $slozka= 'delivery_errors';
  if ( is_dir("$ezer_path_docs/$slozka") ) {
    $match= $cleni= $maily= $emls= array();
    $files= glob("$ezer_path_docs/$slozka/*.eml");
    $i= 0; 
    foreach ($files as $file) {
      $emls[$i]= substr($file,-18);
      $m= null;
      $eml= file_get_contents($file);
      $mails= array();
      $ok= preg_match("/Date:[^\n]*\n(Return-Path:[^\n]*\n|)To:\s*([^\n\s]*)\s*\n/U",$eml,$m); 
      if ($ok && $m[2])
        $mails[]= "'".strtr($m[2],array('<'=>'','>'=>''))."'";
      else {
        $ok= preg_match("/(Original-Recipient: rfc822;)\s*([^\n\s]*)\s*\n/U",$eml,$m); 
        if ($ok && $m[2])
          $mails[]= "'".strtr($m[2],array('<'=>'','>'=>''))."'";
        $ok= preg_match("/(Final-Recipient: rfc822;)\s*([^\n\s]*)\s*\n/U",$eml,$m); 
        if ($ok && $m[2])
          $mails[]= "'".strtr($m[2],array('<'=>'','>'=>''))."'";
      }
      $match[$i]= $mails;
      $email= implode(',',$mails);
      $res->ok= $ok;
      // najdeme člena
      if ($ok && $email) {
//        $idc= select('GROUP_CONCAT(id_clen)','clen',"deleted='' AND email IN ($email)");
        $maily[$i]= select('GROUP_CONCAT(id_mail)','mail',"email IN ($email)");
        query("UPDATE mail SET state=5,msg='nedoručitelné' WHERE email IN ($email)");
//        $cleni[$i]= $idc;
      }
      $i++;
//      break;
    }
    $res->match= $match;
//    $res->cleni= $cleni;
    $res->maily= $maily;
    $res->emls= $emls;
  }
  return $res;
}
# -------------------------------------------------------------------------------------- mail change
# zjištění stavu dávky - pokud neexistuje, založ ji jako 1,0,0
function mail_change($cmd,$idc) {
  $html= '';
  $rok= date('Y')-1;
  switch ($cmd) {
    case 'poslano?':
      $potvrzene= select('COUNT(*)','dar',"deleted='' AND id_clen=$idc
          AND YEAR(castka_kdy)=$rok AND potvrz_kdy!='0000-00-00' ");
      if ($potvrzene) $html= "Na předchozí adresu bylo odesláno potvrzení za $rok, budeme posílat znovu?";
      break;
    case 'znovu!':
      query("UPDATE dar SET potvrz_kdy='0000-00-00' WHERE deleted='' AND id_clen=$idc
          AND YEAR(castka_kdy)=$rok");
      break;
  }
  return $html;
}
# ----------------------------------------------------------------------------------- mail davka_put
# ASK
# zápis stavu dávky
function mail_davka_put($davka) {
  global $ezer_path_docs;
  if ($davka->faze<=1) {
    // inicializace
    query("TRUNCATE TABLE mail");
    query("TRUNCATE TABLE davka");
    query("INSERT INTO davka (id_davka,cmd) VALUE (1,'')");
    // podle toho, co posíláme
    switch ($davka->posilame) {
      case 'potvrzeni':
        // smažeme pdf z mailem_{rok} pokud jsou
        $rok= 0+date('Y');
        $rok= $davka->par_drok==1 ? $rok : ($davka->par_drok==2 ? $rok-1 : 0);
        $slozka= "mailem_$rok";
        if ( is_dir("$ezer_path_docs/$slozka") ) {
          $files= glob("$ezer_path_docs/$slozka/*.pdf");
          foreach ($files as $file) {
            unlink($file);
          }
        }
        break;
      case 'reky':
        // smažeme pdf z mailem_reky pokud jsou
        $slozka= "mailem_reky";
        if ( is_dir("$ezer_path_docs/$slozka") ) {
          $files= glob("$ezer_path_docs/$slozka/*.pdf");
          foreach ($files as $file) {
            unlink($file);
          }
        }
        break;
    }
  }
  $set= array();
  foreach ((array)$davka as $nam=>$val) {
    $set[]= "$nam=\"$val\"";
  }
  query("UPDATE davka SET ".implode(',',$set)." WHERE id_davka=1");
  return 1;
}
# ----------------------------------------------------------------------------------- mail davka_get
# zjištění stavu dávky - pokud neexistuje, založ ji jako 1,0,0
function mail_davka_get() {
  return select_object('*','davka');
}
# -------------------------------------------------------------------------------------- mail verify
# hloubová kontrola správnosti emailů
function mail_verify($posilame,$cond,$having) {
  $ret= (object)array('errors'=>0,'done'=>0);
  query("TRUNCATE TABLE mail");
  // kontrola správnosti mailu a zjištění výše darů
  $qry= $posilame=='potvrzeni'
      ? "SELECT id_clen, c.email, c.jmeno, c.prijmeni,
          SUM(IF(d.potvrz_kdy='0000-00-00',d.castka,0)) AS dary,
          GROUP_CONCAT(IF(d.potvrz_kdy='0000-00-00',d.id_dar,0)) AS ids_dary,
          SUM(IF(d.potvrz_kdy='0000-00-00',d.castka,0)) AS nepotvrzene
         FROM clen AS c JOIN dar AS d USING (id_clen) LEFT JOIN mail AS m USING (id_clen) 
         WHERE $cond 
         GROUP BY id_clen ".($having ? "HAVING $having" : '')." ORDER BY id_clen"
      : "SELECT id_clen, c.email, c.jmeno, c.prijmeni,0,0
         FROM clen AS c 
         WHERE $cond 
         GROUP BY id_clen ORDER BY id_clen"
      ;
  $res= pdo_qry($qry);
  while ( $res && (list($idc,$emails,$jmeno,$prijmeni,$dary,$idds)= pdo_fetch_row($res)) ) {
    $chyby= '';
    $ret->done++;
    // projdi adresy
    foreach(preg_split("/,\s*|;\s*|\s+/",trim($emails," ,;"),-1,PREG_SPLIT_NO_EMPTY) as $email) {
      $chyba= '';
      if (!emailIsValid($email,$chyba)) {
        $ret->errors++;
        $chyby.= "$chyba ";
      }
    }
    $jmeno= str_replace("'",'"',$jmeno);
    $prijmeni= str_replace("'",'"',$prijmeni);
    query("INSERT INTO mail (id_clen,id_dopis,jmeno,prijmeni,email,dary,ids_dar,state,msg) 
      VALUE ($idc,0,'$jmeno','$prijmeni','$email',$dary,'$idds',0,'$chyby')");
  }
  return $ret;
}
# -------------------------------------------------------------------------------------- mail proces
# ASK
// tabulka davka obsahuje stav procesu, který bude krok za krokem prováděn 
// průběžné hodnoty  
//   davka.todo - zbývající počet kroků
//   davka.done - počet provedených kroků 
//   davka.cmd  - označení procedury
//   davka.step - počet kroků provedených v jednom průchodu
//   davka.error = text chyby, způsobí konec
function mail_proces () { 
  $davka= mail_davka_get();
  if ( $davka->error ) { goto end; }
  if ( $davka->done >= $davka->todo ) { 
    $davka->done= $davka->todo; 
    $davka->msg= 'HOTOVO +'; 
    goto end; 
  }
  // vlastní proces
  switch ($davka->cmd) {
    case 'gen': // ------------------------------------ generování potvrzení
      $davka->last= mail_gen($davka);
      $davka->done= min($davka->todo,$davka->done+$davka->step);
      break;
    case 'gen-river': // ------------------------------ generování řek
      $davka->last= mail_gen_river($davka);
      $davka->done= min($davka->todo,$davka->done+$davka->step);
      break;
    case 'send': // ----------------------------------- rozesílání potvrzení
    case 'send-river': // ----------------------------- rozesílání řek
      $davka->last= mail_davka_send($davka);
      $davka->done= min($davka->todo,$davka->done+$davka->step);
      sleep($davka->step); // necháme step/2 chvíli oddech
      break;
  }
  // zpráva
  $davka->msg= $davka->done==$davka->todo ? 'HOTOVO' : "ještě ".($davka->todo-$davka->done); 
end:  
  mail_davka_put($davka);
  return 1;
}
# ----------------------------------------------------------------------------------- mail gen_river
# vygenerování davka.step mailů s potvrzením 
# průvodní mail = pruvodni_mail
# potvrzení = N
function mail_gen_river($davka) {
  global $ezer_path_docs;
  $last= 0;
  // stanovení podsložky docs a případné vytvoření
  $slozka= "mailem_reky";
  if ( !is_dir("$ezer_path_docs/$slozka") ) mkdir("$ezer_path_docs/$slozka");
  // přečtení průvodního dopisu
  $pruvodni= select('*','dopis',"typ='pruvodni_reky'");
  // výběr davka.step ještě nevygenerovaných mailů
  $res= pdo_qry("
    SELECT id_mail,id_clen
         FROM mail 
         WHERE state=0
         ORDER BY id_mail
         LIMIT {$davka->step}");
  while ( $res && (list($idm,$idc)= pdo_fetch_row($res)) ) {
    $last= $idm;
    $clen= clen_kontakt($idc,null,null,true);
    // generování průvodního dopisu
    $body= mail_pruvodni('personify',$pruvodni,$clen);
    // generování přílohy
    $fname= "{$idc}_reka.pdf";
    $f_abs= "$ezer_path_docs/$slozka/$fname";
    dop_tisk_rybnik_ids($idc,$f_abs,'jednotlive',1,$davka->reky_radit,$davka->reky_narozeniny);
    query("UPDATE mail SET state=1,id_dopis=$pruvodni->id_dopis,body=\"$body\","
        . "priloha='$fname' WHERE id_mail=$idm");
  }
  return $last;
}
# ----------------------------------------------------------------------------------------- mail gen
# vygenerování davka.step mailů s potvrzením 
# průvodní mail = pruvodni_mail
# potvrzení = N
function mail_gen($davka) {
  global $ezer_path_docs;
  $last= 0;
  // stanovení podsložky docs a případné vytvoření
  $rok= 0+date('Y');
  $rok= $davka->par_drok==1 ? $rok : ($davka->par_drok==2 ? $rok-1 : 0);
  $slozka= "mailem_$rok";
  if ( !is_dir("$ezer_path_docs/$slozka") ) mkdir("$ezer_path_docs/$slozka");
  // přečtení průvodního dopisu
  $pruvodni= select('*','dopis',"typ='pruvodni_mail'");
  // výběr davka.step ještě nevygenerovaných mailů
  $res= pdo_qry("
    SELECT id_mail,id_clen,dary
         FROM mail 
         WHERE state=0
         ORDER BY id_mail
         LIMIT {$davka->step}");
  while ( $res && (list($idm,$idc,$dary)= pdo_fetch_row($res)) ) {
    $last= $idm;
    $clen= clen_kontakt($idc,null,null,true);
    // generování průvodního dopisu
    $body= mail_pruvodni('personify',$pruvodni,$clen);
    // generování přílohy
    $potvr= dop_potvrzeni($idc,$rok,$davka->par_odeslano,null,null,
        (object)array('clen'=>$clen,'dary'=>$dary,'slozka'=>$slozka));
    query("UPDATE mail SET state=1,id_dopis=$pruvodni->id_dopis,body=\"$body\","
        . "priloha='{$potvr->pdf}' WHERE id_mail=$idm");
  }
  return $last;
}
# ------------------------------------------------------------------------------------ mail pruvodni
# parametrizace textu dopis pro id_clen
function mail_pruvodni($cmd,$pruvodni,$clen=null) { 
  $ret= null;
  switch ($cmd) {
    case 'seek': // ----------------------- vrať id_dopis 'pruvodni_mail' nebo jej vytvoř
      $idd= select('id_dopis','dopis',"typ='$pruvodni'");
      if (!$idd) {
        query("INSERT INTO dopis (typ,nazev,obsah,var_list) 
          VALUES ('$pruvodni','Potvrzení','{osloveni} ...','osloveni')");
        $idd= pdo_insert_id();        
      }
      $ret= $idd;
      break;
    case 'personify': // ------------------ personifikuj existující 'pruvodni_mail'
      // osobní údaje člena:
      $body= $pruvodni->obsah;
      $vars= $pruvodni->var_list;
      if ($vars) {
        $vars= explode(',',$vars);
        // substituce v 'text'
        foreach ($vars as $var ) {
          $body= str_replace('{'.$var.'}',$clen->$var,$body);
        }
      }
      $ret= str_replace('"',"'",$body);
      break;
  }
  return $ret;
}
# -------------------------------------------------------------------------------------- mail single
# ASK
# poslání jednotlivého mailu s potvrzením za rok 
#   mode=html - vrátí preview mailu a vygeneruje docs/mailem_{rok}/{id_clen}_potvrzeni_{rok}.pdf
#   mode=test - pošle zkušební mail na osobní nastavení
#   mode=send - pošle ostrý mail s potvrzením
#   mode=test-reky - pošle zkušební mail s řekou na osobní nastavení
# popis personifikace viz dop_rep_klu_ids, dop_klu_ids
# pokud je uvedena položka jiny_darce a jine_rc bude jimi pozměněno potvrzení
function mail_single($mode,$i_smtp,$dopis,$id_clen,$dary,$rok,$dne,$jiny_darce=null,$jine_rc=null) { trace();
  global $USER;
  $ret= (object)array('_error'=>'','_html'=>'','darce'=>'','rc'=>'');
  $dne= str_replace('.','. ',$dne);
  // osobní údaje
  $email= select('TRIM(email)','clen',"id_clen=$id_clen");
  $chyba= '';
  if ( !emailIsValid($email,$chyba) ) { $ret->_error= "chyba v emailu: $chyba"; goto end; }
  // nalezení dopisu
  list($idd,$nazev,$obsah,$vars)= select('id_dopis,nazev,obsah,var_list','dopis',"typ='$dopis'");
  if (!$idd) { $ret->_error= "dopis '$dopis' nebyl nalezen"; goto end; }
  $vars= $vars ? explode(',',$vars) : null;
  // personifikace textu
  $clen= clen_kontakt($id_clen,null,null,true);
  if ( $vars ) foreach ($vars as $var ) {
    $obsah= str_replace('{'.$var.'}',$clen->$var,$obsah);
    $nazev= str_replace('{'.$var.'}',$clen->$var,$nazev);
  }
  if (in_array($mode,array('html','test','send'))) {
    // příloha - potvrzení
    $dir= "docs/mailem_$rok";
    if (!file_exists($dir)) mkdir($dir);
    $attach= "$dir/{$id_clen}_potvrzeni_$rok.pdf";
  }
  else { // test zaslání řeky
    $attach= "docs/mailem_reky/{$id_clen}_reka.pdf";
  }
  // odesílatel je z číselníku
  $mail= mail_new_PHPMailer($i_smtp);
  switch ($mode) {
    case 'html':  // ------------------------- vrácení mailu jen k zobrazení + generování potvrzení
      // vygenerujeme potvrzení
      $potvr= dop_potvrzeni($id_clen,$rok,$dne,$jiny_darce,$jine_rc,
        (object)array('clen'=>$clen,'dary'=>$dary,'slozka'=>"mailem_$rok"));
      $ret->darce= str_replace('<br>',"\n",$potvr->darce);
      $ret->rc= $potvr->rc;
      // zobrazíme mail
      $from=  $mail->From;
      $name=  $mail->FromName;
      $ret->_html= "
        <style>
          div.dop_mailem>div { padding:10px; margin:5px;}
          div.dop_mailem span:first-child { 
              display:inline-block; width:60px; padding:0; background:transparent  }
          div.dop_mailem span { background:white; }
          div.dop_mailem div span { background:white; padding:10px; }
          div.dop_mailem div * { background:white; }
          div.dop_mailem>div>div { height:260px; overflow-x:scroll; padding:10px; }
        </style>
        <div class='dop_mailem'>
          <div><span>Od:</span><span>$name &lt;$from&gt;</span></div>
          <div><span>Pro:</span><span>$email</span></div>
          <div><span>Předmět:</span><span>$nazev</span></div>
          <div><span>Příloha:</span><span><a href='$attach' target='pdf'>{$potvr->pdf}</a></span></div>
          <div><div>$obsah</div></div>
        </div>
        ";
      break;
    case 'test-reky':  // -------------------- zaslání řeky na testovací adresu
    case 'test':  // ------------------------- zaslání na testovací adresu
      $test_mail= isset($USER->options->email) ? $USER->options->email : '';
      if (!$test_mail) { 
        $ret->_error= "není definován mail v Osobním nastavení na který se test posílá"; goto end; 
      }   
      $ret->_error= mail_single_send($mail,$test_mail,$nazev,$obsah,$attach);
      if (!$ret->_error) {
        $ret->_html= "byl odeslán testovací mail pro adresu $email na adresu $test_mail";
      }
      break;
    case 'send':  // ------------------------- ostré zaslání
      $mail= mail_new_PHPMailer($i_smtp);
      $ret->_error= mail_single_send($mail,$email,$nazev,$obsah,$attach);
      if (!$ret->_error) {
        $dnes= date('Y-m-d');
        query("UPDATE dar SET potvrz_kdy='$dnes' WHERE deleted='' AND id_clen=$id_clen 
          AND YEAR(castka_kdy)=$rok"); 
        $ret->_html= "Mail na adresu $email byl odeslán a datum potvrzení zapsáno k darům";
      }
      break;
  }
end:  
  return $ret;
}
# ------------------------------------------------------------------------------- mail new_PHPMailer
# nastavení parametrů pro SMTP server podle _user.options.smtp_
  global $ezer_path_serv;
  // rozšíření PHPMailer
  $phpmailer_path= "$ezer_path_serv/licensed/phpmailer";
  require_once("$phpmailer_path/class.phpmailer.php");
  require_once("$phpmailer_path/class.smtp.php");
  class Mailer extends PHPMailer {
     /**
       * Save email to a folder (via IMAP)
       *
       * This function will open an IMAP stream using the email
       * credentials previously specified, and will save the email
       * to a specified folder. Parameter is the folder name (ie, Sent)
       * if nothing was specified it will be saved in the inbox.
       *
       * @author David Tkachuk <http://davidrockin.com/>
       */
      public function copyToFolder($folderPath = null) {
          $message = $this->MIMEHeader . $this->MIMEBody;
          $path = "INBOX" . (isset($folderPath) && !is_null($folderPath) ? ".".$folderPath : ""); // Location to save the email
          $imapStream = imap_open("{" . $this->Host . "}" . $path , $this->Username, $this->Password);
          imap_append($imapStream, "{" . $this->Host . "}" . $path, $message);
          imap_close($imapStream);
      }
  }
function mail_new_PHPMailer($i_smtp) {  
  global $ezer_path_serv;
  // získání parametrizace SMTP
  $smtp_json= select1('hodnota','_cis',"druh='smtp_srv' AND data=$i_smtp");
  $smtp= json_decode($smtp_json);
  if ( !$smtp_json || json_last_error() != JSON_ERROR_NONE ) {
    $mail= null;
    fce_warning("chyba ve volbe SMTP serveru" . json_last_error_msg());
    goto end;
  }
  // inicializace phpMailer
  $mail= new Mailer;
  $mail->SetLanguage('cs',"$phpmailer_path/language/");
  $mail->IsSMTP();
  $mail->CharSet = "UTF-8";
  $mail->IsHTML(true);
  $mail->Mailer= "smtp";
  foreach ($smtp as $part=>$value) {
    $mail->$part= $value;
  }
end:  
  return $mail;
}
# --------------------------------------------------------------------------------- mail single_send
# odeslání mailu - vrátí chybu jinak ''
function mail_single_send($mail,$to,$subj,$body,$attach=null) { trace();
  $TEST= 1;
//  $TEST= 0;
  $error= '';
  // From a FromName se nastavuje podle číselníku v mail_new_PHPMailer
  $mail->Subject= $subj;
  $mail->Body= $body;
  $mail->ClearAttachments();
  if ($attach) $mail->AddAttachment($attach);
  $hlavni= '';
  $mail->ClearAddresses();
  $mail->ClearCCs();
//  $to= "martin@smidek.eu";
  foreach(preg_split("/,\s*|;\s*|\s+/",trim($to," ,;"),-1,PREG_SPLIT_NO_EMPTY) as $adresa) {
    if (!$hlavni) {
      $mail->AddAddress($adresa);   // pošli na 1. adresu
      $hlavni= $adresa;
    }
    else                            // na další jako kopie
      $mail->AddCC($adresa);
  }
  if ( $TEST ) {
    fce_warning("TEST odeslání mailu from={$mail->From} to={$adresa} subj={$mail->Subject}");
    $ok= 1;
  }
  else { // zkus poslat mail
    $e= null;
    $ok= false; 
    display("SEND from={$mail->From} to={$adresa} subj={$mail->Subject}");
    try { $ok= $mail->Send(); } catch(Exception $e) { $ok= false; }
//    if ($ok && $to=="martin.smidek@gmail.com") {
//      $mail->copyToFolder("Sent"); // Will save into Sent folder
//      display("COPY folder=Sent");
//    }
  }
  if ( !$ok  ) {
    $err= $mail->ErrorInfo;
    $error= "<br><b style='color:#700'>Při odesílání mailu pro $hlavni došlo k chybě: $err</b>";
  }
  return $error;
}
# ---------------------------------------------------------------------------------- mail davka_send
# odeslání davka.step mailů s potvrzením přes davka.smtp
function mail_davka_send($davka) { trace();
  global $ezer_path_docs;
  $last= 0;
  // nalezení podsložky docs
  if ($davka->cmd=='send') {
    $rok= 0+date('Y');
    $rok= $davka->par_drok==1 ? $rok : ($davka->par_drok==2 ? $rok-1 : 0);
    $slozka= "$ezer_path_docs/mailem_$rok";
  }
  else {
    $slozka= "$ezer_path_docs/mailem_reky";
  }
  $mail= mail_new_PHPMailer($davka->par_smtp);
  // výběr davka.step ještě nevygenerovaných mailů
  $res= pdo_qry("
    SELECT id_mail,id_clen,email,d.nazev,body,priloha,ids_dar
         FROM mail JOIN dopis AS d USING (id_dopis)
         WHERE state IN (1,3)
         ORDER BY id_mail
         LIMIT {$davka->step}");
  while ( $res && (list($idm,$idc,$to,$subj,$obsah,$pdf,$ids_dar)= pdo_fetch_row($res)) ) {
    $last= $idm;
    $err= mail_single_send($mail,$to,$subj,$obsah,"$slozka/$pdf");
    if (!$err) {
      display("$idc");
      query("UPDATE mail SET state=4,msg='' WHERE id_mail=$idm");
      if ($davka->cmd=='send') {
        $dnes= date('Y-m-d');
        foreach (explode(',',$ids_dar) as $id_dar) {
          query("UPDATE dar SET potvrz_kdy='$dnes' WHERE id_dar=$id_dar AND !potvrz_kdy AND YEAR(castka_kdy)=$rok"); 
        }
      }
    }
    else {
      $msg= $mail->ErrorInfo;
      query("UPDATE mail SET state=5,msg=\"$msg\" WHERE id_mail=$idm");
    }
  }
  return $last;
}
# ============================================================================> JEDNOTLIVÁ POTVRZENI
# ---------------------------------------------------------------------------------==> dop show_vars
# ASK
# vrátí potvrzení za 1 dar jako objekt {text,value}
function dop_potvrz_dar1($idc,$idd) {
  $dop= (object)array();
  $d= select_object('*','dopis',"vzor='dar1'");
  $dop->text= $d->obsah; 
  // k proměnným z dopisu doplníme adresu, id člena
  $vars= dop_show_vars($d->id_dopis);
  $vars->use[]= 'adresa_darce';
  $vars->use[]= 'ID';
  // provedeme personalizaci
  $subs= dop_substituce($vars->use,null,null,$idc,$idd);
  $dop->text= strtr($dop->text,$subs->strtr);
  $dop->value= $subs->value;
                                                        debug($dop,'dop_potvrz_dar1');
  return $dop;
}
# ------------------------------------------------------------------------------ dop potvrz_dar1_pdf
# ASK
# vytvoření připraveného dopisu se šablonou pomocí TCPDF
# $c - kontext vytvořený funkcí dop_subst
function dop_potvrz_dar1_pdf($oprava,$dop) { 
  global $ezer_path_root;
                                                         debug($dop,'dop');
  $dop->text= $oprava;
  $dop->adresa= $dop->value->adresa_darce;
  $texty= array($dop);
  $fname= "docs/".date('ymd_Hi_')."{$dop->value->ID}.pdf";
  $fpath= "$ezer_path_root/$fname";
                                                         debug($texty,'texty');
  $listu= null;
  tc_dopisy($texty,$fpath,'rozesilani','_user',$listu,'D',$dop->value->datum);
  return $fname;
}
/** =================================================================================> KORESPONDENCE */
# ---------------------------------------------------------------------------------- clen change_fld
# ASK
# provede hromadnou změnu v Klubu - pro členy
function clen_change_fld($keys,$fld,$mode,$val) {
//                                                         display("clen_change_fld($keys,$fld,$val)");
  $zmeny= new stdClass;
  $zmeny->fld= $fld;
  $zmeny->op= $mode;
  $zmeny->val= $val;
//                                                         debug($zmeny,"ezer_qry(UPDATE_keys,'clen',$keys,...);");
  ezer_qry("UPDATE_keys",'clen',$keys,$zmeny);
  return $keys;
}
# ----------------------------------------------------------------------------------- dar change_fld
# ASK
# provede hromadnou změnu v Klubu - pro dary
function dar_change_fld($keys,$fld,$mode,$val) {
//                                                         display("clen_change_fld($keys,$fld,$val)");
  $zmeny= new stdClass;
  $zmeny->fld= $fld;
  $zmeny->op= $mode;
  $zmeny->val= $val;
//                                                         debug($zmeny,"ezer_qry(UPDATE_keys,'clen',$keys,...);");
  ezer_qry("UPDATE_keys",'dar',$keys,$zmeny);
  return $keys;
}
# ------------------------------------------------------------------------------- dop potvrzeni_cast
# ASK
# přečtení části šablony
function dop_potvrzeni_cast($druh,$cast) { trace();
  $d= null;
  try {
    $qry= "SELECT id_dopis_cast,obsah,w FROM dopis_cast WHERE druh='$druh' AND name='$cast' ";
    $res= mysql_qry($qry,1,null,1);
    $d= pdo_fetch_object($res);
  }
  catch (Exception $e) { display($e); fce_error("dop_sab_cast: část '$cast' sablony nebyla nalezena"); }
  $d->ukazka= $d->obsah;
  if ($cast=='logo' && preg_match('/png$/',$d->ukazka)) {
    $d->ukazka= "<img src='{$d->ukazka}' style='width:100%'>";
  }
  return $d;
}
# -----------------------------------------------------------------------==> . dop potvrzeni_sablona
# ASK
# tisk šablony daňového potvrzení
function dop_potvrzeni_sablona($druh) { trace();
  global $ezer_path_docs;
  $html= '';
  $fname= "sablona.pdf";
  $f_abs= "$ezer_path_docs/$fname";
  $f_rel= "docs/$fname";
  $html= tc_sablona($f_abs,'rozesilani',$druh);
  $html.= "Byl vygenerován PDF soubor: <a target='dopis' href='$f_rel'>$fname</a>";
  return $html;
}
# -------------------------------------------------------------------------------==> . dop potvrzeni
# ASK + mail_proces
# tisk jednotlivého daňového potvrzení
# popis viz dop_rep_klu_ids, dop_klu_ids
# pokud je uvedena položka jiny_darce a jine_rc bude jimi pozměněno potvrzení
# pokud je voláno jako obsluha mail_proces, tak $in_proces={clen,dary,slozka}
function dop_potvrzeni($id_clen,$rok,$kdy,$jiny_darce=null,$jine_rc=null,$in_proces=null) { trace();
  global $ezer_path_docs;
  $ret= (object)array('pdf'=>'','html'=>'','darce'=>'','rc'=>'');
  $kdy= str_replace('.','. ',$kdy);
  // osobní údaje člena:
  $vars= array('rok','castka','darce');
  $clen= $in_proces ? $in_proces->clen : clen_kontakt($id_clen,null,null,true);
  $clen->rok= $rok;
  $castka= $in_proces ? $in_proces->dary : clen_dar_castka($id_clen,$rok);
  $slovy= castka_slovy($castka);
  $castka= number_format($castka,0,',','.');
  $clen->castka= "$castka,-Kč ($slovy)";
  // pokud jsou nabídnuty jiné hodnoty pro darce a rc, mají přednost
  if ($jiny_darce)
    $ret->darce= $clen->darce= str_replace("\n",'<br>',$jiny_darce);
  else
    $ret->darce= $clen->darce= "{$clen->jmeno_darce}<br>{$clen->adresa_radek}";
  if ( $jine_rc ) {
    $ret->rc= $jine_rc;
    $clen->darce.= "<br>$jine_rc";
  }
  elseif ( $clen->rc_darce ) {
    $ret->rc= $rc= trim(str_replace(',','',$clen->rc_darce));
    $clen->darce.= "<br>$rc";
  }
//                                                     debug($clen,"dop_potvrzeni/člen");
  // předání parametrů a dopisu k exportu v TCPDF
  $dopis= select("*","dopis","typ='N'");
  // substituce v 'text'
  $text= $dopis->obsah;
  foreach ($vars as $var ) {
    $text= str_replace('{'.$var.'}',$clen->$var,$text);
  }
  // úprava lámání textu kolem jednopísmenných předložek a přilepení Kč k částce
  $text= preg_replace(array('/ ([v|k|s|o|u|i|a]) /u','/ Kč/u'),array(' \1&nbsp;','&nbsp;Kč'),$text);

  // vytvoření parametrizace stránky
  $page= (object)array();
  $page->text= $text;
  $page->odeslano= "V Ostravě $kdy";
  $adresa= $jiny_darce
      ? str_replace("\n",'<br>',$jiny_darce)
      : "{$clen->jmeno_postovni}<br>{$clen->adresa_postovni}";
  $page->adresa= "<div style=\"line-height:1.5\">$adresa</div>";
  $pages= array($page);

  // generování PDF a předání odkazu
  $listu= 0;
  $sablona= $dopis->sablona ? $dopis->sablona : $dopis->druh;
  if ($in_proces) {
    $fname= "{$id_clen}_potvrzeni_$rok.pdf";
    $f_abs= "$ezer_path_docs/{$in_proces->slozka}/$fname";
    tc_dopisy($pages,$f_abs,'','',$listu,$sablona,1,1,1);
    $ret->pdf= $fname;
  }
  else {
    $fname= "{$id_clen}_potvrzeni_".date('ymd_Hi').".pdf";
    $f_abs= "$ezer_path_docs/$fname";
    $f_rel= "docs/$fname";
    tc_dopisy($pages,$f_abs,'','',$listu,$sablona,1,1,1);
    $href= "<a target='dopis' href='$f_rel'>$fname</a>";
    $ret->html= "Byl vygenerován PDF soubor: $href";
  }
  return $ret; // pro volání z mail_proces vrací cestu na vygenerovaný soubor
}
# ---------------------------------------------------------------------------------- clen dar_castka
# suma darů v daném roce
function clen_dar_castka($id_clen,$dan_rok) { trace();
  $qr= mysql_qry("
    SELECT SUM(castka) FROM dar
    WHERE deleted='' AND id_clen=$id_clen AND YEAR(castka_kdy)=$dan_rok
  ");
  list($castka)= pdo_fetch_row($qr);
  return $castka;
}
# ------------------------------------------------------------------------------------- clen_kontakt
# vrátí objekt s daty pro adresní štítek, oslovení, ...
# vstupní data
#   $id_clen -- klíč kontaktu
#   $c       -- pdo_fetch_object tabulky clen WHERE id_clen=$K
#   $s       -- pdo_fetch_object tabulky clen WHERE id_clen=c.sardinka
#   $adresa_darce -- pole clen.darce ovlivní jmeno_darce a také jmeno_postovni2 a adresa_postovni
#                 -- v NOE není !!!
# složky vráceného objektu
#   cislo    --
#   posta
#   jmeno1
# kde objekt obsahuje pro
#   kontakt K bez sardinky, bez kapra =
#     {cislo:K,posta:'D'+psc(K),jmeno:jméno(K),adresa:adresa(K)}
#   kontakt K bez sardinky, s kaprem =
#     {cislo:K,posta:'K'+kapr(K),jmeno:jméno(K),adresa:adresa(K)}
#   kontakt K se sardinkou S =
#     {cislo:S,posta:S.posta,jmeno:jméno(S)+'/n(pro '+jméno(K)+')',adresa:clen_adresa(S).adresa}
# kde
#   jméno(K) = K.titul+K.jmeno+K.prijmeni
#   adresa(K) = K:clen_uir.status in 'u,c,o' and K:clen_uir.adresa_kod ? uir(K) : K:ulice+K:psc+K:obec
function clen_kontakt($K,$tc=null,$ts=null,$adresa_darce=false) { //trace();
  $adresa_darce= false;
  global $ezer_uir_adr;
  $join= $ezer_uir_adr ? "LEFT JOIN clen_uir USING(id_clen)" : '';
  $join_fld= $ezer_uir_adr ? "clen_uir.id_clen AS uir" : "null AS uir";
  if ( !$tc ) {
    // načtení člena
    $qryc= "SELECT /*1*/ *,$join_fld FROM clen $join
            WHERE id_clen=$K";
    $resc= mysql_qry($qryc);
    if ( !$resc || pdo_num_rows($resc)!=1 ) return fce_error("clen_kontakt: $K není klíčem člena");
    $tc= pdo_fetch_object($resc);
  }
//  if ( $tc->sardinka && !$ts ) {
//    // načtení sardinky
//    $qrys= "SELECT *,$join_fld FROM clen $join
//            WHERE id_clen={$tc->sardinka}";
//    $ress= mysql_qry($qrys);
//    if ( !$ress || pdo_num_rows($ress)!=1 )
//      return fce_error("clen_kontakt: sardinka {$tc->sardinka} člena $K neexistuje");
//    $ts= pdo_fetch_object($ress);
//  }
  $x= (object)array();
//  $S= $ts ? $tc->sardinka : null;
  $xc= clen_adresa($tc);

  // POLOŽKY BEZ OHLEDU NA SARDINKU

  // ... adresa na 2-3 řádky
  $x->adresa_clena= $xc->adresa2;
  // ... adresa na 2 řádky (pro Lk)
  $x->adresa_radek= $xc->adresa1Lk;
  // ... titl. jméno příjmení
  $x->jmeno_clena=  $xc->jmeno1;
  // ... /titl. jméno příjmení resp. firma/kontakt
//   $x->jmeno_clena2= "&nbsp;<br/>" . ($tc->osoba ? $x->jmeno_clena : $xc->jmeno2);
  $x->jmeno_clena2= "&nbsp;<br/>" . ($tc->osoba ? $x->jmeno_clena : str_replace('<br/>',', ',$xc->jmeno2));
  // ... jméno obvyklého dárce
  $x->jmeno_darce=  $xc->jmeno1;
//  $x->jmeno_darce=  $tc->darce=='' ? $xc->jmeno1 : $tc->darce;
  // ... oslovení
  $x->osloveni= clen_osloveni($tc);
  // ... členské číslo
  $x->cislo= $tc->id_clen;

  $x->adresa_postovni= $xc->adresa2;
  $x->jmeno_postovni= $xc->jmeno2;
  if ( !mb_check_encoding($x->jmeno_postovni, 'UTF-8') ) // kontrola UTF-8
    fce_error("clen_kontakt: invalid UTF string for jmeno_postovni:".urlencode($x->jmeno_postovni));
  // ... jméno dárce na poštovní adresu (třeba přes sardinku)
  // pro fyzickou osobu obsahuje jméno dárce, pro právnickou fy+jméno
  $x->jmeno_postovni2= $tc->osoba ? $x->jmeno_darce : $xc->jmeno2;

// ZMĚNA POLOŽEK při nastaveném $adresa_darce
  $rc_ic_darce= '';
//  if ( $adresa_darce && $tc->darce ) {
//    $darce= explode(',',$tc->darce);
//    // ... jméno obvyklého dárce
//    $x->jmeno_darce= $darce[0];
//    if ( count($darce)!=1 ) {
//      $x->jmeno_postovni2= $x->jmeno_darce;
//      $x->adresa_postovni= trim($darce[1])."<br/>".trim($darce[2]);
//      if ( count($darce)==4 ) {
//        // na 4. řádku je uvedeno 'rodné číslo xxxxxxxxxx' nebo 'IČ xxxxxxxxxx'
//        $rc_ic_darce= trim($darce[3]);
//        if ( substr($rc_ic_darce,0,3)=='rod' ) {
//          $rc_ic_darce= mb_substr($rc_ic_darce,0,-4).'/'.mb_substr($rc_ic_darce,-4);
//        }
//      }
//      elseif ( count($darce)!=3 ) {
//        fce_error("údaje příjemce potvrzení u člena č.$K mají chybný formát");
//      }
//    }
//  }
  // rodné číslo ve formátu pro potvrzení: rc_darce=, r.č.:yymmdd/nnnn, resp. IČ pro právnické osoby
  $x->rc_darce= "";
  if ( $rc_ic_darce ) {
    // pokud je explicitně uvedeno v 'příjemce potvrzení na 4. řádku
    $x->rc_darce= ", $rc_ic_darce";
                                                        debug($x,"příjemce potvrzení=$darce[0]");
  }
  // IČ má přednost před RČ
  elseif ( $tc->ico ) {
    // pokud je uvedeno v položce 'ico'
    $x->rc_darce= ", IČ ".$tc->ico;
  }
  elseif ( $tc->nar_datum!='0000-00-00' && !$tc->darce && $tc->osoba==100 ) {
    // reálné datum narození
    $x->rc_darce= ", dat.narození ".sql_date1($tc->nar_datum);
  }
//  elseif ( $tc->rodcis && !$tc->darce && $tc->osoba==100 ) {
//    if ( strlen($tc->rodcis)==10 ) {
//      // 10 znaků implikuje rodné číslo
//      $x->rc_darce= ", rodné číslo ".substr($tc->rodcis,0,6).'/'.substr($tc->rodcis,6);
//    }
//    elseif ( strlen($tc->rodcis)==8 ) {
//      // 8 znaků implikuje datum narození
//      $x->rc_darce= ", dat.narození ".substr($tc->rodcis,0,2).'.'.substr($tc->rodcis,2,2).'.'.substr($tc->rodcis,4);
//    }
//  }
//                                                         debug($x,"adresa:{$x->adresa_postovni},".($adresa_darce?'T':'F'));
  return $x;
}
# -------------------------------------------------------------------------------------- clen adresa
# vrátí objekt {ulice,cisla,psc,obec,stat} na základě $tc = clen LEFT JOIN clen_uir
# kde ulice obsahuje i číslo orientační resp. popisné resp. obě
function clen_adresa($c) {
  $x= (object)array();
  mb_internal_encoding('UTF-8');
//  if ( $c->uir && $c->adresa_kod ) {
//    // ADR-UIR
//    $x->zdroj= 'UIR-ADR';
//    $qrya= "SELECT psc,
//              ulice.nazev AS _un, cisor_hod, cisor_pis, cisdom_hod,
//              cobce.nazev AS _cn,
//              mcast.nazev AS _mn,
//              posta.nazev AS _pn,
//              obec.nazev AS _on
//            FROM adresa
//            JOIN objekt USING(objekt_kod)
//            JOIN cobce USING(cobce_kod)
//            JOIN obec USING(obec_kod)
//            LEFT JOIN mcast USING(mcast_kod)
//            JOIN posta USING(psc)
//            LEFT JOIN ulice USING(ulice_kod)
//            WHERE adresa_kod={$c->adresa_kod}";
//    $resa= mysql_qry($qrya,0,0,0,'uir_adr');
//    if ( !$resa || pdo_num_rows($resa)!=1 )
//      $x->error= "clen_adresa: kód adresy {$c->id_clen}/{$c->adresa_kod} je chybný";
//    else {
//      $a= pdo_fetch_object($resa);
//      // ulice nebo část obce či města, pošta a obec
//      if ( $a->_un ) {
//        $x->ulice= $a->_un;
//        $x->cisla= $a->cisdom_hod;
//        $x->cisla.= $a->cisor_hod ? "/{$a->cisor_hod}{$a->cisor_pis}" : '';
//        if ( $a->_cn && strpos($a->_pn,$a->_cn)===false )
//          $x->obec= "{$a->_pn} - {$a->_cn}";
//        else
//          $x->obec= $a->_pn;
//      }
//      else if ( $a->_cn && $a->cisor_hod=='' && $a->cisor_pis=='' ) {
//        $x->ulice=  $a->_pn==$a->_cn ? '' : $a->_cn;
//        $x->cisla= $a->cisdom_hod;
//        $x->obec= $a->_pn;
//      }
//      else
//        $x->error= "clen_adresa: kód adresy {$c->id_clen}/{$c->adresa_kod} neurčuje adresu";
//      // psč
//      $x->psc= $a->psc;
//      $x->stat= '';                       // UIR-ADR obsahuje jen tuzemské adresy
//    }
//  }
//  else {
    // AD HOC
    $x->zdroj= 'Klub';
    $x->ulice= $c->ulice;    // ulice obsahuje i čísla, v ad_hoc adresách není definováno x->cisla
    $x->psc= $c->psc;
    $x->obec= $c->obec;
    $x->stat= $c->stat;
//  }
  // naplnění proměnných získaných z AD_HOC i ADR-UIR
  //                                 ----------------

  // formátované PSČ (tuzemské a slovenské)
  $psc= (!$x->stat||$x->stat=='SK'||$x->stat=='Slovensko')
    ? substr($x->psc,0,3).' '.substr($x->psc,3,2)
    : $x->psc;
  // jméno na jeden řádek
  $x->jmeno1= $c->osoba
      ? trim("{$c->titul} {$c->jmeno} {$c->prijmeni}")
      : ( (substr($c->prijmeni,0,3)=='FU ' || $c->prijmeni=='FU' )
        ? "Řk. farnost ".mb_substr($c->prijmeni,4) : $c->prijmeni );
  // úplné jméno fyzické i právnické osoby
  $x->jmeno2= $c->osoba
      ? trim("{$c->titul} {$c->jmeno} {$c->prijmeni}")
      : ( (substr($c->prijmeni,0,3)=='FU ' || $c->prijmeni=='FU' )
        ? "Řk. farnost ".mb_substr($c->prijmeni,4) : $c->prijmeni )
          . ($c->jmeno ? "<br/>{$c->jmeno}" : "");

  if ( $c->uir ) {
    // naplnění proměnných získaných z ADR-UIR
    //                                 -------

    // adresa1    -- adresa na jeden řádek
    $obec= $a->_on=='Praha'
      ? "{$a->_pn} - {$a->_cn}"
      : ($a->_mn ? $a->_mn : $x->obec);
    $x->adresa1= $x->ulice ?  "{$x->ulice} {$x->cisla}, $psc $obec" : "$psc $obec {$x->cisla}";
    // adresa2    -- adresa na dva řádky až tři řádky
    $x->adresa2= ($x->ulice ? $x->ulice : 'č.p.')." {$x->cisla}<br/>$psc $obec"
      . ($x->stat ? "<br/>        {$x->stat}" : "");
  }
  else {
    // naplnění proměnných získaných z AD_HOC
    //                                 ------

    // adresa1    -- adresa na jeden řádek
    $ulice= trim($x->ulice);
    $obec= trim($x->obec);
    $x->adresa1= mb_substr($ulice,0,2,'UTF-8')=='č.'
      ? "$psc $obec $ulice"
      : "$ulice".($ulice ? ', ' : '')."$psc $obec";
    // .. pro kapří legitimaci
    //$ulice= str_replace(' ','$nbsp;',$ulice); bohužel TCPDF neumí
    //$obec= str_replace(' ','$nbsp;',$obec);
    $x->adresa1Lk= mb_substr($ulice,0,2,'UTF-8')=='č.'
      ? "$psc $obec $ulice"
      : "$ulice".($ulice ? '<br>' : '')."$psc $obec";
//                                                 display($x->adresa1k);
    // adresa2    -- adresa na dva řádky až tři řádky
    $x->adresa2= "{$x->ulice}<br/>$psc  {$x->obec}"
      . ($x->stat ? "<br/>        {$x->stat}" : "");
  }
  return $x;
}
# ------------------------------------------------------------------------------------ clen osloveni
# vrátí text oslovení
# na vstupu požaduje výsledek SELECT osloveni,prijmeni5p FROM clen vrácený funkcí pdo_fetch_object
function clen_osloveni($c) {
  global $map_osloveni;
  if ( !isset($map_osloveni) || !$map_osloveni )
    $map_osloveni= map_cis('osloveni','zkratka');
  return
    $c->osloveni!=0 && $c->prijmeni5p!='' ? "{$map_osloveni[$c->osloveni]} {$c->prijmeni5p}" : (
    $c->osloveni!=0                       ? $map_osloveni[$c->osloveni] : 'Milí');
}
# ------------------------------------------------------------------------------------- castka slovy
#c: user.castka_slovy (castka,měna) kde měna=0|1|2 s významem Kč|euro|dolar
#      vyjádří absolutní hodnotu peněžní částky x slovy
function castka_slovy($castka,$mena=0) { //trace();
  $nazvy= array(
    0=> array("korunačeská","korunyčeské","korunčeských","haléřů"),
    1=> array("euro",       "eura",       "eur",         "centů"),
    2=> array("dolar",      "dolary",     "dolarů",      "centů")
  );
  $platidlo= $nazvy[$mena][0];          // nominativ singuláru, default 'koruna'
  $platidla= $nazvy[$mena][1];          // nominativ plurálu, default 'koruny'
  $platidel= $nazvy[$mena][2];          // genitiv plurálu, default 'korun'
  $drobnych= $nazvy[$mena][3];          // genitiv plurálu, default 'haléřů'
  $text= '';
  $cele= floor(abs($castka));
  $mena= array($platidlo,$platidla,$platidel);
  $numero= "$cele";
  if ( strlen($numero)<8 ) {
    $slovnik= array();
    $slovnik[0]= array("","jedna","dvě","tři","čtyři","pět","šest","sedm","osm","devět");
    $slovnik[1]= array("","","dvacet","třicet","čtyřicet","padesát","šedesát","sedmdesát","osmdesát","devadesát");
    $slovnik[2]= array("","sto","dvěstě","třista","čtyřista","pětset","šestset","sedmset","osmset","devětset");
    $slovnik[3]= array("tisíc","jedentisíc","dvatisíce","třitisíce","čtyřitisíce", "pěttisíc","šesttisíc","sedmtisíc","osmtisíc","devěttisíc");
    $slovnik[4]= array("","deset","dvacet","třicet","čtyřicet", "padesát","šedesát","sedmdesát","osmdesát","devadesát");
    $slovnik[5]= array("","sto","dvěstě","třista","čtyřista","pětset","šestset","sedmset","osmset","devětset");
    $slovnik[6]= array("milion","jedenmilion","dvamiliony","třimiliony","čtyřimiliony","pětmilionů","šestmilionů","sedmmilionů","osmmilionů","devětmilionů");
    $slovnik2=   array("deset","jedenáct","dvanáct","třináct","čtrnáct","patnáct","šestnáct","sedmnáct","osmnáct","devatenáct");
    for ($x= 0; $x <= strlen($numero)-1; $x++) {
      if (($x==strlen($numero)-2) && ($numero[$x]=="1")) {
        $text.= $slovnik2[$numero[$x+1]];
        $x++;
      }
      elseif (($x==strlen($numero)-5) && ($numero[$x]=="1")) {
        $text.= $slovnik2[$numero[$x+1]]."tisíc";
        $x++;
      }
      else {
        $text.= $slovnik[strlen($numero)-1-$x][$numero[$x]];
      }
    }
  }
  else {
    $text= "********";
  }
  if ( strlen($numero) > 1 && $numero[strlen($numero)-2]=='1' ) {
    $text.= $mena[2];
  }
  else {
    $slovnik3= array(2,0,1,1,1,2,2,2,2,2);
    $text.= $mena[$slovnik3[$numero[strlen($numero)-1]]];
  }
  $drobne= round(100*($castka-floor($castka)));
  if ( $drobne ) {
    $text.= ",$drobne$drobnych";
  }
  return $text;
}
# ---------------------------------------------------------------------------------==> . substituce
# spočítá hodnoty proměnných podle
#   $c==null -- hodnoty se berou z clen pro dané id_clen, případně i z dar je-li dané id_dar
#   $c!=null -- $c tj. předaných z browse a objektu values
# vrací {strtr,value} jako zobrazení pro funkci strtr resp. asociativní pole
function dop_substituce($vars,$params,$c,$idc=0,$idd=0) {  trace();
  $ret= (object)array('strtr'=>array(),'value'=>array());
  // nasycení $c a $d
  if (!$c) {
    $d= null;
    if ($idc) $c= select_object('*','clen',"id_clen=$idc");
    if ($idd) $d= select_object('*','dar',"id_dar=$idd");
  }
  // výpočet proměnných
  foreach ($vars as $var) {
    switch ($var) {
    // -------------------------------- informace z params
    case 'rocni_rok': $val= $params->rok; break;
    case 'datum': $val= sql_date1($params->datum,0,'. '); break;
    // -------------------------------- informace z browse
    case 'rocni_dary':  $val= str_replace('.',',',$c->dary). ' Kč'; break;
    // -------------------------------- informace z clen
    case 'ID':
      $val= $c->id_clen;
      break;
    case 'adresa':
      $psc= $c->psc ? substr($c->psc,0,3).' '.substr($c->psc,3,2) : '';
      $val= $c->osoba
          ? trim("$c->titul $c->jmeno")." $c->prijmeni"
          : "$c->firma".($c->ico ? "<br>IČO: $c->ico" : '');
      $val.= "<br>$c->ulice<br>$psc $c->obec";
      break;
    case 'osloveni':
      $map_osloveni= map_cis('k_osloveni','zkratka');
      $val= ( $c->osloveni!=0 && $c->prijmeni5p!='' ) 
          ? "{$map_osloveni[$c->osloveni]} {$c->prijmeni5p}" : 'Milí';
      break;
    // -------------------------------- informace z dar
    case 'adresa_darce':
      $psc= $c->psc ? substr($c->psc,0,3).' '.substr($c->psc,3,2) : '';
      $val= $c->osoba
          ? ($d->darce ? $d->darce : trim("$c->titul $c->jmeno")." $c->prijmeni")
          : "$c->firma".($c->ico ? "<br>IČO: $c->ico" : '');
      $val.= "<br>$c->ulice<br>$psc $c->obec";
      break;
    case 'dar_castka':
      $castka= $d->castka;
      $castka= ceil($castka)-$castka==0 ? round($castka).",-" : number_format($castka,2,',',' ');
      $val= $castka;
      break;
    case 'dar_datum':
      $val= sql_date1($d->castka_kdy,0,'. ');
      break;
    case 'dar_potvrzeni':
      $val= sql_date1($d->potvrz_kdy,0,'. ');
      break;
    default:
      $val= "<b style='color:red' title='$var'>???</b>";
      break;
    }
    $ret->strtr['{'."$var}"]= $val;
    $ret->value[$var]= $val;
  }
//                                                         debug($ret);
  return $ret;
}
# ---------------------------------------------------------------------------------==> dop show_vars
# ASK
# vrátí seznamy proměnných: all=všech, use=použitých
function dop_show_vars($idd=0) {  trace();
  $html= '';
  $vars= array(
    'rocni_rok'         => 'roční potvrzení: rok potvrzení',
    'rocni_dary'        => 'roční potvrzení: suma za rok',
    'adresa'            => 'adresa odběratele',
    'datum'             => 'datum odeslání',
    'osloveni'          => 'oslovení odběratele z karty Odběratelé',
    'ID'                => 'ID kontaktu',
    'adresa'            => 'adresa u firmy doplněná o IČO',
    'adresa_darce'      => 'jednotlivý dar: případná změna podle údaje u daru',
    'dar_povrzeni'      => 'jednotlivý dar: datum potvrzení'
  );
  $all= array_keys($vars);
  $use= array();
  if ( $idd ) {
    $d= select('*','dopis',"id_dopis=$idd");
    $idd= $d->id_dopis;
    $obsah= $d->obsah;
    $list= null;
    $is_vars= preg_match_all("/[\{]([^}]+)[}]/",$obsah,$list);
    $use= $list[1];
  }
  // redakce zobrazení
  $bad= array_diff($use,$all);
//                                                         debug($bad,'bad');
  if ( count($bad) ) {
    $html.= "<h3 class='work'>Neznámé proměnné</h3><div style='color:red'>";
    sort($bad);
    foreach ($bad as $x) {
      $html.= "<div><b>{{$x}}</b></div>";
    }
    $html.= '</div>';
  }
  $html.= "<h3 class='work'>Seznam proměnných</h3><dl>";
  ksort($vars);
  foreach ($vars as $k=>$x) {
    $clr= in_array($k,$use) ? 'green' : 'silver';
    $html.= "<dt style='color:$clr'><b>{{$k}}</b></dt><dd><i>$x</i></dd>";
  }
  $html.= '</dl>';
  $vars= (object)array('html'=>$html,'all'=>$all,'use'=>$use);
//                                                       debug($vars,"dop_show_vars($idd)");
  return $vars;
}
