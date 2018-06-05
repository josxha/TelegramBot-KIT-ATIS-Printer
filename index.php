<?php
define('BOT_TOKEN', '<bot_token>');
define('API_URL', 'https://api.telegram.org/bot'.BOT_TOKEN.'/');

define('MYSQL_IP', '<db_ip>');
define('MYSQL_USERNAME', '<db_username>');
define('MYSQL_PASSWORD', '<db_password>');
define('MYSQL_DB_NAME', '<db_name>');

define('ATIS_IP', 'i08fs1.informatik.kit.edu');

set_include_path(get_include_path() . PATH_SEPARATOR . 'phpseclib1.0.11');


// read incoming info
$update = json_decode(file_get_contents("php://input"), true);
$chatID = $update["message"]["chat"]["id"];
$userID = $update["message"]["from"]["id"];
$userMsg = $update["message"]["text"];
$mysql_conn = new mysqli(MYSQL_IP, MYSQL_USERNAME, MYSQL_PASSWORD, MYSQL_DB_NAME);

function send_message($chatID, $msg) {
    file_get_contents(API_URL . "sendmessage?chat_id=" . $chatID . "&text=" . $msg);
}

function delete_message($chatID, $msgID) {
    //can only delete messages of the bot itself
    file_get_contents(API_URL . "deleteMessage?chat_id=" . $chatID . "&message_id=" . $msgID);
}

//main content
$result = $mysql_conn->query("SELECT * FROM `telegram_atis` WHERE id='" . mysqli_escape_string($mysql_conn, $userID) . "'");
if ($result->num_rows > 0) {
    //telegram id allready in database
    $row = $result->fetch_assoc();

    if ($row['name'] == null) {

        //id exists, name and password doesnt exist
        if ($mysql_conn->query("UPDATE `telegram_atis` SET `name`='" . mysqli_escape_string($mysql_conn, $userMsg) . "' WHERE `id`=" . mysqli_escape_string($mysql_conn, $userID)))
            send_message($chatID, "Dein ATIS Passwort:");
        else
            send_message($chatID, "Dein ATIS Nutzername (s_nachname):");

    } elseif ($row['pwd'] == null) {

        //id and username exist, password doesnt exist -> test if username and password are valid
        require('phpseclib1.0.11/Net/SSH2.php');
        $ssh_conn = new Net_SSH2(ATIS_IP);
        if ($ssh_conn->login($row['name'], $userMsg)) {

            //ssh connection successfull -> login data correct
            if ($mysql_conn->query("UPDATE `telegram_atis` SET `pwd`='" . mysqli_escape_string($mysql_conn, $userMsg) . "' WHERE `id`=" . mysqli_escape_string($mysql_conn, $userID))) {
                send_message($chatID, "Alle Dokumente, die du mir nun schickst, werde ich in der ATIS für dich drucken. Tipp: Lösche die Nachrichten mit deinem Zugangsdaten.");

            } else
                send_message($chatID, "Dein ATIS Passwort:");

        } else {

            //ssh connection failed -> login data wrong
            if ($mysql_conn->query("UPDATE `telegram_atis` SET `name`=NULL WHERE `id`=" . mysqli_escape_string($mysql_conn, $userID))) {
                send_message($chatID, "Der Nutzername oder das Passwort waren falsch.");
                send_message($chatID, "Dein ATIS Nutzername (s_nachname):");
            } else
                send_message($chatID, "Dein ATIS Passwort:");

        }
    } else {
        //all user data given
        if ($update["message"]["document"] != null) {

            //get document url
            $file_id = $update["message"]["document"]["file_id"];
            $array = json_decode(file_get_contents(API_URL . "getFile?file_id=" . $file_id));
            $file_path = $array->result->file_path;
            $file_url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $file_path;

            //create ssh connection
            require('phpseclib1.0.11/Net/SSH2.php');
            $ssh_conn = new Net_SSH2(ATIS_IP);
            if ($ssh_conn->login($row['name'], $row['pwd'])) {

                //ssh connection successfull -> print document
                $ssh_conn->exec("wget " . $file_url);
                $file_name = $file_path;
                while (strpos($file_name, '/') !== false)
                    $file_name = substr($file_name, strpos($file_name, '/') + 1);
                $ssh_conn->exec("lpr -P pool-sw1 ~/" . $file_name);
                send_message($chatID, "Dein Dokument wird auf Drucker 'pool-sw1' gedruckt.");
            } else {

                //ssh connection failed -> login data wrong
                send_message($chatID, "Deine Anmeldedaten sind veraltet.");
                if ($mysql_conn->query("UPDATE `telegram_atis` SET `name`=NULL AND `pwd`=NULL WHERE `id`=" . mysqli_escape_string($mysql_conn, $userID)))
                    send_message($chatID, "Dein aktueller ATIS Nutzername (s_nachname):");

            }
        }
    }

} else {
    //telegram id not in database
    $mysql_conn->query("INSERT INTO `telegram_atis` (`id`) VALUES ('" . mysqli_escape_string($mysql_conn, $userID) . "')");
    send_message($chatID, "Dein ATIS Nutzername (s_nachname):");
}

$mysql_conn->close();