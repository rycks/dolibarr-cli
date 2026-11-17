<?php

declare(strict_types=1);

namespace Dolifleet\Console;

use Dolifleet\Console\Screen\PreviewScreen;
use Dolifleet\Console\Screen\PromptScreen;
use Dolifleet\Console\Screen\Screen;
use Dolifleet\Console\Tasks\Command;

/**
 * The writes of the console keys (m, b, u) and of the palette, always through a preview.
 */
final class Actions
{
    /**
     * Maintenance mode: switched off when it is on everywhere, switched on otherwise.
     *
     * @param list<string> $ids
     */
    public static function maintenance(App $app, array $ids): Screen
    {
        $on = false;
        foreach ($ids as $id) {
            if (true !== $app->row($id)?->maintenance()) {
                $on = true;
            }
        }
        $target = implode(',', $ids);
        $args = Command::maintenance($on, $target);

        return new PreviewScreen(new Action(
            $on ? 'maintenance-on' : 'maintenance-off',
            $app->t->t($on ? 'action.maintenance_on' : 'action.maintenance_off'),
            $app->t->t($on ? 'action.maintenance_on.summary' : 'action.maintenance_off.summary'),
            $target,
            $ids,
            $args,
            $args,
            $app->t->t('undo.same_key', 'm'),
            false,
            'maintenance',
        ));
    }

    /**
     * Backup of the database and the documents, as detached jobs on the servers.
     *
     * @param list<string> $ids
     */
    public static function backup(App $app, array $ids): Screen
    {
        $target = implode(',', $ids);
        $args = Command::backup($target);

        return new PreviewScreen(new Action(
            'backup',
            $app->t->t('action.backup'),
            $app->t->t('action.backup.summary'),
            $target,
            $ids,
            $args,
            $args,
            $app->t->t('undo.nothing'),
            false,
            'backup',
        ));
    }

    /**
     * Upgrade of one instance: the target version first, then the plan as preview.
     */
    public static function upgrade(App $app, string $id): Screen
    {
        $current = $app->row($id)?->version() ?? '';

        return new PromptScreen(
            [$id, $app->t->t('action.upgrade')],
            $app->t->t('upgrade.ask_version', $id, '' === $current ? '?' : $current),
            static function (App $app, string $version) use ($id): ?string {
                if (1 !== preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                    return $app->t->t('upgrade.bad_version');
                }
                $app->replace(new PreviewScreen(new Action(
                    'upgrade',
                    $app->t->t('action.upgrade_to', $version),
                    $app->t->t('action.upgrade.summary', $version),
                    $id,
                    [$id],
                    ['upgrade', 'run', $id],
                    Command::upgradePlan($id, $version),
                    $app->t->t('undo.upgrade'),
                    true,
                    'upgrade-run',
                )));

                return null;
            },
        );
    }

    /**
     * A write of the palette: dolicli words on a target.
     *
     * @param list<string> $words
     * @param list<string> $ids
     */
    public static function command(App $app, string $target, array $ids, array $words, bool $destructive): Screen
    {
        $args = Command::exec($target, $words);

        return new PreviewScreen(new Action(
            'command',
            implode(' ', array_slice($words, 0, 3)),
            $app->t->t('action.command.summary'),
            $target,
            $ids,
            $args,
            $args,
            $app->t->t('undo.unknown'),
            $destructive,
            'command',
        ));
    }
}
