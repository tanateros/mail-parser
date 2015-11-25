<?php

/*
|--------------------------------------------------------------------------
| Email parser from Tanateros
|--------------------------------------------------------------------------
*/
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Link;
use Symfony\Component\CssSelector\CssSelector;

Route::get('list.xls', function () {
	function xlsBOF() {
		echo pack("ssssss", 0x809, 0x8, 0x0, 0x10, 0x0, 0x0);
		return;
	}
	function xlsEOF() {
		echo pack("ss", 0x0A, 0x00);
		return;
	}
	function xlsWriteNumber($Row, $Col, $Value){
		echo pack("sssss", 0x203, 14, $Row, $Col, 0x0);
		echo pack("d", $Value);
		return;
	}
	function xlsWriteString($Row , $Col , $Value){
		$L = strlen($Value);
		echo pack("ssssss", 0x204, 8 + $L, $Row, $Col, 0x0, $L);
		echo $Value;
		return;
	}
	
	header("Pragma: public");
	header("Expires: 0");
	header("Cache-Control: must-revalidate, post-check=0, pre-check=0"); 
	header("Content-Type: application/force-download");
	header("Content-Type: application/octet-stream");
	header("Content-Type: application/download");;
	header("Content-Disposition: attachment;filename=list.xls ");
	header("Content-Transfer-Encoding: binary ");
	
	xlsBOF();
	
	$i = 0;
	$redis = Redis::connection();
	
	foreach($redis->keys('*') as $key){
		$arr = explode(PHP_EOL, $redis->get($key));
		foreach($arr as $current){
			if(!empty($current)){
				xlsWriteString($i, 0, $key);
				xlsWriteString($i, 1, $current);
				$i++;
			}
		}
	}
	
	xlsEOF(); //заканчиваем собирать
	exit();
});
Route::get('/', function () {
	echo '
	<form method="get" action="' . url('parser') . '">
	<label>Please input site for parse emails</label>
	<input type="text" value="" name="parseurl" />
	<input name="_token" type="hidden" value="'.csrf_token().'" />
	<button>Go</button>
	</form>
	';
	$redis = Redis::connection();
	echo '
	<div><a href="'.url('list.xls').'">Export</a></div>
	<table>';
	foreach($redis->keys('*') as $key)
		echo '<tr><td style="vertical-align: top;" onclick="document.getElementById(\''.$key.'\').style.display = \'block\';"><a style="text-decoration: underline;">'.$key.'</a></td><td style="display: none;" id="'.$key.'"><pre>'.$redis->get($key).'</pre></td></tr>';
	echo '</table>';
	$redis->del('b2b-russia.ru');
	exit;
});

Route::get('parser', function () {
	$site = Request::input('parseurl');
	$cacheDir = __DIR__ . DIRECTORY_SEPARATOR . '..'. DIRECTORY_SEPARATOR .'..' . DIRECTORY_SEPARATOR . 'cache';
	if(!file_exists($cacheDir))
		mkdir($cacheDir);
	$fileLinksResult = __DIR__ . DIRECTORY_SEPARATOR . '..'. DIRECTORY_SEPARATOR .'..' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . basename($site);
		if(!file_exists($fileLinksResult)){
			$crawler = new Crawler();
			$crawler->add(file_get_contents($site));
			 
			$arrLinks = $crawler
				->filter('a')
				->each(function (Crawler $nodeCrawler) use ($site) {
					return [
						$nodeCrawler->filter('a')->attr('href'),
					];
				});
			$validLinks = [];
			$validMails = [];
			foreach($arrLinks as $k=>$url){
				$url[0] = str_replace('/redirect.php?url=', '', $url[0]);
				if(!filter_var($url[0],FILTER_VALIDATE_URL)){
					$validLinks[] = $site.$url[0];
				}
				else if(filter_var($url[0], FILTER_VALIDATE_EMAIL)){
					$validMails[] = $url[0];
				}
				else
					if(strstr($url[0], 'mailto')!='')
						$validMails[] = $url[0];
					else
						$validLinks[] = $url[0];
			}
			mkdir($fileLinksResult);
			file_put_contents($fileLinksResult . DIRECTORY_SEPARATOR . basename($site) . '.json', json_encode(array($validLinks)));
		}
	return redirect(url('result', array('link' => basename($site))));
});

Route::get('result/{link}/{resultget?}', function ($link = '', $resultget = null) {
	$fileLinksResult =__DIR__ . DIRECTORY_SEPARATOR . '..'. DIRECTORY_SEPARATOR .'..' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . basename($link) . DIRECTORY_SEPARATOR . basename($link) . '.json';
	$arrLinks = json_decode(file_get_contents($fileLinksResult), true)[0];
	$count = count($arrLinks);
	
	$result = $fileLinksResult.'.txt';
	
	if($resultget !='yes' && !file_exists($result)){
		$currentSite = 'http://'.$_SERVER['HTTP_HOST'].'/parsemail/'.$link.'/';
		
		for($i = 0; $i < $count; $i++)
			if(!file_exists($fileLinksResult . '_' . $i))
				exec('php -f '. __DIR__ . DIRECTORY_SEPARATOR .'cli.php '.$currentSite.$i . ' > ' . $fileLinksResult.'_'.$i . ' 2>&1 &');
		
		echo 'Please waiting '.ceil(($count + 10) / 60).' minutes and your auto redirected in result page...';
		header( 'Refresh: '.($count + 10).'; url='.url('result', array('link' => basename($link), 'resultget' => 'yes')) );
	}
	else{
		if(!file_exists($result)){
			$str = '';
			for($i = 0; $i < $count; $i++)
				$str .= file_get_contents($fileLinksResult . '_' . $i);
			
			$arr = explode(PHP_EOL, $str);
			$arr = array_unique($arr);
			$arr = implode(PHP_EOL, $arr);
			file_put_contents($result, $arr);
			$redis = Redis::connection();
			$redis->set($link, $arr);
		}
		else
			$arr = file_get_contents($result);
		echo '<pre>'.$arr.'</pre>';
	}
	exit;
});

Route::get('parsemail/{site}/{linkId}', function ($site, $linkId = 0) {
	
	$fileLinksResult = __DIR__ . DIRECTORY_SEPARATOR . '..'. DIRECTORY_SEPARATOR .'..' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . basename($site) . DIRECTORY_SEPARATOR . basename($site) . '.json';
	$arrLinks = json_decode(file_get_contents($fileLinksResult))[0];
	if(get_headers($arrLinks[$linkId])[0] == 'HTTP/1.1 200 OK'){
		$crawler = new Crawler();
		$crawler->add(file_get_contents($arrLinks[$linkId]));
		 
		$arrLinks = $crawler
			->filter('a')
			->each(function (Crawler $nodeCrawler) use ($site) {
				return [
					$nodeCrawler->filter('a')->attr('href'),
				];
			});
		
		$validLinks = [];
		$validMails = [];
		foreach($arrLinks as $k=>$url){
			if(!filter_var($url[0],FILTER_VALIDATE_URL)){
				$validLinks[] = $site.$url[0];
			}
			else if(filter_var($url[0], FILTER_VALIDATE_EMAIL)){
				$validMails[] = $url[0];
			}
			else
				if(strstr($url[0], 'mailto')!='')
					$validMails[] = $url[0];
				else
					$validLinks[] = $url[0];
		}
		$mails = '';
		foreach($validMails as $i){
			$i = str_replace('mailto:', '', $i);
			$mails .= $i . PHP_EOL;
		}
		echo $mails;
		file_put_contents($fileLinksResult.'_'.$linkId, $mails);
	}
	else
		echo @file_get_contents($fileLinksResult.'_'.$linkId);
	exit;
});