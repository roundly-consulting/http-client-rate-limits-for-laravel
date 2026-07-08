<?php

declare(strict_types=1);

use RoundlyConsulting\Enums\DataTransferObjects\EnumOption;
use RoundlyConsulting\Enums\Exceptions\EnumException;
use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\InvalidTimespanException;

// --- Domain methods (regression: kept verbatim) ---

it('returns the length in milliseconds for every case', function () {
    expect(Timespan::Second->lengthInMs())->toBe(1_000)
        ->and(Timespan::Minute->lengthInMs())->toBe(60_000)
        ->and(Timespan::Hour->lengthInMs())->toBe(3_600_000)
        ->and(Timespan::Day->lengthInMs())->toBe(86_400_000);
});

it('resolves a timespan from a valid string value', function () {
    expect(Timespan::fromValue('second'))->toBe(Timespan::Second)
        ->and(Timespan::fromValue('minute'))->toBe(Timespan::Minute)
        ->and(Timespan::fromValue('hour'))->toBe(Timespan::Hour)
        ->and(Timespan::fromValue('day'))->toBe(Timespan::Day);
});

it('throws a typed exception for an unknown string value', function () {
    Timespan::fromValue('fortnight');
})->throws(InvalidTimespanException::class, 'Timespan [fortnight] is not supported');

// --- Trait: static collection methods ---

it('exposes backed values in declaration order', function () {
    expect(Timespan::values()->all())->toBe(['second', 'minute', 'hour', 'day']);
});

it('exposes case names in declaration order', function () {
    expect(Timespan::names()->all())->toBe(['Second', 'Minute', 'Hour', 'Day']);
});

it('exposes readable labels derived from the headline of each value', function () {
    expect(Timespan::labels()->all())->toBe(['Second', 'Minute', 'Hour', 'Day']);
});

it('maps values to labels for select inputs', function () {
    $expected = [
        'second' => 'Second',
        'minute' => 'Minute',
        'hour' => 'Hour',
        'day' => 'Day',
    ];

    expect(Timespan::toOptions()->all())->toBe($expected)
        ->and(Timespan::toArray())->toBe($expected);
});

it('exposes option DTOs with matching value, label and name', function () {
    $options = Timespan::options();

    expect($options)->toHaveCount(4)
        ->and($options->first())->toBeInstanceOf(EnumOption::class);

    expect($options->map->toArray()->all())->toBe([
        ['value' => 'second', 'label' => 'Second', 'name' => 'Second'],
        ['value' => 'minute', 'label' => 'Minute', 'name' => 'Minute'],
        ['value' => 'hour', 'label' => 'Hour', 'name' => 'Hour'],
        ['value' => 'day', 'label' => 'Day', 'name' => 'Day'],
    ]);
});

it('builds an in rule from the backed values', function () {
    expect(Timespan::validationRule())->toBe('in:second,minute,hour,day');
});

it('counts and collects every case', function () {
    expect(Timespan::count())->toBe(4)
        ->and(Timespan::collect())->toHaveCount(4)
        ->and(Timespan::collect()->first())->toBe(Timespan::Second);
});

it('returns a valid case at random', function () {
    expect(Timespan::cases())->toContain(Timespan::random());
});

// --- Trait: name/label resolvers (distinct from domain fromValue) ---

it('resolves a case by its name', function () {
    expect(Timespan::fromName('Hour'))->toBe(Timespan::Hour)
        ->and(Timespan::tryFromName('Day'))->toBe(Timespan::Day)
        ->and(Timespan::tryFromName('bogus'))->toBeNull()
        ->and(Timespan::tryFromName(null))->toBeNull();
});

it('throws when resolving an unknown name', function () {
    Timespan::fromName('bogus');
})->throws(EnumException::class);

it('resolves a case by its label', function () {
    expect(Timespan::fromLabel('Day'))->toBe(Timespan::Day)
        ->and(Timespan::tryFromLabel('Minute'))->toBe(Timespan::Minute)
        ->and(Timespan::tryFromLabel('bogus'))->toBeNull()
        ->and(Timespan::tryFromLabel(null))->toBeNull();
});

it('throws when resolving an unknown label', function () {
    Timespan::fromLabel('bogus');
})->throws(EnumException::class);

it('reports whether a name or value exists', function () {
    expect(Timespan::hasName('Second'))->toBeTrue()
        ->and(Timespan::hasName('Year'))->toBeFalse()
        ->and(Timespan::hasValue('hour'))->toBeTrue()
        ->and(Timespan::hasValue('year'))->toBeFalse();
});

// --- Trait: instance methods ---

it('exposes readable and label for an instance', function () {
    expect(Timespan::Minute->readable())->toBe('Minute')
        ->and(Timespan::Minute->label())->toBe('Minute');
});

it('compares instances with is and isNot', function () {
    expect(Timespan::Hour->is(Timespan::Hour))->toBeTrue()
        ->and(Timespan::Hour->isNot(Timespan::Day))->toBeTrue()
        ->and(Timespan::Hour->is(Timespan::Day))->toBeFalse();
});

it('checks membership with isIn and isNotIn', function () {
    expect(Timespan::Hour->isIn([Timespan::Hour, Timespan::Day]))->toBeTrue()
        ->and(Timespan::Second->isIn([Timespan::Hour, Timespan::Day]))->toBeFalse()
        ->and(Timespan::Second->isNotIn([Timespan::Hour, Timespan::Day]))->toBeTrue();
});

it('runs the matching branch of whenIs', function () {
    $matched = false;
    $fellThrough = false;

    Timespan::Hour->whenIs(
        Timespan::Hour,
        function () use (&$matched): void {
            $matched = true;
        },
        function () use (&$fellThrough): void {
            $fellThrough = true;
        },
    );

    expect($matched)->toBeTrue()->and($fellThrough)->toBeFalse();
});

it('runs the default branch of whenIs when it does not match', function () {
    $matched = false;
    $fellThrough = false;

    Timespan::Hour->whenIs(
        Timespan::Day,
        function () use (&$matched): void {
            $matched = true;
        },
        function () use (&$fellThrough): void {
            $fellThrough = true;
        },
    );

    expect($matched)->toBeFalse()->and($fellThrough)->toBeTrue();
});

it('runs the matching branch of whenIsNot', function () {
    $ran = false;

    Timespan::Hour->whenIsNot(Timespan::Day, function () use (&$ran): void {
        $ran = true;
    });

    expect($ran)->toBeTrue();
});
