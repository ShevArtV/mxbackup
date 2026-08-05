<?php

namespace MxBackup\Core\Report;

final class ManifestBuilder
{
    public function build(array $context, RunReport $report)
    {
        return [
            'schema' => 1,
            'mxbackup_version' => isset($context['mxbackup_version']) ? $context['mxbackup_version'] : 'unknown',
            'modx_version' => isset($context['modx_version']) ? $context['modx_version'] : 'unknown',
            'php_version' => PHP_VERSION,
            'profile' => $context['profile'],
            'mode' => $context['mode'],
            'created_at' => gmdate('c', $context['created_at']),
            'site_root' => $context['site_root'],
            'payload_checksums' => isset($context['payload_checksums']) ? $context['payload_checksums'] : [],
            'status' => 'payload_ready',
            'masking' => isset($context['mode']) && $context['mode'] === 'dev' ? 'applied' : 'not_applied',
            'warnings' => $report->toArray()['warnings'],
            'stats' => $report->toArray()['stats'],
        ];
    }
}
