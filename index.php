<?php # Systém Archa/Ezer, (c) 2008-2018 Martin Šmídek <martin@smidek.eu>
  
  // volba verze jádra Ezer
  $kernel= "ezer3.1";
  $_GET['pdo']= 2; 
  $_GET['touch']= 0; // nezavede jquery.touchSwipe.min.js => filtry v browse jdou upravit myší

  // servery a jejich cesty
  $deep_root= "../files/aby";
  require_once("$deep_root/aby.dbs.php");

  // parametry aplikace FiS
  $app_name=  "Aby";
  $app_root=  'aby';
  $app_js=    array('/aby/aby_user.js');
  $app_css=   array('/aby/aby.css.php=skin',"/$kernel/client/wiki.css");
  $skin=      'ck';
  $title_style= $ezer_server==0 ? " style='color:#ef7f13'" : '' ;
  $title_flag=  $ezer_server==0 ? 'lokální' : '';

  $continue= array(1,1,1);
  if (!$continue[$ezer_server] && !isset($_GET['go'])) die('Web under construction');

  // (re)definice Ezer.options
  $kontakt= " V případě zjištění problému nebo <br/>potřeby konzultace mi prosím napište<br/>na "
      . "mail&nbsp;<a target='mail' href='mailto:martin@smidek.eu?subject=FiS'>martin@smidek.eu</a> "
      . "případně zavolejte&nbsp;603 150 565 "
      . "<br/>Za spolupráci děkuje <br/>Martin";

  // upozornění na testovací verzi
  $demo= '';
//  $click= "jQuery('#DEMO').fadeOut(500);";
//  $dstyle= "left:0; top:0; position:fixed; transform:rotate(320deg) translate(-128px,-20px); "
//      . "width:500px;height:100px;background:orange; color:white; font-weight: bolder; "
//      . "text-align: center; font-size: 38px; line-height: 44px; z-index: 16; opacity: .5;";
//  $demo= "<div id='DEMO' onmouseover=\"$click\" style='$dstyle'>testovací data<br>funkce bez záruky</div>";

  $favicon= array(
      "aby_local.png",
      "aby.png",
    )[$ezer_server];

  $add_pars= array(
    'favicon' => $favicon,
    'title_right' => "<span$title_style>$title_flag $app_name</span>$demo",
    'watch_key' => 1,   // true = povolit přístup jen po vložení klíče
    'watch_ip' => 1,    // true = povolit přístup jen ze známých IP adres
    'contact' => $kontakt,
    'CKEditor' => "{
      version:'4.6',
      Minimal:{toolbar:[['Bold','Italic','Source']]},
      Letter:{toolbar:[['Format','Bold','Italic','Underline'],['Table'],
        ['JustifyLeft','JustifyCenter','JustifyRight'],['Source']]}
    }"
  );
  $add_options= (object)array(
    'login_interval' => 8*60,          // povolená nečinnost v minutách - 8 hodin
    'path_files_u'    => "'{$abs_roots[$ezer_server]}'",   // absolutní cesta do kořene aplikace
  );

  
  // je to aplikace se startem v rootu
  require_once("$kernel/ezer_main.php");

?>
