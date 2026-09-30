<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Model;

/** The text being classified, plus any labels it already carries (input to derived strategies). */
final readonly class Document
{
    public const string SCOPE_HEADLINE = 'headline';
    public const string SCOPE_SUMMARY = 'summary';
    public const string SCOPE_BODY = 'body';

    /** @param array<string, list<string>> $labels vocabulary name => concept codes */
    public function __construct(
        public string $id,
        public string $headline,
        public string $summary = '',
        public string $body = '',
        public array $labels = [],
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['headline'] ?? ''),
            (string) ($row['summary'] ?? ''),
            (string) ($row['body'] ?? ''),
            array_map(static fn (array $codes): array => array_values(array_map(strval(...), $codes)), $row['labels'] ?? []),
        );
    }

    public function text(string $scope): string
    {
        return match ($scope) {
            self::SCOPE_HEADLINE => $this->headline,
            self::SCOPE_SUMMARY => $this->summary,
            self::SCOPE_BODY => $this->body,
            default => throw new \InvalidArgumentException(sprintf('Unknown scope "%s".', $scope)),
        };
    }
}
