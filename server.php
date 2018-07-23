<?php
/**
 * Git HTTP Server
 * @author: comdeng
 * @since: 2017/6/21 22:19
 * @copyright: 2017@dengxiaolong.com
 * @filesource: server.php
 */

// simple way for recording log
function writeLog($obj)
{
    if (is_scalar($obj)) {
        $msg = $obj;
    } else {
        $msg = var_export($obj);
    }
    file_put_contents(__DIR__ . '/tmp', $msg . PHP_EOL, FILE_APPEND);
}
// uncomporess content
function gzBody($gzData)
{
    // whether uncompress content by the header
    $encoding = $_SERVER['HTTP_CONTENT_ENCODING'];
    $gzip = ($encoding == 'gzip' || $encoding == 'x-gzip');
    if (!$gzip) {
        return $gzData;
    }
    $i = 10;
    $flg = ord(substr($gzData, 3, 1));
    if ($flg > 0) {
        if ($flg & 4) {
            list($xlen) = unpack('v', substr($gzData, $i, 2));
            $i = $i + 2 + $xlen;
        }
        if ($flg & 8) $i = strpos($gzData, "\0", $i) + 1;
        if ($flg & 16) $i = strpos($gzData, "\0", $i) + 1;
        if ($flg & 2) $i = $i + 2;
    }
    return gzinflate(substr($gzData, $i, -8));
}

// define the git repository
define('GIT_DIR', __DIR__ . '/repos/');

// find the matched git repo directory by the uri
$uri = $_SERVER['REQUEST_URI'];
$info = parse_url($uri);
$path = $info['path'];
$arr = explode('/', trim($path, '/'));
$git['group'] = array_shift($arr);
$git['name'] = rtrim(array_shift($arr), '.git');
$path = sprintf('%s/%s/%s.git', GIT_DIR, $git['group'], $git['name']);

$action = implode('/', $arr);

switch ($action) {
    case 'info/refs':
        $service = $_GET['service'];
        header('Content-type: application/x-' . $service . '-advertisement');
        $cmd = sprintf('git %s --stateless-rpc --advertise-refs %s', substr($service, 4), $path);
        writeLog('cmd:' . $cmd);
        exec($cmd, $outputs);
        $serverAdvert = sprintf('# service=%s', $service);
        $length = strlen($serverAdvert) + 4;

        echo sprintf('%04x%s0000', $length, $serverAdvert);
        echo implode(PHP_EOL, $outputs);
        
        unset($outputs);
        break;
    case 'git-receive-pack':
    case 'git-upload-pack':
        $input = file_get_contents('php://input');
        
        // required to define the content's Content-type
        header(sprintf('Content-type: application/x-%s-result', $action));
        $input = gzBody($input);
        // writeLog("input:".$input);
        $cmd = sprintf('git %s --stateless-rpc %s', substr($action, 4), $path);
        $descs = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        writeLog('cmd:' . $cmd);
        $process = proc_open($cmd, $descs, $pipes);
        if (is_resource($process)) {
            fwrite($pipes[0], $input);
            fclose($pipes[0]);
            while (!feof($pipes[1])) {
                $data = fread($pipes[1], 4096);
                echo $data;
            }

            fclose($pipes[1]);
            fclose($pipes[2]);

            $return_value = proc_close($process);
        }

        // need to update server's /info/refs file when upload object
        if ($action == 'git-receive-pack') {
            $cmd = sprintf('git --git-dir %s update-server-info', $path);
            writeLog('cmd:' . $cmd);
            exec($cmd);
        }
        break;
}
