<?php

declare(strict_types=1);

use NunoMaduro\PhpInsights\Domain\Insights\ClassMethodAverageCyclomaticComplexityIsHigh;
use NunoMaduro\PhpInsights\Domain\Insights\CyclomaticComplexityIsHigh;
use NunoMaduro\PhpInsights\Domain\Insights\MethodCyclomaticComplexityIsHigh;
use NunoMaduro\PhpInsights\Domain\Sniffs\ForbiddenSetterSniff;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Formatting\SpaceAfterNotSniff;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Files\LineLengthSniff;
use PhpCsFixer\Fixer\ClassNotation\OrderedClassElementsFixer;
use SlevomatCodingStandard\Sniffs\TypeHints\DisallowMixedTypeHintSniff;

return [
    'preset' => 'default',
    'exclude' => [
        'vendor',
        'src/Resources/app',
        'src/Resources/views',
    ],
    'remove' => [
        DisallowMixedTypeHintSniff::class,
        ForbiddenSetterSniff::class,
        LineLengthSniff::class,
        OrderedClassElementsFixer::class,
        SpaceAfterNotSniff::class,
    ],
    'config' => [
        CyclomaticComplexityIsHigh::class => [
            'maxComplexity' => 160,
        ],
        MethodCyclomaticComplexityIsHigh::class => [
            'maxMethodComplexity' => 10,
        ],
        ClassMethodAverageCyclomaticComplexityIsHigh::class => [
            'maxClassMethodAverageComplexity' => 7,
        ],
    ],
];
