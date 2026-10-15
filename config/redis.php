<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Predis\Client;

try {

    $redis = new Client([
        'scheme' => 'tcp',
        'host'   => '127.0.0.1',
        'port'   => 6379,
        'timeout' => 2.5
    ]);

    $redis->connect();

} catch (Exception $e) {
    error_log("Redis connection error: " . $e->getMessage());
    die("<div style='font-family:sans-serif;padding:30px;max-width:500px;margin:50px auto;border:1px solid #fed7d7;border-radius:10px;background:#fff5f5;color:#c53030;'><h3>Database Service Unavailable</h3><p>Unable to connect to Redis data store. Please ensure Redis server is running on 127.0.0.1:6379.</p></div>");
}

/**
 * Safely inspect Redis key type.
 */
function safeRedisType($redis, $key) {
    try {
        if (!$redis->exists($key)) {
            return 'none';
        }
        return (string)$redis->type($key);
    } catch (Exception $e) {
        error_log("safeRedisType error on {$key}: " . $e->getMessage());
        return 'none';
    }
}

/**
 * Safely get hash contents from Redis. Checks key type first to prevent WRONGTYPE errors.
 */
function safeRedisHGetAll($redis, $key) {
    try {
        if (safeRedisType($redis, $key) !== 'hash') {
            return [];
        }
        $data = $redis->hgetall($key);
        return is_array($data) ? $data : [];
    } catch (Exception $e) {
        error_log("safeRedisHGetAll error on {$key}: " . $e->getMessage());
        return [];
    }
}

/**
 * Safely get string content from Redis. Checks key type first.
 */
function safeRedisGet($redis, $key) {
    try {
        if (safeRedisType($redis, $key) !== 'string') {
            return null;
        }
        return $redis->get($key);
    } catch (Exception $e) {
        error_log("safeRedisGet error on {$key}: " . $e->getMessage());
        return null;
    }
}

/**
 * Safely get range from a list key.
 */
function safeRedisLRange($redis, $key, $start = 0, $stop = -1) {
    try {
        if (safeRedisType($redis, $key) !== 'list') {
            return [];
        }
        $data = $redis->lrange($key, $start, $stop);
        return is_array($data) ? $data : [];
    } catch (Exception $e) {
        error_log("safeRedisLRange error on {$key}: " . $e->getMessage());
        return [];
    }
}

/**
 * Safely get members of a set key.
 */
function safeRedisSMembers($redis, $key) {
    try {
        if (safeRedisType($redis, $key) !== 'set') {
            return [];
        }
        $data = $redis->smembers($key);
        return is_array($data) ? $data : [];
    } catch (Exception $e) {
        error_log("safeRedisSMembers error on {$key}: " . $e->getMessage());
        return [];
    }
}

/**
 * Retrieve the four authorized hospital clinical departments from Redis.
 * 1. General Medicine
 * 2. Cardiology
 * 3. Orthopedics
 * 4. Pediatrics
 * Preserves existing Redis records and IDs.
 */
function getAllActiveDepartments($redis) {
    $departments = [];
    $allowed = [
        'general-medicine' => [
            'id'          => 'D001',
            'name'        => 'General Medicine',
            'description' => 'General Medicine and primary outpatient care'
        ],
        'cardiology' => [
            'id'          => 'D002',
            'name'        => 'Cardiology',
            'description' => 'Cardiology and cardiovascular clinical care'
        ],
        'orthopedics' => [
            'id'          => 'D004',
            'name'        => 'Orthopedics',
            'description' => 'Orthopedics and musculoskeletal care'
        ],
        'pediatrics' => [
            'id'          => 'D005',
            'name'        => 'Pediatrics',
            'description' => 'Pediatrics and child healthcare'
        ]
    ];

    try {
        foreach ($allowed as $key => $default) {
            $redisKey = 'department:' . $key;
            $dept = safeRedisHGetAll($redis, $redisKey);
            if (!empty($dept)) {
                $departments[] = [
                    'id'          => $dept['id'] ?? $default['id'],
                    'name'        => $dept['name'] ?? $default['name'],
                    'key'         => $key,
                    'description' => $dept['description'] ?? $default['description'],
                    'status'      => 'Active',
                    'created_at'  => $dept['created_at'] ?? ''
                ];
            } else {
                $data = [
                    'id'          => $default['id'],
                    'name'        => $default['name'],
                    'key'         => $key,
                    'description' => $default['description'],
                    'status'      => 'Active',
                    'created_at'  => date('Y-m-d H:i:s')
                ];
                $redis->hmset($redisKey, $data);
                $departments[] = $data;
            }
        }
    } catch (Exception $e) {
        error_log("getAllActiveDepartments error: " . $e->getMessage());
    }

    return $departments;
}

/**
 * Generate next daily token number for a department safely.
 */
function getNextDepartmentTokenNumber($redis, $deptKey, $date) {
    // Primary counter key
    $counterKey = 'token-counter:' . $deptKey . ':' . $date;
    $legacyCounterKey = 'token:' . $deptKey . ':' . $date;

    // Check if legacy key exists and is a string
    if ($redis->exists($legacyCounterKey) && safeRedisType($redis, $legacyCounterKey) === 'string') {
        if (!$redis->exists($counterKey)) {
            $legacyVal = (int)$redis->get($legacyCounterKey);
            if ($legacyVal > 0) {
                $redis->set($counterKey, $legacyVal);
            }
        }
    }

    return (int)$redis->incr($counterKey);
}

/**
 * Generate synchronized next patient ID.
 */
function getNextPatientId($redis) {
    $c1 = (int)($redis->get('patient:counter') ?? 0);
    $c2 = (int)($redis->get('patient:id') ?? 0);
    $nextVal = max($c1, $c2) + 1;
    $redis->set('patient:counter', $nextVal);
    $redis->set('patient:id', $nextVal);
    return 'P' . str_pad($nextVal, 4, '0', STR_PAD_LEFT);
}

/**
 * Calculate numeric priority from risk level.
 * Emergency => 1 (highest), High => 2, Normal => 3.
 */
function getRiskPriorityValue($riskLevel) {
    $normalized = ucfirst(strtolower(trim($riskLevel ?? 'Normal')));
    if ($normalized === 'Emergency') {
        return 1;
    }
    if ($normalized === 'High') {
        return 2;
    }
    return 3;
}

/**
 * Safely delete a patient record and clean up associated active token/queue entry
 * without affecting other patients, other tokens, department queues, or counters.
 */
function deletePatientRecord($redis, $patientId) {
    if (empty($patientId)) {
        return false;
    }

    $patientKey = 'patient:' . $patientId;
    if (!$redis->exists($patientKey) || safeRedisType($redis, $patientKey) !== 'hash') {
        return false;
    }

    $patientData = $redis->hgetall($patientKey);

    // 1. Clean up email index
    if (!empty($patientData['email'])) {
        $emailKey = 'patient:email:' . strtolower(trim($patientData['email']));
        if ($redis->exists($emailKey) && safeRedisGet($redis, $emailKey) === $patientId) {
            $redis->del($emailKey);
        }
    }

    // 2. Collect tokens belonging to this patient that need queue cleanup
    $tokensToClean = [];
    $activeTokenKey = 'patient:active-token:' . $patientId;
    $activeTokenId = safeRedisGet($redis, $activeTokenKey);
    if (!empty($activeTokenId)) {
        $tokensToClean[] = $activeTokenId;
    }

    try {
        $allTokenKeys = $redis->keys('token:*');
        foreach ($allTokenKeys as $tk) {
            if (safeRedisType($redis, $tk) === 'hash') {
                $tPid = $redis->hget($tk, 'patient_id');
                if ($tPid === $patientId) {
                    $tid = substr($tk, strlen('token:'));
                    if (!in_array($tid, $tokensToClean, true)) {
                        $tokensToClean[] = $tid;
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("deletePatientRecord token scan error: " . $e->getMessage());
    }

    // Safely remove tokens from queues and serving keys
    foreach ($tokensToClean as $tid) {
        $tokKey = 'token:' . $tid;
        $tokData = safeRedisHGetAll($redis, $tokKey);
        $deptKey = $tokData['department_key'] ?? '';
        $tokDate = $tokData['date'] ?? date('Y-m-d');

        // Remove from department queue list safely using LREM
        if (!empty($deptKey)) {
            $queueKey = 'queue:' . $deptKey . ':' . $tokDate;
            if ($redis->exists($queueKey) && safeRedisType($redis, $queueKey) === 'list') {
                $redis->lrem($queueKey, 0, $tid);
            }

            // If serving in department
            $curDeptKey = 'queue:current:' . $deptKey . ':' . $tokDate;
            if (safeRedisGet($redis, $curDeptKey) === $tid) {
                $redis->del($curDeptKey);
            }
            $todayDeptKey = 'queue:current:' . $deptKey . ':' . date('Y-m-d');
            if (safeRedisGet($redis, $todayDeptKey) === $tid) {
                $redis->del($todayDeptKey);
            }
        }

        // If serving with doctor
        $calledBy = $tokData['called_by'] ?? '';
        if (!empty($calledBy)) {
            $docServingKey = 'doctor:serving:' . $calledBy;
            if (safeRedisGet($redis, $docServingKey) === $tid) {
                $redis->del($docServingKey);
            }
        }

        // Check any doctor:serving:* key that matches $tid
        try {
            $docKeys = $redis->keys('doctor:serving:*');
            foreach ($docKeys as $dk) {
                if (safeRedisGet($redis, $dk) === $tid) {
                    $redis->del($dk);
                }
            }
        } catch (Exception $e) {
            error_log("deletePatientRecord doctor serving cleanup: " . $e->getMessage());
        }

        // Delete the token hash
        $redis->del($tokKey);
    }

    if ($redis->exists($activeTokenKey)) {
        $redis->del($activeTokenKey);
    }

    // 3. Finally delete the patient record hash
    $redis->del($patientKey);

    return true;
}