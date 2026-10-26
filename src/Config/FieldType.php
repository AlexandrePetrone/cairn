<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Type of a global field. Drives the form widget and the value storage.
 */
enum FieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case RichText = 'rich_text';
    /** Dropdown list of options. */
    case Select = 'select';
    /** Segmented buttons: a short list of options shown inline. */
    case Choice = 'choice';
    case Number = 'number';
    case Date = 'date';
    case Url = 'url';
    case Bool = 'bool';

    public function hasOptions(): bool
    {
        return self::Select === $this || self::Choice === $this;
    }
}
