<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\ChoiceList\Loader\AbstractChoiceLoader;

/**
 * The choices of a field whose list is searched on the server (a
 * PickerType with a `search_url`): no list is loaded with the form, a
 * value submitted is looked up one by one with `$find`, which answers
 * null for what may not be chosen. What was found is the whole list the
 * form then knows, enough to draw the choice again when it is refused.
 */
final class SearchedChoiceLoader extends AbstractChoiceLoader
{
    /** @var array<string, object> */
    private array $found = [];

    /**
     * @param \Closure(string): ?object $find
     */
    public function __construct(private readonly \Closure $find)
    {
    }

    /**
     * @return list<object>
     */
    protected function loadChoices(): iterable
    {
        return array_values($this->found);
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<object>
     */
    protected function doLoadChoicesForValues(array $values, ?callable $value): array
    {
        $choices = [];
        foreach ($values as $submitted) {
            if (!\is_string($submitted) || '' === $submitted) {
                continue;
            }
            $choice = $this->found[$submitted] ?? ($this->find)($submitted);
            if (null !== $choice) {
                $choices[] = $this->found[$submitted] = $choice;
            }
        }

        return $choices;
    }
}
