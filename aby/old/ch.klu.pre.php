<?php # (c) 2011 Martin Smidek <martin@smidek.eu>
/** =======================================================================================> IMPORTY */
# importní filtry pro formáty
# Komerční banka:       GPC, KPC
# Volksbanka:           GEM (ACE), KPC
//if (!function_exists('fnmatch')) {
//  function fnmatch($pattern, $string) {
//    return @preg_match('/^' . strtr(addcslashes($pattern, '\\.+^$(){}=!<>|')
//      , array('*' => '.*', '?' => '.?')) . '$/i', $string);
//  }
//}
# -------------------------------------------------------------------------------------------------- bank_pub
# rozklad textového zápisu účtu na složky
# $padding -- true přidá levostranné nuly, false odstraní nuly případně i předčíslí
function bank_pub($pub,&$p,&$u,&$b,$padding=true) {
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
# -------------------------------------------------------------------------------------------------- bank_load_ucty
# $bank_nase_banky = array ('0100',...)
# $bank_nase_ucty  = array ('0100'=> array('000000-1234567890'=>'X'),...)
# $bank_nase_nucty = array ('X'=> n) -- n je data účtu v _cis.druh=='k_ucty'
function bank_load_ucty () {
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
# -------------------------------------------------------------------------------------------------- bank_import0
# ASK - Zjistit výpisy
# porovná složku $path s tabulkou vypis a vrátí seznam bankovních převodů typu $type (ACE|GEM|GPC)
# vyhovujících masce $patt
# do pole $bank_soubory=>banka vloží pole jmen dosud neimportovaných souborů
function bank_import0($patt='*') {
//                                                 display("banka_import0($patt)");
  global $path_banka, $bank_soubory, $y, $vypisy, $bank_nase_ucty, $bank_nase_banky, $ezer_root;
  $banka_typ= array ( '6800' => 'GEM', '0100' => 'GPC', '0300' => 'GPC|GP_' );
  bank_load_ucty();                             // zajisti naplnění $bank_nase_ucty,$bank_nase_banky
  $result= '';
  $err= 0;
  $bank_soubory= array();
//  if (!function_exists('fnmatch')) { 
//    function fnmatch($pattern, $string) {
//      return @preg_match('/^' . strtr(addcslashes($pattern, '\\.+^$(){}=!<>|')
//        , array('*' => '.*', '?' => '.?')) . '$/i', $string);
//    }
//  }
//                                                 debug($bank_nase_ucty,'$bank_nase_ucty');
  foreach ($bank_nase_banky as $banka) {
    $banka_kody= "";
    foreach ($bank_nase_ucty[$banka] as $kod) {
      $banka_kody.= $kod;
    }
    $bank_soubory[$banka]= array();
    $path= $path_banka[$banka];
    if ( !$path ) {
      $err++;
      $result= "u banky $banka neumím číst výpisy";
      continue;
    }
    $handle= @opendir($path);
    while ($handle && false !== ($file= readdir($handle))) {
      if ($file=='.'||$file=='..' ) continue;
      $info= pathinfo($path.$file);
      $typ= strtoupper($info['extension']);
      $soubor= $info['filename'];
                                                 display("-- $path $soubor.$typ");
      if ( in_array($typ,explode('|',$banka_typ[$banka])) && fnmatch($patt,$soubor) ) {
        if ( $banka=='0800' ) {
          if ( $typ=='CSV' ) {
            // pokud obsahuje aspoň 1 výpis z účtu, tak
            // přejmenovat                // 0     1    2         3  4
//            $part= explode('_',$soubor);  //'bb'IČ_účet_yyyymmdd_'d'_n
//            $ucet= "000000-".str_pad($part[1],10,'0',STR_PAD_LEFT);
//            $date= substr($part[2],0,8);
//            $year= substr($date,0,4);
//                                                 debug($part,"CSAS $ucet|$date|$year -- $path $soubor.$typ");
          }
          else {
            $err++;
            $result= "vypis z banky 0800 ma koncovku '$typ'";
          }
          $qry1= "SELECT * FROM vypis WHERE soubor='$soubor' ";
          $res1= pdo_qry($qry1);
          if ( $res1 ) {
//                                                   display("$soubor:".pdo_num_rows($res1));
            $rows1= pdo_num_rows($res1);
            $row1= pdo_fetch_assoc($res1);
            $v_ident= $row1['ident'];
            if ( !$rows1 ) {
              $result.= " $banka:$soubor.$typ";
              $bank_soubory[$banka][]= $soubor;
            }
          }
        }
        else {
          $err++;
          $result= "u banky $banka neumím číst výpisy";
        }
      }
    }
    if ( $handle ) closedir($handle);
    else fce_error("Cesta '$path' pro výpisy banky '$banka' v $ezer_root.php není platná");
  }
//                                                         debug($bank_soubory);
  return (object)array('html'=>$result ? $result : '---','err'=>$err);
}
# -------------------------------------------------------------------------------------------------- bank_import1
# zjistí dostupné výpisy a vybere nejstarší dosud nevložený z banky $banka
# pokud je $to_move==1 tak naimportované soubory přesune do $path/yyyy
function bank_import1($bank,$to_move=0) {
                                                display("banka_import1($banka,$to_move)");
  global $bank_soubory;
  $soubor= '';
  bank_import0();
  if ( count($bank_soubory[$bank]) ) {
    sort($bank_soubory[$bank]);
    $soubor= $bank_soubory[$bank][0];
    $one= bank_import($soubor,0,$to_move);
  }
//                                                 debug($bank_soubory,$soubor);
  return $soubor;
}
# -------------------------------------------------------------------------------------------------- bank_imported
# zjistí zda nejstarší výpis v importní složce banky $banka je už vložený
# POZOR 6800 neliší názvy mezi roky
function bank_imported($bank) {
                                                display("banka_imported($banka)");
  global $bank_soubory;
  $imported= '';
  bank_import0();
  if ( count($bank_soubory[$bank]) ) {
    sort($bank_soubory[$bank]);
    $soubor= $bank_soubory[$bank][0];
    $year= $bank='0100' || $bank='0300' ? substr($soubor,0,4) : date('Y');
    $qry1= "SELECT * FROM vypis WHERE soubor='$soubor' AND year(datum)=$year";
    $res1= pdo_qry($qry1);
    if ( $res1 && pdo_num_rows($res1) ) $imported= $soubor;
  }
//                                                 debug($bank_soubory,$soubor);
  return $imported;
}
# -------------------------------------------------------------------------------------------------- bank_import_remove
# provede smazání vypis a prevod vzniklých z importu daného souboru z daného data
function bank_import_remove($soubor,$datum) {
//                                                 display("bank_import_remove($soubor,$datum)");
  global $path_banka, $y, $vypisy;
  // projdi výpisy
  $p= 0;
  $sql_datum= sql_date($datum,1);
  $qry1= "SELECT * FROM vypis WHERE soubor='$soubor' AND datum='$sql_datum' GROUP BY ident";
  $res1= pdo_qry($qry1);
  while ( $res1 && $row1= pdo_fetch_assoc($res1) ) {
    // odstraň převody
    $v_ident= $row1['ident'];
    $qry2= "DELETE FROM prevod WHERE left(prevod.ident,7)='$v_ident' ";
    $res2= pdo_qry($qry2);
    $p+= pdo_affected_rows();
  }
  // odstraň výpisy
  $qry3= "DELETE FROM vypis WHERE soubor='$soubor' AND datum='$sql_datum'";
  $res3= pdo_qry($qry3);
  $v= pdo_affected_rows();
  // redakce zprávy
  $text.= "$soubor z $datum: odstraněno $v výpisů a $p převodů";
  return $text;
}
# -------------------------------------------------------------------------------------------------- bank_import
# ASK - Přidej výpisy
# porovná složku $path s tabulkou vypis a provede import bankovních převodů typu $type (ACE|GEM|GPC)
# vyhovujících masce $patt
# pokud je $reimport==1 provede smazání položek v vypis a prevod a potom provede import
# pokud je $to_move==1 tak naimportované soubory přesune do $path/yyyy
# change= 'vypisy'|'prevody' určuje (pokud je reimport) zda se mení jen výpis nebo i jeho převody
function bank_import($patt='*',$reimport=0,$to_move=0,$change='vypisy') {
                                                display("banka_import($patt,$reimport,$to_move,$change)");
  global $path_banka, $y, $vypisy, $bank_nase_ucty, $bank_nase_banky, $ezer_root;
  $varsyms= map_cis('varsym','zkratka');
  $kontrola= $change=='missing';
  $msg= '';
  $n_vypisu= 0;
  $ids_vypisu= '';
//  if (!function_exists('fnmatch')) {
//    function fnmatch($pattern, $string) {
//      return @preg_match('/^' . strtr(addcslashes($pattern, '\\.+^$(){}=!<>|')
//        , array('*' => '.*', '?' => '.?')) . '$/i', $string);
//    }
//  }
  // projdeme všechny naše účty
  $banka_typ= array ( '6800' => 'GEM', '0100' => 'GPC', '0300' => 'GPC' );
  bank_load_ucty();                             // zajisti naplnění $bank_nase_ucty,$bank_nase_banky
//                                         debug($bank_nase_banky,'$bank_nase_banky');
//                                         debug($bank_nase_ucty,'$bank_nase_ucty');
//                                         debug($path_banka,'$path_banka');
  foreach ($bank_nase_banky as $banka) {
    $banka_kody= "";
    foreach ($bank_nase_ucty[$banka] as $kod) {
      $banka_kody.= $kod;
    }
    $path= $path_banka[$banka];
    if ( !$path ) {
      fce_error("Soubor $ezer_root.php neobsahuje cestu pro výpisy banky '$banka'");
      continue;
    }
    $handle= @opendir($path);
    while ($handle && false !== ($file= readdir($handle))) {
      $info= pathinfo($path.$file);
      $typ= strtoupper($info['extension']);
      $soubor= $info['filename'];
      $vypisy= array();
      if ( $typ==$banka_typ[$banka] && fnmatch($patt,$soubor) ) {
//                                         display("$soubor: reimport=$reimport, fnmatch($patt,$soubor)=".fnmatch($patt,$soubor));
        $year= $banka=='0100' || $banka=='0300' ? substr($soubor,0,4) : date('Y');
        $wh= $banka=='0300'
           ? "soubor='$soubor'"
           : "soubor='$soubor' AND LOCATE(ucet,'$banka_kody') AND year(datum)=$year";
        $qry1= "SELECT * FROM vypis WHERE $wh";
//                   WHERE soubor='$soubor' AND LOCATE(ucet,'$banka_kody') AND year(datum)=$year";
        $res1= pdo_qry($qry1);
        $rows1= $res1 ? pdo_num_rows($res1) : 0;
        if ( $rows1==0 || $reimport ) {
//                                         display("$soubor/$rows1: prošlo testem");
          $row1= pdo_fetch_assoc($res1);
          $v_ident= $row1['ident'];
          if ( $rows1 && !$kontrola ) {
            // pokud je soubor v tabulkách vypis a prevod, vymažeme jej
            if ( $reimport==0 || $change=='prevody' ) {
              // ale převody jen pokud se to chce
              $qry3= "DELETE FROM prevod USING prevod,vypis WHERE soubor='$soubor' AND left(prevod.ident,7)=vypis.ident ";
              $res3= pdo_qry($qry3);
            }
            $qry2= "DELETE FROM vypis WHERE soubor='$soubor' ";
            $res2= pdo_qry($qry2);
          }
          // provedeme vlastní import do polí vypisy, prevody
          switch ( $typ ) {
          case 'ACE':                                   // 6800 old
            $buf= file_get_contents($path.$file);
            bank_ace($buf,$soubor,$yyyy);
            break;
          case 'GEM':                                   // 6800
            $buf= file_get_contents($path.$file);
            bank_gem($path.$file,$soubor,$yyyy);
            break;
          case 'GPC':                                   // 0100, 0300
            if ( $banka=='0100' ) {
              bank_gpc_kb($path.$file,$soubor,$yyyy);
                                                                         display("bank_gpc_kb($path.$file,$soubor,$yyyy)");
//                                                                       debug($vypisy,"0100");
            }
            else if ( $banka=='0300' ) {
              bank_gpc_csob($path.$file,$soubor,$yyyy);
                                                                         display("bank_gpc_csob($path.$file,$soubor,$yyyy)");
//                                                                       debug($vypisy,"0300");
            }
            break;
          }
          // provedeme záměny čísel účtů symboly a vyrobíme ident
          foreach ( $vypisy as $v => $vypis ) {
            $ucet= $bank_nase_ucty[$banka][$vypis['ucet']];
            // pokud se má účet ignorovat, jdi na další
            $ignorovat= !isset($bank_nase_ucty[$banka][$vypis['ucet']]);
            if ( $ignorovat ) {
                                                display("vypis $v z uctu {$vypis['ucet']} byl ignorovan (1)");
              continue;
            }
//             if ( !$ucet ) return fce_error("Výpis $soubor: účet '{$vypis['ucet']}' není zapsán v číselníku ".
//               "vlastních účtů banky $banka");
            if ( $typ=='GEM' ) {
              // pro formát GEM provedeme kontrolu konzistence jména souboru a obsahu
              if ( $soubor[0]!=$ucet )
                return fce_error("soubor $soubor obsahuje výpis účtu {$vypis['ucet']} - to je špatně vytvořené jméno");
              if ( substr($soubor,3,3)!=$vypis['vypis'] )
                return fce_error("soubor $soubor obsahuje výpis číslo {$vypis['vypis']} - to je špatně vytvořené jméno");
            }
            $vypisy[$v]['ucet']= $ucet;
            $vypisy[$v]['prijmu']= 0;
            $v_ident= $ucet.substr($vypis['datum'],2,2)."_".$vypis['vypis'];
            $vypisy[$v]['ident']= $v_ident;
            if ( $vypis['prevody'] ) foreach ( $vypis['prevody'] as $p => $prevod ) {
              $metoda= '';
              if ( $prevod['typ']>=5 ) {
                $vypisy[$v]['prijmu']++;
              }
              $vypisy[$v]['prevody'][$p]['metoda']= '';
              $vypisy[$v]['prevody'][$p]['ucet']= $ucet;
              $vypisy[$v]['prevody'][$p]['ident']= $v_ident.$prevod['ident'];
              // přesuny mezi vlastními účty
              if ( $bank_nase_ucty[$prevod['banka']][$prevod['protiucet']] ) {
                $vypisy[$v]['prevody'][$p]['kat']= "#u";
                $vypisy[$v]['prevody'][$p]['metoda'].= ', vlastní účet';
              }
              // příjem s ksym=0998 je dar - soupis složenek
              else if ( $prevod['typ']==5 && $prevod['ksym']=='0998' ) {
                $vypisy[$v]['prevody'][$p]['typ']= 8;
                $vypisy[$v]['prevody'][$p]['kat']= "+d";
                $vypisy[$v]['prevody'][$p]['metoda'].= ' je třeba rozepsat z poukázek';
              }
              // může být příjem darem?
              else if ( $prevod['typ']==5 ) {
//                                                 display("{$vypisy[$v]['prevody'][$p]['ident']} ... ");
                $clen= 0;
                $vsym= ltrim($prevod['vsym'],'0');
                if ( !isset($varsyms[$vsym]) && $prevod['typ']==5 ) {
                  // neznámý variabilní symbol není darem
                  $vypisy[$v]['prevody'][$p]['typ']= 6;
                  $vypisy[$v]['prevody'][$p]['metoda'].= 'neurčen, varsym je mimo číselník';
                }
                else {
                  if ( !$clen && $prevod['typ']==5 && $prevod['protiucet']!='000000-0000000000' ) {
                    // pokud je účet nenulový
                    // zhoduje se číslo účtu s nějakým uvedeným v kontaktu?
                    $clen= bank_ucet2clen($prevod['protiucet']);
                    if ( $clen) {
                      $vypisy[$v]['prevody'][$p]['metoda'].= ' podle účtu zapsaného u&nbsp;kontaktu';
//                                                               display("... U $clen");
                    }
                    else {
                      // přišel z něj někdy dar, který jsme určili?
                      $cond= "protiucet='{$prevod['protiucet']}' AND banka='{$prevod['banka']}' AND clen!=0 ";
                      $qry4= "SELECT clen FROM prevod WHERE $cond ORDER BY splatnost DESC LIMIT 1";
                      $res4= pdo_qry($qry4);
                      $rows4= pdo_num_rows($res4);
                      if ( $rows4 ) {
                        $row4= pdo_fetch_assoc($res4);
                        $clen= $row4['clen'];
                      }
                      if ( $clen) {
                        $vypisy[$v]['prevody'][$p]['metoda'].= ' podle obdobného převodu dříve';
//                                                                 display("... U $clen");
                      }
                    }
                  }
                  if ( !$clen ) {
                    // poznáme člena z POPISu účtu (rozkladem na příjmení a jméno a s požadavkem jednznačnosti
                    $clen= bank_popis2clen($prevod['popis'],$metoda);
                    if ( $clen) {
                      $vypisy[$v]['prevody'][$p]['metoda'].= ' podle jednoznačnosti jména';
//                                                                 display("... N $clen");
                    }
                  }
                  if ( $clen ) {
                    $vypisy[$v]['prevody'][$p]['clen']= $clen;
                    $vypisy[$v]['prevody'][$p]['typ']= 7;
                    $vypisy[$v]['prevody'][$p]['kat']= "+d";
                  }
                }
              }
              // zjistíme kat
//             $vypisy[$v]['prevody'][$p]['kat']= "+-#";
            }
          }
//                                         debug($vypisy,"vypisy $soubor");
          // zkontrolujeme obrat
          foreach ( $vypisy as $vypis ) {
            // pokud se má účet ignorovat, jdi na další
            $ignorovat= !isset($bank_nase_ucty[$banka][$vypis['ucetcislo']]);
            if ( $ignorovat ) {
//                                                 display("vypis $v z uctu {$vypis['ucetcislo']} byl ignorovan (2)");
              continue;
            }
            $obrat1= $vypis['stav'] - $vypis['stav_poc'];
            $obrat2= $vypis['*obrat'];
            $rozdil= round($obrat1-$obrat2,2);
//                                         display("$obrat%$obrat2%$rozdil v ".count($vypis['prevody']));
            if ( abs($rozdil)>0.01 ) {
              $vypis['_error']= "inkonsistence obratu: $obrat1 x $obrat2";
//                                         debug($vypis,"vypis ze $soubor");
              fce_error("Při importu $soubor byla zjištěna inkonsistence obratu: $obrat1 x $obrat2");
            }
          }
          // zapíšeme do tabulek
          foreach ( $vypisy as $vypis ) if ( !$vypis['_error'] ) {
            // pokud se má účet ignorovat, jdi na další
            $ignorovat= !isset($bank_nase_ucty[$banka][$vypis['ucetcislo']]);
            if ( $ignorovat ) {
//                                                 display("vypis $v z uctu {$vypis['ucetcislo']} byl ignorovan (3)");
              continue;
            }
            // kontrola existence výpisu
            $importovan= select("count(*)","vypis","ident='{$vypis['ident']}'");
                                        display("soubor={$vypis['soubor']}, vypis={$vypis['ident']},
                                        importovan=$importovan, ignorovat=$ignorovat, kontrola=$kontrola, change=$change");
//                                         debug($vypis,"vypis ze $soubor");
            if ( $importovan && $kontrola && $change=='missing' ) {
              // kontrola položek výpisu a jeho převodů a případné doplnění převodu
              $qry= bank_check_qry('vypis','ident,vypis,ucet,datum,soubor,stav_poc,stav',$vypis);
//               $qry= bank_check_qry('vypis','ident,vypis,ucet,datum,soubor,stav',$vypis);
//                                         display("qry:$qry");
              $res= pdo_qry($qry);
              if ( !$res ) fce_error("banka_import: kontrola výpisu {$vypis['ident']} selhala");
              if ( $res && $row= pdo_fetch_assoc($res) ) {
                if ( $er= $row['err_list'] ) {
                  fce_error("Při kontrole $soubor byla zjištěna změna položek vypisu: $er");
                }
                else {
                  // vypis je stejný jako byl
                  if ( $vypis['prevody'] )
                  foreach ( $vypis['prevody'] as $prevod )
                  if ( $prevod['typ']>=5 ) {
                    // doplň údaje do převodů - pro Hospic jen příjmy, výdaje ignoruj
                    $qry= bank_check_qry('prevod'
                      ,'ident,vypis,ucet,popis,clen,typ,castka,protiucet,banka,splatnost,'
                        . 'ksym,vsym,ssym,kat,poznamka'
//                         . 'ksym,vsym,ssym,kat,poznamka,metoda' -- metoda se nemusí porovnávat
                      ,$prevod,'typ,kat,clen,dar');
//                                         display("qry:$qry");
                    $res= pdo_qry($qry);
                    $rows= pdo_num_rows($res);
                    if ( $rows ) {
                      if ( $res && $row= pdo_fetch_assoc($res) ) {
                        if ( $er= $row['err_list'] ) {
                          fce_error("Při kontrole {$prevod['ident']} byla zjištěna změna položek převodu: $er");
                        }
                      }
                    }
                    else {
                      $qry= bank_insert_qry('prevod'
                        ,'ident,vypis,ucet,popis,clen,typ,castka,protiucet,banka,splatnost,'
                          . 'ksym,vsym,ssym,kat,poznamka,metoda',$prevod);
//                                         display("qry:$qry");
                      $res= pdo_qry($qry);
                      if ( !$res ) fce_error("banka_import: přidání převodu {$prevod['ident']} selhalo");
                      else {
                        $n_vypisu++;
                        $ids_vypisu.= "{$prevod['ident']} ";
                      }
                    }
                  }
                }
              }
            }
            else /*if ( $vypis['prijmu']>0 ) if ( $kontrola && $change=='missing' )*/ {
              // doplnění nového výpisu nebo oprava starého znovunačtením
//               $existuje= select("count(*)","vypis","ident='{$vypis['ident']}'");
//               if ( $existuje )
//                 fce_warning("výpis {$vypis['ident']} již byl importován ze souboru {$vypis['soubor']},
//                   převody nebyly přepsány");
//               else {
                $n_vypisu++;
                $ids_vypisu.= " {$vypis['ident']} ";
                $qry= bank_insert_qry('vypis','ident,vypis,ucet,datum,soubor,stav_poc,stav',$vypis);
                $res= pdo_qry($qry);
                if ( !$res ) fce_warning("banka_ace: zápis výpisu {$vypis['ident']} selhal");
                else if ( $reimport==0 || $change=='prevody' || ($change=='missing' && !$importovan)) {
                  if ( $vypis['prevody'] )
                  foreach ( $vypis['prevody'] as $prevod )
                  if ( $prevod['typ']>=5 ) {
                    // doplň údaje do převodů - pro Hospic jen příjmy, výdaje ignoruj
                    $qry= bank_insert_qry('prevod'
                      ,'ident,vypis,ucet,popis,clen,typ,castka,protiucet,banka,splatnost,'
                        . 'ksym,vsym,ssym,kat,poznamka,metoda',$prevod);
                    $msg.= "{$prevod['ident']} ";
                    $res= pdo_qry($qry);
                    if ( !$res ) fce_error("banka_import: zápis převodu {$prevod['ident']} selhal");
                  }
                }
//               }
            }
          }
          // přesuneme soubor do podsložky s názvem roku
          if ( $to_move && $yyyy && !$kontrola ) {
            if ( !is_dir($path.$yyyy) ) mkdir($path.$yyyy);
            $file1= $path.$yyyy.'/'.$file;
            $ok= 1;
            if ( file_exists($file1) && $to_move ) {
              $ok= @unlink($file1);
              if ( !$ok )
                fce_error("Soubor $file nelze odstranit z FTP složky banky $banka");
            }
            if ( $ok ) {
              $ok= @rename($path.$file,$file1);
              if ( !$ok )
                fce_error("Soubor $file nelze přesunout FTP složky banky $banka/rok");
            }
          }
        }
      }
    }
    if ( $handle ) closedir($handle);
    else fce_error("Cesta '$path' pro výpisy banky '$banka' v $ezer_root.php není platná");
  }
//                                         debug($vypisy,'vypisy 2');
  if ( $n_vypisu ) $n_vypisu.=" (".trim($ids_vypisu).")";
  return $n_vypisu; //$msg;
}
# -------------------------------------------------------------------------------------------------- bank_popis2clen
# zkusíme z popisu účtu uhodnout člena
# předpokládáme, že popis= příjmení jméno ...
function bank_popis2clen($popis,&$metoda) {
  $clen= 0;
  // rozklad popisu na jméno
  $popis= str_replace('  ',' ',trim($popis));
  $jm= array();
  foreach(explode(' ',$popis) as $x) {
    if ( substr($x,-1)!='.' ) {
      $jm[]= $x;
    }
  }
  $jm0= pdo_real_escape_string($jm[0]);
  $jm1= pdo_real_escape_string($jm[1]);
  list($prijmeni,$jmeno)= explode(' ',trim($popis));
  if ( $jm0 && $jm1 ) {
    $qry= "SELECT id_clen FROM clen WHERE
             (  (jmeno LIKE '$jm0' COLLATE utf8_general_ci
                 AND prijmeni LIKE '$jm1' COLLATE utf8_general_ci)
             OR (jmeno LIKE '$jm1' COLLATE utf8_general_ci
                 AND prijmeni LIKE '$jm0' COLLATE utf8_general_ci))
             AND left(deleted,1)!='D' ";
    $res= pdo_qry($qry);
    if ( $res && pdo_num_rows($res)==1 ) {
      $row= pdo_fetch_assoc($res);
      $clen= $row['id_clen'];
      $metoda.= ", podle jednoznačnosti jména";
    }
  }
  return $clen;
}
# -------------------------------------------------------------------------------------------------- bank_ucet2clen
# zkusíme z účtu uhodnout člena mezi těmi, kteří mají tuto položku uvedenu
function bank_ucet2clen($pub) {
  bank_pub($pub,$p,$u,$b,false);     // rozklad bez doplňování nul
  $match= ($p ? "0*$p-" : '')."0*$u/$b";
  $clen= 0;
  $qry= "SELECT id_clen FROM clen WHERE ucet REGEXP '$match' AND LEFT(deleted,1)!='D' ";
  $res= pdo_qry($qry);
  if ( $res && pdo_num_rows($res)==1 ) {
    $c= pdo_fetch_object($res);
    $clen= $c->id_clen;
  }
  return $clen;
}
# -------------------------------------------------------------------------------------------------- bank_vsym2clen
# zkusíme z variabilního symbolu uhodnout člena
# predanych 10 cislic je bud clenske nebo rodne cislo nebo nic
#   0000nnnnnn - členské číslo, je-li n>12
#   rrmmddxxxx - rodné číslo
#   9999nnnnnn - hromadný dar
function bank_vsym2clen($vsym,&$metoda) {
  $clen= 0;
  $metoda= '';
  if ( verify_rodcis($vsym) ) {
    $metoda= "$vsym je rc";
    // rodne cislo - rrmmddxxx(x)
    $qry= "SELECT id_clen FROM clen WHERE rodcis='$vsym' AND left(deleted,1)!='D' LIMIT 1";
    $res= pdo_qry($qry);
    if ( $res && $row= pdo_fetch_assoc($res) ) {
      // rodné číslo existuje Klubu?
      $clen= $row['id_clen'];
      $metoda.= ", v Klubu je";
    }
    else
      $metoda.= ", v Klubu není";
  }
  else {
    if ( substr($vsym,0,4)=="9999" ) {
      // hromadny dar=9999nnnnn
      $clen= intval(substr($vsym,5));
      $metoda.= "$vsym určuje hromadný dar člena $clen";
    }
    else {
      $clen= intval($vsym);
      if ( $clen>12 && $clen<999999 ) {
        // členské číslo=0000nnnnnn
        $metoda.= "$vsym může být členské číslo $clen";
      }
      else $clen= 0;
    }
    if ( $clen ) {
      // existuje číslo v Klubu?
      $qry= "SELECT id_clen FROM clen WHERE id_clen=$clen AND left(deleted,1)!='D' LIMIT 1";
      $res= pdo_qry($qry);
      if ( pdo_num_rows($res) ) {
        $metoda.= ", v Klubu je";
      }
      else {
        $metoda.= ", v Klubu není";
        $clen= 0;
      }
    }
  }
  if ( NOE && !$clen && $vsym>100000 ) {
    // i chybné formáty rodného čísla
    $qry= "SELECT id_clen FROM clen WHERE rodcis=$vsym AND left(deleted,1)!='D' LIMIT 1";
    $res= pdo_qry($qry);
    if ( $res && $row= pdo_fetch_assoc($res) ) {
      // rodné číslo existuje Klubu?
      $clen= $row['id_clen'];
      $metoda.= ", v Klubu je 2";
    }
    else
      $metoda.= ", v Klubu není 2, protože $qry";
  }
//                                         display("bank_vsym2clen($vsym) - $clen $metoda");
  return $clen;
}
# -------------------------------------------------------------------------------------------------- bank_check_qry
# zjistí shodu všech položek pro "ident={$values['ident']}"
function bank_check_qry($table,$items,$values,$buts='') { #trace();
  $cond= "ident='{$values['ident']}'";
  $del= '';
  $list= '';
  $but= explode(',',$buts);
  foreach (explode(',',$items) as $item) {
    if ( !in_array($item,$but) ) {
      $value= pdo_real_escape_string($values[$item]);
      $list.= "{$del}if($item='$value','',concat('$item:','/','$value,$item'))";
      $del= ',';
    }
  }
  $qry= "SELECT CONCAT($list) as err_list FROM $table WHERE $cond";
  return $qry;
}
# -------------------------------------------------------------------------------------------------- bank_insert_qry
function bank_insert_qry($table,$items,$values) { //trace();
  $qry= "INSERT INTO $table ($items) VALUES (";
  $del= '';
  foreach (explode(',',$items) as $item) {
    $value= pdo_real_escape_string($values[$item]);
    $qry.= "$del'$value'";
    $del= ',';
  }
  return "$qry);";
}

