<?php

declare(strict_types=1);

namespace Dolifleet\Console\Screen;

use Dolifleet\Console\Actions;
use Dolifleet\Console\App;
use Dolifleet\Console\Lang\Translator;
use Dolifleet\Console\Tasks\Command;
use Dolifleet\Console\Terminal\Line;
use Dolifleet\Console\View\Input;
use Dolifleet\Inventory\InventoryException;
use Dolifleet\Inventory\Targets;

/**
 * Palette: any dolicli command, with completion, on the instance open or the checked ones, or on
 * a target typed first (tag:prod system status). The category comes from the manifest: a read
 * runs at once, a write goes through the preview, a destruction needs the name and one instance.
 */
final class PaletteScreen extends Screen
{
    private Input $input;

    private string $error = '';

    public function __construct(private readonly string $context)
    {
        $this->input = new Input('', 300);
    }

    public function breadcrumb(App $app): array
    {
        return [$app->t->t('palette.title')];
    }

    public function captures(): bool
    {
        return true;
    }

    public function command(App $app): ?array
    {
        [$target, $words] = $this->parse($app);

        return '' === $target || [] === $words ? null : Command::exec($target, $words);
    }

    public function keys(App $app): array
    {
        [$target] = $this->parse($app);

        return [
            ['Tab', $app->t->t('key.complete')],
            [$app->t->t('key.enter'), '' === $target ? $app->t->t('key.run') : $app->t->t('palette.run_on', $target)],
            [$app->t->t('key.esc'), $app->t->t('key.close')],
        ];
    }

    public function handle(App $app, string $key): bool
    {
        $this->error = '';
        if ('esc' === $key) {
            $app->pop();

            return true;
        }
        if ('tab' === $key) {
            $suggestions = $this->suggestions($app);
            if ([] !== $suggestions) {
                [$target, $words, $explicit] = $this->parse($app);
                $arguments = array_slice($words, count(explode(' ', $this->typedCommand($words))));
                $this->input->value = trim(($explicit ? $target.' ' : '').$suggestions[0]['name'].' '.implode(' ', $arguments)).' ';
            }

            return true;
        }
        if ('enter' === $key) {
            $this->submit($app);

            return true;
        }
        $this->input->handle($key);

        return true;
    }

    public function body(App $app, int $width, int $height): array
    {
        $t = $app->t;
        $lines = [Line::of(' : ', 'key')->add($this->input->value, 'input')->add(' ', 'reverse'), Line::of($app->style->rule($width), 'frame')];
        if ('' !== $this->error) {
            $lines[] = Line::of('  '.$this->error, 'error');
        }
        foreach (array_slice($this->suggestions($app), 0, max(1, $height - 4)) as $entry) {
            $lines[] = Line::of('  '.Line::pad($entry['name'], 28), 'bold')
                ->add(Line::pad($entry['summary'], max(10, $width - 46)))
                ->add('  '.$t->t('category.'.$entry['category']), 'destructive' === $entry['category'] ? 'crit' : ('write' === $entry['category'] ? 'warn' : 'dim'));
        }
        [$target] = $this->parse($app);
        $lines[] = Line::blank();
        $lines[] = Line::of('  '.('' === $target ? $t->t('palette.no_target_hint') : $t->t('palette.target_hint', $target)), 'dim');

        return $lines;
    }

    /**
     * @return array{0: string, 1: list<string>, 2: bool} Target, dolicli words, whether the target was typed
     */
    private function parse(App $app): array
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim($this->input->value)) ?: [], static fn (string $w): bool => '' !== $w));
        if ([] !== $words && $this->isTarget($app, $words[0])) {
            return [$words[0], array_slice($words, 1), true];
        }

        return [$this->context, $words, false];
    }

    private function isTarget(App $app, string $word): bool
    {
        if ('all' === $word || str_starts_with($word, 'tag:') || str_starts_with($word, 'server:')) {
            return true;
        }
        $instances = $app->inventory()->instances();
        foreach (explode(',', $word) as $part) {
            if ( ! isset($instances[$part])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Words of the command typed so far (without its arguments).
     *
     * @param list<string> $words
     */
    private function typedCommand(array $words): string
    {
        $command = [];
        foreach ($words as $word) {
            if (str_contains($word, '=') || str_starts_with($word, '-')) {
                break;
            }
            $command[] = $word;
        }

        return implode(' ', array_slice($command, 0, 3));
    }

    /**
     * @return list<array{name: string, summary: string, category: string}>
     */
    private function suggestions(App $app): array
    {
        [, $words] = $this->parse($app);
        $typed = $this->typedCommand($words);
        $found = [];
        foreach ([['lang', 'palette.lang', 'pilot'], ['quit', 'key.quit', 'pilot']] as [$name, $key, $category]) {
            if ('' === $typed || str_starts_with($name, $typed)) {
                $found[] = ['name' => $name, 'summary' => $app->t->t($key), 'category' => $category];
            }
        }
        foreach ($app->manifest() as $name => $entry) {
            if ('' !== $typed && ! str_starts_with($name, $typed) && ! str_starts_with($typed, $name.' ')) {
                continue;
            }
            $summary = $app->t->has('palette.cmd.'.$name) ? $app->t->t('palette.cmd.'.$name) : (string) ($entry['summary'] ?? '');
            $found[] = ['name' => $name, 'summary' => $summary, 'category' => (string) ($entry['category'] ?? 'read')];
        }

        return $found;
    }

    private function submit(App $app): void
    {
        [$target, $words] = $this->parse($app);
        if ([] === $words) {
            return;
        }
        if ('quit' === $words[0]) {
            $app->requestQuit();

            return;
        }
        if ('lang' === $words[0]) {
            $code = Translator::normalize($words[1] ?? '');
            if ( ! isset(Translator::available()[$code])) {
                $this->error = $app->t->t('palette.lang_unknown', implode(', ', array_keys(Translator::available())));

                return;
            }
            $app->changeLanguage(new Translator($code), in_array('--save', $words, true));
            $app->pop();
            $app->toast($app->t->t('palette.lang_changed', $code));

            return;
        }
        $entry = $this->entry($app, $words);
        if (null === $entry) {
            $this->error = $app->t->t('palette.unknown', $this->typedCommand($words));

            return;
        }
        if ('' === $target) {
            $this->error = $app->t->t('palette.no_target');

            return;
        }
        try {
            $ids = array_keys(Targets::resolve($app->inventory(), $target));
        } catch (InventoryException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $category = (string) ($entry['category'] ?? 'read');
        if ('read' === $category) {
            $args = Command::exec($target, $words);
            $task = $app->read($args, 'palette', $ids);
            $app->replace(new ResultScreen($task, $args, implode(' ', array_slice($words, 0, 3))));

            return;
        }
        if ('destructive' === $category && 1 !== count($ids)) {
            $this->error = $app->t->t('palette.destructive_batch');

            return;
        }
        $app->replace(Actions::command($app, $target, $ids, $words, 'destructive' === $category));
    }

    /**
     * Manifest entry of the longest command at the start of the words.
     *
     * @param list<string> $words
     * @return array<string, mixed>|null
     */
    private function entry(App $app, array $words): ?array
    {
        $command = explode(' ', $this->typedCommand($words));
        $manifest = $app->manifest();
        for ($length = count($command); $length >= 1; --$length) {
            $name = implode(' ', array_slice($command, 0, $length));
            if (isset($manifest[$name])) {
                return $manifest[$name];
            }
        }

        return null;
    }
}
