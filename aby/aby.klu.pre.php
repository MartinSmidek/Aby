<?php # (c) 2011 Martin Smidek <martin@smidek.eu>
# ----------------------------------------------------------------------------------==> ch ban_zmena
# ASK
# $idc_new je buďto '*' pokud se ID plátce nemá měnit, nebo nové ID plátce
# povolené kombinace old(typ,idc)->new(typ,idc) 
#   jedar     (6,0)->(5,0)
#   nedar     (5/8,0)->(6,0)
#   spojit    (5/7/8,0/x)->(8/9,x)
#   rozpojit  (9,x)->(5/6,0) nebo (8,x)->(8,0)
# upozornění na chybu obsluhy
#   napřed rozpoj (8/9,x)->(8/9,y)
#   napřed rozpoj (8/9,x)->(5/6,0)
#   už je dar (8/9,x)->(5,0)
# možné hodnoty old (1/5/6/7/8,0),(8/9,x)
function ch_ban_zmena($idd,$typ_new,$idc_new='*') {  trace();
  $y= (object)array('err'=>'','msg'=>'ok');
  $upd= array();
  // zjištění starých hodnot 
  list($typ_old,$idc_old)= select('typ,id_clen','dar',"id_dar=$idd");
  // test zakázaných kombinací
  $ok_kombinace= 
         $idc_old==$idc_new && $idc_new && $typ_old==7 && $typ_new==9 
      || !$idc_old && in_array($typ_old,array(1,5,6,7,8)) 
      || $idc_old && in_array($typ_old,array(8,9));
  if (!$ok_kombinace) {
    $y->err= "chybná vazba ($typ_old,$idc_old) => ($typ_new,$idc_new) - je nutné opravit! Martin)";
    goto end;
  }
  // test upozornění na chybný požadavek
  if ( $idc_old && in_array($typ_old,array(8,9)) && $idc_new && in_array($typ_new,array(8,9)) ) {
    $y->err= "napřed rozpoj od již zapsaného dárce";
    goto end;
  }
  // rozbor povolených operací
  if ( $typ_old==6 && !$idc_old && $typ_new==5 && !$idc_new ) { // jen změna typu
    $upd[]= (object)array('fld'=>'typ', 'op'=>'u','val'=>$typ_new,'old'=>$typ_old);
  }
  elseif ( $typ_old==5 && !$idc_old && $typ_new==6 && !$idc_new ) { // jen změna typu
    $upd[]= (object)array('fld'=>'typ', 'op'=>'u','val'=>$typ_new,'old'=>$typ_old);
  }
  elseif ( in_array($typ_old,array(5,7,8)) && (!$idc_old || $idc_old==$idc_new) // spojit
      && in_array($typ_new,array(8,9)) && $idc_new ) { 
    $upd[]= (object)array('fld'=>'typ', 'op'=>'u','val'=>$typ_new,'old'=>$typ_old);
    $upd[]= (object)array('fld'=>'id_clen', 'op'=>'u','val'=>$idc_new,'old'=>$idc_old);
  }
  elseif ( $typ_old==9 && $idc_old && in_array($typ_new,array(5,6)) && !$idc_new ) { 
    $upd[]= (object)array('fld'=>'typ', 'op'=>'u','val'=>$typ_new,'old'=>$typ_old);
    $upd[]= (object)array('fld'=>'id_clen', 'op'=>'u','val'=>$idc_new,'old'=>$idc_old);
  }
  elseif ( $typ_old==8 && $idc_old && $typ_new==8 && !$idc_new ) { 
    $upd[]= (object)array('fld'=>'id_clen', 'op'=>'u','val'=>$idc_new,'old'=>$idc_old);
  }
  else {
    $y->err= "nepřípustný požadavek na změnu ($typ_old,$idc_old) => ($typ_new,$idc_new)";
    goto end;
  }
  // proveď změnu se zápisem do _track
  ezer_qry("UPDATE",'dar',$idd,$upd);
end:
  return $y;
}
# -------------------------------------------------------------------------------==> ch search_popis
# viz https://php.vrana.cz/vyhledani-textu-bez-diakritiky.php
function ch_search_popis($popis) { 
  $popis= utf2ascii($popis,' .');
  $popis= strtr($popis,array(
      'mgr'=>'', 'mudr'=>'', 'mvdr'=>'', 'rndr'=>'', 'ing'=>'', 'bc'=>'', 
      '_'=>'','.'=>''));
  $popis= trim($popis);
  $cond1= "'$popis' RLIKE CONCAT(ascii_prijmeni,' ',ascii_jmeno)";
  $cond2= "'$popis' RLIKE CONCAT(ascii_jmeno,' ',ascii_prijmeni)";
  $cond3= "CONCAT(ascii_prijmeni,' ',ascii_jmeno) LIKE '%$popis%'";
  $cond4= "CONCAT(ascii_jmeno,' ',ascii_prijmeni) LIKE '%$popis%'";
  $cond= "($cond1 OR $cond2 OR $cond3 OR $cond4) AND NOT (jmeno='' AND prijmeni='') 
      AND NOT (ascii_jmeno='' AND ascii_prijmeni='') ";
//                        display("ch_search_popis($popis) => $cond");
  return $cond;
}
# ----------------------------------------------------------------------------==> ch search_popis_fy
function ch_search_popis_fy($popis) { 
  $popis= strtoupper(strtr($popis,array(' '=>'',','=>'')));
  $firma= "UPPER(REPLACE(REPLACE(firma,' ',''),',',''))";
  $cond1= "'$popis' RLIKE $firma";
  $cond2= "$firma LIKE '%$popis%'";
  $cond= "($cond1 OR $cond2) ";
//                        display("ch_search_popis_fy($popis) => $cond");
  return $cond;
}
# ------------------------------------------------------------------------==> ch remake_ascii_fields
# zajistí korektní nastavení ascii-položek
function ch_remake_ascii_fields($given_idc=0) {
  $only_one= $given_idc ? "AND id_clen=$given_idc" : '';
  $rc= pdo_qry("SELECT id_clen,prijmeni,ascii_prijmeni,jmeno,ascii_jmeno 
    FROM clen WHERE deleted='' $only_one");
  while ($rc && (list($idc,$p,$ap,$j,$aj)=pdo_fetch_row($rc))) {
    $oap= trim(utf2ascii($p,' .'));
    if ($oap!=$ap) query("UPDATE clen SET ascii_prijmeni='$oap' WHERE id_clen=$idc");
    $oaj= trim(utf2ascii($j,' .'));
    if ($oaj!=$aj) query("UPDATE clen SET ascii_jmeno='$oaj' WHERE id_clen=$idc");
  }
}
# ===========================================================================================> BANKA
# ------------------------------------------------------------------------------- ch bank_novy_darce
# založ nového dárce
function ch_bank_novy_darce ($idd) {
  $ret= (object)array('err'=>'');
  // kontroly vhodnosti vytvoření
  list($popis,$typ)= select('ucet_popis,typ','dar',"id_dar=$idd");
  if ($typ!=5) { $ret->err= "lze použít jen na žluté řádky"; goto end; }
  $cond= ch_search_popis($popis);
  $idc= select('id_clen','clen',"deleted='' AND $cond LIMIT 1");
  if ($idc) { $ret->err= "kontakt tohoto jména už v databázi je"; goto end; }
  // vytvoření návrhu 
  list($jmeno,$prijmeni)= preg_split("/[\s,]+/u",trim($popis));
//  display("$popis:$jmeno,$prijmeni");
//  $jmeno= mb_ucfirst(mb_strtolower($jmeno));
//  $prijmeni= mb_ucfirst(mb_strtolower($prijmeni));
  $jmeno= mb_convert_case($jmeno, MB_CASE_TITLE, 'UTF-8');
  $prijmeni= mb_convert_case($prijmeni, MB_CASE_TITLE, 'UTF-8');
  $zname= select('jmeno','_jmena',"jmeno='$jmeno'");
  if ($zname) {
    $ret->jmeno= $jmeno;
    $ret->prijmeni= $prijmeni;
  }
  else {
    $ret->jmeno= $prijmeni;
    $ret->prijmeni= $jmeno;
  }
end:
  return $ret;
}
function ch_bank_uloz_darce($idd,$jmeno,$prijmeni,$telefon,$kategorie) {
  // vlož kontakt
  $upd= array();
  $idc= ezer_qry("INSERT",'clen',0,array(
    (object)array('fld'=>'zdroj',     'op'=>'i','val'=>'VYPIS'),
    (object)array('fld'=>'osoba',     'op'=>'i','val'=>1),
    (object)array('fld'=>'jmeno',     'op'=>'i','val'=>$jmeno),
    (object)array('fld'=>'prijmeni',  'op'=>'i','val'=>$prijmeni),
    (object)array('fld'=>'telefony',  'op'=>'i','val'=>$telefon),
    (object)array('fld'=>'kategorie', 'op'=>'i','val'=>$kategorie)
  ));
  // proveď změnu daru
  ezer_qry("UPDATE",'dar',$idd,array(
    (object)array('fld'=>'id_clen', 'op'=>'u','val'=>$idc), //,'old'=>0),
    (object)array('fld'=>'typ',     'op'=>'u','val'=>9)     //,'old'=>5)
  ));
  ch_remake_ascii_fields($idc);
}
# --------------------------------------------------------------------------------- ch bank kontrola
# kotrola řad
function ch_bank_kontrola ($cis_ucet,$rok) {
  list($zkratka,$nazev,$ucet)= select('zkratka,hodnota,ikona','_cis',
      "druh='b_ucty' AND data='$cis_ucet' ");
  $html= "<div style='font-weight:bold;padding:3px;border-bottom:1px solid black'>
    Účet $zkratka: $ucet - $nazev</div>";
  // projdeme výpisy
  $rv= pdo_query("
    SELECT soubor_od,soubor_do FROM vypis 
    WHERE YEAR(datum_od)='$rok' AND nas_ucet='$cis_ucet'
    ORDER BY datum_od  ");
  while ($rv && (list($soubor_od,$soubor_do)=pdo_fetch_row($rv))) {
    $html.= "<br>$soubor_od $soubor_do";
  }
  return $html;
}
# -------------------------------------------------------------------------------- ch bank_join_dary
# spáruj dary výpisu 
function ch_bank_join_dary ($idv) {
  $rv= pdo_query("SELECT id_dar FROM dar WHERE id_vypis=$idv AND typ=5 AND ucet_popis!='' ");
  while ($rv && (list($idd)=pdo_fetch_row($rv))) {
    ch_bank_join_dar($idd);
  }
}
# --------------------------------------------------------------------------------- ch bank_join_dar
# spáruj dar
function ch_bank_join_dar ($idd) {
  // podrobnosti z převodu a získání podmínky na popis
  list($castka,$datum,$popis,$typ)= 
      select('castka,castka_kdy,ucet_popis,typ','dar',"id_dar=$idd");
  if ($typ==9) goto end;
  $cond= ch_search_popis($popis);
  // hledání dárce
  list($idd2,$idc)= select('id_dar,id_clen','dar JOIN clen USING (id_clen)',
      "zpusob=2 AND typ=9 AND dar.deleted='' AND $cond AND castka=$castka AND castka_kdy='$datum' ");
  if ($idd2) {
//    display("idc=$idc, idd2= $idd2");
    query("UPDATE dar SET deleted='D x' WHERE id_dar=$idd2");
    query("UPDATE dar SET typ=9,id_clen=$idc WHERE id_dar=$idd");
  }
  else {
    $idc= select('id_clen','clen',$cond);
    if ($idc) {
//      display("idc=$idc, idd2= ---");
      query("UPDATE dar SET typ=7,id_clen=$idc WHERE id_dar=$idd");
    }
//    display("? $popis ");
  }
end:
}
# -------------------------------------------------------------------------------- ch bank_load_ucty
function ch_bank_pub($pub,&$p,&$u,&$b,$padding=true) {
  list($pu,$b)= explode('/',$pub);
  list($p,$u)= explode('-',$pu);
  if ( $padding ) {
    if ( $u ) {
      $u= str_pad($u,10,'0',STR_PAD_LEFT);
      $p= str_pad($p,6,'0',STR_PAD_LEFT);
    }
    else {
      $u= str_pad($p,10,'0',STR_PAD_LEFT);
      $p= '000000';
    }
  }
  else {
    if ( !$u ) {
      $u= $p;
      $p= '';
    }
    $u= ltrim($u,'0');
    $p= ltrim($p,'0');
  }
}
# -------------------------------------------------------------------------------- ch bank_load_ucty
# $bank_nase_banky = array ('0100',...)
# $bank_nase_ucty  = array ('0100'=> array('000000-1234567890'=>'X'),...)
# $bank_nase_nucty = array ('X'=> n) -- n je data účtu v _cis.druh=='k_ucty'
function ch_bank_load_ucty () {
  global $bank_nase_banky, $bank_nase_ucty, $bank_nase_nucty;
  if ( !isset($bank_nase_banky) ) {
    $bank_nase_ucty= array();
    $bank_nase_banky= array();
    $qry= "SELECT * FROM _cis WHERE druh='b_ucty' AND ikona!='' ";
    $res= pdo_qry($qry);
    while ( $res && $c= pdo_fetch_object($res) ) {
      bank_pub($c->ikona,$p,$u,$b);
      if ( !in_array($b,$bank_nase_banky) )
        $bank_nase_banky[]= $b;
      $bank_nase_ucty[$b]["$p-$u"]= $c->zkratka;
      $bank_nase_nucty[$c->zkratka]= $c->data;
    }
  }
}
# -----------------------------------------------------------------------------------==> ch ban_load
# ASK
# načtení souboru CSV z ČSAS
function ch_ban_load($file) {  //trace();
  global $ezer_path_root;
  $y= (object)array('err'=>'','msg'=>'ok',idv=>0);
  // definice importovaných sloupců
  $flds_0800= array(
      "Datum zaúčtování"  => array(0,'d','castka_kdy'),
      "Název protiúčtu"   => array(0,'n','ucet_popis'),
      "Protiúčet"         => array(0,'u','ucet'),
      "Částka"            => array(0,'c','castka'),
      "Měna"              => array(0,'m'),
      "Zpráva pro příjemce" => array(0,'p0','zprava'),
      "Zpráva pro mě"     => array(0,'p1','zprava')
    );
  $flds_0600= array(
      "Číslo účtu"        => array(0),
      "Splatnost"         => array(0,'d','castka_kdy'),
      "Částka"            => array(0,'c','castka'),
      "Měna"              => array(0,'m'),
      "Název protiúčtu"   => array(0,'n','ucet_popis'),
      "Číslo protiúčtu"   => array(0,'u1','ucet'),
      "Banka protiúčtu"   => array(0,'u2','ucet'),
      "Variabilní Symbol" => array(0,'vs','vsym'),
      "Zpráva pro příjemce" => array(0,'p0','zprava'),
      "Popis 1"           => array(0,'p1','zprava'),
//      "Popis 2"           => array(0,'p2','zprava'),
//      "Popis 3"           => array(0,'p3','zprava')
    );
  // načti vlastní účty
  $nase_ucty= array(); // účet -> ID
  $res= pdo_qry("SELECT data,ikona FROM _cis WHERE druh='b_ucty' AND ikona!='' ");
  while ( $res && list($idu,$u_b)= pdo_fetch_row($res) ) {
    $nase_ucty[$u_b]= $idu;
  }
//  debug($nase_ucty,'$nase_ucty');
  // rozlišení banky 0800/0600 podle jména souboru
  $m= null;
  $ok= preg_match("~(\d+)_(0800|0600)_(\d\d\d\d_\d\d_\d\d)_(\d\d\d\d_\d\d_\d\d)\.csv~",$file,$m);
  if (!$ok) {
    $y->err= "vložený soubor nemá standardní pojmenování:<br> 'účet-banka_od_do.csv', 
      <br>kde od a do jsou datumy ve tvaru rrrr-mm-dd"; 
    goto end;
  }
  $banka= $m[2];
  $nas_ucet= "$m[1]/$banka";
  $idu= isset($nase_ucty[$nas_ucet]) ? $nase_ucty[$nas_ucet] : 0;
  if (!$idu) { $y->err= "'$nas_ucet' není mezi účty zapsanými v Nastavení"; goto end; }
  $sod= str_replace('_','-',$m[3]);
  $sdo= str_replace('_','-',$m[4]);
  // načtení hlavičky a převodů do pole
  $csv= "$ezer_path_root/banka/$file";
  $data= array();
  ch_csv2array($csv,$data,0, $banka=='0800' ? 'UTF-16LE' : 'CP1250');
  $flds= $banka=='0800' ? $flds_0800 : $flds_0600;
  $od= '9999-99-99'; $do= '0000-00-00';
  $prevody= array(); // idc,
  foreach ($data as $i=>$rec) {
    // v prvním průchodu proveď kontroly a založ záznam pro výpis
    if ($i==0) {
      // ověření existence základních položek
      foreach ($rec as $fld=>$val) {
        if (isset($flds[$fld])) $flds[$fld][0]++;
      }
      foreach ($flds as $fld=>$desc) {
        if (!$desc[0]) { $y->err= "ve výpisu chybí povinné pole '$fld'"; goto end; }
      }
    }
    if ($banka=='0600') {
      // kontrola čísla_účtu pro Monetu
      if ($rec["Číslo účtu"]!=$nas_ucet) {
        $y->err= "na řádku $i je převod na účet {$rec["Číslo účtu"]} 
          což nesouhlasí s účtem výpisu $nas_ucet"; 
        goto end;
      }
    }
    // vložení záznamu
    $set= ''; $castka= 0; $ucet= $popis= $pozn= $zprava= '';
    foreach ($rec as $fld=>$val) {
      list(,$fmt,$f)= $flds[$fld];
      switch ($fmt) {
        // společné
        case 'd': $datum= sql_date($val,1); 
                  $od= strnatcmp($datum,$od)<0 ? $datum : $od; 
                  $do= strnatcmp($datum,$do)>0 ? $datum : $do; 
                  $set.= ", $f='$datum'"; 
                  // test, jestli je v hranicích daných jménem souboru
                  if (strnatcmp($datum,$sod)<0 || strnatcmp($datum,$sdo)>0) {
                    $y->err= "na řádku $i je platba s datem $datum, které neleží v intervalu $sod až $sdo"; 
                    goto end;
                  }
                  $nd= select('COUNT(*)','dar',"nas_ucet=$idu AND deleted='' AND castka_kdy='$datum'");
                  if ($nd) {
                    display("na řádku $i je platba s datem $datum, které již pro tento účet bylo zpracované"); 
//                    $y->war= "na řádku $i je platba s datem $datum, které již pro tento účet bylo zpracované"; 
                    $y->err= "na řádku $i je platba s datem $datum, které již pro tento účet bylo zpracované"; 
                    goto end;
                  }
                  break;
        case 'c': $castka= preg_replace(array("/\s/u","/,/u"),array('','.'),$val);
                  $set.= ", $f=$castka"; break;
        case 'm': if ($val=='CZK') break;
                  $y->err= "nekorunové platby nejsou implementovány"; goto end;
        case 'n': $popis= $val; 
                  $set.= ", $f='$val'"; break;
        // 0800
        case 'u': $ucet= $val; 
                  $set.= ", $f='$val'"; break;
        // 0600
        case 'vs': $set.= ", $f='$val'"; break;
        case 'u1': $ucet= $val; break;
        case 'u2': $ucet.= "/$val"; 
                   $set.= ", ucet='$ucet'"; break;
        case 'p0': $zprava= $val; break;
        case 'p1': $pozn.= " $val"; break;
        case 'p2': $pozn.= " $val"; break;
        case 'p3': $pozn.= " $val"; break;
      }
    }
    // dokončení převodu pro Moneta
    if ($banka=='0600') {
      $pozn= strtr($pozn,array('OKAMŽITÁ ÚHRADA'=>'','PŘEVOD PROSTŘEDKŮ'=>'',$popis=>''));
      $pozn= trim("$zprava $pozn");
      if ($pozn) $set.= ", zprava='$pozn'"; 
    }
    // dokončení převodu pro Moneta
    if ($banka=='0800') {
      $pozn= trim("$zprava $pozn");
      if ($pozn) $set.= ", zprava='$pozn'"; 
    }
    // určení typu a způsobu
    $typ= $castka<=0 ? 1 : ($ucet=='160987123/0300' && $popis=='CESKA POSTA, S.P.' ? 8 : 5);
    $zpusob= $typ==8 ? 3 : 2;
    // pokus o zjištění dárce
    $idc= 0;
    if ($typ==5) {
      // nejprve podle účtu je-li
      if ($ucet)
        $idc= select('id_clen','dar',"zpusob=2 AND ucet='$ucet' ORDER BY castka_kdy DESC LIMIT 1");
      // potom podle popisu
      if (!$idc && $popis) {
        $cond= ch_search_popis($popis);
        $idc= select('id_clen','clen',"deleted='' AND $cond ORDER BY id_clen LIMIT 1");
      }
      $idc= $idc ?: 0;
      $typ= $idc ? 7 : 5;
    }
    // vložení záznamu - pokud jde o příjem
    $set.= ", id_clen=$idc, typ= $typ, zpusob=$zpusob ";
    if ($castka>0) {
      $prevody[]= $set;
    }
  }
  debug($prevody);
  // pokud je vše v pořádku vlož výpis
  query("INSERT INTO vypis SET soubor='$file', nas_ucet=$idu, 
      soubor_od='$sod', soubor_do='$sdo', datum_od='$od', datum_do='$do' ");
  $y->idv= pdo_insert_id();
  // a převody
  foreach ($prevody as $set) {
    query("INSERT INTO dar SET id_vypis=$y->idv, nas_ucet=$idu $set");    
  }
end:
  return $y;
}
