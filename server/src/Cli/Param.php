<?php

namespace Dolicli\Cli;

/**
 * Declaration of a command parameter (`name=value`) or flag (`--name`), used for
 * validation, help and the manifest.
 */
final class Param
{
    const VALUE = 'value';
    const FLAG = 'flag';

    /** @var string */
    private $name;

    /** @var string */
    private $kind;

    /** @var string */
    private $description;

    /** @var bool */
    private $required = false;

    /** @var bool */
    private $sensitive = false;

    /** @var string */
    private $placeholder = 'VALUE';

    /** @var array<int, string> */
    private $choices = [];

    /** @var string */
    private $pattern = '';

    private function __construct(string $name, string $kind, string $description)
    {
        $this->name = $name;
        $this->kind = $kind;
        $this->description = $description;
    }

    public static function value(string $name, string $placeholder, string $description): self
    {
        $param = new self($name, self::VALUE, $description);
        $param->placeholder = $placeholder;

        return $param;
    }

    public static function flag(string $name, string $description): self
    {
        return new self($name, self::FLAG, $description);
    }

    public function required(): self
    {
        $this->required = true;

        return $this;
    }

    /**
     * A secret: masked in journals, refused on the real command line.
     */
    public function sensitive(): self
    {
        $this->sensitive = true;

        return $this;
    }

    /**
     * @param array<int, string> $choices
     */
    public function choices(array $choices): self
    {
        $this->choices = $choices;

        return $this;
    }

    public function integer(): self
    {
        $this->pattern = '/^\d+$/';

        return $this;
    }

    public function matching(string $pattern): self
    {
        $this->pattern = $pattern;

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function isFlag(): bool
    {
        return self::FLAG === $this->kind;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isSensitive(): bool
    {
        return $this->sensitive;
    }

    public function description(): string
    {
        return $this->description;
    }

    /**
     * @return array<int, string>
     */
    public function getChoices(): array
    {
        return $this->choices;
    }

    public function syntax(): string
    {
        if ($this->isFlag()) {
            return '--' . $this->name;
        }

        return $this->name . '=' . ([] !== $this->choices ? implode('|', $this->choices) : $this->placeholder);
    }

    /**
     * Error message for an invalid value, empty string when the value is valid.
     */
    public function validate(string $value): string
    {
        if ([] !== $this->choices && !in_array($value, $this->choices, true)) {
            return "Invalid value '{$value}' for {$this->name}: expected " . implode(', ', $this->choices) . '.';
        }
        if ('' !== $this->pattern && 1 !== preg_match($this->pattern, $value)) {
            return "Invalid value '{$value}' for {$this->name}.";
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'kind' => $this->kind,
            'required' => $this->required,
            'sensitive' => $this->sensitive,
            'description' => $this->description,
        ];
        if ([] !== $this->choices) {
            $data['choices'] = $this->choices;
        }

        return $data;
    }
}
