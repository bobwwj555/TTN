<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
$caller_ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($caller_ip !== AGENT_ALLOWED_IP) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit; }
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid JSON']); exit; }
if (!hash_equals(AGENT_SECRET, $input['secret'] ?? '')) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }
$interface = $input['interface'] ?? ''; $username = $input['username'] ?? ''; $password = $input['password'] ?? ''; $action = $input['action'] ?? 'set';
if (!array_key_exists($interface, INTERFACES)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Unknown interface']); exit; }
if (!preg_match('/^[A-Z0-9]{3,12}$/', strtoupper($username))) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid username']); exit; }
if ($action === 'set' && (strlen($password) < 6 || preg_match('/[\'"\\\\\$`]/', $password))) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid password']); exit; }
$username = strtolower($username); $cfg = INTERFACES[$interface]; $results = [];
try {
    switch ($cfg['tool']) {
        case 'htpasswd':
            $file = escapeshellarg($cfg['htpasswd_file']); $user = escapeshellarg($username);
            if ($action === 'delete') { $cmd = "sudo htpasswd -D $file $user 2>&1"; }
            else { $pass = escapeshellarg($password); $cmd = "sudo htpasswd -Bb $file $user $pass 2>&1"; }
            exec($cmd, $out, $rc); if ($rc !== 0) throw new Exception(implode(' ', $out));
            $results[] = "$interface htpasswd: ok"; break;
        case 'allmon3_passwd':
            $user = escapeshellarg($username);
            if ($action === 'delete') { $cmd = "sudo allmon3-passwd --delete $user 2>&1"; }
            else { $pass = escapeshellarg($password); $cmd = "sudo allmon3-passwd --password $pass $user 2>&1"; }
            exec($cmd, $out, $rc); if ($rc !== 0) throw new Exception(implode(' ', $out));
            exec('sudo systemctl restart allmon3 2>&1', $out2, $rc2);
            if ($rc2 !== 0) throw new Exception('allmon3 restart failed: '.implode(' ',$out2));
            $results[] = 'allmon3: ok, service restarted'; break;
        case 'allscan_db':
            $db = new PDO('sqlite:' . $cfg['db_file']);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            if ($action === 'delete') {
                $stmt = $db->prepare("DELETE FROM user WHERE name = ?");
                $stmt->execute([$username]);
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $exists = $db->prepare("SELECT user_id FROM user WHERE name = ?");
                $exists->execute([$username]);
                if ($exists->fetch()) {
                    $stmt = $db->prepare("UPDATE user SET hash = ? WHERE name = ?");
                    $stmt->execute([$hash, $username]);
                } else {
                    $stmt = $db->prepare("INSERT INTO user (name, hash, permission) VALUES (?, ?, 1)");
                    $stmt->execute([$username, $hash]);
                }
            }
            $results[] = 'allscan: ok'; break;
        default: throw new Exception('Unknown tool');
    }
    echo json_encode(['ok'=>true,'results'=>$results]);
} catch (Exception $e) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
