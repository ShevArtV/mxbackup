<?php

namespace MxBackup\Core\Masking;

final class JsonPathMasker
{
    private $sensitiveKeys = [
        'email', 'phone', 'mobilephone', 'fullname', 'receiver', 'address',
        'street', 'city', 'zip', 'postcode', 'comment', 'ip', 'token', 'secret',
        'password', 'authorization', 'api_key', 'apikey',
    ];

    public function mask($value, $seed)
    {
        if (!is_string($value) || trim($value) === '') {
            return $value;
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return '';
        }
        $masked = $this->walk($decoded, (string)$seed);
        return json_encode($masked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function applyPath($value, $path, $action, $replacement, $seed)
    {
        if (!is_string($value) || trim($value) === '') return $value;
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) return $value;
        $segments = array_values(array_filter(preg_split('/[.\/]+/', preg_replace('/^\$\.?/', '', (string)$path)), 'strlen'));
        if (!$segments) return $value;
        $this->applySegments($decoded, $segments, $action, $replacement, (string)$seed);
        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function applySegments(array &$data, array $segments, $action, $replacement, $seed)
    {
        $segment = array_shift($segments);
        foreach ($data as $key => &$value) {
            if ($segment !== '*' && (string)$key !== $segment) continue;
            if ($segments && is_array($value)) {
                $this->applySegments($value, $segments, $action, $replacement, $seed . '|' . $key);
                continue;
            }
            if ($segments) continue;
            if ($action === 'replace') $value = $replacement;
            elseif ($action === 'hash') $value = hash('sha256', $seed . '|' . (string)$value);
            elseif ($action === 'hide') $value = null;
            else $value = $this->replacement(strtolower((string)$key), $seed . '|' . $key);
        }
        unset($value);
    }

    private function walk(array $data, $seed)
    {
        foreach ($data as $key => $value) {
            $normalized = strtolower((string)$key);
            if (in_array($normalized, $this->sensitiveKeys, true)) {
                $data[$key] = $this->replacement($normalized, $seed . '|' . $normalized);
            } elseif (is_array($value)) {
                $data[$key] = $this->walk($value, $seed . '|' . $normalized);
            }
        }
        return $data;
    }

    private function replacement($key, $seed)
    {
        $id = hexdec(substr(hash('sha256', $seed), 0, 7));
        if ($key === 'email') return 'user' . $id . '@example.test';
        if (strpos($key, 'phone') !== false) return '+7000' . str_pad((string)($id % 10000000), 7, '0', STR_PAD_LEFT);
        if ($key === 'ip') return '192.0.2.' . (($id % 253) + 1);
        if (in_array($key, ['token', 'secret', 'password', 'authorization', 'api_key', 'apikey'], true)) return '';
        return 'Test ' . $id;
    }
}
