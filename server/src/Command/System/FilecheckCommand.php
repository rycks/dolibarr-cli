<?php

namespace Dolicli\Command\System;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Param;
use Dolicli\Upgrade\Filecheck;

final class FilecheckCommand extends Command
{
    public function name(): string
    {
        return 'system filecheck';
    }

    public function summary(): string
    {
        return 'Check deployed files against the official signature';
    }

    public function params(): array
    {
        return [
            Param::flag('remote', 'Use the official signature of dolibarr.org (recommended: the local one can be altered along with the files)'),
            Param::value('xmlfile', 'PATH', 'Use a specific XML signature file'),
            Param::flag('show-ok', 'Also list the files that match'),
            Param::flag('detect-extra', 'Report files present on disk but not in the signature (slower)'),
        ];
    }

    public function help(): string
    {
        return "Compares the MD5 of every file of htdocs/ with a reference signature: htdocs/install/filelist-<version>.xml\n"
            . "by default. Modified or missing files set exit code 1 (errors FILES_MODIFIED, FILES_MISSING);\n"
            . 'a signature that cannot be found or read too (SIGNATURE_NOT_FOUND).';
    }

    public function examples(): array
    {
        return ['system filecheck --remote', 'system filecheck --detect-extra --show-ok', 'system filecheck --format=json > report.json'];
    }

    public function execute(Context $ctx): array
    {
        $list = Filecheck::run($ctx->flag('remote'), (string) $ctx->value('xmlfile'), $ctx->flag('detect-extra'));

        if ([] !== $list['missing']) {
            $ctx->addError('FILES_MISSING', count($list['missing']) . ' file(s) missing');
        }
        if ([] !== $list['updated']) {
            $ctx->addError('FILES_MODIFIED', count($list['updated']) . ' file(s) modified');
        }

        $data = [
            'version' => DOL_VERSION,
            'document_root' => DOL_DOCUMENT_ROOT,
            'signature' => $list['source'],
            'summary' => [
                'in_signature' => count($list['insignature']),
                'ok' => count($list['ok']),
                'modified' => count($list['updated']),
                'missing' => count($list['missing']),
                'extra' => $ctx->flag('detect-extra') ? count($list['added']) : null,
            ],
            'missing' => $list['missing'],
            'modified' => $list['updated'],
            'extra' => $list['added'],
        ];
        if ($ctx->flag('show-ok')) {
            $data['ok'] = $list['ok'];
        }

        return $data;
    }

    public function renderText(array $data): string
    {
        $out = "Dolibarr {$data['version']} in {$data['document_root']}\nSignature: {$data['signature']}\n\n";
        foreach ($data['missing'] as $file) {
            $out .= '  [MISSING ] ' . $file['filename'] . "\n";
        }
        foreach ($data['modified'] as $file) {
            $out .= '  [MODIFIED] ' . $file['filename'] . '  expected ' . $file['expectedmd5'] . ' got ' . $file['md5'] . "\n";
        }
        foreach ($data['extra'] as $file) {
            $out .= '  [EXTRA   ] ' . $file['filename'] . "\n";
        }
        if (isset($data['ok'])) {
            foreach ($data['ok'] as $file) {
                $out .= '  [OK      ] ' . $file['filename'] . "\n";
            }
        }
        $summary = $data['summary'];
        $out .= "\nIn signature: {$summary['in_signature']}, ok: {$summary['ok']}, modified: {$summary['modified']}, missing: {$summary['missing']}"
            . (null !== $summary['extra'] ? ", extra: {$summary['extra']}" : '') . "\n";

        return $out . (0 === $summary['missing'] && 0 === $summary['modified'] ? "Deployment integrity OK.\n" : "Deployment integrity FAILED.\n");
    }
}
