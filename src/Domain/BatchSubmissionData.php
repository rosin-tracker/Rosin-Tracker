<?php

declare(strict_types=1);

namespace RosinTracker\Domain;

/** A validated New Batch submission and its explicit ordered press passes. */
final readonly class BatchSubmissionData
{
    /** @param non-empty-list<PassData> $passes */
    public function __construct(
        public BatchData $batch,
        public array $passes,
    ) {
    }
}
