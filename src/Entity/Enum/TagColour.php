<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * The colours a tag is drawn with: a closed palette, each a colour role of
 * the theme (`--color-tag-<value>` in app.css, with its light and its dark
 * value). A new case comes with its token there and its variant in
 * `Ui:Tag`.
 */
enum TagColour: string
{
    case Red = 'red';
    case Orange = 'orange';
    case Amber = 'amber';
    case Yellow = 'yellow';
    case Lime = 'lime';
    case Green = 'green';
    case Emerald = 'emerald';
    case Teal = 'teal';
    case Cyan = 'cyan';
    case Sky = 'sky';
    case Blue = 'blue';
    case Indigo = 'indigo';
    case Violet = 'violet';
    case Purple = 'purple';
    case Fuchsia = 'fuchsia';
    case Pink = 'pink';
    case Rose = 'rose';
    case Brown = 'brown';
    case Slate = 'slate';
    case Gray = 'gray';
}
