<?php # Aplikace Polička, (c) 2021 Martin Smidek <martin@smidek.eu>

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

# ==========================================================================================> ŠTÍTKY
# --------------------------------------------------------------------------------- dop gener_stitky
# ASK
# vytvoření souboru se štítky
# par = {kateg:0|1,kat:kategorie,darci:0|1,aspon:částka,od:datum,do:datum,firmy:0|1}
function dop_gener_stitky($komu,$par,$report) { 
  debug($par,"dop_gener_stitky");
  $ret= (object)array(pdf=>'',msg=>'');
  // generování podle komu+kat
  $idcs= $err_idcs= array();
  // vynechat firmy?
  $firmy= $par->firmy ? '' : 'AND osoba=1';
  // --------------------- podle kategorie
  if ($par->kateg) {
    $cond= '0';
    foreach ($par->kat AS $k) {
      $cond.= " OR FIND_IN_SET($k,kategorie)";
    }
    $rc= pdo_qry("SELECT id_clen,psc,email 
      FROM clen WHERE deleted='' AND ($cond) $firmy
      ORDER BY CONCAT(firma,prijmeni)  ");
    while ($rc && (list($idc,$psc,$email)=pdo_fetch_row($rc))) {
      if (trim($psc) || trim($email)) 
        if (!in_array($idc,$idcs)) $idcs[]= $idc;
      else
        if (!in_array($idc,$err_idcs)) $err_idcs[]= $idc;
    }
  }
  // --------------------- dárci {a=>vynechat když nemají kat | nebo=>přidat} 
  //                       dali alespoň ... od ... do
  if ($par->darci) {
    $od= sql_date1($par->od,1);
    $do= sql_date1($par->do,1);
    $rc= pdo_qry("SELECT id_clen,psc,email,SUM(castka) AS _celkem 
      FROM clen AS c JOIN dar AS d USING (id_clen)
      WHERE c.deleted='' AND d.deleted='' AND castka_kdy BETWEEN '$od' AND '$do' $firmy
      GROUP BY id_clen HAVING _celkem>='$par->aspon'
      ORDER BY CONCAT(firma,prijmeni)
      ");
    while ($rc && (list($idc,$psc,$email,$celkem)=pdo_fetch_row($rc))) {
      if (trim($psc) || trim($email)) {
        if (!in_array($idc,$idcs)) $idcs[]= $idc;
      }
      else
        if (!in_array($idc,$err_idcs)) $err_idcs[]= $idc;
    }
  }
  // generování štítků, pokud jsou
  $ok= count($idcs);
  if ($ok) {
    // generování způsobilých štítků
    $fname= "stitky_".date('ymd_Hi').".pdf";
    $pdf= dop_rep_stitky($fname,$idcs,$report);
    $ret->pdf= "<b>adresní štítky: </b> $pdf";
    // generování seznamu
    $fname= "seznam_".date('ymd_Hi');
    $flds= $par->firmy 
        ? "id_clen,firma,firma_info,jmeno,prijmeni,ulice,psc,obec,email" 
        : "id_clen,jmeno,prijmeni,ulice,psc,obec,email";
    $xls= dop_rep_seznam($fname,$flds,$idcs,"Seznam ke štítkům");
    $ret->pdf.= ", <b>seznam: </b> $xls";
  }
  // vytvoření zprávy
  $stitku= kolik_1_2_5($ok,'Byl vytvořen $ štítek,Byly vytvořeny $ štítky,Bylo vytvořeno $ štítků');
  $ret->msg= "$stitku";
  $ko= count($err_idcs);
  if ($ko) {
  $stitku= kolik_1_2_5($ko,'štítek přeskočen,štítky přeskočeny,štítků přeskočeno');
    $ret->msg.= ", $stitku - kontakt nemá ani PSČ ani email. 
      <br><br>Přeskočeni byli dárci:";
    foreach ($err_idcs as $idc) {
      list($osoba,$prijmeni,$jmeno,$firma)= 
          select('osoba,prijmeni,jmeno,firma','clen',"id_clen=$idc");
      $ret->msg.= "<br>kontaktu č.$idc: ".($osoba ? "$prijmeni $jmeno" : "$firma");
    }
  }
  return $ret;
}
# ----------------------------------------------------------------------------------- dop rep_seznam
# tisk seznamu členů do Excelu
# struktura pole $adresy
#   array(-$idc,$osloveni,$titul,$jmeno,$prijmeni,$organizace,$ulice,$obec,$psc,$kusy,$konto)
# $report_json obsahuje: jmeno, adresa
function dop_rep_seznam($fname,$flds,$idcs,$title) { trace();
  // vlastní export do Excelu
  $xls= <<<__XLS
    |open $fname
    |sheet vypis;;L;page
    |A1 $title ::bold size=14
__XLS;
  $n= 4;
  $lc= 0;
  $clmns= $del= '';
  $lc= 0;
  if ( $flds ) foreach (explode(',',$flds) as $fld) {
    $A= Excel5_n2col($lc);
    $w= strtr($fld,array('psc'=>7,'email'=>30,'firma'=>20,'firma_info'=>15,
        'prijmeni'=>15,'ulice'=>15,'id_clen'=>7)); 
    $fld= $fld=='id_clen' ? 'ID' : $fld;
    $xls.= "|$A$n $fld";
    if ( $w!=$fld ) {
      $clmns.= "$del$A=$w";
      $del= ',';
    }
    $lc++;
  }
  if ( $clmns ) $xls.= "\n|columns $clmns ";
  $xls.= "\n|A$n:$A$n bcolor=ffc0e2c2 wrap border=+h|A$n:$A$n border=t\n";
  $n= 5;                                   // první řádek dat (pro sumy)
  // datové řádky

  // projdi kontakty
  foreach ($idcs as $idc) {
    $xls.= "\n";
    $lc= 0;
    $row= select_object($flds,'clen',"id_clen=$idc");
//                                                  debug($row);
    foreach (explode(',',$flds) as $fld) {
      $A= Excel5_n2col($lc);
      $val= strtr($row->$fld,array("\n\r"=>"  ","®"=>""));
      $val= str_replace("\n","{}",$val);        // ochrana proti řádkům v hodnotě - viz ae_slib
      if ($fld=='psc') {
        $val= str_replace(" ","",$val);
      }
      $xls.= "|$A$n $val";
      $lc++;
    }
    $n++;
  }
  // předání ke generování
  $xls.= "\n|close";
  require_once "ezer3.1/server/vendor/autoload.php";
  Excel2007($xls,1);
  $ref= "<a href='docs/$fname.xlsx' target='xls'>$fname.xlsx</a>";
  return $ref;
}
# ----------------------------------------------------------------------------------- dop rep_stitky
# tisk adresních štítků pro seznam členů (přes TCPDF)
# struktura pole $adresy
#   array(-$idc,$osloveni,$titul,$jmeno,$prijmeni,$organizace,$ulice,$obec,$psc,$kusy,$konto)
# $report_json obsahuje: jmeno, adresa
function dop_rep_stitky($fname,$idcs,$report_json,$ramecek=0) { trace();
  global $ezer_path_docs, $json;
  // rozbalení reportu
  if ( !isset($json) ) $json= new Services_JSON_Ezer();  
  $report= $json->decode($report_json);
  $map_osloveni= map_cis('k_osloveni','zkratka');

//  /**/                                               debug($report,"report $report_json");
  $texty= array();
  // projdi kontakty
  foreach ($idcs as $i=>$idc) {
    list($osoba,$ulice,$psc,$obec,
        $titul,$jmeno,$prijmeni,$titul_za,$firma,$firma_info,$osloveni)= 
      select('osoba,ulice,psc,obec,
        titul,jmeno,prijmeni,titul_za,firma,firma_info,osloveni','clen',"id_clen=$idc");
    if ($osoba) { // fyzická osoba
      $adresa= $osloveni!=0 ? "{$map_osloveni[$osloveni]}<br>" : '';
      $adresa.= trim("$titul $jmeno $prijmeni $titul_za");
    }
    else { // firma
      $adresa= trim("$firma<br>$firma_info");
    }
    // na adresu
    $adresa.= "<br>$ulice<br>$psc $obec";
    $texty[$i]= (object)array(adresa=>$adresa);
    if ($ramecek ) $texty[$i]->stitek= ' ';
  }
//  /**/                                           debug($texty,"pdf_rep_stitky");
  // předání k tisku
  $fpath= "$ezer_path_docs/$fname";
  tc_report($report,$texty,$fpath);
  $ref= "<a href='docs/$fname' target='pdf'>$fname</a>";
  return $ref;
}
# =========================================================================================> SEZNAMY
# --------------------------------------------------------------------------------------- dop seznam
# seznam dárců podle zadaných parametrů
#   vecny=1 - jen věcné dary
#     nazev - titulek
#   vecny=0 - finanční dary
#     ucty - které mají být zahrnuty
#     neucty - které mají být vynechány
#     kdo - 0|1|0,1
#     velky - mez významého daru
#     nazev - titulek
function dop_seznam($par,$ref=0) { trace(); 
  $html= '';
  $idcs= array();
  // společné parametry
  $nazev= $par->nazev;
  $i_firmy= $par->kdo=='1' ? 0 : 1;
  if (isset($par->vecne) || $par->vecne==1) {
    // parametry věcných darů    
    $vecne= 1;
    $cond= "zpusob=4";
    $HAVING= '';
    $note= " obsahuje všechny dárce věcných darů";
  }
  else {
    // parametry finančních darů
    $vecne= 0;
    $velky= $par->velky;
    $note_kdo= strtr($par->kdo,
        array('0,1'=>'firemní i individuální','0'=>'firemní','1'=>'individuální'));
    $note= " obsahuje $note_kdo dárce finančních darů";
    $cond= "zpusob!=4";
    $cond.= isset($par->kdo) ? ($cond?' AND ':'')."osoba IN ($par->kdo)" : '';
    // dárci určení účtem
    $ucty= isset($par->ucty) ? select('GROUP_CONCAT(data)','_cis',
        "druh='b_ucty' AND FIND_IN_SET(zkratka,'$par->ucty') ") : '';
    $neucty= isset($par->neucty) ? select('GROUP_CONCAT(data)','_cis',
        "druh='b_ucty' AND FIND_IN_SET(zkratka,'$par->neucty') ") : '';
    $cond.= $ucty ? ($cond?' AND ':'')."FIND_IN_SET(nas_ucet,'$ucty')" : '';
    $cond.= $neucty ? ($cond?' AND ':'')."NOT FIND_IN_SET(nas_ucet,'$neucty')" : '';
    $HAVING= $velky ? "HAVING _celkem>=$velky" : '';
    $note.= $par->ucty ? " na účty $par->ucty" : '';
    $note.= $par->neucty ? " ale ne na účty $par->neucty" : '';
    $note.= $velky ? ", celkem darujících aspoň $velky Kč" : ', jakkoliv velkých';
  }
  // přehled darů
  $darci= array();
  $html.= "<h3>$nazev</h3>";
  $rd= pdo_qry("
    SELECT id_clen, SUM(castka) AS _celkem, 
      IF(osoba=1,CONCAT(titul,' ',prijmeni,' ',jmeno,' ',titul_za),firma) AS _jmeno,
      IF(osoba=1,CONCAT(prijmeni,' ',jmeno),firma) AS _order
    FROM dar AS d JOIN clen AS c USING (id_clen)
    WHERE $cond AND c.deleted='' AND d.deleted='' 
    GROUP BY id_clen $HAVING
    ORDER BY _order
  ");
  while ($rd && (list($idc,$dar,$jmeno,$ord)= pdo_fetch_row($rd))) {
    $idcs[]= $idc;
    $jmeno= trim($jmeno);
    $darci[]= $ref ? klub_ukaz_clena($idc)." $jmeno": $jmeno;
  }
  if (count($darci)) {
    $del= '';
    foreach($darci as $jmeno) {
      $html.= "$del$jmeno"; $del= '; ';
    }
    $html.= '<br>';
  }
  // generování seznamu
  $fname= "seznam_".date('ymd_Hi');
  $flds= $i_firmy 
      ? "id_clen,firma,firma_info,jmeno,prijmeni,ulice,psc,obec,email" 
      : "id_clen,jmeno,prijmeni,ulice,psc,obec,email";
  $xls= dop_rep_seznam($fname,$flds,$idcs,$nazev);
  return "<b>vygnerovaný $xls</b> $note<br>$html";
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
# =================================================================================> ROČNÍ POTVRZENI
# -----------------------------------------------------------------------------------==> . potvrzení
# ASK
# provede substituce pro rozeslání potvrzení
# pokud je zadáno idc vygeneruje dopis jen pro nej
function dop_potvrzeni($browse_status,$params,$idc=0) {  trace();
  global $ezer_path_docs;
  $ret= (object)array(pdf=>'',msg=>'');
  $ref= $err= '';
  $dopis= select_object('*','dopis',"vzor='rocni'");
  $html= $dopis->obsah; // poslední zůstane jako ukázka
  $vars= dop_show_vars($dopis->id_dopis);
  // doplníme adresu
  $vars->use[]= 'adresa';
//                                                         debug($vars);
  $browse= browse_status($browse_status,$idc?"id_clen=$idc":1);
  $rc= pdo_qry($browse->qry);
  // zahájení generování
  $n= 0;
  $dopisy= array();
  while ($rc && ($clen= pdo_fetch_object($rc))) {
    $n++;
    // generování dopisu, personifikuj obsah
    $subs= dop_substituce($vars->use,$params,$clen);
    $ret->html= strtr($html,$subs->strtr);
    $dop= (object)array('text'=>$ret->html,'adresa'=>$subs->value['adresa']);
    $dopisy[]= $dop;
  }
  // generování PDF a předání odkazu
  $fname= "potvrzeni_".date('ymd_Hi').".pdf";
  $f_abs= "$ezer_path_docs/$fname";
  $f_rel= "docs/$fname";
  $listu= null;
  tc_dopisy($dopisy,$f_abs,'rozesilani','_user',$listu,'D','');
  $ref= "<a target='dopis' href='$f_rel'>zde</a>";
  $ret->pdf= "Vygenerované PDF je ke stažení $ref";
  // zápis počtu k dopisu
  query("UPDATE dopis SET pocet=$n WHERE id_dopis=$dopis->id_dopis");
  // vytvoření zprávy
  $dopisu= kolik_1_2_5($n,'Byl vytvořen $ dopis,Byly vytvořeny $ dopisy,Bylo vytvořeno $ dopisů');
  $ret->msg= "$dopisu";
end:
  return $ret;
}
# ---------------------------------------------------------------------------------==> . zapis_datum
# ASK
# zapíše datum odeslání potvrzení k darům
function dop_zapis_datum($browse_status,$params,$idc=0) {  trace();
  $rok= $params->rok;
  $kdy= sql_date1($params->datum,1);
  // projdeme řádky browse
  $browse= browse_status($browse_status,$idc?"id_clen=$idc":1);
  $rc= pdo_qry($browse->qry);
  $n= 0;
  while ($rc && ($clen= pdo_fetch_object($rc))) {
    $n++;
    $idc= $clen->id_clen;
    // zapiš datum odeslání nepotvrzeným finančním darům
    query("UPDATE dar SET potvrz_kdy='$kdy' WHERE 
      id_clen=$idc AND YEAR(castka_kdy)=$rok AND potvrz_kdy='0000-00-00' AND zpusob!=4 ");
  }
end:
  return "U $n dárců bylo doplněno datum odeslání potvrzení darů ";
}
# =================================================================================> HROMADNÉ DOPISY
# --------------------------------------------------------------------------------------==> . ukázka
# ASK
# uloží dopis idd personifikovaný pro idc
function dop_ukazka($idd,$idc) {  trace();
  global $ezer_path_docs;
  $vars= dop_show_vars($idd);
  $dopis= select('*','dopis',"id_dopis=$idd");
  $params= (object)array('datum'=>$dopis->datum);
  $subs= dop_substituce($vars->use,$params,null,$idc);
  // pro testování doplň title se jménem proměnné
  if ( $vars ) foreach ($subs->strtr as $var=>$pair) {
    $pairs[$var]= "<span title='$var'>$pair</span>";
  }
  // pokud dopis obsahuje proměnné, personifikuj obsah
  $html= $dopis->obsah;
  if ( $vars ) {
    $html= strtr($html,$pairs);
  }
  // generování PDF a předání odkazu
  $fname= -$idc."_ukazka_".date('ymd_Hi').".pdf";
  $f_abs= "$ezer_path_docs/$fname";
  $f_rel= "docs/$fname";
  tc_html_open();
  tc_html_write($html,'');
  tc_html_close($f_abs);
  $ref= "<a target='dopis' href='$f_rel'>zde</a>";
  return (object)array('html'=>$html,'ref'=>$ref);
}
# --------------------------------------------------------------------------------------==> . všem
# ASK
# provede substituce pro dané odběratele
# pokud je zadáno idc vygeneruje dopis jen pro nej
function dop_vsem($idd,$idc=0) {  trace();
  global $ezer_path_docs;
  $ret= (object)array(pdf=>'',msg=>'');
  $ref= $err= '';
  $vars= dop_show_vars($idd);
  $dopis= select('*','dopis',"id_dopis=$idd");
  $params= (object)array('datum'=>$dopis->datum);
  // zahájení a získání seznamu ID pro obeslání
  $idcs= $err_idcs= array();
  switch ($dopis->adresati) {
    case 1: // --------------------- podle kategorie
      $rc= pdo_qry("SELECT id_clen,psc FROM clen 
        WHERE deleted='' AND FIND_IN_SET({$dopis->kategorie},kategorie)");
      while ($rc && (list($idc,$psc)=pdo_fetch_row($rc))) {
        if (trim($psc)) 
          $idcs[]= $idc;
        else
          $err_idcs[]= $idc;
      }
      break;
    default: $err= "Dopis má chybný výběr adresátů"; goto end;
  }
  // zápis počtu k dopisu
  $ok= count($idcs);
  query("UPDATE dopis SET pocet=$ok WHERE id_dopis=$idd");
  // generování dopisů, pokud jsou
  if ($ok) {
    // generování způsobilých dopisů
    tc_html_open();
    foreach ($idcs as $idc) {
      $subs= dop_substituce($vars->use,$params,null,$idc);
      // pokud dopis obsahuje proměnné, personifikuj obsah
      $html= $dopis->obsah;
      if ( $vars ) {
        $html= strtr($html,$subs->strtr);
      }
      tc_html_write($html,'');
    }
    // generování PDF a předání odkazu
    $fname= "dopis_{$idd}_".date('ymd_Hi').".pdf";
    $f_abs= "$ezer_path_docs/$fname";
    $f_rel= "docs/$fname";
    tc_html_close($f_abs);
    $ref= "<a target='dopis' href='$f_rel'>zde</a>";
    $ret->pdf= "Vygenerované dopisy jsou ke stažení $ref";
  }
  // vytvoření zprávy
  $dopisu= kolik_1_2_5($ok,'Byl vytvořen $ dopis,Byly vytvořeny $ dopisy,Bylo vytvořeno $ dopisů');
  $ret->msg= "$dopisu";
  $ko= count($err_idcs);
  if ($ko) {
  $dopisu= kolik_1_2_5($ko,'dopis vytvořen nebyl,dopisy vytvořeny nebyly,dopisů vytvořeno nebylo');
    $ret->msg.= ", ale $dopisu - v adrese chybí PSČ. 
      <br><br>Týká se to:";
    foreach ($err_idcs as $idc) {
      list($osoba,$prijmeni,$jmeno,$firma)= 
          select('osoba,prijmeni,jmeno,firma','clen',"id_clen=$idc");
      $ret->msg.= "<br>kontaktu č.$idc: ".($osoba ? "$prijmeni $jmeno" : "$firma");
    }
  }
end:
  return $ret;
}
# ===================================================================================> PERSONIFIKACE
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
