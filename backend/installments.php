<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Simple Installments API for Gremo
$dataDir = __DIR__ . '/../data/';
if (!file_exists($dataDir)) { mkdir($dataDir, 0777, true); }

$paymentsFile = $dataDir . 'payments.json';
$clientsFile = $dataDir . 'clients.json';
$installmentsFile = $dataDir . 'installments.json';

if (!file_exists($installmentsFile)) {
    file_put_contents($installmentsFile, json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function readJsonFileLocal($file) {
    if (file_exists($file)) {
        $c = file_get_contents($file);
        return json_decode($c, true) ?: [];
    }
    return [];
}

function writeJsonFileLocal($file, $data) {
    return file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? null;

try {
    // GET actions
    if ($method === 'GET') {
        switch ($action) {
            case 'get_installment':
                $clientId = $_GET['id'] ?? null;
                if (!$clientId) { echo json_encode(['success'=>false,'message'=>'id required'], JSON_UNESCAPED_UNICODE); exit; }
                $installments = readJsonFileLocal($installmentsFile);
                $payments = readJsonFileLocal($paymentsFile);
                $found = null;
                foreach ($installments as $inst) {
                    if ((string)$inst['clientId'] === (string)$clientId) { $found = $inst; break; }
                }
                if (!$found) { echo json_encode(['success'=>true,'data'=>null], JSON_UNESCAPED_UNICODE); exit; }

                $amount_paid = 0.0;
                foreach ($payments as $p) {
                    if ((string)$p['clientId'] === (string)$clientId && (($p['status'] ?? $p['paymentStatus'] ?? '') === 'مدفوع' || ($p['status'] ?? '') === 'paid')) {
                        $amount_paid += floatval($p['amount'] ?? 0);
                    }
                }
                $total_amount = floatval($found['total_amount'] ?? 0);
                $remaining_balance = max(0, $total_amount - $amount_paid);
                $weekly_amount = floatval($found['weekly_amount'] ?? 0);
                $completed_payments = $weekly_amount > 0 ? floor($amount_paid / $weekly_amount) : 0;
                $remaining_payments = $weekly_amount > 0 ? ceil($remaining_balance / $weekly_amount) : 0;
                $status = ((!empty($found['enabled']) && $found['enabled']) ? ($remaining_balance <= 0 ? 'Completed' : 'Active') : 'No Installment');

                $found['derived'] = [
                    'amount_paid' => round($amount_paid,2),
                    'remaining_balance' => round($remaining_balance,2),
                    'completed_payments' => intval($completed_payments),
                    'remaining_payments' => intval($remaining_payments),
                    'status' => $status
                ];

                echo json_encode(['success'=>true,'data'=>$found], JSON_UNESCAPED_UNICODE);
                exit;

            case 'get_overview_installments':
                $installments = readJsonFileLocal($installmentsFile);
                $payments = readJsonFileLocal($paymentsFile);
                $expectedRevenue = 0.0;
                $details = [];
                foreach ($installments as $inst) {
                    $clientId = $inst['clientId'];
                    $total = floatval($inst['total_amount'] ?? 0);
                    $amount_paid = 0.0;
                    foreach ($payments as $p) {
                        if ((string)$p['clientId'] === (string)$clientId && (($p['status'] ?? '') === 'مدفوع' || ($p['status'] ?? '') === 'paid')) {
                            $amount_paid += floatval($p['amount'] ?? 0);
                        }
                    }
                    $remaining = max(0, $total - $amount_paid);
                    $expectedRevenue += $total;
                    $details[] = ['clientId'=>$clientId,'total_amount'=>round($total,2),'amount_paid'=>round($amount_paid,2),'remaining'=>round($remaining,2)];
                }
                $karim_share = round($expectedRevenue * 0.5,2);
                $mahmoud_share = round($expectedRevenue * 0.5,2);
                echo json_encode(['success'=>true,'data'=>[
                    'expectedRevenueAfterInstallments'=>round($expectedRevenue,2),
                    'details'=>$details,
                    'profitDistribution'=>['karim'=>['totalShare'=>$karim_share],'mahmoud'=>['totalShare'=>$mahmoud_share]]
                ]], JSON_UNESCAPED_UNICODE);
                exit;

            default:
                echo json_encode(['success'=>false,'message'=>'Invalid action'], JSON_UNESCAPED_UNICODE);
                exit;
        }
    }

    // POST actions
    if ($method === 'POST') {
        // support both form-data and JSON
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $action = $input['action'] ?? $action;
        switch ($action) {
            case 'update_installment':
                $clientId = $input['clientId'] ?? null;
                if (!$clientId) { echo json_encode(['success'=>false,'message'=>'clientId required'], JSON_UNESCAPED_UNICODE); exit; }
                $installments = readJsonFileLocal($installmentsFile);
                $found = false;
                for ($i=0;$i<count($installments);$i++) {
                    if ((string)$installments[$i]['clientId'] === (string)$clientId) {
                        $installments[$i] = array_merge($installments[$i],[
                            'clientId'=>(int)$clientId,
                            'enabled'=>isset($input['enabled']) ? boolval($input['enabled']) : boolval($installments[$i]['enabled']),
                            'total_amount'=>isset($input['total_amount']) ? floatval($input['total_amount']) : floatval($installments[$i]['total_amount']),
                            'months'=>isset($input['months']) ? intval($input['months']) : intval($installments[$i]['months']),
                            'weekly_amount'=>isset($input['weekly_amount']) ? floatval($input['weekly_amount']) : floatval($installments[$i]['weekly_amount']),
                            'start_date'=>$input['start_date'] ?? $installments[$i]['start_date'] ?? null
                        ]);
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $new = [
                        'id'=>time(),
                        'clientId'=>(int)$clientId,
                        'enabled'=>isset($input['enabled']) ? boolval($input['enabled']) : false,
                        'total_amount'=>isset($input['total_amount']) ? floatval($input['total_amount']) : 0.0,
                        'months'=>isset($input['months']) ? intval($input['months']) : 0,
                        'weekly_amount'=>isset($input['weekly_amount']) ? floatval($input['weekly_amount']) : 0.0,
                        'start_date'=>$input['start_date'] ?? null,
                        'created_at'=>date('c')
                    ];
                    $installments[] = $new;
                }
                writeJsonFileLocal($installmentsFile, $installments);
                echo json_encode(['success'=>true,'message'=>'Installment updated'], JSON_UNESCAPED_UNICODE);
                exit;

            default:
                echo json_encode(['success'=>false,'message'=>'Invalid action'], JSON_UNESCAPED_UNICODE);
                exit;
        }
    }

    echo json_encode(['success'=>false,'message'=>'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
