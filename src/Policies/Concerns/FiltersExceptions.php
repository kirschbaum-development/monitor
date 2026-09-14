<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Policies\Concerns;

use Throwable;

trait FiltersExceptions
{
    /** @var list<class-string<Throwable>> */
    protected array $only = [];

    /** @var list<class-string<Throwable>> */
    protected array $except = [];

    /**
     * Only these exception classes are subject to the policy.
     *
     * @param  list<class-string<Throwable>>  $classes
     */
    public function on(array $classes): static
    {
        $this->only = $classes;

        return $this;
    }

    /**
     * These exception classes are never subject to the policy.
     *
     * @param  list<class-string<Throwable>>  $classes
     */
    public function except(array $classes): static
    {
        $this->except = $classes;

        return $this;
    }

    protected function applies(Throwable $e): bool
    {
        foreach ($this->except as $class) {
            if ($e instanceof $class) {
                return false;
            }
        }

        if ($this->only === []) {
            return true;
        }

        foreach ($this->only as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
