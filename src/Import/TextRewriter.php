<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Turns the tokens of an archive's texts into Cairn's Markdown:
 * `{{issue:ID}}` into the key the issue got (`#MD-SPT-12`), or its id as
 * plain text when it is not imported; `{{user:ID}}` into a mention;
 * `{{file:ID}}` into the address of the attachment. A token that names
 * nothing known is left as it is.
 */
final class TextRewriter
{
    /**
     * @param callable(string): (string|null)   $keyOf the key an issue of the archive gets
     * @param array<string, array{int, string}> $users person id => [account id, name]
     */
    public function references(string $text, callable $keyOf, array $users): string
    {
        $text = (string) preg_replace_callback('/\{\{issue:([^}\s]+)\}\}/', static function (array $match) use ($keyOf): string {
            $key = $keyOf($match[1]);

            return null !== $key ? '#'.$key : '#'.$match[1];
        }, $text);

        return (string) preg_replace_callback('/\{\{user:([^}\s]+)\}\}/', static function (array $match) use ($users): string {
            $user = $users[$match[1]] ?? null;

            return null === $user ? $match[0] : \sprintf('@[%s](user:%d)', str_replace(['[', ']'], '', $user[1]), $user[0]);
        }, $text);
    }

    /**
     * @param array<string, string> $urls file id => address of its attachment
     */
    public function files(string $text, array $urls): string
    {
        return (string) preg_replace_callback('/\{\{file:([^}\s]+)\}\}/', static fn (array $match): string => $urls[$match[1]] ?? $match[0], $text);
    }
}
