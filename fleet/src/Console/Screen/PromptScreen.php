<?php

declare(strict_types=1);

namespace Dolifleet\Console\Screen;

use Closure;
use Dolifleet\Console\App;
use Dolifleet\Console\Terminal\Line;
use Dolifleet\Console\View\Input;

/**
 * One question, one line to type (target version of an upgrade...). The callback returns an
 * error to show, or null when it took over (usually by replacing this screen).
 */
final class PromptScreen extends Screen
{
    private Input $input;

    private string $error = '';

    /**
     * @param list<string> $breadcrumb
     * @param Closure(App, string): ?string $submit
     */
    public function __construct(private readonly array $breadcrumb, private readonly string $question, private readonly Closure $submit, string $default = '')
    {
        $this->input = new Input($default, 120);
    }

    public function breadcrumb(App $app): array
    {
        return $this->breadcrumb;
    }

    public function captures(): bool
    {
        return true;
    }

    public function keys(App $app): array
    {
        return [[$app->t->t('key.enter'), $app->t->t('key.next')], [$app->t->t('key.esc'), $app->t->t('key.cancel')]];
    }

    public function handle(App $app, string $key): bool
    {
        if ('esc' === $key) {
            $app->pop();

            return true;
        }
        if ('enter' === $key) {
            $this->error = (string) ($this->submit)($app, trim($this->input->value));

            return true;
        }
        $this->input->handle($key);
        $this->error = '';

        return true;
    }

    public function body(App $app, int $width, int $height): array
    {
        $lines = [Line::blank(), Line::of('   '.$this->question, 'bold'), Line::blank(), Line::of('   > ', 'key')->add($this->input->value, 'input')->add(' ', 'reverse')];
        if ('' !== $this->error) {
            $lines[] = Line::blank();
            $lines[] = Line::of('   '.$this->error, 'error');
        }

        return $lines;
    }
}
