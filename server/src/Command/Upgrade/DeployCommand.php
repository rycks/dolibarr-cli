<?php

namespace Dolicli\Command\Upgrade;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\System\Files;
use Dolicli\System\Http;
use Dolicli\Upgrade\Deployer;
use Dolicli\Upgrade\FileSignature;
use Dolicli\Upgrade\Releases;
use Dolicli\Upgrade\Workspace;

/**
 * Prepare htdocs.new-<version> next to the current htdocs, without touching the running site.
 */
final class DeployCommand extends Command
{
    public function name(): string
    {
        return 'upgrade deploy';
    }

    public function summary(): string
    {
        return 'Prepare the files of a new version next to the current htdocs (htdocs.new-<version>)';
    }

    public function category(): string
    {
        return self::WRITE;
    }

    public function params(): array
    {
        return [
            Param::value('version', 'X.Y.Z', 'Version to deploy')->required()->matching('/^\d+\.\d+\.\d+$/'),
            Param::value('archive', 'PATH', 'Dolibarr archive (.tgz or .zip) already on the server'),
            Param::value('source', 'PATH', 'Directory holding the new htdocs (or a directory containing htdocs/)'),
            Param::flag('download', 'Download the archive from dolibarr.org (the server needs outgoing access)'),
            Param::value('sha256', 'HEX', 'Expected SHA256 of the archive')->matching('/^[0-9a-f]{64}$/'),
            Param::value('signature', 'PATH', 'Official signature filelist-X.Y.Z.xml to check the new files against'),
            Param::flag('remote-signature', 'Download the official signature from dolibarr.org'),
            Param::flag('skip-verify', 'Do not check the new files against a signature'),
        ];
    }

    public function help(): string
    {
        return "Exactly one of archive=, source=, --download. The new files are checked against the official\n"
            . "signature (signature=, or --remote-signature) unless --skip-verify. conf/conf.php, custom/ and\n"
            . "the local top-level files are carried over. Then: dolicli upgrade switch-files version=X.Y.Z.\n"
            . 'Needs write access to the directory containing htdocs (otherwise the switch is a manual action).';
    }

    public function execute(Context $ctx): array
    {
        $version = (string) $ctx->value('version');
        $archive = (string) $ctx->value('archive');
        $source = (string) $ctx->value('source');
        $download = $ctx->flag('download');
        if (1 !== ('' !== $archive ? 1 : 0) + ('' !== $source ? 1 : 0) + ($download ? 1 : 0)) {
            throw Failure::usage('MISSING_PARAMETER', 'Give exactly one of archive=PATH, source=PATH, --download.');
        }
        $signature = (string) $ctx->value('signature');
        if ('' === $signature && !$ctx->flag('remote-signature') && !$ctx->flag('skip-verify')) {
            throw Failure::usage('MISSING_PARAMETER', 'Give signature=PATH or --remote-signature, or --skip-verify to deploy unchecked files.');
        }

        $deployer = Deployer::current();
        if ($ctx->dryRun()) {
            $actions = [];
            foreach ($deployer->switchBlockers() as $blocker) {
                $actions[] = 'BLOCKED: ' . $blocker;
            }
            $actions[] = 'Prepare ' . $deployer->stagedDir($version) . ' from ' . ($download ? 'dolibarr.org' : ('' !== $archive ? $archive : $source));
            $actions[] = $ctx->flag('skip-verify') ? 'Do not check the signature' : 'Check the new files against the official signature';
            $actions[] = 'Carry conf/conf.php, custom/ and local files over';

            return ['dry_run' => true, 'actions' => $actions];
        }

        $workspace = Workspace::current();
        $workspace->ensure();
        $temporary = [];
        try {
            if ($download) {
                $archive = $this->download($ctx, $version, $workspace->dir());
                $temporary[] = $archive;
            }
            if ('' !== $archive && null !== $ctx->value('sha256')) {
                $hash = hash_file('sha256', $archive);
                if ($hash !== $ctx->value('sha256')) {
                    throw Failure::operation('ARCHIVE_CORRUPTED', "SHA256 of {$archive} is {$hash}, expected {$ctx->value('sha256')}: transfer corrupted, nothing was changed.");
                }
            }
            if ($ctx->flag('remote-signature')) {
                $url = sprintf(FileSignature::REMOTE_URL, $version);
                $xml = Http::get($url, 60);
                if (null === $xml) {
                    throw Failure::operation('SIGNATURE_NOT_FOUND', "Cannot download the official signature {$url}: " . Http::lastError());
                }
                $signature = $workspace->dir() . '/filelist-' . $version . '.xml';
                Files::writeAtomic($signature, $xml, 0640);
                $temporary[] = $signature;
            }
            $result = $deployer->stage('' !== $archive ? $archive : $source, $version, $ctx->flag('skip-verify') ? '' : $signature, function ($line) use ($ctx) {
                $ctx->progress($line);
            });
        } finally {
            foreach ($temporary as $file) {
                @unlink($file);
            }
        }
        if (!$result['owner']['kept']) {
            $ctx->warn('OWNER_CHANGED', $result['owner']['message']);
        }
        $workspace->recordStep('deploy', 'done', $result['staged']);

        return $result;
    }

    private function download(Context $ctx, string $version, string $dir): string
    {
        foreach (Releases::archiveUrls($version) as $url) {
            $target = $dir . '/' . basename($url);
            $ctx->progress("Downloading {$url}");
            if (Http::download($url, $target, 600)) {
                return $target;
            }
        }
        throw Failure::operation('DOWNLOAD_FAILED', "Download of Dolibarr {$version} failed: " . Http::lastError());
    }

    public function renderText(array $data): string
    {
        return "Dolibarr {$data['version']} prepared in {$data['staged']}" . ($data['verified'] ? ' (signature checked)' : ' (signature NOT checked)') . ".\n"
            . 'Carried over: ' . ([] === $data['carried'] ? 'nothing' : implode(', ', $data['carried'])) . "\n"
            . "Next: dolicli upgrade switch-files version={$data['version']}\n";
    }
}
