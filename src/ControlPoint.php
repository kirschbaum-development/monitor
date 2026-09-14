<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

use Illuminate\Container\Container;
use Illuminate\Foundation\Bus\PendingDispatch;
use Kirschbaum\Monitor\Attributes\Point;
use Kirschbaum\Monitor\Exceptions\InvalidControlPoint;
use Kirschbaum\Monitor\Queue\RunControlPoint;
use ReflectionClass;
use Throwable;

/**
 * The class form of a control point.
 *
 *     #[Point('payment.charge', profile: 'external')]
 *     class ChargeCard extends ControlPoint
 *     {
 *         public function __construct(private readonly Invoice $invoice) {}
 *
 *         protected function control(Control $control): void
 *         {
 *             $control->recover(CardDeclined::class, fn ($e) => ChargeResult::declined($e->code))
 *                 ->escalate(PagePayments::class);
 *         }
 *
 *         public function handle(StripeClient $stripe): ChargeResult { ... }
 *     }
 *
 *     ChargeCard::run($invoice);      // value or throws
 *     ChargeCard::attempt($invoice);  // Outcome
 *
 * handle() is resolved through the container, so its parameters are injected.
 * control() must not read constructor arguments: the inventory calls it on an
 * instance built without the constructor, and says so when that fails.
 */
abstract class ControlPoint
{
    /**
     * Declare risks, corrections, policies, limits and escalation.
     */
    protected function control(Control $control): void {}

    /**
     * Context recorded with every transition of this point.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [];
    }

    /**
     * Construct and execute; return the value or throw what escaped.
     */
    public static function run(mixed ...$arguments): mixed
    {
        return static::make(...$arguments)->toControl()->run(fn (): mixed => static::invoke(static::make(...$arguments)));
    }

    /**
     * Construct and execute; return the Outcome.
     */
    public static function attempt(mixed ...$arguments): Outcome
    {
        $instance = static::make(...$arguments);

        return $instance->toControl()->attempt(fn (): mixed => static::invoke($instance));
    }

    /**
     * Construct the point and run it on the queue. The queue owns the job's
     * retries; the point owns its policies, corrections and records.
     */
    public static function dispatch(mixed ...$arguments): PendingDispatch
    {
        return dispatch(new RunControlPoint(static::make(...$arguments)));
    }

    /**
     * Construct the point and run it through the queue synchronously.
     */
    public static function dispatchSync(mixed ...$arguments): mixed
    {
        return dispatch_sync(new RunControlPoint(static::make(...$arguments)));
    }

    /**
     * Execute this instance and return the Outcome.
     */
    public function execute(): Outcome
    {
        return $this->toControl()->attempt(fn (): mixed => static::invoke($this));
    }

    /**
     * The Control this point declares, ready to run.
     */
    public function toControl(): Control
    {
        $point = static::point();

        $control = new Control($point->name, static::class);

        if ($point->profile !== null) {
            $control->profile($point->profile);
        }

        if ($point->domain !== null) {
            $control->domain($point->domain);
        }

        $this->control($control);

        return $control->with($this->context());
    }

    /**
     * The #[Point] attribute on this class.
     */
    public static function point(): Point
    {
        $attributes = (new ReflectionClass(static::class))->getAttributes(Point::class);

        if ($attributes === []) {
            throw new InvalidControlPoint(sprintf('[%s] must carry a #[Point] attribute.', static::class));
        }

        return $attributes[0]->newInstance();
    }

    /**
     * The static description of this point for the inventory, without running
     * or constructing it.
     *
     * @return array<string, mixed>
     */
    public static function describe(): array
    {
        $instance = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $point = static::point();
        $control = new Control($point->name, static::class, validate: false);

        if ($point->profile !== null) {
            $control->profile($point->profile);
        }

        if ($point->domain !== null) {
            $control->domain($point->domain);
        }

        $notes = [];
        $unreadable = false;

        try {
            $instance->control($control);
        } catch (Throwable $e) {
            $unreadable = true;
            $notes[] = sprintf('control() could not be read statically: %s', $e->getMessage());
        }

        return $control->describe() + ['form' => 'class', 'notes' => $notes, 'unreadable' => $unreadable];
    }

    protected static function make(mixed ...$arguments): static
    {
        return (new ReflectionClass(static::class))->newInstanceArgs($arguments);
    }

    protected static function invoke(self $instance): mixed
    {
        $handle = [$instance, 'handle'];

        if (! is_callable($handle)) {
            throw new InvalidControlPoint(sprintf('[%s] must define a public handle() method.', static::class));
        }

        return Container::getInstance()->call($handle);
    }
}
