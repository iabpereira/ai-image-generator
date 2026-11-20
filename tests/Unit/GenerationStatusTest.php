<?php

use App\Enums\GenerationStatus;

it('only moves forward', function (GenerationStatus $from, GenerationStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    [GenerationStatus::Pending, GenerationStatus::Queued, true],
    [GenerationStatus::Pending, GenerationStatus::Succeeded, true],
    [GenerationStatus::Queued, GenerationStatus::Processing, true],
    [GenerationStatus::Processing, GenerationStatus::Queued, false],
    [GenerationStatus::Processing, GenerationStatus::Processing, false],
    [GenerationStatus::Succeeded, GenerationStatus::Failed, false],
    [GenerationStatus::Failed, GenerationStatus::Succeeded, false],
    [GenerationStatus::Succeeded, GenerationStatus::Processing, false],
]);

it('lists the statuses allowed to move into a status', function () {
    expect(GenerationStatus::predecessorsOf(GenerationStatus::Processing))
        ->toBe([GenerationStatus::Pending, GenerationStatus::Queued]);
});
